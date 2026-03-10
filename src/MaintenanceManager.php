<?php

namespace App;

use PDO;

class MaintenanceManager
{
    /**
     * Select best target node for a VM migration.
     * Considers: online status, maintenance mode, memory capacity, affinity rules.
     *
     * @param int|null $vmid If provided, affinity rules are checked for this VM
     */
    public static function selectTargetNode(ProxmoxAPI $api, string $excludeNode, ?int $vmid = null): ?string
    {
        $nodes = $api->getNodes()['data'] ?? [];
        $db = Database::connection();
        $maintNodes = $db->query('SELECT node_name FROM maintenance_nodes')
            ->fetchAll(PDO::FETCH_COLUMN);

        $candidates = [];
        foreach ($nodes as $node) {
            if ($node['node'] === $excludeNode) continue;
            if (in_array($node['node'], $maintNodes, true)) continue;
            if (($node['status'] ?? '') !== 'online') continue;
            $candidates[] = $node;
        }

        if (empty($candidates)) return null;

        // Sort by available memory descending
        usort($candidates, fn($a, $b) =>
            (($b['maxmem'] ?? 0) - ($b['mem'] ?? 0)) <=> (($a['maxmem'] ?? 0) - ($a['mem'] ?? 0))
        );

        // If VMID provided, filter by affinity rules
        if ($vmid !== null) {
            $zones = AffinityHelper::getNodeZones();
            $rules = AffinityHelper::getRules();
            if (!empty($zones) && !empty($rules)) {
                $vmNodeMap = AffinityHelper::getVmNodeMap($api);
                foreach ($candidates as $c) {
                    if (AffinityHelper::isTargetAllowed($vmid, $c['node'], $zones, $rules, $vmNodeMap)) {
                        return $c['node'];
                    }
                }
                // No candidate satisfies affinity rules — fall back to best memory candidate
                AppLogger::warning('maintenance', "No affinity-compatible target for VM {$vmid}, using best-memory fallback");
            }
        }

        return $candidates[0]['node'];
    }

    public static function getNodeGuests(ProxmoxAPI $api, string $node): array
    {
        $guests = [];

        $vms = $api->getVMs($node)['data'] ?? [];
        foreach ($vms as $vm) {
            if (($vm['status'] ?? '') === 'running' && empty($vm['template'])) {
                $vm['type'] = 'qemu';
                $guests[] = $vm;
            }
        }

        $cts = $api->getCTs($node)['data'] ?? [];
        foreach ($cts as $ct) {
            if (($ct['status'] ?? '') === 'running' && empty($ct['template'])) {
                $ct['type'] = 'lxc';
                $guests[] = $ct;
            }
        }

        return $guests;
    }
}
