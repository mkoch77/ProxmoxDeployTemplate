<?php

namespace App;

use PDO;

class AffinityHelper
{
    /**
     * Validate whether migrating a VM to a target node respects all affinity rules.
     *
     * @return string|null Error message if violation found, null if OK
     */
    public static function validateMigration(ProxmoxAPI $api, int $vmid, string $targetNode): ?string
    {
        $allZones = self::getNodeZones();
        $rules = self::getRules();

        if (empty($allZones) || empty($rules)) {
            return null;
        }

        $vmNodeMap = self::getVmNodeMap($api);

        foreach ($rules as $rule) {
            $ruleVmids = $rule['vmids'];
            if (!in_array($vmid, $ruleVmids, true)) {
                continue;
            }

            $otherVmids = array_filter($ruleVmids, fn($id) => $id !== $vmid);
            if (empty($otherVmids)) {
                continue;
            }

            $zoneGroup = $rule['zone_group'] ?? 'default';
            $zones = $allZones[$zoneGroup] ?? [];
            if (empty($zones)) continue;

            $targetZone = $zones[$targetNode] ?? null;

            if ($rule['type'] === 'anti-affinity') {
                if ($targetZone === null) continue; // Target not in a zone — can't enforce
                foreach ($otherVmids as $otherId) {
                    $otherNode = $vmNodeMap[$otherId] ?? null;
                    if ($otherNode === null) continue;
                    $otherZone = $zones[$otherNode] ?? null;
                    if ($otherZone === null) continue;

                    if ($otherZone === $targetZone) {
                        $otherName = self::getVmName($api, $otherId);
                        return sprintf(
                            'Anti-affinity rule "%s" violated: VM %d (%s) is already in zone "%s" — VM %d cannot be migrated there',
                            $rule['name'], $otherId, $otherName, $targetZone, $vmid
                        );
                    }
                }
            } elseif ($rule['type'] === 'affinity') {
                foreach ($otherVmids as $otherId) {
                    $otherNode = $vmNodeMap[$otherId] ?? null;
                    if ($otherNode === null) continue;
                    $otherZone = $zones[$otherNode] ?? null;

                    // Both assigned: must match
                    if ($targetZone !== null && $otherZone !== null && $otherZone !== $targetZone) {
                        $otherName = self::getVmName($api, $otherId);
                        return sprintf(
                            'Affinity rule "%s" violated: VM %d (%s) is in zone "%s" — VM %d must stay in the same zone',
                            $rule['name'], $otherId, $otherName, $otherZone, $vmid
                        );
                    }
                    // Target unassigned but other VM has a zone — block migration to unassigned node
                    if ($targetZone === null && $otherZone !== null) {
                        $otherName = self::getVmName($api, $otherId);
                        return sprintf(
                            'Affinity rule "%s" violated: VM %d (%s) is in zone "%s" — VM %d cannot migrate to unassigned node "%s"',
                            $rule['name'], $otherId, $otherName, $otherZone, $vmid, $targetNode
                        );
                    }
                }
            }
        }

        return null;
    }

    /**
     * Check if a target node is valid for a VM. Used by load balancer to filter candidates.
     * $zones is now [zone_group => [node_name => zone_name]]
     */
    public static function isTargetAllowed(int $vmid, string $targetNode, array $allZones, array $rules, array $vmNodeMap): bool
    {
        if (empty($allZones) || empty($rules)) {
            return true;
        }

        foreach ($rules as $rule) {
            if (!in_array($vmid, $rule['vmids'], true)) {
                continue;
            }

            $zoneGroup = $rule['zone_group'] ?? 'default';
            $zones = $allZones[$zoneGroup] ?? [];
            if (empty($zones)) continue;

            $targetZone = $zones[$targetNode] ?? null;

            $otherVmids = array_filter($rule['vmids'], fn($id) => $id !== $vmid);

            if ($rule['type'] === 'anti-affinity') {
                if ($targetZone === null) continue; // Can't enforce without zone
                foreach ($otherVmids as $otherId) {
                    $otherNode = $vmNodeMap[$otherId] ?? null;
                    if ($otherNode === null) continue;
                    $otherZone = $zones[$otherNode] ?? null;
                    if ($otherZone === $targetZone) {
                        return false;
                    }
                }
            } elseif ($rule['type'] === 'affinity') {
                foreach ($otherVmids as $otherId) {
                    $otherNode = $vmNodeMap[$otherId] ?? null;
                    if ($otherNode === null) continue;
                    $otherZone = $zones[$otherNode] ?? null;
                    // Target has no zone but other VM does — reject
                    if ($targetZone === null && $otherZone !== null) {
                        return false;
                    }
                    // Both have zones but different — reject
                    if ($targetZone !== null && $otherZone !== null && $otherZone !== $targetZone) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * Compute migrations needed to resolve all affinity violations.
     */
    public static function resolveViolations(ProxmoxAPI $api): array
    {
        $allZones = self::getNodeZones();
        $rules = self::getRules();
        if (empty($allZones) || empty($rules)) return [];

        $vmNodeMap = self::getVmNodeMap($api);
        $resources = $api->getClusterResources('vm');
        $vmInfo = [];
        foreach ($resources['data'] ?? [] as $r) {
            if (!empty($r['vmid'])) {
                $vmInfo[(int)$r['vmid']] = [
                    'name' => $r['name'] ?? "VM {$r['vmid']}",
                    'type' => $r['type'] ?? 'qemu',
                    'status' => $r['status'] ?? '',
                    'maxmem' => (int)($r['maxmem'] ?? 0),
                ];
            }
        }

        // Build node info for target selection
        $nodes = $api->getNodes()['data'] ?? [];
        $db = Database::connection();
        $maintNodes = $db->query('SELECT node_name FROM maintenance_nodes')->fetchAll(PDO::FETCH_COLUMN);
        $onlineNodes = [];
        foreach ($nodes as $n) {
            if (($n['status'] ?? '') === 'online' && !in_array($n['node'], $maintNodes, true)) {
                $onlineNodes[$n['node']] = [
                    'maxmem' => (int)($n['maxmem'] ?? 0),
                    'mem' => (int)($n['mem'] ?? 0),
                ];
            }
        }

        $simVmNodeMap = $vmNodeMap;
        $migrations = [];

        foreach ($rules as $rule) {
            $ruleVmids = $rule['vmids'];
            if (count($ruleVmids) < 2) continue;

            $zoneGroup = $rule['zone_group'] ?? 'default';
            $zones = $allZones[$zoneGroup] ?? [];
            if (empty($zones)) continue;

            // Build node info with zone for this rule's zone group
            $nodesWithZone = [];
            foreach ($onlineNodes as $nodeName => $info) {
                $nodesWithZone[$nodeName] = array_merge($info, [
                    'zone' => $zones[$nodeName] ?? null,
                ]);
            }

            if ($rule['type'] === 'anti-affinity') {
                $zoneVms = [];
                foreach ($ruleVmids as $vid) {
                    $node = $simVmNodeMap[$vid] ?? null;
                    if (!$node) continue;
                    $zone = $zones[$node] ?? null;
                    if (!$zone) continue;
                    $zoneVms[$zone][] = $vid;
                }

                foreach ($zoneVms as $zone => $vidsInZone) {
                    for ($i = 1; $i < count($vidsInZone); $i++) {
                        $vmid = $vidsInZone[$i];
                        $sourceNode = $simVmNodeMap[$vmid] ?? '';

                        $occupiedZones = [];
                        foreach ($ruleVmids as $otherId) {
                            if ($otherId === $vmid) continue;
                            $oNode = $simVmNodeMap[$otherId] ?? null;
                            $oZone = $oNode ? ($zones[$oNode] ?? null) : null;
                            if ($oZone) $occupiedZones[$oZone] = true;
                        }

                        $targetNode = self::findTargetInZones($nodesWithZone, $sourceNode, $occupiedZones, $vmInfo[$vmid]['maxmem'] ?? 0);
                        if (!$targetNode) continue;

                        $migrations[] = [
                            'vmid' => $vmid,
                            'vm_name' => $vmInfo[$vmid]['name'] ?? "VM $vmid",
                            'vm_type' => $vmInfo[$vmid]['type'] ?? 'qemu',
                            'source_node' => $sourceNode,
                            'target_node' => $targetNode,
                            'rule' => $rule['name'],
                            'reason' => sprintf('Anti-affinity: move from zone "%s" to zone "%s"', $zones[$sourceNode] ?? '?', $zones[$targetNode] ?? '?'),
                        ];
                        $simVmNodeMap[$vmid] = $targetNode;
                    }
                }
            } elseif ($rule['type'] === 'affinity') {
                $zoneCount = [];
                foreach ($ruleVmids as $vid) {
                    $node = $simVmNodeMap[$vid] ?? null;
                    if (!$node) continue;
                    $zone = $zones[$node] ?? null;
                    if ($zone) {
                        $zoneCount[$zone] = ($zoneCount[$zone] ?? 0) + 1;
                    }
                }

                if (!empty($zoneCount)) {
                    // Pick zone with most VMs (majority wins)
                    arsort($zoneCount);
                    $targetZone = array_key_first($zoneCount);
                } else {
                    // All VMs on unassigned nodes — pick the first zone that has online nodes
                    $targetZone = null;
                    foreach ($nodesWithZone as $nName => $nInfo) {
                        if ($nInfo['zone'] !== null) {
                            $targetZone = $nInfo['zone'];
                            break;
                        }
                    }
                    if ($targetZone === null) continue; // No zones at all — can't resolve
                }

                foreach ($ruleVmids as $vmid) {
                    $sourceNode = $simVmNodeMap[$vmid] ?? '';
                    $currentZone = $zones[$sourceNode] ?? null;
                    // Already in correct zone — skip
                    if ($currentZone === $targetZone) continue;
                    // VM is on unassigned node OR wrong zone — needs migration

                    $targetNode = self::findNodeInZone($nodesWithZone, $targetZone, $sourceNode, $vmInfo[$vmid]['maxmem'] ?? 0);
                    if (!$targetNode) continue;

                    $migrations[] = [
                        'vmid' => $vmid,
                        'vm_name' => $vmInfo[$vmid]['name'] ?? "VM $vmid",
                        'vm_type' => $vmInfo[$vmid]['type'] ?? 'qemu',
                        'source_node' => $sourceNode,
                        'target_node' => $targetNode,
                        'rule' => $rule['name'],
                        'reason' => sprintf('Affinity: move from zone "%s" to zone "%s"', $currentZone ?? '?', $targetZone),
                    ];
                    $simVmNodeMap[$vmid] = $targetNode;
                }
            }
        }

        return $migrations;
    }

    /**
     * Find a target node in a zone NOT in $occupiedZones.
     */
    private static function findTargetInZones(array $onlineNodes, string $excludeNode, array $occupiedZones, int $requiredMem): ?string
    {
        $candidates = [];
        foreach ($onlineNodes as $nodeName => $info) {
            if ($nodeName === $excludeNode) continue;
            if ($info['zone'] === null) continue;
            if (isset($occupiedZones[$info['zone']])) continue;
            $freeMem = $info['maxmem'] - $info['mem'];
            if ($requiredMem > 0 && $freeMem < $requiredMem) continue;
            $candidates[] = ['node' => $nodeName, 'free' => $freeMem];
        }
        if (empty($candidates)) return null;
        usort($candidates, fn($a, $b) => $b['free'] <=> $a['free']);
        return $candidates[0]['node'];
    }

    /**
     * Find a target node in a specific zone.
     */
    private static function findNodeInZone(array $onlineNodes, string $targetZone, string $excludeNode, int $requiredMem): ?string
    {
        $candidates = [];
        foreach ($onlineNodes as $nodeName => $info) {
            if ($nodeName === $excludeNode) continue;
            if ($info['zone'] !== $targetZone) continue;
            $freeMem = $info['maxmem'] - $info['mem'];
            if ($requiredMem > 0 && $freeMem < $requiredMem) continue;
            $candidates[] = ['node' => $nodeName, 'free' => $freeMem];
        }
        if (empty($candidates)) return null;
        usort($candidates, fn($a, $b) => $b['free'] <=> $a['free']);
        return $candidates[0]['node'];
    }

    // ── Data access ─────────────────────────────────────────────────────────

    /**
     * Get all node zones grouped by zone_group.
     * Returns [zone_group => [node_name => zone_name]]
     */
    public static function getNodeZones(): array
    {
        try {
            $db = Database::connection();
            $rows = $db->query('SELECT node_name, zone_group, zone_name FROM affinity_node_zones')
                ->fetchAll(PDO::FETCH_ASSOC);
            $map = [];
            foreach ($rows as $r) {
                $group = $r['zone_group'] ?? 'default';
                $map[$group][$r['node_name']] = $r['zone_name'];
            }
            return $map;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get node zones for a specific zone group (flat map).
     * Returns [node_name => zone_name]
     */
    public static function getNodeZonesFlat(string $zoneGroup = 'default'): array
    {
        try {
            $db = Database::connection();
            $stmt = $db->prepare('SELECT node_name, zone_name FROM affinity_node_zones WHERE zone_group = ?');
            $stmt->execute([$zoneGroup]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $map = [];
            foreach ($rows as $r) {
                $map[$r['node_name']] = $r['zone_name'];
            }
            return $map;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get all zone groups that exist.
     */
    public static function getZoneGroups(): array
    {
        try {
            $db = Database::connection();
            return $db->query('SELECT DISTINCT zone_group FROM affinity_node_zones ORDER BY zone_group')
                ->fetchAll(PDO::FETCH_COLUMN);
        } catch (\Exception $e) {
            return [];
        }
    }

    public static function getRules(): array
    {
        try {
            $db = Database::connection();
            $rows = $db->query('SELECT * FROM affinity_rules ORDER BY name')
                ->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['vmids'] = json_decode($r['vmids'], true) ?: [];
                $r['vmids'] = array_map('intval', $r['vmids']);
                $r['zone_group'] = $r['zone_group'] ?? 'default';
            }
            return $rows;
        } catch (\Exception $e) {
            return [];
        }
    }

    public static function setNodeZone(string $nodeName, string $zoneName, string $zoneGroup = 'default'): void
    {
        $db = Database::connection();
        $stmt = $db->prepare('INSERT INTO affinity_node_zones (node_name, zone_group, zone_name) VALUES (?, ?, ?)
            ON CONFLICT (node_name, zone_group) DO UPDATE SET zone_name = EXCLUDED.zone_name');
        $stmt->execute([$nodeName, $zoneGroup, $zoneName]);
    }

    public static function removeNodeZone(string $nodeName, string $zoneGroup = 'default'): void
    {
        $db = Database::connection();
        $stmt = $db->prepare('DELETE FROM affinity_node_zones WHERE node_name = ? AND zone_group = ?');
        $stmt->execute([$nodeName, $zoneGroup]);
    }

    public static function createRule(string $name, string $type, array $vmids, string $zoneGroup = 'default'): int
    {
        $db = Database::connection();
        $stmt = $db->prepare('INSERT INTO affinity_rules (name, type, vmids, zone_group) VALUES (?, ?, ?, ?) RETURNING id');
        $stmt->execute([$name, $type, json_encode(array_map('intval', $vmids)), $zoneGroup]);
        return (int) $stmt->fetchColumn();
    }

    public static function updateRule(int $id, string $name, string $type, array $vmids, string $zoneGroup = 'default'): void
    {
        $db = Database::connection();
        $stmt = $db->prepare('UPDATE affinity_rules SET name = ?, type = ?, vmids = ?, zone_group = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $stmt->execute([$name, $type, json_encode(array_map('intval', $vmids)), $zoneGroup, $id]);
    }

    public static function deleteRule(int $id): void
    {
        $db = Database::connection();
        $stmt = $db->prepare('DELETE FROM affinity_rules WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * Delete an entire zone group and all its node assignments.
     */
    public static function deleteZoneGroup(string $zoneGroup): void
    {
        $db = Database::connection();
        $stmt = $db->prepare('DELETE FROM affinity_node_zones WHERE zone_group = ?');
        $stmt->execute([$zoneGroup]);
    }

    /**
     * Get a map of VMID → node name for all VMs in the cluster.
     */
    public static function getVmNodeMap(ProxmoxAPI $api): array
    {
        $resources = $api->getClusterResources('vm');
        $map = [];
        foreach ($resources['data'] ?? [] as $r) {
            if (!empty($r['vmid'])) {
                $map[(int)$r['vmid']] = $r['node'] ?? '';
            }
        }
        return $map;
    }

    private static function getVmName(ProxmoxAPI $api, int $vmid): string
    {
        static $nameCache = null;
        if ($nameCache === null) {
            $nameCache = [];
            $resources = $api->getClusterResources('vm');
            foreach ($resources['data'] ?? [] as $r) {
                if (!empty($r['vmid'])) {
                    $nameCache[(int)$r['vmid']] = $r['name'] ?? "VM {$r['vmid']}";
                }
            }
        }
        return $nameCache[$vmid] ?? "VM $vmid";
    }
}
