<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\Auth;
use App\Request;
use App\Response;
use App\Helpers;
use App\AppLogger;

Bootstrap::init();
Request::requireMethod('GET');
Auth::requirePermission('cluster.health.view');

$report = Request::get('report', '');

if ($report === 'vm-inventory') {
    AppLogger::debug('reports', 'Generating VM inventory report');

    try {
        $api = Helpers::createAPI();
        $guests = $api->getGuests();

        $onlineNodes = [];
        try {
            $nodesResult = $api->getNodes();
            foreach ($nodesResult['data'] ?? [] as $n) {
                if (($n['status'] ?? '') === 'online') {
                    $onlineNodes[$n['node']] = true;
                }
            }
        } catch (\Exception $e) {}

        // Get guest configs for OS type
        foreach ($guests as &$guest) {
            $guest['ostype'] = null;
            if (!isset($onlineNodes[$guest['node']])) continue;
            try {
                $config = $api->getGuestConfig($guest['node'], $guest['type'], (int)$guest['vmid']);
                $guest['ostype'] = $config['data']['ostype'] ?? null;
            } catch (\Exception $e) {}
        }
        unset($guest);

        // Attach IPs
        $db = \App\Database::connection();
        $ipRows = $db->query('SELECT vmid, node, ips FROM guest_ips')->fetchAll(PDO::FETCH_ASSOC);
        $ipMap = [];
        foreach ($ipRows as $row) {
            $ipMap[$row['vmid'] . '-' . $row['node']] = json_decode($row['ips'], true) ?: [];
        }

        $rows = [];
        foreach ($guests as $g) {
            $key = $g['vmid'] . '-' . $g['node'];
            $ips = $ipMap[$key] ?? [];
            // Primary IP: first non-loopback IPv4
            $primaryIp = '';
            foreach ($ips as $ip) {
                if (is_string($ip) && !str_starts_with($ip, '127.') && !str_contains($ip, ':')) {
                    $primaryIp = $ip;
                    break;
                }
            }

            $rows[] = [
                'vmid' => (int)$g['vmid'],
                'name' => $g['name'] ?? '',
                'type' => $g['type'] ?? 'qemu',
                'node' => $g['node'] ?? '',
                'status' => $g['status'] ?? 'unknown',
                'cpus' => (int)($g['maxcpu'] ?? 0),
                'ram_bytes' => (int)($g['maxmem'] ?? 0),
                'disk_max_bytes' => (int)($g['maxdisk'] ?? 0),
                'disk_used_bytes' => (int)($g['disk'] ?? 0),
                'ostype' => $g['ostype'] ?? '',
                'primary_ip' => $primaryIp,
                'tags' => $g['tags'] ?? '',
            ];
        }

        usort($rows, fn($a, $b) => $a['vmid'] - $b['vmid']);

        AppLogger::info('reports', 'VM inventory report generated', ['count' => count($rows)], Auth::check()['id'] ?? null);
        Response::success(['rows' => $rows]);
    } catch (\Exception $e) {
        AppLogger::error('reports', 'VM inventory report failed: ' . $e->getMessage(), null, Auth::check()['id'] ?? null);
        Response::error($e->getMessage(), 500);
    }
    exit; // safety net
}

// ── Snapshot Report ──────────────────────────────────────────────────────────
if ($report === 'snapshots') {
    AppLogger::debug('reports', 'Generating snapshot report');

    try {
        $api = Helpers::createAPI();
        $guests = $api->getGuests();

        $onlineNodes = [];
        try {
            $nodesResult = $api->getNodes();
            foreach ($nodesResult['data'] ?? [] as $n) {
                if (($n['status'] ?? '') === 'online') {
                    $onlineNodes[$n['node']] = true;
                }
            }
        } catch (\Exception $e) {}

        $rows = [];
        foreach ($guests as $g) {
            $vmid = (int)($g['vmid'] ?? 0);
            $node = $g['node'] ?? '';
            $type = $g['type'] ?? 'qemu';
            if (!$vmid || !$node || !isset($onlineNodes[$node])) continue;

            try {
                $snaps = $api->getSnapshots($node, $type, $vmid);
                $snapList = $snaps['data'] ?? [];
                // Filter out 'current' pseudo-snapshot
                $snapList = array_filter($snapList, fn($s) => ($s['name'] ?? '') !== 'current');
                if (empty($snapList)) continue;

                $oldest = null;
                $newest = null;
                $names = [];
                foreach ($snapList as $s) {
                    $ts = $s['snaptime'] ?? 0;
                    $names[] = $s['name'] ?? '';
                    if ($oldest === null || $ts < $oldest) $oldest = $ts;
                    if ($newest === null || $ts > $newest) $newest = $ts;
                }

                $rows[] = [
                    'vmid' => $vmid,
                    'name' => $g['name'] ?? '',
                    'type' => $type,
                    'node' => $node,
                    'status' => $g['status'] ?? 'unknown',
                    'snapshot_count' => count($snapList),
                    'oldest_snapshot' => $oldest ? date('Y-m-d H:i', $oldest) : '',
                    'oldest_age_days' => $oldest ? (int)round((time() - $oldest) / 86400) : 0,
                    'newest_snapshot' => $newest ? date('Y-m-d H:i', $newest) : '',
                    'snapshot_names' => $names,
                    'disk_max_bytes' => (int)($g['maxdisk'] ?? 0),
                ];
            } catch (\Exception $e) {}
        }

        // Sort by oldest snapshot age descending (most stale first)
        usort($rows, fn($a, $b) => $b['oldest_age_days'] - $a['oldest_age_days']);

        AppLogger::info('reports', 'Snapshot report generated', ['count' => count($rows)], Auth::check()['id'] ?? null);
        Response::success(['rows' => $rows]);
    } catch (\Exception $e) {
        AppLogger::error('reports', 'Snapshot report failed: ' . $e->getMessage(), null, Auth::check()['id'] ?? null);
        Response::error($e->getMessage(), 500);
    }
    exit;
}

// ── Stopped VMs Report ───────────────────────────────────────────────────────
if ($report === 'stopped-vms') {
    AppLogger::debug('reports', 'Generating stopped VMs report');

    try {
        $api = Helpers::createAPI();
        $guests = $api->getGuests();

        $rows = [];
        foreach ($guests as $g) {
            if (($g['status'] ?? '') !== 'stopped') continue;
            if (!empty($g['template'])) continue;

            $rows[] = [
                'vmid' => (int)($g['vmid'] ?? 0),
                'name' => $g['name'] ?? '',
                'type' => $g['type'] ?? 'qemu',
                'node' => $g['node'] ?? '',
                'cpus' => (int)($g['maxcpu'] ?? 0),
                'ram_bytes' => (int)($g['maxmem'] ?? 0),
                'disk_max_bytes' => (int)($g['maxdisk'] ?? 0),
                'disk_used_bytes' => (int)($g['disk'] ?? 0),
                'tags' => $g['tags'] ?? '',
            ];
        }

        usort($rows, fn($a, $b) => $b['disk_max_bytes'] - $a['disk_max_bytes']);

        // Total wasted resources
        $totalDisk = array_sum(array_column($rows, 'disk_max_bytes'));
        $totalRam = array_sum(array_column($rows, 'ram_bytes'));
        $totalCpus = array_sum(array_column($rows, 'cpus'));

        AppLogger::info('reports', 'Stopped VMs report generated', ['count' => count($rows)], Auth::check()['id'] ?? null);
        Response::success([
            'rows' => $rows,
            'totals' => [
                'count' => count($rows),
                'disk_bytes' => $totalDisk,
                'ram_bytes' => $totalRam,
                'cpus' => $totalCpus,
            ],
        ]);
    } catch (\Exception $e) {
        AppLogger::error('reports', 'Stopped VMs report failed: ' . $e->getMessage(), null, Auth::check()['id'] ?? null);
        Response::error($e->getMessage(), 500);
    }
    exit;
}

// ── Storage Usage per VM ─────────────────────────────────────────────────────
if ($report === 'storage-usage') {
    AppLogger::debug('reports', 'Generating storage usage report');

    try {
        $api = Helpers::createAPI();
        $guests = $api->getGuests();

        $rows = [];
        foreach ($guests as $g) {
            if (!empty($g['template'])) continue;
            $maxDisk = (int)($g['maxdisk'] ?? 0);
            $usedDisk = (int)($g['disk'] ?? 0);
            $pct = $maxDisk > 0 ? round($usedDisk / $maxDisk * 100, 1) : 0;

            $rows[] = [
                'vmid' => (int)($g['vmid'] ?? 0),
                'name' => $g['name'] ?? '',
                'type' => $g['type'] ?? 'qemu',
                'node' => $g['node'] ?? '',
                'status' => $g['status'] ?? 'unknown',
                'disk_max_bytes' => $maxDisk,
                'disk_used_bytes' => $usedDisk,
                'disk_pct' => $pct,
                'ram_bytes' => (int)($g['maxmem'] ?? 0),
            ];
        }

        // Sort by usage % descending
        usort($rows, fn($a, $b) => $b['disk_pct'] <=> $a['disk_pct']);

        AppLogger::info('reports', 'Storage usage report generated', ['count' => count($rows)], Auth::check()['id'] ?? null);
        Response::success(['rows' => $rows]);
    } catch (\Exception $e) {
        AppLogger::error('reports', 'Storage usage report failed: ' . $e->getMessage(), null, Auth::check()['id'] ?? null);
        Response::error($e->getMessage(), 500);
    }
    exit;
}

// ── Resource Overcommit Report ───────────────────────────────────────────────
if ($report === 'resource-overcommit') {
    AppLogger::debug('reports', 'Generating resource overcommit report');

    try {
        $api = Helpers::createAPI();
        $nodesResult = $api->getNodes();
        $nodes = $nodesResult['data'] ?? [];

        // Get allocated resources per node
        $resources = $api->getClusterResources('vm');
        $vcpuPerNode = [];
        $vramPerNode = [];
        $vmCountPerNode = [];
        foreach ($resources['data'] ?? [] as $item) {
            if (!empty($item['template'])) continue;
            $guestNode = $item['node'] ?? '';
            if (!$guestNode) continue;
            $vcpuPerNode[$guestNode] = ($vcpuPerNode[$guestNode] ?? 0) + (int)($item['maxcpu'] ?? 0);
            $vramPerNode[$guestNode] = ($vramPerNode[$guestNode] ?? 0) + (int)($item['maxmem'] ?? 0);
            $vmCountPerNode[$guestNode] = ($vmCountPerNode[$guestNode] ?? 0) + 1;
        }

        $rows = [];
        foreach ($nodes as $node) {
            $name = $node['node'] ?? '';
            $isOnline = ($node['status'] ?? '') === 'online';
            $maxMem = (int)($node['maxmem'] ?? 0);
            $maxCpu = (int)($node['maxcpu'] ?? 0);

            // Get physical cores
            $physicalCores = $maxCpu;
            if ($isOnline) {
                try {
                    $nodeStatus = $api->getNodeStatus($name);
                    $cpuInfo = $nodeStatus['data']['cpuinfo'] ?? [];
                    $physicalCores = ((int)($cpuInfo['sockets'] ?? 1)) * ((int)($cpuInfo['cores'] ?? $maxCpu));
                } catch (\Exception $e) {}
            }

            $allocVcpu = $vcpuPerNode[$name] ?? 0;
            $allocRam = $vramPerNode[$name] ?? 0;
            $cpuRatio = $physicalCores > 0 ? round($allocVcpu / $physicalCores, 1) : 0;
            $ramRatio = $maxMem > 0 ? round($allocRam / $maxMem * 100, 1) : 0;

            $rows[] = [
                'node' => $name,
                'status' => $node['status'] ?? 'unknown',
                'vm_count' => $vmCountPerNode[$name] ?? 0,
                'physical_cores' => $physicalCores,
                'threads' => $maxCpu,
                'allocated_vcpus' => $allocVcpu,
                'cpu_ratio' => $cpuRatio,
                'total_ram_bytes' => $maxMem,
                'allocated_ram_bytes' => $allocRam,
                'ram_alloc_pct' => $ramRatio,
                'used_ram_bytes' => (int)($node['mem'] ?? 0),
                'used_cpu_pct' => round(($node['cpu'] ?? 0) * 100, 1),
            ];
        }

        usort($rows, fn($a, $b) => $b['cpu_ratio'] <=> $a['cpu_ratio']);

        AppLogger::info('reports', 'Resource overcommit report generated', ['count' => count($rows)], Auth::check()['id'] ?? null);
        Response::success(['rows' => $rows]);
    } catch (\Exception $e) {
        AppLogger::error('reports', 'Resource overcommit report failed: ' . $e->getMessage(), null, Auth::check()['id'] ?? null);
        Response::error($e->getMessage(), 500);
    }
    exit;
}

// ── Resource Forecast Report ─────────────────────────────────────────────
if ($report === 'resource-forecast') {
    AppLogger::debug('reports', 'Generating resource forecast report');

    try {
        $db = \App\Database::connection();

        // Helper: simple linear regression returns [slope, intercept, r_squared]
        $linreg = function (array $xs, array $ys): array {
            $n = count($xs);
            if ($n < 3) return [0, 0, 0];
            $sx = array_sum($xs); $sy = array_sum($ys);
            $sxx = 0; $sxy = 0;
            for ($i = 0; $i < $n; $i++) {
                $sxx += $xs[$i] * $xs[$i];
                $sxy += $xs[$i] * $ys[$i];
            }
            $denom = $n * $sxx - $sx * $sx;
            if (abs($denom) < 1e-10) return [0, $sy / $n, 0];
            $slope = ($n * $sxy - $sx * $sy) / $denom;
            $intercept = ($sy - $slope * $sx) / $n;
            // R²
            $mean = $sy / $n;
            $ssTot = 0; $ssRes = 0;
            for ($i = 0; $i < $n; $i++) {
                $ssTot += ($ys[$i] - $mean) ** 2;
                $ssRes += ($ys[$i] - ($slope * $xs[$i] + $intercept)) ** 2;
            }
            $r2 = $ssTot > 0 ? 1 - $ssRes / $ssTot : 0;
            return [$slope, $intercept, $r2];
        };

        // Days until value reaches target, given current value and daily slope
        $daysUntil = function (float $current, float $target, float $dailySlope): ?int {
            if ($dailySlope <= 0) return null; // not growing
            $remaining = $target - $current;
            if ($remaining <= 0) return 0; // already exceeded
            return (int)ceil($remaining / $dailySlope);
        };

        // ── Get live node data from API (always available) ────────────────
        $api = Helpers::createAPI();
        $liveNodes = [];
        try {
            $nodesResult = $api->getNodes();
            foreach ($nodesResult['data'] ?? [] as $n) {
                if (($n['status'] ?? '') !== 'online') continue;
                $liveNodes[$n['node']] = [
                    'cpu' => (float)($n['cpu'] ?? 0),
                    'mem_used' => (int)($n['mem'] ?? 0),
                    'mem_total' => (int)($n['maxmem'] ?? 0),
                ];
            }
        } catch (\Exception $e) {}

        // ── Node CPU & RAM forecast (from node_metrics, last 30 days) ────
        $stmt = $db->prepare("
            SELECT node,
                   DATE(ts) as day,
                   AVG(cpu_pct) as avg_cpu,
                   AVG(mem_used) as avg_mem_used,
                   MAX(mem_total) as mem_total
            FROM node_metrics
            WHERE ts >= NOW() - INTERVAL '30 days'
            GROUP BY node, DATE(ts)
            ORDER BY node, day
        ");
        $stmt->execute();
        $dailyRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Group by node
        $byNode = [];
        foreach ($dailyRows as $row) {
            $byNode[$row['node']][] = $row;
        }

        $nodeForecasts = [];
        // Start with all live nodes (even without history)
        $allNodeNames = array_unique(array_merge(array_keys($liveNodes), array_keys($byNode)));
        sort($allNodeNames);

        foreach ($allNodeNames as $nodeName) {
            $days = $byNode[$nodeName] ?? [];
            $live = $liveNodes[$nodeName] ?? null;
            $hasTrend = count($days) >= 2;

            // Current values: prefer history last point, fallback to live
            if (!empty($days)) {
                $baseDay = strtotime($days[0]['day']);
                $memTotal = (int)$days[count($days) - 1]['mem_total'];
                $xs = []; $cpuYs = []; $memYs = [];
                foreach ($days as $d) {
                    $xs[] = (strtotime($d['day']) - $baseDay) / 86400;
                    $cpuYs[] = (float)$d['avg_cpu'];
                    $memYs[] = (float)$d['avg_mem_used'];
                }
                $lastCpu = end($cpuYs);
                $lastMem = end($memYs);
            } elseif ($live) {
                $memTotal = $live['mem_total'];
                $lastCpu = $live['cpu'];
                $lastMem = (float)$live['mem_used'];
                $xs = []; $cpuYs = []; $memYs = [];
            } else {
                continue;
            }

            // Regression only with enough data
            $cpuSlope = 0; $cpuR2 = 0; $memSlope = 0; $memR2 = 0;
            if ($hasTrend) {
                [$cpuSlope, , $cpuR2] = $linreg($xs, $cpuYs);
                [$memSlope, , $memR2] = $linreg($xs, $memYs);
            }

            $memPct = $memTotal > 0 ? $lastMem / $memTotal : 0;

            $cpuDays80 = $hasTrend ? $daysUntil($lastCpu, 0.80, $cpuSlope) : null;
            $cpuDays90 = $hasTrend ? $daysUntil($lastCpu, 0.90, $cpuSlope) : null;
            $memDays80 = ($hasTrend && $memTotal > 0) ? $daysUntil($lastMem, $memTotal * 0.80, $memSlope) : null;
            $memDays90 = ($hasTrend && $memTotal > 0) ? $daysUntil($lastMem, $memTotal * 0.90, $memSlope) : null;
            $memDays100 = ($hasTrend && $memTotal > 0) ? $daysUntil($lastMem, $memTotal, $memSlope) : null;

            $nodeForecasts[] = [
                'node' => $nodeName,
                'data_days' => count($days),
                'has_trend' => $hasTrend,
                'cpu' => [
                    'current_pct' => round($lastCpu * 100, 1),
                    'daily_change_pct' => round($cpuSlope * 100, 2),
                    'trend' => !$hasTrend ? 'no_data' : ($cpuSlope > 0.001 ? 'rising' : ($cpuSlope < -0.001 ? 'falling' : 'stable')),
                    'r_squared' => round($cpuR2, 3),
                    'days_to_80' => $cpuDays80,
                    'days_to_90' => $cpuDays90,
                ],
                'ram' => [
                    'current_bytes' => (int)$lastMem,
                    'total_bytes' => $memTotal,
                    'current_pct' => round($memPct * 100, 1),
                    'daily_change_bytes' => (int)round($memSlope),
                    'trend' => !$hasTrend ? 'no_data' : ($memSlope > 1048576 ? 'rising' : ($memSlope < -1048576 ? 'falling' : 'stable')),
                    'r_squared' => round($memR2, 3),
                    'days_to_80' => $memDays80,
                    'days_to_90' => $memDays90,
                    'days_to_100' => $memDays100,
                ],
            ];
        }

        // ── Storage forecast (from vm_metrics disk_used aggregated by day) ──
        $stmt = $db->prepare("
            SELECT DATE(ts) as day,
                   SUM(disk_used) as total_used,
                   SUM(disk_total) as total_capacity
            FROM vm_metrics
            WHERE ts >= NOW() - INTERVAL '30 days'
              AND status = 'running'
            GROUP BY DATE(ts)
            ORDER BY day
        ");
        $stmt->execute();
        $storageDays = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $storageForecast = null;
        if (count($storageDays) >= 2) {
            $baseDay = strtotime($storageDays[0]['day']);
            $xs = []; $usedYs = [];
            foreach ($storageDays as $d) {
                $xs[] = (strtotime($d['day']) - $baseDay) / 86400;
                $usedYs[] = (float)$d['total_used'];
            }

            [$storSlope, , $storR2] = $linreg($xs, $usedYs);
            $lastUsed = end($usedYs);
            $lastCapacity = (float)$storageDays[count($storageDays) - 1]['total_capacity'];
            $usedPct = $lastCapacity > 0 ? $lastUsed / $lastCapacity : 0;

            $storageForecast = [
                'current_used_bytes' => (int)$lastUsed,
                'total_capacity_bytes' => (int)$lastCapacity,
                'current_pct' => round($usedPct * 100, 1),
                'daily_change_bytes' => (int)round($storSlope),
                'trend' => $storSlope > 1048576 ? 'rising' : ($storSlope < -1048576 ? 'falling' : 'stable'),
                'r_squared' => round($storR2, 3),
                'days_to_80' => $lastCapacity > 0 ? $daysUntil($lastUsed, $lastCapacity * 0.80, $storSlope) : null,
                'days_to_90' => $lastCapacity > 0 ? $daysUntil($lastUsed, $lastCapacity * 0.90, $storSlope) : null,
                'days_to_100' => $lastCapacity > 0 ? $daysUntil($lastUsed, $lastCapacity, $storSlope) : null,
                'data_days' => count($storageDays),
            ];
        }

        // ── Per-storage-pool from Proxmox API (current snapshot) ────────
        $storagePools = [];
        try {
            $api = Helpers::createAPI();
            $storageResources = $api->getClusterResources('storage');
            $pools = [];
            foreach ($storageResources['data'] ?? [] as $s) {
                $key = $s['storage'] ?? '';
                if (!isset($pools[$key])) {
                    $pools[$key] = ['storage' => $key, 'type' => $s['plugintype'] ?? $s['type'] ?? '', 'total' => 0, 'used' => 0];
                }
                $pools[$key]['total'] += $s['maxdisk'] ?? 0;
                $pools[$key]['used'] += $s['disk'] ?? 0;
            }
            foreach ($pools as &$p) {
                $p['pct'] = $p['total'] > 0 ? round($p['used'] / $p['total'] * 100, 1) : 0;
                $p['free_bytes'] = $p['total'] - $p['used'];
            }
            unset($p);
            $storagePools = array_values($pools);
            usort($storagePools, fn($a, $b) => $b['pct'] <=> $a['pct']);
        } catch (\Exception $e) {}

        // ── VM count growth ─────────────────────────────────────────────
        $stmt = $db->prepare("
            SELECT DATE(ts) as day, COUNT(DISTINCT vmid) as vm_count
            FROM vm_metrics
            WHERE ts >= NOW() - INTERVAL '30 days'
            GROUP BY DATE(ts)
            ORDER BY day
        ");
        $stmt->execute();
        $vmCountDays = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get live VM count as fallback
        $liveVmCount = 0;
        try {
            $liveResources = $api->getClusterResources('vm');
            foreach ($liveResources['data'] ?? [] as $item) {
                if (empty($item['template'])) $liveVmCount++;
            }
        } catch (\Exception $e) {}

        $vmGrowth = null;
        if (count($vmCountDays) >= 2) {
            $baseDay = strtotime($vmCountDays[0]['day']);
            $xs = []; $ys = [];
            foreach ($vmCountDays as $d) {
                $xs[] = (strtotime($d['day']) - $baseDay) / 86400;
                $ys[] = (int)$d['vm_count'];
            }
            [$vmSlope, , $vmR2] = $linreg($xs, $ys);
            $vmGrowth = [
                'current_count' => end($ys),
                'daily_change' => round($vmSlope, 2),
                'monthly_change' => round($vmSlope * 30, 1),
                'trend' => $vmSlope > 0.05 ? 'rising' : ($vmSlope < -0.05 ? 'falling' : 'stable'),
                'r_squared' => round($vmR2, 3),
                'data_days' => count($vmCountDays),
            ];
        } elseif ($liveVmCount > 0) {
            $vmGrowth = [
                'current_count' => $liveVmCount,
                'daily_change' => 0,
                'monthly_change' => 0,
                'trend' => 'no_data',
                'r_squared' => 0,
                'data_days' => count($vmCountDays),
            ];
        }

        usort($nodeForecasts, fn($a, $b) => strcasecmp($a['node'], $b['node']));

        AppLogger::info('reports', 'Resource forecast report generated', ['nodes' => count($nodeForecasts)], Auth::check()['id'] ?? null);
        Response::success([
            'nodes' => $nodeForecasts,
            'storage' => $storageForecast,
            'storage_pools' => $storagePools,
            'vm_growth' => $vmGrowth,
        ]);
    } catch (\Exception $e) {
        AppLogger::error('reports', 'Resource forecast report failed: ' . $e->getMessage(), null, Auth::check()['id'] ?? null);
        Response::error($e->getMessage(), 500);
    }
    exit;
}

Response::error('Unknown report type', 400);
