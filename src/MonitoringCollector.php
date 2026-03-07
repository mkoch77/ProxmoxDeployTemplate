<?php

namespace App;

use PDO;

class MonitoringCollector
{
    public static function collect(ProxmoxAPI $api): array
    {
        AppLogger::debug('monitoring', 'Starting metrics collection');

        $db = Database::connection();
        $now = date('Y-m-d H:i:s');
        $stats = ['nodes' => 0, 'vms' => 0];

        // Collect node metrics
        $nodesResult = $api->getNodes();
        $nodes = $nodesResult['data'] ?? [];

        $nodeStmt = $db->prepare('INSERT INTO node_metrics
            (node, ts, cpu_pct, mem_used, mem_total, disk_read_bytes, disk_write_bytes, disk_read_iops, disk_write_iops, net_in_bytes, net_out_bytes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

        foreach ($nodes as $node) {
            if (($node['status'] ?? '') !== 'online') continue;
            $name = $node['node'];

            // Get detailed node status for disk/net I/O
            $diskRead = 0; $diskWrite = 0;
            $netIn = 0; $netOut = 0;
            $diskReadIops = 0; $diskWriteIops = 0;

            try {
                $rrd = $api->getNodeRRDData($name, 'hour');
                $data = $rrd['data'] ?? [];
                if (!empty($data)) {
                    $last = end($data);
                    $diskRead = (int)($last['diskread'] ?? 0);
                    $diskWrite = (int)($last['diskwrite'] ?? 0);
                    $netIn = (int)($last['netin'] ?? 0);
                    $netOut = (int)($last['netout'] ?? 0);
                    $diskReadIops = (float)($last['iowait'] ?? 0);
                }
            } catch (\Exception $e) {}

            $nodeStmt->execute([
                $name, $now,
                (float)($node['cpu'] ?? 0),
                (int)($node['mem'] ?? 0),
                (int)($node['maxmem'] ?? 0),
                $diskRead, $diskWrite,
                $diskReadIops, $diskWriteIops,
                $netIn, $netOut,
            ]);
            $stats['nodes']++;
        }

        AppLogger::debug('monitoring', 'Node metrics collected', ['count' => $stats['nodes']]);

        // Collect VM/CT metrics
        $resources = $api->getClusterResources('vm');
        $guests = $resources['data'] ?? [];

        $vmStmt = $db->prepare('INSERT INTO vm_metrics
            (vmid, node, name, vm_type, ts, status, cpu_pct, cpu_count, mem_used, mem_total, disk_read_bytes, disk_write_bytes, disk_read_iops, disk_write_iops, net_in_bytes, net_out_bytes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

        foreach ($guests as $guest) {
            if (!empty($guest['template'])) continue;
            $vmid = (int)($guest['vmid'] ?? 0);
            if (!$vmid) continue;

            $type = ($guest['type'] ?? 'qemu') === 'lxc' ? 'lxc' : 'qemu';
            $node = $guest['node'] ?? '';
            $status = $guest['status'] ?? 'unknown';

            $diskRead = (int)($guest['diskread'] ?? 0);
            $diskWrite = (int)($guest['diskwrite'] ?? 0);
            $netIn = (int)($guest['netin'] ?? 0);
            $netOut = (int)($guest['netout'] ?? 0);

            $vmStmt->execute([
                $vmid, $node,
                $guest['name'] ?? "VM $vmid",
                $type, $now, $status,
                (float)($guest['cpu'] ?? 0),
                (int)($guest['maxcpu'] ?? 0),
                (int)($guest['mem'] ?? 0),
                (int)($guest['maxmem'] ?? 0),
                $diskRead, $diskWrite,
                0, 0, // iops not available from cluster resources
                $netIn, $netOut,
            ]);
            $stats['vms']++;
        }

        AppLogger::debug('monitoring', 'VM metrics collected', ['count' => $stats['vms']]);

        return $stats;
    }

    public static function cleanup(int $days = 30): array
    {
        $db = Database::connection();
        $stmt = $db->prepare("DELETE FROM node_metrics WHERE ts < NOW() - make_interval(days := ?)");
        $stmt->execute([$days]);
        $nodeRows = $stmt->rowCount();

        $stmt = $db->prepare("DELETE FROM vm_metrics WHERE ts < NOW() - make_interval(days := ?)");
        $stmt->execute([$days]);
        $vmRows = $stmt->rowCount();

        return ['node_rows' => $nodeRows, 'vm_rows' => $vmRows];
    }

    public static function getNodeMetrics(string $node, string $timerange = '1h', int $smoothing = 0): array
    {
        $db = Database::connection();
        $interval = self::parseTimerange($timerange);

        $stmt = $db->prepare("SELECT ts, cpu_pct, mem_used, mem_total, disk_read_bytes, disk_write_bytes, disk_read_iops, disk_write_iops, net_in_bytes, net_out_bytes
            FROM node_metrics WHERE node = ? AND ts >= NOW() - ?::interval ORDER BY ts");
        $stmt->execute([$node, $interval]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $rows = self::computeRates($rows);

        if ($smoothing > 1) {
            $rows = self::applyMovingAverage($rows, $smoothing);
        }

        return $rows;
    }

    public static function getVmMetrics(int $vmid, string $timerange = '1h', int $smoothing = 0): array
    {
        $db = Database::connection();
        $interval = self::parseTimerange($timerange);

        $stmt = $db->prepare("SELECT ts, status, cpu_pct, cpu_count, mem_used, mem_total, disk_read_bytes, disk_write_bytes, disk_read_iops, disk_write_iops, net_in_bytes, net_out_bytes
            FROM vm_metrics WHERE vmid = ? AND ts >= NOW() - ?::interval ORDER BY ts");
        $stmt->execute([$vmid, $interval]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $rows = self::computeRates($rows);

        if ($smoothing > 1) {
            $rows = self::applyMovingAverage($rows, $smoothing);
        }

        return $rows;
    }

    public static function getVmSummary(int $vmid, string $timerange = '24h'): array
    {
        $db = Database::connection();
        $interval = self::parseTimerange($timerange);

        $stmt = $db->prepare("SELECT
            COUNT(*) as samples,
            AVG(cpu_pct) as avg_cpu,
            MAX(cpu_pct) as max_cpu,
            PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY cpu_pct) as p95_cpu,
            AVG(mem_used) as avg_mem,
            MAX(mem_used) as max_mem,
            PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY mem_used) as p95_mem,
            MAX(mem_total) as mem_total,
            MAX(cpu_count) as cpu_count,
            AVG(net_in_bytes) as avg_net_in,
            MAX(net_in_bytes) as max_net_in,
            AVG(net_out_bytes) as avg_net_out,
            MAX(net_out_bytes) as max_net_out,
            AVG(disk_read_bytes) as avg_disk_read,
            MAX(disk_read_bytes) as max_disk_read,
            AVG(disk_write_bytes) as avg_disk_write,
            MAX(disk_write_bytes) as max_disk_write,
            MIN(ts) as first_sample,
            MAX(ts) as last_sample
        FROM vm_metrics WHERE vmid = ? AND ts >= NOW() - ?::interval AND status = 'running'");
        $stmt->execute([$vmid, $interval]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    public static function getAllVmSummaries(string $timerange = '24h'): array
    {
        $db = Database::connection();
        $interval = self::parseTimerange($timerange);

        $stmt = $db->prepare("SELECT
            vmid,
            MAX(name) as name,
            MAX(node) as node,
            MAX(vm_type) as vm_type,
            COUNT(*) as samples,
            AVG(cpu_pct) as avg_cpu,
            MAX(cpu_pct) as max_cpu,
            PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY cpu_pct) as p95_cpu,
            AVG(mem_used) as avg_mem,
            MAX(mem_used) as max_mem,
            PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY mem_used) as p95_mem,
            MAX(mem_total) as mem_total,
            MAX(cpu_count) as cpu_count
        FROM vm_metrics WHERE ts >= NOW() - ?::interval AND status = 'running'
        GROUP BY vmid
        HAVING COUNT(*) >= 10
        ORDER BY vmid");
        $stmt->execute([$interval]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Convert cumulative byte counters to per-second rates.
     * Proxmox returns total bytes since start; we need bytes/s for charts.
     */
    private static function computeRates(array $rows): array
    {
        $fields = ['disk_read_bytes', 'disk_write_bytes', 'net_in_bytes', 'net_out_bytes'];
        $result = [];

        for ($i = 0; $i < count($rows); $i++) {
            $row = $rows[$i];
            if ($i === 0) {
                // No previous row to diff against — set rates to 0
                foreach ($fields as $f) {
                    $row[$f] = 0;
                }
            } else {
                $prev = $rows[$i - 1];
                $dt = max(1, strtotime($row['ts']) - strtotime($prev['ts']));
                foreach ($fields as $f) {
                    $cur = (float)($row[$f] ?? 0);
                    $prv = (float)($prev[$f] ?? 0);
                    $delta = $cur - $prv;
                    // Counter reset (VM restarted) → clamp to 0
                    $row[$f] = $delta >= 0 ? $delta / $dt : 0;
                }
            }
            $result[] = $row;
        }

        return $result;
    }

    private static function parseTimerange(string $range): string
    {
        $map = [
            '1h' => '1 hour', '3h' => '3 hours', '6h' => '6 hours',
            '12h' => '12 hours', '24h' => '24 hours', '48h' => '48 hours',
            '7d' => '7 days', '30d' => '30 days',
        ];
        return $map[$range] ?? '1 hour';
    }

    private static function applyMovingAverage(array $rows, int $window): array
    {
        if (count($rows) <= $window) return $rows;

        $numericFields = ['cpu_pct', 'mem_used', 'mem_total', 'disk_read_bytes', 'disk_write_bytes',
            'disk_read_iops', 'disk_write_iops', 'net_in_bytes', 'net_out_bytes'];

        $result = [];
        for ($i = 0; $i < count($rows); $i++) {
            $start = max(0, $i - intdiv($window, 2));
            $end = min(count($rows), $start + $window);
            $start = max(0, $end - $window);

            $averaged = $rows[$i];
            foreach ($numericFields as $field) {
                if (!isset($averaged[$field])) continue;
                $sum = 0;
                $count = 0;
                for ($j = $start; $j < $end; $j++) {
                    if (isset($rows[$j][$field])) {
                        $sum += (float)$rows[$j][$field];
                        $count++;
                    }
                }
                $averaged[$field] = $count > 0 ? $sum / $count : 0;
            }
            $result[] = $averaged;
        }
        return $result;
    }
}
