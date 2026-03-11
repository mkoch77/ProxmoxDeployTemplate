<?php

namespace App;

use PDO;

class RightSizing
{
    // Thresholds for sizing recommendations
    private const CPU_OVER_THRESHOLD = 0.10;    // avg < 10% → oversized
    private const CPU_UNDER_THRESHOLD = 0.80;   // p95 > 80% → undersized
    private const MEM_OVER_THRESHOLD = 0.30;    // avg usage < 30% of allocated → oversized
    private const MEM_UNDER_THRESHOLD = 0.85;   // p95 > 85% of allocated → undersized
    private const MIN_SAMPLES = 60;             // ~10 minutes at 10s intervals

    public static function analyze(string $timerange = '24h'): array
    {
        AppLogger::debug('monitoring', 'RightSizing analysis started', ['timerange' => $timerange]);

        $summaries = MonitoringCollector::getAllVmSummaries($timerange);

        // Get current VM configs from the cluster (cores, memory) to filter stale suggestions
        $liveConfigs = self::getLiveVmConfigs();

        // Get physical core limits per node (to suppress impossible recommendations)
        $nodeMaxCpus = self::getNodeMaxCpus();

        // Get VMs with recent right-sizing applies (cooldown period)
        $recentApplies = self::getRecentApplies();

        // Get node-level context: vCPU:pCPU ratios and I/O wait
        $nodeVcpuRatios = self::getNodeVcpuRatios($liveConfigs, $nodeMaxCpus);
        $nodeIowait = self::getNodeIowait($timerange);

        $results = [];

        foreach ($summaries as $vm) {
            if ((int)($vm['samples'] ?? 0) < self::MIN_SAMPLES) continue;

            $vmid = (int)$vm['vmid'];

            // Skip VMs that no longer exist in the cluster
            if ($liveConfigs !== null && !isset($liveConfigs[$vmid])) continue;

            // Skip VMs with recent right-sizing apply (cooldown: suppress until
            // VM has rebooted and new monitoring data replaces old baseline)
            if (isset($recentApplies[$vmid])) {
                $appliedAgo = time() - strtotime($recentApplies[$vmid]);
                if ($appliedAgo < 600) {
                    // Within 10 min cooldown — always suppress
                    continue;
                }
                // After cooldown, check if live config now differs from monitoring baseline
                if ($liveConfigs !== null && isset($liveConfigs[$vmid])) {
                    $live = $liveConfigs[$vmid];
                    $monitorCores = (int)($vm['cpu_count'] ?? 0);
                    $monitorMem = (int)($vm['mem_total'] ?? 0);
                    if ($live['cores'] !== $monitorCores || $live['mem_bytes'] !== $monitorMem) {
                        // Config was changed but monitoring data still shows old values — suppress
                        continue;
                    }
                    // Live config matches monitoring data — new baseline established, clean up
                    self::clearApply($vmid);
                }
            }

            // Use live config instead of monitoring aggregates for analysis
            // Monitoring uses MAX() over 24h which mixes old and new values after config changes
            if ($liveConfigs !== null && isset($liveConfigs[$vmid])) {
                $vm['cpu_count'] = $liveConfigs[$vmid]['cores'];
                $vm['mem_total'] = $liveConfigs[$vmid]['mem_bytes'];
            }

            $vmNode = $liveConfigs[$vmid]['node'] ?? ($vm['node'] ?? '');
            $nodeContext = [
                'vcpu_ratio' => $nodeVcpuRatios[$vmNode] ?? 0,
                'iowait' => $nodeIowait[$vmNode] ?? 0,
                'node_max_cpus' => $nodeMaxCpus[$vmNode] ?? 0,
            ];
            $recommendation = self::analyzeVm($vm, $nodeContext);
            if ($recommendation) {
                // Skip CPU recommendation if it exceeds node's physical core count
                $recCores = $recommendation['recommended']['cpu_cores'] ?? null;
                if ($recCores !== null) {
                    $vmNode = $liveConfigs[$vmid]['node'] ?? ($recommendation['node'] ?? '');
                    $maxCpu = $nodeMaxCpus[$vmNode] ?? 0;
                    if ($maxCpu > 0 && $recCores > $maxCpu) {
                        $currentCores = $recommendation['current']['cpu_cores'] ?? 0;
                        if ($currentCores >= $maxCpu) {
                            // Already at max — remove CPU suggestion entirely
                            unset($recommendation['recommended']['cpu_cores']);
                            $recommendation['issues'] = array_values(array_filter($recommendation['issues'], fn($i) => !str_contains($i, 'CPU')));
                            $recommendation['suggestions'] = array_values(array_filter($recommendation['suggestions'], fn($s) => !str_contains($s, 'CPU cores')));
                            if (empty($recommendation['issues'])) continue;
                        } else {
                            // Cap at node max
                            $recommendation['recommended']['cpu_cores'] = $maxCpu;
                            $recommendation['suggestions'] = array_map(fn($s) => str_contains($s, 'CPU cores')
                                ? sprintf('Increase CPU cores from %d to %d (node max)', $currentCores, $maxCpu)
                                : $s, $recommendation['suggestions']);
                        }
                    }
                }
                $results[] = $recommendation;
            }
        }

        AppLogger::debug('monitoring', 'RightSizing analysis complete', ['recommendations' => count($results)]);

        // Sort: most impactful first (undersized before oversized)
        usort($results, function ($a, $b) {
            $priority = ['critical' => 0, 'undersized' => 1, 'oversized' => 2, 'optimal' => 3];
            $pa = $priority[$a['severity']] ?? 3;
            $pb = $priority[$b['severity']] ?? 3;
            return $pa - $pb;
        });

        return $results;
    }

    public static function analyzeVm(array $vm, array $nodeContext = []): ?array
    {
        $vmid = (int)$vm['vmid'];
        $name = $vm['name'] ?? "VM $vmid";
        $node = $vm['node'] ?? '';
        $type = $vm['vm_type'] ?? 'qemu';
        $cpuCount = (int)($vm['cpu_count'] ?? 0);
        $memTotal = (int)($vm['mem_total'] ?? 0);

        if ($cpuCount === 0 || $memTotal === 0) return null;

        $avgCpu = (float)($vm['avg_cpu'] ?? 0);
        $maxCpu = (float)($vm['max_cpu'] ?? 0);
        $p95Cpu = (float)($vm['p95_cpu'] ?? 0);
        $avgMem = (float)($vm['avg_mem'] ?? 0);
        $maxMem = (float)($vm['max_mem'] ?? 0);
        $p95Mem = (float)($vm['p95_mem'] ?? 0);

        $memPctAvg = $memTotal > 0 ? $avgMem / $memTotal : 0;
        $memPctP95 = $memTotal > 0 ? $p95Mem / $memTotal : 0;

        $vcpuRatio = (float)($nodeContext['vcpu_ratio'] ?? 0);
        $nodeIowait = (float)($nodeContext['iowait'] ?? 0);

        $issues = [];
        $suggestions = [];
        $severity = 'optimal';

        // CPU analysis
        if ($p95Cpu > self::CPU_UNDER_THRESHOLD) {
            // If node is already heavily overcommitted (>4:1), warn instead of recommending more cores
            if ($vcpuRatio > 4) {
                $severity = 'undersized';
                $issues[] = sprintf('CPU p95 at %.0f%% — high load, but node vCPU:pCPU is %.1f:1 (overcommitted)', $p95Cpu * 100, $vcpuRatio);
                $suggestions[] = sprintf('Consider migrating to a less loaded node (vCPU:pCPU %.1f:1)', $vcpuRatio);
            } else {
                $severity = 'undersized';
                $recCores = self::recommendCpuCores($p95Cpu, $cpuCount);
                $issues[] = sprintf('CPU p95 at %.0f%% — consistently high load', $p95Cpu * 100);
                $suggestions[] = sprintf('Increase CPU cores from %d to %d', $cpuCount, $recCores);
            }
        } elseif ($avgCpu < self::CPU_OVER_THRESHOLD && $maxCpu < 0.30) {
            $recCores = self::recommendCpuCoresDown($avgCpu, $p95Cpu, $cpuCount);
            if ($recCores < $cpuCount) {
                $severity = 'oversized';
                $issues[] = sprintf('CPU avg %.1f%%, max %.1f%% — very low utilization', $avgCpu * 100, $maxCpu * 100);
                $suggestions[] = sprintf('Reduce CPU cores from %d to %d', $cpuCount, $recCores);
                // Emphasize reduction if node is overcommitted
                if ($vcpuRatio > 2) {
                    $suggestions[] = sprintf('Node vCPU:pCPU is %.1f:1 — reducing cores improves cluster balance', $vcpuRatio);
                }
            }
        }

        // Memory analysis
        if ($memPctP95 > self::MEM_UNDER_THRESHOLD) {
            $severity = ($severity === 'undersized') ? 'critical' : 'undersized';
            $recMem = self::recommendMemoryUp($p95Mem, $memTotal);
            $issues[] = sprintf('RAM p95 at %.0f%% of allocated — near limit', $memPctP95 * 100);
            $suggestions[] = sprintf('Increase memory from %s to %s', self::formatBytes($memTotal), self::formatBytes($recMem));
        } elseif ($memPctAvg < self::MEM_OVER_THRESHOLD && $memPctP95 < 0.50) {
            $recMem = self::recommendMemoryDown($p95Mem, $memTotal);
            if ($recMem < $memTotal) {
                if ($severity === 'optimal') $severity = 'oversized';
                $issues[] = sprintf('RAM avg %.0f%%, p95 %.0f%% of allocated — underutilized', $memPctAvg * 100, $memPctP95 * 100);
                $suggestions[] = sprintf('Reduce memory from %s to %s', self::formatBytes($memTotal), self::formatBytes($recMem));
            }
        }

        // I/O Wait analysis — flag if node has sustained high I/O wait
        if ($nodeIowait > 15) {
            if ($severity === 'optimal') $severity = 'undersized';
            $issues[] = sprintf('Node I/O wait avg %.1f%% — storage bottleneck', $nodeIowait);
            $suggestions[] = 'Check storage performance or migrate to a node with lower I/O wait';
        } elseif ($nodeIowait > 5 && !empty($issues)) {
            // Only mention moderate I/O wait if there are already other issues
            $issues[] = sprintf('Node I/O wait avg %.1f%% — may affect performance', $nodeIowait);
        }

        if (empty($issues)) return null;

        // Build recommended values (only include changed fields)
        $recommended = [];
        if ($p95Cpu > self::CPU_UNDER_THRESHOLD && $vcpuRatio <= 4) {
            $recommended['cpu_cores'] = self::recommendCpuCores($p95Cpu, $cpuCount);
        } elseif ($avgCpu < self::CPU_OVER_THRESHOLD && $maxCpu < 0.30) {
            $recCores = self::recommendCpuCoresDown($avgCpu, $p95Cpu, $cpuCount);
            if ($recCores < $cpuCount) $recommended['cpu_cores'] = $recCores;
        }
        if ($memPctP95 > self::MEM_UNDER_THRESHOLD) {
            $recommended['mem_bytes'] = self::recommendMemoryUp($p95Mem, $memTotal);
        } elseif ($memPctAvg < self::MEM_OVER_THRESHOLD && $memPctP95 < 0.50) {
            $recMem = self::recommendMemoryDown($p95Mem, $memTotal);
            if ($recMem < $memTotal) $recommended['mem_bytes'] = $recMem;
        }

        return [
            'vmid' => $vmid,
            'name' => $name,
            'node' => $node,
            'vm_type' => $type,
            'severity' => $severity,
            'current' => [
                'cpu_cores' => $cpuCount,
                'mem_bytes' => $memTotal,
            ],
            'recommended' => $recommended,
            'usage' => [
                'avg_cpu' => round($avgCpu * 100, 1),
                'max_cpu' => round($maxCpu * 100, 1),
                'p95_cpu' => round($p95Cpu * 100, 1),
                'avg_mem_pct' => round($memPctAvg * 100, 1),
                'p95_mem_pct' => round($memPctP95 * 100, 1),
                'avg_mem_bytes' => (int)$avgMem,
                'p95_mem_bytes' => (int)$p95Mem,
            ],
            'node_context' => [
                'vcpu_ratio' => round($vcpuRatio, 1),
                'iowait' => round($nodeIowait, 1),
            ],
            'issues' => $issues,
            'suggestions' => $suggestions,
            'samples' => (int)($vm['samples'] ?? 0),
        ];
    }

    private static function getRecentApplies(): array
    {
        try {
            $db = Database::connection();
            $rows = $db->query('SELECT vmid, applied_at FROM rightsizing_applied')->fetchAll(PDO::FETCH_ASSOC);
            $map = [];
            foreach ($rows as $row) {
                $map[(int)$row['vmid']] = $row['applied_at'];
            }
            return $map;
        } catch (\Exception $e) {
            return [];
        }
    }

    private static function clearApply(int $vmid): void
    {
        try {
            $db = Database::connection();
            $stmt = $db->prepare('DELETE FROM rightsizing_applied WHERE vmid = ?');
            $stmt->execute([$vmid]);
        } catch (\Exception $e) {
            // ignore
        }
    }

    private static function getLiveVmConfigs(): ?array
    {
        try {
            $api = Helpers::createAPI();
            $resources = $api->getClusterResources('vm');
            $configs = [];
            foreach ($resources['data'] ?? [] as $r) {
                if (!empty($r['vmid'])) {
                    $configs[(int)$r['vmid']] = [
                        'cores' => (int)($r['maxcpu'] ?? 0),
                        'mem_bytes' => (int)($r['maxmem'] ?? 0),
                        'node' => $r['node'] ?? '',
                    ];
                }
            }
            return $configs;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get physical core count per node.
     */
    private static function getNodeMaxCpus(): array
    {
        try {
            $api = Helpers::createAPI();
            $nodes = $api->getNodes();
            $map = [];
            foreach ($nodes['data'] ?? [] as $n) {
                $map[$n['node']] = (int)($n['maxcpu'] ?? 0);
            }
            return $map;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Compute vCPU:pCPU ratio per node from live VM configs.
     */
    private static function getNodeVcpuRatios(?array $liveConfigs, array $nodeMaxCpus): array
    {
        if ($liveConfigs === null) return [];
        $vcpuPerNode = [];
        foreach ($liveConfigs as $vmid => $cfg) {
            $n = $cfg['node'] ?? '';
            if ($n) {
                $vcpuPerNode[$n] = ($vcpuPerNode[$n] ?? 0) + ($cfg['cores'] ?? 0);
            }
        }
        $ratios = [];
        foreach ($vcpuPerNode as $node => $vcpus) {
            $pCpus = $nodeMaxCpus[$node] ?? 0;
            $ratios[$node] = $pCpus > 0 ? $vcpus / $pCpus : 0;
        }
        return $ratios;
    }

    /**
     * Get average I/O wait per node from monitoring data.
     */
    private static function getNodeIowait(string $timerange): array
    {
        try {
            $db = Database::connection();
            $intervalMap = [
                '1h' => '1 hour', '3h' => '3 hours', '6h' => '6 hours',
                '12h' => '12 hours', '24h' => '24 hours', '48h' => '48 hours',
                '7d' => '7 days', '30d' => '30 days',
            ];
            $interval = $intervalMap[$timerange] ?? '24 hours';
            // disk_read_iops stores iowait (0-1 float) from Proxmox RRD
            $stmt = $db->prepare("SELECT node, AVG(disk_read_iops) * 100 as avg_iowait
                FROM node_metrics WHERE ts >= NOW() - ?::interval
                GROUP BY node");
            $stmt->execute([$interval]);
            $map = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $map[$row['node']] = (float)$row['avg_iowait'];
            }
            return $map;
        } catch (\Exception $e) {
            return [];
        }
    }

    private static function recommendCpuCores(float $p95Cpu, int $currentCores): int
    {
        // Target: p95 CPU usage at ~60% after scaling
        $targetUtilization = 0.60;
        $needed = ceil(($p95Cpu * $currentCores) / $targetUtilization);
        return max($currentCores + 1, (int)$needed);
    }

    private static function recommendCpuCoresDown(float $avgCpu, float $p95Cpu, int $currentCores): int
    {
        // Keep p95 under 50% with fewer cores; minimum 1
        $targetUtilization = 0.50;
        $needed = ceil(($p95Cpu * $currentCores) / $targetUtilization);
        return max(1, (int)$needed);
    }

    private static function recommendMemoryUp(float $p95Mem, int $currentMem): int
    {
        // Give 30% headroom above p95
        $needed = $p95Mem * 1.30;
        // Round up to nearest 256MB
        $mb = 256 * 1024 * 1024;
        return (int)(ceil($needed / $mb) * $mb);
    }

    private static function recommendMemoryDown(float $p95Mem, int $currentMem): int
    {
        // Keep 40% headroom above p95; minimum 256MB
        $needed = $p95Mem * 1.40;
        $mb = 256 * 1024 * 1024;
        $result = (int)(ceil($needed / $mb) * $mb);
        return max($mb, $result);
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) return round($bytes / 1073741824, 1) . ' GB';
        if ($bytes >= 1048576) return round($bytes / 1048576) . ' MB';
        return $bytes . ' B';
    }
}
