<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\Auth;
use App\Request;
use App\Response;
use App\Helpers;
use App\Database;
use App\AppLogger;
use App\CephCollector;

Bootstrap::init();
Request::requireMethod('GET');
Auth::requirePermission('cluster.health.view');

AppLogger::debug('api', 'Fetching cluster health');

try {
    $api = Helpers::createAPI();
    $db = Database::connection();

    // Get nodes
    $nodesResult = $api->getNodes();
    $nodes = $nodesResult['data'] ?? [];

    // Enrich with maintenance status
    $maintStmt = $db->query('SELECT * FROM maintenance_nodes');
    $maintNodes = [];
    foreach ($maintStmt->fetchAll() as $row) {
        $maintNodes[$row['node_name']] = $row;
    }

    // Get node IPs from cluster status
    $nodeIps = [];
    try {
        $clusterStatus = $api->getClusterStatus();
        foreach ($clusterStatus['data'] ?? [] as $entry) {
            if (($entry['type'] ?? '') === 'node' && !empty($entry['ip'])) {
                $nodeIps[$entry['name']] = $entry['ip'];
            }
        }
    } catch (\Exception $e) {}

    $totalCpu = 0;
    $totalMaxCpu = 0;
    $totalMem = 0;
    $totalMaxMem = 0;
    $totalDisk = 0;
    $totalMaxDisk = 0;
    $nodesOnline = 0;

    foreach ($nodes as &$node) {
        $node['maintenance'] = $maintNodes[$node['node']] ?? false;
        $node['ip'] = $nodeIps[$node['node']] ?? '';

        if (($node['status'] ?? '') === 'online') {
            $nodesOnline++;
            $totalCpu += ($node['cpu'] ?? 0) * ($node['maxcpu'] ?? 0);
            $totalMaxCpu += $node['maxcpu'] ?? 0;
            $totalMem += $node['mem'] ?? 0;
            $totalMaxMem += $node['maxmem'] ?? 0;
            $totalDisk += $node['disk'] ?? 0;
            $totalMaxDisk += $node['maxdisk'] ?? 0;
        }
    }
    unset($node);

    // Get guest counts + vCPU allocation per node
    $resources = $api->getClusterResources('vm');
    $totalVms = 0;
    $totalRunning = 0;
    $totalQemu = 0;
    $totalQemuRunning = 0;
    $totalLxc = 0;
    $totalLxcRunning = 0;
    $vcpuPerNode = [];   // node => total allocated vCPUs
    $vramPerNode = [];   // node => total allocated RAM (bytes)
    foreach ($resources['data'] ?? [] as $item) {
        if (empty($item['template'])) {
            $totalVms++;
            $isRunning = ($item['status'] ?? '') === 'running';
            if ($isRunning) $totalRunning++;
            if (($item['type'] ?? '') === 'qemu') {
                $totalQemu++;
                if ($isRunning) $totalQemuRunning++;
            } elseif (($item['type'] ?? '') === 'lxc') {
                $totalLxc++;
                if ($isRunning) $totalLxcRunning++;
            }
            // Count allocated vCPUs and RAM per node (all guests, not just running)
            $guestNode = $item['node'] ?? '';
            $guestCpus = $item['maxcpu'] ?? 0;
            $guestMem  = $item['maxmem'] ?? 0;
            if ($guestNode) {
                if ($guestCpus > 0) {
                    $vcpuPerNode[$guestNode] = ($vcpuPerNode[$guestNode] ?? 0) + $guestCpus;
                }
                if ($guestMem > 0) {
                    $vramPerNode[$guestNode] = ($vramPerNode[$guestNode] ?? 0) + $guestMem;
                }
            }
        }
    }

    // Get storage resources
    $storageResources = $api->getClusterResources('storage');
    $storages = [];
    foreach ($storageResources['data'] ?? [] as $s) {
        $key = $s['storage'] ?? '';
        if (!isset($storages[$key])) {
            $storages[$key] = [
                'storage' => $key,
                'type' => $s['plugintype'] ?? $s['type'] ?? '',
                'total' => 0,
                'used' => 0,
                'avail' => 0,
                'nodes' => [],
            ];
        }
        $storages[$key]['total'] += $s['maxdisk'] ?? 0;
        $storages[$key]['used'] += $s['disk'] ?? 0;
        $storages[$key]['avail'] += ($s['maxdisk'] ?? 0) - ($s['disk'] ?? 0);
        if (!empty($s['node'])) {
            $storages[$key]['nodes'][] = $s['node'];
        }
    }

    // Build VM name lookup + guests list from cluster resources
    $vmNames = [];
    $guests = [];
    foreach ($resources['data'] ?? [] as $item) {
        $vmid = $item['vmid'] ?? null;
        if ($vmid) {
            $vmNames[$vmid] = $item['name'] ?? '';
        }
        if (empty($item['template']) && $vmid) {
            $guests[] = [
                'vmid'   => (int)$vmid,
                'name'   => $item['name'] ?? '',
                'type'   => $item['type'] ?? 'qemu',
                'node'   => $item['node'] ?? '',
                'status' => $item['status'] ?? '',
            ];
        }
    }
    // Sort guests by name then vmid
    usort($guests, fn($a, $b) => ($a['name'] ?: (string)$a['vmid']) <=> ($b['name'] ?: (string)$b['vmid']));

    // HA status (may not be available)
    $ha = null;
    try {
        $haStatus = $api->getHAStatus();
        $haResources = $api->getHAResources();

        // Enrich HA resources with VM names
        $enrichedResources = [];
        foreach ($haResources['data'] ?? [] as $r) {
            // sid format: "vm:103" or "ct:200"
            $vmid = null;
            if (!empty($r['sid'])) {
                $parts = explode(':', $r['sid']);
                $vmid = (int)($parts[1] ?? 0);
            }
            $r['name'] = $vmid ? ($vmNames[$vmid] ?? '') : '';
            $enrichedResources[] = $r;
        }

        $ha = [
            'status' => $haStatus['data'] ?? [],
            'resources' => $enrichedResources,
        ];
    } catch (\Exception $e) {
        // HA not available or not configured
    }

    // Fetch CPU topology per online node (sockets, cores, threads → NUMA info)
    // Also fetch I/O wait from RRD data
    $totalVcpu = 0;
    $totalPhysicalCores = 0;
    $totalMemAllocated = 0;
    $enrichStart = microtime(true);
    $enrichMaxSeconds = 8; // max total time for all per-node enrichment
    foreach ($nodes as &$node) {
        $nodeName = $node['node'] ?? '';
        $node['vcpus_allocated'] = $vcpuPerNode[$nodeName] ?? 0;
        $node['mem_allocated'] = $vramPerNode[$nodeName] ?? 0;
        $totalVcpu += $node['vcpus_allocated'];
        $totalMemAllocated += $node['mem_allocated'];

        if (($node['status'] ?? '') === 'online') {
            // Skip enrichment if we've already spent too long
            $elapsed = microtime(true) - $enrichStart;
            if ($elapsed > $enrichMaxSeconds) {
                $node['cpu_topology'] = null;
                $node['numa_nodes'] = null;
                $node['physical_cores'] = $node['maxcpu'] ?? 0;
                $totalPhysicalCores += $node['maxcpu'] ?? 0;
                $node['iowait'] = 0;
                continue;
            }

            $quickOpts = ['connect_timeout' => 1, 'timeout' => 2];
            try {
                $nodeStatus = $api->getNodeStatus($nodeName, $quickOpts);
                $cpuInfo = $nodeStatus['data']['cpuinfo'] ?? [];
                $node['cpu_topology'] = [
                    'sockets' => (int)($cpuInfo['sockets'] ?? 1),
                    'cores'   => (int)($cpuInfo['cores'] ?? ($node['maxcpu'] ?? 1)),
                    'threads' => (int)($cpuInfo['cpus'] ?? ($node['maxcpu'] ?? 1)),
                    'model'   => $cpuInfo['model'] ?? '',
                ];
                $node['numa_nodes'] = (int)($cpuInfo['sockets'] ?? 1);
                $physCores = $node['cpu_topology']['sockets'] * $node['cpu_topology']['cores'];
                $node['physical_cores'] = $physCores;
                $totalPhysicalCores += $physCores;
            } catch (\Exception $e) {
                $node['cpu_topology'] = null;
                $node['numa_nodes'] = null;
                $node['physical_cores'] = $node['maxcpu'] ?? 0;
                $totalPhysicalCores += $node['maxcpu'] ?? 0;
            }

            // Fetch detailed metrics from RRD data (short timeout)
            try {
                $rrd = $api->getNodeRRDData($nodeName, 'hour', $quickOpts);
                $rrdData = $rrd['data'] ?? [];
                if (!empty($rrdData)) {
                    $last = end($rrdData);
                    $node['iowait'] = round((float)($last['iowait'] ?? 0) * 100, 1);
                    $node['loadavg'] = round((float)($last['loadavg'] ?? 0), 2);
                    $node['swapused'] = (int)($last['swapused'] ?? 0);
                    $node['swaptotal'] = (int)($last['swaptotal'] ?? 0);
                    $node['netin_rate'] = (float)($last['netin'] ?? 0);
                    $node['netout_rate'] = (float)($last['netout'] ?? 0);
                    $node['diskread_rate'] = (float)($last['diskread'] ?? 0);
                    $node['diskwrite_rate'] = (float)($last['diskwrite'] ?? 0);
                } else {
                    $node['iowait'] = 0;
                }
            } catch (\Exception $e) {
                $node['iowait'] = 0;
            }
        } else {
            $node['cpu_topology'] = null;
            $node['numa_nodes'] = null;
            $node['physical_cores'] = 0;
            $node['iowait'] = 0;
        }
    }
    unset($node);

    // Sort nodes alphabetically
    usort($nodes, fn($a, $b) => strcasecmp($a['node'] ?? '', $b['node'] ?? ''));

    // CEPH status (if available)
    $ceph = null;
    $onlineNodeNames = array_map(fn($n) => $n['node'], array_filter($nodes, fn($n) => ($n['status'] ?? '') === 'online'));
    if (!empty($onlineNodeNames)) {
        $ceph = CephCollector::getStatus($api, $onlineNodeNames);
    } else {
        $ceph = ['available' => false];
    }

    Response::success([
        'nodes' => $nodes,
        'ceph' => $ceph,
        'cluster' => [
            'total_cpu' => $totalMaxCpu > 0 ? round($totalCpu / $totalMaxCpu, 4) : 0,
            'total_maxcpu' => $totalMaxCpu,
            'total_physical_cores' => $totalPhysicalCores,
            'total_vcpus' => $totalVcpu,
            'total_mem' => $totalMem,
            'total_maxmem' => $totalMaxMem,
            'total_mem_allocated' => $totalMemAllocated,
            'total_disk' => $totalDisk,
            'total_maxdisk' => $totalMaxDisk,
            'total_vms' => $totalVms,
            'total_running' => $totalRunning,
            'total_qemu' => $totalQemu,
            'total_qemu_running' => $totalQemuRunning,
            'total_lxc' => $totalLxc,
            'total_lxc_running' => $totalLxcRunning,
            'total_nodes' => count($nodes),
            'nodes_online' => $nodesOnline,
        ],
        'storage' => array_values($storages),
        'guests' => $guests,
        'ha' => $ha,
    ]);
} catch (\Exception $e) {
    Response::error($e->getMessage(), 500);
}
