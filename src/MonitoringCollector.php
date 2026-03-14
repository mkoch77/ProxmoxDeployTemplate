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
            (node, ts, cpu_pct, mem_used, mem_total, disk_read_bytes, disk_write_bytes, disk_read_iops, disk_write_iops, net_in_bytes, net_out_bytes, load_avg, swap_used, swap_total, iowait)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

        foreach ($nodes as $node) {
            if (($node['status'] ?? '') !== 'online') continue;
            $name = $node['node'];

            // Get detailed node status for disk/net I/O + load + swap + iowait
            $diskRead = 0; $diskWrite = 0;
            $netIn = 0; $netOut = 0;
            $loadAvg = 0.0;
            $swapUsed = 0; $swapTotal = 0;
            $iowait = 0.0;

            try {
                $rrd = $api->getNodeRRDData($name, 'hour');
                $data = $rrd['data'] ?? [];
                if (!empty($data)) {
                    $last = end($data);
                    $diskRead = (int)($last['diskread'] ?? 0);
                    $diskWrite = (int)($last['diskwrite'] ?? 0);
                    $netIn = (int)($last['netin'] ?? 0);
                    $netOut = (int)($last['netout'] ?? 0);
                    $iowait = (float)($last['iowait'] ?? 0);
                    $loadAvg = (float)($last['loadavg'] ?? 0);
                    $swapUsed = (int)($last['swapused'] ?? 0);
                    $swapTotal = (int)($last['swaptotal'] ?? 0);
                }
            } catch (\Exception $e) {}

            $nodeStmt->execute([
                $name, $now,
                (float)($node['cpu'] ?? 0),
                (int)($node['mem'] ?? 0),
                (int)($node['maxmem'] ?? 0),
                $diskRead, $diskWrite,
                0, 0, // IOPS not available from node RRD
                $netIn, $netOut,
                $loadAvg, $swapUsed, $swapTotal,
                $iowait,
            ]);
            $stats['nodes']++;
        }

        AppLogger::debug('monitoring', 'Node metrics collected', ['count' => $stats['nodes']]);

        // Collect VM/CT metrics
        $resources = $api->getClusterResources('vm');
        $guests = $resources['data'] ?? [];

        $vmStmt = $db->prepare('INSERT INTO vm_metrics
            (vmid, node, name, vm_type, ts, status, cpu_pct, cpu_count, mem_used, mem_total, disk_read_bytes, disk_write_bytes, disk_read_iops, disk_write_iops, net_in_bytes, net_out_bytes, uptime, disk_used, disk_total, iowait)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

        // Build a set of online node names for RRD lookups
        $onlineNodes = [];
        foreach ($nodes as $n) {
            if (($n['status'] ?? '') === 'online') $onlineNodes[$n['node']] = true;
        }

        foreach ($guests as $guest) {
            if (!empty($guest['template'])) continue;
            $vmid = (int)($guest['vmid'] ?? 0);
            if (!$vmid) continue;

            $type = ($guest['type'] ?? 'qemu') === 'lxc' ? 'lxc' : 'qemu';
            $guestNode = $guest['node'] ?? '';
            $status = $guest['status'] ?? 'unknown';

            $diskRead = (int)($guest['diskread'] ?? 0);
            $diskWrite = (int)($guest['diskwrite'] ?? 0);
            $netIn = (int)($guest['netin'] ?? 0);
            $netOut = (int)($guest['netout'] ?? 0);
            $diskReadIops = 0.0;
            $diskWriteIops = 0.0;
            $vmIowait = 0.0;

            // Fetch per-VM RRD for iowait (running guests on online nodes)
            // VM RRD provides: diskread/diskwrite (bytes/s), netin/netout (bytes/s), iowait (LXC only)
            if ($status === 'running' && $guestNode && isset($onlineNodes[$guestNode])) {
                try {
                    $rrd = $api->getGuestRRDData($guestNode, $type, $vmid, 'hour', ['connect_timeout' => 2, 'timeout' => 3]);
                    $rrdData = $rrd['data'] ?? [];
                    if (!empty($rrdData)) {
                        $last = end($rrdData);
                        $vmIowait = (float)($last['iowait'] ?? 0);
                    }
                } catch (\Exception $e) {
                    // RRD not available — iowait stays 0
                }
            }

            $vmStmt->execute([
                $vmid, $guestNode,
                $guest['name'] ?? "VM $vmid",
                $type, $now, $status,
                (float)($guest['cpu'] ?? 0),
                (int)($guest['maxcpu'] ?? 0),
                (int)($guest['mem'] ?? 0),
                (int)($guest['maxmem'] ?? 0),
                $diskRead, $diskWrite,
                $diskReadIops, $diskWriteIops,
                $netIn, $netOut,
                (int)($guest['uptime'] ?? 0),
                (int)($guest['disk'] ?? 0),
                (int)($guest['maxdisk'] ?? 0),
                $vmIowait,
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

        $stmt = $db->prepare("SELECT ts, cpu_pct, mem_used, mem_total, disk_read_bytes, disk_write_bytes, disk_read_iops, disk_write_iops, net_in_bytes, net_out_bytes, load_avg, swap_used, swap_total, iowait
            FROM node_metrics WHERE node = ? AND ts >= NOW() - ?::interval ORDER BY ts");
        $stmt->execute([$node, $interval]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Node RRD data from Proxmox is already per-second rates (bytes/s),
        // NOT cumulative counters — do NOT apply computeRates().

        if ($smoothing > 1) {
            $rows = self::applyMovingAverage($rows, $smoothing);
        }

        return $rows;
    }

    public static function getVmMetrics(int $vmid, string $timerange = '1h', int $smoothing = 0): array
    {
        $db = Database::connection();
        $interval = self::parseTimerange($timerange);

        $stmt = $db->prepare("SELECT ts, status, cpu_pct, cpu_count, mem_used, mem_total, disk_read_bytes, disk_write_bytes, disk_read_iops, disk_write_iops, net_in_bytes, net_out_bytes, uptime, disk_used, disk_total, iowait
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
            MAX(uptime) as uptime,
            MAX(disk_used) as disk_used,
            MAX(disk_total) as disk_total,
            AVG(disk_used) as avg_disk_used,
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

    /**
     * Find VMs with sustained high CPU or RAM over the last N minutes.
     * Returns only VMs where ALL samples in the window exceed the threshold.
     */
    public static function getVmAlerts(int $minutes = 5): array
    {
        $db = Database::connection();
        $interval = "{$minutes} minutes";

        // Minimum samples: at least half the expected count (collection every 10s)
        $minSamples = max(3, intdiv($minutes * 60, 10) / 2);

        $stmt = $db->prepare("
            SELECT
                vmid,
                MAX(name) as name,
                MAX(node) as node,
                MAX(vm_type) as vm_type,
                COUNT(*) as samples,
                ROUND((AVG(cpu_pct) * 100)::numeric, 1) as avg_cpu_pct,
                ROUND((MIN(cpu_pct) * 100)::numeric, 1) as min_cpu_pct,
                MAX(mem_total) as mem_total,
                ROUND((AVG(mem_used)::float / NULLIF(MAX(mem_total), 0) * 100)::numeric, 1) as avg_mem_pct,
                ROUND((MIN(mem_used)::float / NULLIF(MAX(mem_total), 0) * 100)::numeric, 1) as min_mem_pct
            FROM vm_metrics
            WHERE ts >= NOW() - ?::interval
              AND status = 'running'
            GROUP BY vmid
            HAVING COUNT(*) >= ?
        ");
        $stmt->execute([$interval, $minSamples]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $alerts = [];
        foreach ($rows as $r) {
            $cpuAlerts = [];
            $ramAlerts = [];

            // CPU: min >= threshold means ALL samples were above it (values are now 0-100%)
            if ((float)$r['min_cpu_pct'] >= 95) {
                $cpuAlerts[] = ['level' => 'danger', 'pct' => (float)$r['avg_cpu_pct']];
            } elseif ((float)$r['min_cpu_pct'] >= 85) {
                $cpuAlerts[] = ['level' => 'warning', 'pct' => (float)$r['avg_cpu_pct']];
            }

            // RAM: min >= threshold (already 0-100%)
            if ((float)$r['min_mem_pct'] >= 95) {
                $ramAlerts[] = ['level' => 'danger', 'pct' => (float)$r['avg_mem_pct']];
            } elseif ((float)$r['min_mem_pct'] >= 85) {
                $ramAlerts[] = ['level' => 'warning', 'pct' => (float)$r['avg_mem_pct']];
            }

            if ($cpuAlerts || $ramAlerts) {
                $alerts[] = [
                    'vmid' => (int)$r['vmid'],
                    'name' => $r['name'],
                    'node' => $r['node'],
                    'vm_type' => $r['vm_type'],
                    'cpu' => $cpuAlerts[0] ?? null,
                    'ram' => $ramAlerts[0] ?? null,
                ];
            }
        }

        return $alerts;
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
            'disk_read_iops', 'disk_write_iops', 'net_in_bytes', 'net_out_bytes',
            'load_avg', 'swap_used', 'swap_total', 'disk_used', 'disk_total', 'iowait'];

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
