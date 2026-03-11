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

    // ── Node data ────────────────────────────────────────────────────────
    $nodesResult = $api->getNodes();
    $nodes = $nodesResult['data'] ?? [];
    $onlineNodes = array_filter($nodes, fn($n) => ($n['status'] ?? '') === 'online');

    // ── Collect tasks from all online nodes (last 24h) ───────────────────
    $now = time();
    $since24h = $now - 86400;
    $since7d = $now - 604800;

    $allTasks = [];
    foreach ($onlineNodes as $node) {
        try {
            $tasks = $api->getNodeTasks($node['node'], ['limit' => 500]);
            foreach ($tasks['data'] ?? [] as $t) {
                $t['_node'] = $node['node'];
                $allTasks[] = $t;
            }
        } catch (\Exception $e) {
            // skip unreachable node
        }
    }

    // Categorize tasks
    $migrations24h = 0;
    $migrations7d = 0;
    $migrationsFailed = 0;
    $snapshots24h = 0;
    $backups24h = 0;
    $vmStarts24h = 0;
    $vmStops24h = 0;
    $clones24h = 0;
    $totalTasks24h = 0;
    $totalTasks7d = 0;
    $failedTasks24h = 0;
    $taskTypes24h = [];

    foreach ($allTasks as $t) {
        $start = $t['starttime'] ?? 0;
        $type = $t['type'] ?? '';
        $status = $t['status'] ?? '';
        $isOk = $status === 'OK' || $status === '';
        $isFailed = !$isOk && $status !== '';

        if ($start >= $since7d) {
            $totalTasks7d++;
            if (in_array($type, ['qmigrate', 'vzmigrate'])) {
                $migrations7d++;
            }
        }

        if ($start >= $since24h) {
            $totalTasks24h++;
            if ($isFailed) $failedTasks24h++;

            // Count by type
            $taskTypes24h[$type] = ($taskTypes24h[$type] ?? 0) + 1;

            if (in_array($type, ['qmigrate', 'vzmigrate'])) {
                $migrations24h++;
                if ($isFailed) $migrationsFailed++;
            } elseif (in_array($type, ['qmsnapshot', 'vzsnapshot'])) {
                $snapshots24h++;
            } elseif (in_array($type, ['vzdump'])) {
                $backups24h++;
            } elseif (in_array($type, ['qmstart', 'vzstart'])) {
                $vmStarts24h++;
            } elseif (in_array($type, ['qmstop', 'vzstop', 'qmshutdown', 'vzshutdown'])) {
                $vmStops24h++;
            } elseif (in_array($type, ['qmclone', 'vzclone'])) {
                $clones24h++;
            }
        }
    }

    // ── Guest resources ──────────────────────────────────────────────────
    $resources = $api->getClusterResources('vm');
    $totalVms = 0;
    $totalRunning = 0;
    $totalQemu = 0;
    $totalLxc = 0;
    $totalGuestCpu = 0;
    $totalGuestMem = 0;
    $totalGuestDisk = 0;

    foreach ($resources['data'] ?? [] as $item) {
        if (!empty($item['template'])) continue;
        $totalVms++;
        if (($item['status'] ?? '') === 'running') {
            $totalRunning++;
            $totalGuestCpu += $item['cpu'] ?? 0;
            $totalGuestMem += $item['mem'] ?? 0;
        }
        $totalGuestDisk += $item['maxdisk'] ?? 0;
        if (($item['type'] ?? '') === 'qemu') $totalQemu++;
        else $totalLxc++;
    }

    // ── Storage ──────────────────────────────────────────────────────────
    $storageResources = $api->getClusterResources('storage');
    $totalStorageUsed = 0;
    $totalStorageMax = 0;
    foreach ($storageResources['data'] ?? [] as $s) {
        $totalStorageUsed += $s['disk'] ?? 0;
        $totalStorageMax += $s['maxdisk'] ?? 0;
    }

    // ── Node performance ─────────────────────────────────────────────────
    $nodeStats = [];
    $clusterCpu = 0;
    $clusterMaxCpu = 0;
    $clusterMem = 0;
    $clusterMaxMem = 0;
    $maxUptime = 0;

    foreach ($onlineNodes as $node) {
        $cpu = ($node['cpu'] ?? 0) * ($node['maxcpu'] ?? 0);
        $clusterCpu += $cpu;
        $clusterMaxCpu += $node['maxcpu'] ?? 0;
        $clusterMem += $node['mem'] ?? 0;
        $clusterMaxMem += $node['maxmem'] ?? 0;
        $uptime = $node['uptime'] ?? 0;
        if ($uptime > $maxUptime) $maxUptime = $uptime;

        $nodeStats[] = [
            'node' => $node['node'],
            'cpu_pct' => round(($node['cpu'] ?? 0) * 100, 1),
            'mem_pct' => ($node['maxmem'] ?? 0) > 0
                ? round(($node['mem'] ?? 0) / $node['maxmem'] * 100, 1) : 0,
            'maxcpu' => $node['maxcpu'] ?? 0,
            'maxmem' => $node['maxmem'] ?? 0,
            'mem_used' => $node['mem'] ?? 0,
            'uptime' => $uptime,
        ];
    }

    // ── App deploy stats (from app_logs) ─────────────────────────────────
    $deployCount24h = 0;
    $deployCount7d = 0;
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM app_logs WHERE category = 'deploy' AND level = 'info' AND created_at >= NOW() - INTERVAL '24 hours'");
        $stmt->execute();
        $deployCount24h = (int)$stmt->fetchColumn();

        $stmt = $db->prepare("SELECT COUNT(*) FROM app_logs WHERE category = 'deploy' AND level = 'info' AND created_at >= NOW() - INTERVAL '7 days'");
        $stmt->execute();
        $deployCount7d = (int)$stmt->fetchColumn();
    } catch (\Exception $e) {
        // table may not exist yet
    }

    // ── HA status ────────────────────────────────────────────────────────
    $haManaged = 0;
    try {
        $haResources = $api->getHAResources();
        $haManaged = count($haResources['data'] ?? []);
    } catch (\Exception $e) {}

    Response::success([
        'nodes' => [
            'total' => count($nodes),
            'online' => count($onlineNodes),
            'stats' => $nodeStats,
            'max_uptime' => $maxUptime,
        ],
        'cluster' => [
            'cpu_pct' => $clusterMaxCpu > 0 ? round($clusterCpu / $clusterMaxCpu * 100, 1) : 0,
            'total_cores' => $clusterMaxCpu,
            'mem_used' => $clusterMem,
            'mem_total' => $clusterMaxMem,
            'mem_pct' => $clusterMaxMem > 0 ? round($clusterMem / $clusterMaxMem * 100, 1) : 0,
            'storage_used' => $totalStorageUsed,
            'storage_total' => $totalStorageMax,
            'storage_pct' => $totalStorageMax > 0 ? round($totalStorageUsed / $totalStorageMax * 100, 1) : 0,
        ],
        'guests' => [
            'total' => $totalVms,
            'running' => $totalRunning,
            'stopped' => $totalVms - $totalRunning,
            'qemu' => $totalQemu,
            'lxc' => $totalLxc,
            'ha_managed' => $haManaged,
        ],
        'tasks' => [
            'total_24h' => $totalTasks24h,
            'total_7d' => $totalTasks7d,
            'failed_24h' => $failedTasks24h,
            'migrations_24h' => $migrations24h,
            'migrations_7d' => $migrations7d,
            'migrations_failed' => $migrationsFailed,
            'snapshots_24h' => $snapshots24h,
            'backups_24h' => $backups24h,
            'vm_starts_24h' => $vmStarts24h,
            'vm_stops_24h' => $vmStops24h,
            'clones_24h' => $clones24h,
            'types_24h' => $taskTypes24h,
        ],
        'deploys' => [
            'count_24h' => $deployCount24h,
            'count_7d' => $deployCount7d,
        ],
    ]);
} catch (\Exception $e) {
    Response::error($e->getMessage(), 500);
}
