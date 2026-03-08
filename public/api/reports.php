<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\Auth;
use App\Request;
use App\Response;
use App\Helpers;
use App\AppLogger;
use PDO;

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

Response::error('Unknown report type', 400);
