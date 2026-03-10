<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\Auth;
use App\Request;
use App\Response;
use App\Helpers;
use App\AffinityHelper;
use App\AppLogger;

Bootstrap::init();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

switch ($method) {
    case 'GET':
        Auth::requirePermission('cluster.affinity');

        if ($action === 'zones') {
            $allZones = AffinityHelper::getNodeZones();
            $groups = AffinityHelper::getZoneGroups();
            Response::success(['zones' => $allZones, 'zone_groups' => $groups]);
        } elseif ($action === 'rules') {
            $rules = AffinityHelper::getRules();

            // Enrich rules with VM names from cluster
            try {
                $api = Helpers::createAPI();
                $resources = $api->getClusterResources('vm');
                $vmNames = [];
                foreach ($resources['data'] ?? [] as $r) {
                    if (!empty($r['vmid'])) {
                        $vmNames[(int)$r['vmid']] = [
                            'name' => $r['name'] ?? "VM {$r['vmid']}",
                            'node' => $r['node'] ?? '',
                            'status' => $r['status'] ?? '',
                        ];
                    }
                }
                foreach ($rules as &$rule) {
                    $rule['vm_details'] = [];
                    foreach ($rule['vmids'] as $vmid) {
                        $rule['vm_details'][] = array_merge(
                            ['vmid' => $vmid],
                            $vmNames[$vmid] ?? ['name' => "VM $vmid (not found)", 'node' => '', 'status' => '']
                        );
                    }
                }
            } catch (\Exception $e) {
                // Enrich failed — return rules without VM details
            }

            Response::success(['rules' => $rules]);
        } elseif ($action === 'overview') {
            $allZones = AffinityHelper::getNodeZones();
            $groups = AffinityHelper::getZoneGroups();
            $rules = AffinityHelper::getRules();
            $violations = [];

            try {
                $api = Helpers::createAPI();
                $vmNodeMap = AffinityHelper::getVmNodeMap($api);
                $resources = $api->getClusterResources('vm');
                $vmNames = [];
                foreach ($resources['data'] ?? [] as $r) {
                    if (!empty($r['vmid'])) {
                        $vmNames[(int)$r['vmid']] = $r['name'] ?? "VM {$r['vmid']}";
                    }
                }

                foreach ($rules as &$rule) {
                    $zoneGroup = $rule['zone_group'] ?? 'default';
                    $zones = $allZones[$zoneGroup] ?? [];

                    $rule['vm_details'] = [];
                    foreach ($rule['vmids'] as $vmid) {
                        $node = $vmNodeMap[$vmid] ?? '';
                        $zone = $zones[$node] ?? null;
                        $rule['vm_details'][] = [
                            'vmid' => $vmid,
                            'name' => $vmNames[$vmid] ?? "VM $vmid",
                            'node' => $node,
                            'zone' => $zone,
                        ];
                    }

                    // Detect violations
                    if ($rule['type'] === 'anti-affinity') {
                        $zoneVmids = [];
                        $unassigned = [];
                        foreach ($rule['vm_details'] as $vm) {
                            if ($vm['zone']) {
                                $zoneVmids[$vm['zone']][] = $vm;
                            } else {
                                $unassigned[] = $vm;
                            }
                        }
                        foreach ($zoneVmids as $zone => $vms) {
                            if (count($vms) > 1) {
                                $names = array_map(fn($v) => "{$v['vmid']} ({$v['name']})", $vms);
                                $violations[] = [
                                    'rule' => $rule['name'],
                                    'type' => 'anti-affinity',
                                    'zone_group' => $zoneGroup,
                                    'message' => sprintf(
                                        'VMs %s are in the same zone "%s"',
                                        implode(', ', $names), $zone
                                    ),
                                ];
                            }
                        }
                        if (!empty($unassigned)) {
                            $names = array_map(fn($v) => "{$v['vmid']} ({$v['name']}@{$v['node']})", $unassigned);
                            $violations[] = [
                                'rule' => $rule['name'],
                                'type' => 'anti-affinity',
                                'zone_group' => $zoneGroup,
                                'message' => sprintf('VMs %s are on node(s) without zone assignment', implode(', ', $names)),
                            ];
                        }
                    } elseif ($rule['type'] === 'affinity') {
                        $zonesUsed = [];
                        $unassigned = [];
                        foreach ($rule['vm_details'] as $vm) {
                            if ($vm['zone']) {
                                $zonesUsed[$vm['zone']][] = $vm;
                            } else {
                                $unassigned[] = $vm;
                            }
                        }

                        // Violation conditions:
                        // 1. VMs spread across multiple zones
                        // 2. Any VM on an unassigned node (doesn't matter if others are assigned or not)
                        // 3. VMs in different zones
                        $hasViolation = count($zonesUsed) > 1 || !empty($unassigned);

                        if ($hasViolation) {
                            $parts = [];
                            foreach ($zonesUsed as $zone => $vms) {
                                $names = array_map(fn($v) => "{$v['vmid']} ({$v['name']})", $vms);
                                $parts[] = sprintf('%s in zone "%s"', implode(', ', $names), $zone);
                            }
                            if (!empty($unassigned)) {
                                $names = array_map(fn($v) => "{$v['vmid']} ({$v['name']}@{$v['node']})", $unassigned);
                                $parts[] = sprintf('%s on unassigned node(s)', implode(', ', $names));
                            }
                            $violations[] = [
                                'rule' => $rule['name'],
                                'type' => 'affinity',
                                'zone_group' => $zoneGroup,
                                'message' => 'VMs are not in the required zone: ' . implode('; ', $parts),
                            ];
                        }
                    }
                }
            } catch (\Exception $e) {
                // Best effort
            }

            Response::success([
                'zones' => $allZones,
                'zone_groups' => $groups,
                'rules' => $rules,
                'violations' => $violations,
            ]);
        } else {
            Response::error('Invalid action. Use: zones, rules, overview', 400);
        }
        break;

    case 'POST':
        Request::validateCsrf();
        $user = Auth::requirePermission('cluster.affinity');
        $body = Request::jsonBody();

        if ($action === 'zone') {
            $nodeName = $body['node'] ?? '';
            $zoneName = trim($body['zone'] ?? '');
            $zoneGroup = trim($body['zone_group'] ?? 'default');

            if (!$nodeName || !Helpers::validateNodeName($nodeName)) {
                Response::error('Invalid node name', 400);
            }
            if (!$zoneName || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\-_]{0,63}$/', $zoneName)) {
                Response::error('Invalid zone name (alphanumeric, dashes, underscores)', 400);
            }
            if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\-_]{0,63}$/', $zoneGroup)) {
                Response::error('Invalid zone group name', 400);
            }

            AffinityHelper::setNodeZone($nodeName, $zoneName, $zoneGroup);
            AppLogger::info('affinity', "Node {$nodeName} assigned to zone {$zoneName} in group {$zoneGroup}", null, $user['id']);
            Response::success(['node' => $nodeName, 'zone' => $zoneName, 'zone_group' => $zoneGroup]);

        } elseif ($action === 'zone-remove') {
            $nodeName = $body['node'] ?? '';
            $zoneGroup = trim($body['zone_group'] ?? 'default');
            if (!$nodeName) Response::error('Missing node name', 400);
            AffinityHelper::removeNodeZone($nodeName, $zoneGroup);
            AppLogger::info('affinity', "Node {$nodeName} removed from zone group {$zoneGroup}", null, $user['id']);
            Response::success(['removed' => $nodeName, 'zone_group' => $zoneGroup]);

        } elseif ($action === 'zone-group-delete') {
            $zoneGroup = trim($body['zone_group'] ?? '');
            if (!$zoneGroup || $zoneGroup === 'default') {
                Response::error('Cannot delete default zone group', 400);
            }
            // Check if any rules use this zone group
            $rules = AffinityHelper::getRules();
            $usedBy = array_filter($rules, fn($r) => ($r['zone_group'] ?? 'default') === $zoneGroup);
            if (!empty($usedBy)) {
                $names = array_map(fn($r) => $r['name'], $usedBy);
                Response::error('Zone group is used by rules: ' . implode(', ', $names), 400);
            }
            AffinityHelper::deleteZoneGroup($zoneGroup);
            AppLogger::info('affinity', "Zone group '{$zoneGroup}' deleted", null, $user['id']);
            Response::success(['deleted' => $zoneGroup]);

        } elseif ($action === 'rule') {
            $id = (int)($body['id'] ?? 0);
            $name = trim($body['name'] ?? '');
            $type = $body['type'] ?? '';
            $vmids = $body['vmids'] ?? [];
            $zoneGroup = trim($body['zone_group'] ?? 'default');

            if (!$name) Response::error('Rule name is required', 400);
            if (!in_array($type, ['affinity', 'anti-affinity'], true)) {
                Response::error('Type must be "affinity" or "anti-affinity"', 400);
            }
            if (!is_array($vmids) || count($vmids) < 2) {
                Response::error('At least 2 VMIDs are required', 400);
            }

            if ($id > 0) {
                AffinityHelper::updateRule($id, $name, $type, $vmids, $zoneGroup);
                AppLogger::info('affinity', "Rule '{$name}' (#{$id}) updated", ['type' => $type, 'vmids' => $vmids, 'zone_group' => $zoneGroup], $user['id']);
                Response::success(['id' => $id, 'updated' => true]);
            } else {
                $newId = AffinityHelper::createRule($name, $type, $vmids, $zoneGroup);
                AppLogger::info('affinity', "Rule '{$name}' created", ['type' => $type, 'vmids' => $vmids, 'zone_group' => $zoneGroup], $user['id']);
                Response::success(['id' => $newId, 'created' => true]);
            }

        } elseif ($action === 'rule-delete') {
            $id = (int)($body['id'] ?? 0);
            if ($id <= 0) Response::error('Invalid rule ID', 400);
            AffinityHelper::deleteRule($id);
            AppLogger::info('affinity', "Rule #{$id} deleted", null, $user['id']);
            Response::success(['deleted' => $id]);

        } elseif ($action === 'resolve') {
            // Auto-resolve all affinity violations by migrating VMs
            try {
                $api = Helpers::createAPI();
                $migrations = AffinityHelper::resolveViolations($api);

                if (empty($migrations)) {
                    Response::success(['message' => 'No violations to resolve', 'migrations' => []]);
                }

                $results = [];
                foreach ($migrations as $mig) {
                    try {
                        $result = $api->migrateGuest(
                            $mig['source_node'],
                            $mig['vm_type'],
                            $mig['vmid'],
                            $mig['target_node'],
                            true // online migration
                        );
                        $results[] = [
                            'vmid' => $mig['vmid'],
                            'vm_name' => $mig['vm_name'],
                            'source' => $mig['source_node'],
                            'target' => $mig['target_node'],
                            'rule' => $mig['rule'],
                            'reason' => $mig['reason'],
                            'upid' => $result['data'] ?? '',
                            'status' => 'running',
                        ];
                    } catch (\Exception $e) {
                        $results[] = [
                            'vmid' => $mig['vmid'],
                            'vm_name' => $mig['vm_name'],
                            'source' => $mig['source_node'],
                            'target' => $mig['target_node'],
                            'rule' => $mig['rule'],
                            'reason' => $mig['reason'],
                            'status' => 'error',
                            'error' => $e->getMessage(),
                        ];
                    }
                }

                $succeeded = count(array_filter($results, fn($r) => $r['status'] === 'running'));
                $failed = count(array_filter($results, fn($r) => $r['status'] === 'error'));
                AppLogger::info('affinity', "Resolved violations: {$succeeded} migrations started, {$failed} failed", ['results' => $results], $user['id']);

                Response::success(['migrations' => $results]);
            } catch (\Exception $e) {
                AppLogger::error('affinity', 'Failed to resolve violations', ['error' => $e->getMessage()], $user['id']);
                Response::error($e->getMessage(), 500);
            }

        } else {
            Response::error('Invalid action', 400);
        }
        break;

    default:
        Response::error('Method not allowed', 405);
}
