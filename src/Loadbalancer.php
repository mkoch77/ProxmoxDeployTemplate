<?php

namespace App;

use PDO;

class Loadbalancer
{
    // Threshold mapping: level (1-5) -> deviation percentage
    private const THRESHOLD_MAP = [
        1 => 0.10,
        2 => 0.15,
        3 => 0.20,
        4 => 0.25,
        5 => 0.30,
    ];

    public static function getSettings(): array
    {
        $db = Database::connection();
        $row = $db->query('SELECT * FROM drs_settings WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        return $row ?: [
            'enabled' => 0,
            'automation_level' => 'manual',
            'cpu_weight' => 50,
            'ram_weight' => 50,
            'threshold' => 3,
            'interval_minutes' => 5,
            'max_concurrent' => 3,
        ];
    }

    public static function updateSettings(array $data): void
    {
        $allowed = ['enabled', 'automation_level', 'cpu_weight', 'ram_weight', 'threshold', 'interval_minutes', 'max_concurrent'];
        $sets = [];
        $values = [];

        foreach ($allowed as $key) {
            if (array_key_exists($key, $data)) {
                $sets[] = "$key = ?";
                $values[] = $data[$key];
            }
        }

        if (empty($sets)) return;

        $sets[] = "updated_at = CURRENT_TIMESTAMP";
        $values[] = 1;

        $db = Database::connection();
        $sql = 'UPDATE drs_settings SET ' . implode(', ', $sets) . ' WHERE id = ?';
        $stmt = $db->prepare($sql);
        $stmt->execute($values);
    }

    /**
     * Main entry point: evaluate cluster and generate recommendations.
     */
    public static function evaluate(ProxmoxAPI $api, string $triggeredBy = 'manual'): array
    {
        $settings = self::getSettings();

        $nodeMetrics = self::collectNodeMetrics($api, $settings);

        if (count($nodeMetrics) < 2) {
            return ['run_id' => null, 'recommendations' => [], 'cluster' => self::buildClusterSummary($nodeMetrics)];
        }

        $scores = array_column($nodeMetrics, 'score');
        $avgScore = array_sum($scores) / count($scores);
        $thresholdPct = self::THRESHOLD_MAP[$settings['threshold']] ?? 0.20;

        $skippedReasons = [];
        $recommendations = self::generateRecommendations($nodeMetrics, $avgScore, $thresholdPct, $settings, $skippedReasons);

        $runId = self::storeRun($triggeredBy, $nodeMetrics, $avgScore, $recommendations, $skippedReasons);

        $executed = 0;
        if ($settings['automation_level'] === 'full' && !empty($recommendations)) {
            $results = self::applyAllRecommendations($api, $runId);
            $executed = count(array_filter($results, fn($r) => $r['status'] === 'applied'));
        }

        return [
            'run_id' => $runId,
            'recommendations' => self::getRunRecommendations($runId),
            'cluster' => self::buildClusterSummary($nodeMetrics),
            'executed' => $executed,
            'skipped' => $skippedReasons,
        ];
    }

    /**
     * Get live cluster balance snapshot for UI.
     */
    public static function getClusterBalance(ProxmoxAPI $api): array
    {
        $settings = self::getSettings();
        $nodeMetrics = self::collectNodeMetrics($api, $settings);
        return self::buildClusterSummary($nodeMetrics);
    }

    /**
     * Collect metrics for all online, non-maintenance nodes.
     */
    private static function collectNodeMetrics(ProxmoxAPI $api, array $settings): array
    {
        $nodes = $api->getNodes()['data'] ?? [];
        $db = Database::connection();
        $maintNodes = $db->query('SELECT node_name FROM maintenance_nodes')
            ->fetchAll(PDO::FETCH_COLUMN);

        $guests = $api->getGuests();
        $guestsByNode = [];
        foreach ($guests as $g) {
            if (($g['status'] ?? '') === 'running') {
                $node = $g['node'] ?? '';
                if (!isset($guestsByNode[$node])) $guestsByNode[$node] = [];
                $guestsByNode[$node][] = $g;
            }
        }

        $cpuWeight = (int)($settings['cpu_weight'] ?? 50);
        $ramWeight = (int)($settings['ram_weight'] ?? 50);

        $metrics = [];
        foreach ($nodes as $node) {
            $name = $node['node'] ?? '';
            if (($node['status'] ?? '') !== 'online') continue;
            if (in_array($name, $maintNodes, true)) continue;

            $cpuPct = (float)($node['cpu'] ?? 0);
            $maxMem = (int)($node['maxmem'] ?? 0);
            $ramPct = $maxMem > 0 ? (float)($node['mem'] ?? 0) / $maxMem : 0;

            $metrics[] = [
                'node' => $name,
                'cpu_pct' => $cpuPct,
                'ram_pct' => $ramPct,
                'score' => self::calculateScore($cpuPct, $ramPct, $cpuWeight, $ramWeight),
                'maxcpu' => (int)($node['maxcpu'] ?? 0),
                'maxmem' => $maxMem,
                'mem' => (int)($node['mem'] ?? 0),
                'guests' => $guestsByNode[$name] ?? [],
            ];
        }

        return $metrics;
    }

    private static function calculateScore(float $cpuPct, float $ramPct, int $cpuWeight, int $ramWeight): float
    {
        $total = $cpuWeight + $ramWeight;
        if ($total === 0) return 0;
        return ($cpuWeight * $cpuPct + $ramWeight * $ramPct) / $total;
    }

    private static function buildClusterSummary(array $nodeMetrics): array
    {
        if (empty($nodeMetrics)) {
            return ['nodes' => [], 'avg_score' => 0, 'std_dev' => 0];
        }

        $scores = array_column($nodeMetrics, 'score');
        $avg = array_sum($scores) / count($scores);
        $variance = 0;
        foreach ($scores as $s) {
            $variance += ($s - $avg) ** 2;
        }
        $stdDev = sqrt($variance / count($scores));

        $nodeSummary = [];
        foreach ($nodeMetrics as $m) {
            $nodeSummary[] = [
                'node' => $m['node'],
                'cpu_pct' => round($m['cpu_pct'] * 100, 1),
                'ram_pct' => round($m['ram_pct'] * 100, 1),
                'score' => round($m['score'] * 100, 1),
                'guest_count' => count($m['guests']),
            ];
        }

        usort($nodeSummary, fn($a, $b) => strcmp($a['node'], $b['node']));

        return [
            'nodes' => $nodeSummary,
            'avg_score' => round($avg * 100, 1),
            'std_dev' => round($stdDev * 100, 1),
        ];
    }

    private static function generateRecommendations(array $nodeMetrics, float $avgScore, float $thresholdPct, array $settings, array &$skippedReasons = []): array
    {
        $cpuWeight = (int)($settings['cpu_weight'] ?? 50);
        $ramWeight = (int)($settings['ram_weight'] ?? 50);

        // Work with a mutable copy of metrics that gets updated after each recommendation
        $simMetrics = $nodeMetrics;
        $recommendations = [];
        $skippedGuests = [];

        // Iteratively find migrations that move scores closer to the cluster average.
        // We try each VM on the most-loaded node and pick the one that brings scores
        // closest together. Continue until no beneficial moves remain.
        $maxRounds = 10;

        for ($round = 0; $round < $maxRounds; $round++) {
            $scores = array_column($simMetrics, 'score');
            $currentAvg = array_sum($scores) / count($scores);
            $currentStdDev = self::stdDev($scores);

            // Stop if cluster is already near-perfectly balanced (std dev < 1%)
            if ($currentStdDev < 0.01) break;

            // Find the node with the highest score (most overloaded)
            $srcIdx = null;
            $maxScore = -1;
            foreach ($simMetrics as $idx => $m) {
                if ($m['score'] > $maxScore) {
                    $maxScore = $m['score'];
                    $srcIdx = $idx;
                }
            }

            // Source must exceed average by at least the configured threshold
            if ($maxScore <= $currentAvg + $thresholdPct) break;

            $srcNode = $simMetrics[$srcIdx];

            // No guests to migrate
            if (empty($srcNode['guests'])) break;

            // Find best migration: try each guest to each target node
            $bestPick = null;
            $bestNewStdDev = $currentStdDev;

            foreach ($srcNode['guests'] as $guest) {
                $guestMaxMem = (int)($guest['maxmem'] ?? 0);
                $guestKey = ($guest['vmid'] ?? 0);

                foreach ($simMetrics as $tgtIdx => $tgtNode) {
                    if ($tgtIdx === $srcIdx) continue;

                    // Target must be meaningfully below average (by at least half the threshold)
                    if ($tgtNode['score'] >= $currentAvg - ($thresholdPct / 2)) continue;

                    // Check RAM capacity on target
                    $tgtFreeMem = $tgtNode['maxmem'] - $tgtNode['mem'];
                    if ($guestMaxMem > $tgtFreeMem) {
                        if (!isset($skippedGuests[$guestKey])) {
                            $skippedGuests[$guestKey] = [
                                'vmid' => (int)($guest['vmid'] ?? 0),
                                'vm_name' => $guest['name'] ?? "VM " . ($guest['vmid'] ?? 0),
                                'vm_type' => $guest['type'] ?? 'qemu',
                                'source_node' => $srcNode['node'],
                                'reason' => 'ram',
                                'required_mem' => $guestMaxMem,
                                'best_target_free' => 0,
                            ];
                        }
                        // Track the best available free mem across targets
                        if ($tgtFreeMem > $skippedGuests[$guestKey]['best_target_free']) {
                            $skippedGuests[$guestKey]['best_target_free'] = $tgtFreeMem;
                        }
                        continue;
                    }

                    // Simulate new scores after migration
                    $newScores = $scores;
                    $guestCpuContrib = $srcNode['maxcpu'] > 0 ? (float)($guest['cpu'] ?? 0) / $srcNode['maxcpu'] : 0;
                    $guestRamContrib = $srcNode['maxmem'] > 0 ? (float)$guestMaxMem / $srcNode['maxmem'] : 0;
                    $tgtCpuContrib = $tgtNode['maxcpu'] > 0 ? (float)($guest['cpu'] ?? 0) / $tgtNode['maxcpu'] : 0;
                    $tgtRamContrib = $tgtNode['maxmem'] > 0 ? (float)$guestMaxMem / $tgtNode['maxmem'] : 0;

                    $newScores[$srcIdx] = self::calculateScore(
                        max(0, $srcNode['cpu_pct'] - $guestCpuContrib),
                        max(0, $srcNode['ram_pct'] - $guestRamContrib),
                        $cpuWeight, $ramWeight
                    );
                    $newScores[$tgtIdx] = self::calculateScore(
                        $tgtNode['cpu_pct'] + $tgtCpuContrib,
                        $tgtNode['ram_pct'] + $tgtRamContrib,
                        $cpuWeight, $ramWeight
                    );

                    $newStdDev = self::stdDev($newScores);

                    // Only consider if improvement is meaningful (≥ 1% std dev reduction)
                    if ($newStdDev < $bestNewStdDev && ($currentStdDev - $newStdDev) >= 0.01) {
                        $bestNewStdDev = $newStdDev;
                        $bestPick = [
                            'tgtIdx' => $tgtIdx,
                            'guest' => $guest,
                            'rec' => [
                                'vmid' => (int)($guest['vmid'] ?? 0),
                                'vm_name' => $guest['name'] ?? "VM " . ($guest['vmid'] ?? 0),
                                'vm_type' => $guest['type'] ?? 'qemu',
                                'source_node' => $srcNode['node'],
                                'target_node' => $tgtNode['node'],
                                'reason' => sprintf(
                                    '%s overloaded (%.0f%%), %s has capacity (%.0f%%)',
                                    $srcNode['node'],
                                    $srcNode['score'] * 100,
                                    $tgtNode['node'],
                                    $tgtNode['score'] * 100
                                ),
                                'impact_score' => round(($currentStdDev - $newStdDev) * 100, 2),
                            ],
                        ];
                    }
                }
            }

            if (!$bestPick) break;

            // Remove successfully picked guest from skipped list
            $pickedVmid = (int)($bestPick['guest']['vmid'] ?? 0);
            unset($skippedGuests[$pickedVmid]);

            $recommendations[] = $bestPick['rec'];

            $guest = $bestPick['guest'];
            $guestCpu = (float)($guest['cpu'] ?? 0);
            $guestMaxMem = (int)($guest['maxmem'] ?? 0);
            $tgtIdx = $bestPick['tgtIdx'];

            // Update source: remove guest load
            $src = &$simMetrics[$srcIdx];
            if ($src['maxcpu'] > 0) {
                $src['cpu_pct'] = max(0, $src['cpu_pct'] - $guestCpu / $src['maxcpu']);
            }
            if ($src['maxmem'] > 0) {
                $src['ram_pct'] = max(0, $src['ram_pct'] - (float)$guestMaxMem / $src['maxmem']);
                $src['mem'] = max(0, $src['mem'] - $guestMaxMem);
            }
            $src['score'] = self::calculateScore($src['cpu_pct'], $src['ram_pct'], $cpuWeight, $ramWeight);
            $src['guests'] = array_values(array_filter($src['guests'], fn($g) => ($g['vmid'] ?? 0) != ($guest['vmid'] ?? -1)));
            unset($src);

            // Update target: add guest load
            $tgt = &$simMetrics[$tgtIdx];
            if ($tgt['maxcpu'] > 0) {
                $tgt['cpu_pct'] += $guestCpu / $tgt['maxcpu'];
            }
            if ($tgt['maxmem'] > 0) {
                $tgt['ram_pct'] += (float)$guestMaxMem / $tgt['maxmem'];
                $tgt['mem'] += $guestMaxMem;
            }
            $tgt['score'] = self::calculateScore($tgt['cpu_pct'], $tgt['ram_pct'], $cpuWeight, $ramWeight);
            $tgt['guests'][] = $guest;
            unset($tgt);
        }

        // Build skip reasons from remaining skipped guests
        foreach ($skippedGuests as $skip) {
            $skippedReasons[] = $skip;
        }

        return $recommendations;
    }

    /**
     * Simulate the impact of migrating a guest from source to target node.
     * Returns improvement in cluster standard deviation (positive = better).
     */
    private static function simulateMigration(
        array $nodeMetrics,
        int $srcIdx,
        int $tgtIdx,
        array $guest,
        int $cpuWeight,
        int $ramWeight
    ): float {
        $src = $nodeMetrics[$srcIdx];
        $tgt = $nodeMetrics[$tgtIdx];

        $guestCpuContrib = $src['maxcpu'] > 0 ? (float)($guest['cpu'] ?? 0) / $src['maxcpu'] : 0;

        $newSrcCpu = $src['cpu_pct'] - $guestCpuContrib;
        $newSrcRam = $src['ram_pct'] - ($src['maxmem'] > 0 ? (float)($guest['maxmem'] ?? 0) / $src['maxmem'] : 0);

        $tgtCpuContrib = $tgt['maxcpu'] > 0 ? (float)($guest['cpu'] ?? 0) / $tgt['maxcpu'] : 0;
        $tgtRamContrib = $tgt['maxmem'] > 0 ? (float)($guest['maxmem'] ?? 0) / $tgt['maxmem'] : 0;

        $newTgtCpu = $tgt['cpu_pct'] + $tgtCpuContrib;
        $newTgtRam = $tgt['ram_pct'] + $tgtRamContrib;

        $oldScores = array_column($nodeMetrics, 'score');
        $newScores = $oldScores;
        $newScores[$srcIdx] = self::calculateScore(max(0, $newSrcCpu), max(0, $newSrcRam), $cpuWeight, $ramWeight);
        $newScores[$tgtIdx] = self::calculateScore($newTgtCpu, $newTgtRam, $cpuWeight, $ramWeight);

        return self::stdDev($oldScores) - self::stdDev($newScores);
    }

    private static function stdDev(array $values): float
    {
        if (count($values) < 2) return 0;
        $avg = array_sum($values) / count($values);
        $variance = 0;
        foreach ($values as $v) {
            $variance += ($v - $avg) ** 2;
        }
        return sqrt($variance / count($values));
    }

    private static function storeRun(string $triggeredBy, array $nodeMetrics, float $avgScore, array $recommendations, array $skippedReasons = []): int
    {
        $db = Database::connection();

        $stmt = $db->prepare('INSERT INTO drs_runs (triggered_by, node_count, cluster_avg_score, recommendations_count, skipped_reasons) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$triggeredBy, count($nodeMetrics), round($avgScore * 100, 2), count($recommendations), !empty($skippedReasons) ? json_encode($skippedReasons) : null]);
        $runId = (int)$db->lastInsertId();

        $stmt = $db->prepare('INSERT INTO drs_recommendations (run_id, vmid, vm_name, vm_type, source_node, target_node, reason, impact_score) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($recommendations as $rec) {
            $stmt->execute([
                $runId,
                $rec['vmid'],
                $rec['vm_name'],
                $rec['vm_type'],
                $rec['source_node'],
                $rec['target_node'],
                $rec['reason'],
                $rec['impact_score'],
            ]);
        }

        return $runId;
    }

    public static function applyRecommendation(ProxmoxAPI $api, int $recommendationId): array
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT * FROM drs_recommendations WHERE id = ?');
        $stmt->execute([$recommendationId]);
        $rec = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$rec) {
            throw new \RuntimeException('Recommendation not found');
        }
        if ($rec['status'] !== 'pending') {
            throw new \RuntimeException('Recommendation has already been processed');
        }

        try {
            $result = $api->migrateGuest(
                $rec['source_node'],
                $rec['vm_type'],
                (int)$rec['vmid'],
                $rec['target_node'],
                true
            );

            $upid = $result['data'] ?? '';
            $stmt = $db->prepare('UPDATE drs_recommendations SET status = ?, upid = ?, applied_at = CURRENT_TIMESTAMP WHERE id = ?');
            $stmt->execute(['applied', $upid, $recommendationId]);

            $stmt = $db->prepare('UPDATE drs_runs SET executed_count = executed_count + 1 WHERE id = ?');
            $stmt->execute([$rec['run_id']]);

            $rec['status'] = 'applied';
            $rec['upid'] = $upid;
        } catch (\Exception $e) {
            $stmt = $db->prepare('UPDATE drs_recommendations SET status = ?, error_message = ? WHERE id = ?');
            $stmt->execute(['error', $e->getMessage(), $recommendationId]);

            $rec['status'] = 'error';
            $rec['error_message'] = $e->getMessage();
        }

        return $rec;
    }

    public static function applyAllRecommendations(ProxmoxAPI $api, int $runId, ?int $limit = null): array
    {
        if ($limit === null) {
            $settings = self::getSettings();
            $limit = (int)($settings['max_concurrent'] ?? 3);
        }
        $db = Database::connection();
        $stmt = $db->prepare('SELECT id FROM drs_recommendations WHERE run_id = ? AND status = ? ORDER BY id ASC LIMIT ?');
        $stmt->execute([$runId, 'pending', $limit]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $results = [];
        foreach ($ids as $id) {
            $results[] = self::applyRecommendation($api, (int)$id);
        }
        return $results;
    }

    public static function getLatestRun(): ?array
    {
        $db = Database::connection();
        $run = $db->query('SELECT * FROM drs_runs ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        if (!$run) return null;

        $run['recommendations'] = self::getRunRecommendations((int)$run['id']);
        $run['skipped'] = !empty($run['skipped_reasons']) ? json_decode($run['skipped_reasons'], true) : [];
        unset($run['skipped_reasons']);
        return $run;
    }

    public static function getRunHistory(int $limit = 20, int $offset = 0): array
    {
        $db = Database::connection();

        $countStmt = $db->query('SELECT COUNT(*) FROM drs_runs');
        $total = (int)$countStmt->fetchColumn();

        $stmt = $db->prepare('SELECT * FROM drs_runs ORDER BY id DESC LIMIT ? OFFSET ?');
        $stmt->execute([$limit, $offset]);
        $runs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return ['runs' => $runs, 'total' => $total];
    }

    public static function getRunRecommendations(int $runId): array
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT * FROM drs_recommendations WHERE run_id = ? ORDER BY impact_score DESC');
        $stmt->execute([$runId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function cleanupOldRuns(int $days = 30): int
    {
        $db = Database::connection();
        $stmt = $db->prepare("DELETE FROM drs_runs WHERE created_at < datetime('now', ?)");
        $stmt->execute(["-$days days"]);
        return $stmt->rowCount();
    }
}
