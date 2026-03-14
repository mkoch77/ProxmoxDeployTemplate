<?php

namespace App;

class CephCollector
{
    /**
     * Detect if CEPH is available and return full status.
     * Returns ['available' => false] if CEPH is not installed.
     */
    public static function getStatus(ProxmoxAPI $api, array $onlineNodes): array
    {
        if (empty($onlineNodes)) {
            return ['available' => false];
        }

        $quickOpts = ['connect_timeout' => 2, 'timeout' => 5];

        // Try CEPH status on first available online node
        $lastError = null;
        foreach ($onlineNodes as $node) {
            try {
                $status = $api->getCephStatus($node, $quickOpts);
                $data = $status['data'] ?? [];

                $health = $data['health']['status'] ?? 'UNKNOWN';
                $healthChecks = $data['health']['checks'] ?? [];

                // OSD stats from osd_map
                $osdMap = $data['osdmap']['osdmap'] ?? $data['osdmap'] ?? [];
                $osdTotal = (int)($osdMap['num_osds'] ?? 0);
                $osdUp = (int)($osdMap['num_up_osds'] ?? $osdMap['num_up'] ?? 0);
                $osdIn = (int)($osdMap['num_in_osds'] ?? $osdMap['num_in'] ?? 0);

                // Monitor stats from monmap
                $monMap = $data['monmap'] ?? [];
                $monCount = (int)($monMap['num_mons'] ?? count($monMap['mons'] ?? []));

                // MDS stats
                $mdsMap = $data['fsmap'] ?? [];
                $mdsUp = (int)($mdsMap['up'] ?? 0);
                $mdsIn = (int)($mdsMap['in'] ?? 0);

                // MGR
                $mgrMap = $data['mgrmap'] ?? [];
                $mgrActive = !empty($mgrMap['active_name']);

                // PG stats
                $pgMap = $data['pgmap'] ?? [];
                $pgTotal = (int)($pgMap['num_pgs'] ?? 0);
                $bytesTotal = (int)($pgMap['bytes_total'] ?? 0);
                $bytesUsed = (int)($pgMap['bytes_used'] ?? $pgMap['data_bytes'] ?? 0);
                $bytesAvail = (int)($pgMap['bytes_avail'] ?? 0);
                $readOps = (float)($pgMap['read_op_per_sec'] ?? 0);
                $writeOps = (float)($pgMap['write_op_per_sec'] ?? 0);
                $readBytes = (float)($pgMap['read_bytes_sec'] ?? 0);
                $writeBytes = (float)($pgMap['write_bytes_sec'] ?? 0);
                $objectCount = (int)($pgMap['num_objects'] ?? 0);

                // PG states
                $pgStates = [];
                foreach ($pgMap['pgs_by_state'] ?? [] as $pg) {
                    $pgStates[] = [
                        'state' => $pg['state_name'] ?? '',
                        'count' => (int)($pg['count'] ?? 0),
                    ];
                }

                // Health check messages
                $warnings = [];
                foreach ($healthChecks as $checkName => $check) {
                    $severity = $check['severity'] ?? 'HEALTH_WARN';
                    $summary = $check['summary']['message'] ?? $checkName;
                    $warnings[] = ['severity' => $severity, 'message' => $summary];
                }

                return [
                    'available' => true,
                    'health' => $health,
                    'warnings' => $warnings,
                    'osds' => [
                        'total' => $osdTotal,
                        'up' => $osdUp,
                        'in' => $osdIn,
                    ],
                    'monitors' => $monCount,
                    'mds' => ['up' => $mdsUp, 'in' => $mdsIn],
                    'mgr_active' => $mgrActive,
                    'pgs' => [
                        'total' => $pgTotal,
                        'states' => $pgStates,
                    ],
                    'capacity' => [
                        'total' => $bytesTotal,
                        'used' => $bytesUsed,
                        'available' => $bytesAvail,
                    ],
                    'performance' => [
                        'read_ops' => $readOps,
                        'write_ops' => $writeOps,
                        'read_bytes' => $readBytes,
                        'write_bytes' => $writeBytes,
                    ],
                    'objects' => $objectCount,
                    'queried_node' => $node,
                ];
            } catch (\Exception $e) {
                $lastError = $e;
                // CEPH might not be on this node, try next
                continue;
            }
        }

        // None of the nodes had CEPH
        return ['available' => false];
    }

    /**
     * Get detailed OSD list with status.
     * Proxmox returns a CRUSH tree: root -> hosts -> osds
     */
    public static function getOsdDetails(ProxmoxAPI $api, string $node): array
    {
        $quickOpts = ['connect_timeout' => 2, 'timeout' => 5];
        try {
            $result = $api->getCephOsd($node, $quickOpts);
            $data = $result['data'] ?? [];

            // The API returns a CRUSH tree in data.root with nested children
            $root = $data['root'] ?? $data;
            $osds = [];
            self::extractOsdsFromTree($root, '', $osds);

            // Sort by ID
            usort($osds, fn($a, $b) => $a['id'] - $b['id']);
            return $osds;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Recursively walk the CRUSH tree to extract OSD nodes.
     */
    private static function extractOsdsFromTree(array $node, string $parentHost, array &$osds): void
    {
        $type = $node['type'] ?? '';
        $name = $node['name'] ?? '';

        // Track host name for child OSDs
        $hostName = $parentHost;
        if ($type === 'host') {
            $hostName = $name;
        }

        // If this is an OSD node, collect it
        if ($type === 'osd' || (isset($node['id']) && ($node['id'] ?? -1) >= 0 && $type !== 'root' && $type !== 'host')) {
            $osds[] = [
                'id' => (int)($node['id'] ?? 0),
                'name' => $name ?: ('osd.' . ($node['id'] ?? '?')),
                'status' => $node['status'] ?? 'unknown',
                'host' => is_string($node['host'] ?? null) ? $node['host'] : $hostName,
                'type' => $type,
                'device_class' => $node['device_class'] ?? '',
                'crush_weight' => (float)($node['crush_weight'] ?? 0),
            ];
            return;
        }

        // Recurse into children
        foreach ($node['children'] ?? [] as $child) {
            if (is_array($child)) {
                self::extractOsdsFromTree($child, $hostName, $osds);
            }
        }
    }

    /**
     * Get CEPH pool details with usage and IOPS.
     */
    public static function getPoolDetails(ProxmoxAPI $api, string $node): array
    {
        $quickOpts = ['connect_timeout' => 2, 'timeout' => 5];
        try {
            $result = $api->getCephPools($node, $quickOpts);
            $pools = [];
            foreach ($result['data'] ?? [] as $pool) {
                $pools[] = [
                    'name' => $pool['pool_name'] ?? $pool['name'] ?? '',
                    'id' => (int)($pool['pool'] ?? 0),
                    'size' => (int)($pool['size'] ?? 0),
                    'min_size' => (int)($pool['min_size'] ?? 0),
                    'pg_num' => (int)($pool['pg_num'] ?? 0),
                    'bytes_used' => (int)($pool['bytes_used'] ?? 0),
                    'percent_used' => round((float)($pool['percent_used'] ?? 0), 2),
                    'crush_rule_name' => $pool['crush_rule_name'] ?? '',
                    'type' => $pool['type'] ?? '',
                ];
            }
            return $pools;
        } catch (\Exception $e) {
            return [];
        }
    }
}
