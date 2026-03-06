<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\Auth;
use App\Request;
use App\Response;
use App\Helpers;
use App\Database;

Bootstrap::init();
Request::requireMethod('GET');
Auth::requirePermission('cluster.health.view');

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

    $totalCpu = 0;
    $totalMaxCpu = 0;
    $totalMem = 0;
    $totalMaxMem = 0;
    $totalDisk = 0;
    $totalMaxDisk = 0;
    $nodesOnline = 0;

    foreach ($nodes as &$node) {
        $node['maintenance'] = $maintNodes[$node['node']] ?? false;

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

    // Get guest counts
    $resources = $api->getClusterResources('vm');
    $totalVms = 0;
    $totalRunning = 0;
    $totalQemu = 0;
    $totalQemuRunning = 0;
    $totalLxc = 0;
    $totalLxcRunning = 0;
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

    // Sort nodes alphabetically
    usort($nodes, fn($a, $b) => strcasecmp($a['node'] ?? '', $b['node'] ?? ''));

    Response::success([
        'nodes' => $nodes,
        'cluster' => [
            'total_cpu' => $totalMaxCpu > 0 ? round($totalCpu / $totalMaxCpu, 4) : 0,
            'total_maxcpu' => $totalMaxCpu,
            'total_mem' => $totalMem,
            'total_maxmem' => $totalMaxMem,
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
