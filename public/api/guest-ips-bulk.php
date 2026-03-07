<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\Auth;
use App\Request;
use App\Response;
use App\Helpers;
use App\AppLogger;

Bootstrap::init();
Request::requireMethod('POST');
Auth::requireAuth();

AppLogger::debug('api', 'Fetching guest IPs (bulk)');

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$guests = $body['guests'] ?? [];

if (!is_array($guests) || empty($guests)) {
    Response::error('guests array required', 400);
}

try {
    $api = Helpers::createAPI();
    $result = [];

    foreach ($guests as $g) {
        $node = $g['node'] ?? '';
        $type = $g['type'] ?? '';
        $vmid = (int)($g['vmid'] ?? 0);
        if (!$node || !$type || !$vmid) continue;

        $ips = [];
        try {
            if ($type === 'lxc') {
                $res = $api->getLxcInterfaces($node, $vmid);
                $ifaces = $res['data'] ?? $res;
                foreach ($ifaces as $iface) {
                    foreach (['inet', 'inet6'] as $family) {
                        $raw = $iface[$family] ?? null;
                        if (!$raw) continue;
                        $addr = is_array($raw) ? $raw[0] : $raw;
                        $ip = explode('/', $addr)[0];
                        if ($ip === '127.0.0.1' || $ip === '::1' || str_starts_with($ip, 'fe80')) continue;
                        $ips[] = $ip;
                    }
                }
            } else {
                try {
                    $res = $api->getQemuAgentNetworks($node, $vmid);
                    $ifaces = $res['data']['result'] ?? $res['result'] ?? [];
                    foreach ($ifaces as $iface) {
                        foreach ($iface['ip-addresses'] ?? [] as $addr) {
                            $ip = $addr['ip-address'] ?? '';
                            $addrType = $addr['ip-address-type'] ?? '';
                            if (!$ip) continue;
                            if ($addrType === 'ipv4' && $ip === '127.0.0.1') continue;
                            if ($addrType === 'ipv6' && ($ip === '::1' || str_starts_with($ip, 'fe80'))) continue;
                            $ips[] = $ip;
                        }
                    }
                } catch (\Exception $e) { /* agent not running */ }

                if (empty($ips)) {
                    try {
                        $config = $api->getGuestConfig($node, 'qemu', $vmid);
                        $cfg = $config['data'] ?? $config;
                        foreach ($cfg as $key => $value) {
                            if (!preg_match('/^ipconfig\d+$/', $key) || !$value) continue;
                            if (preg_match('/(?:^|,)ip=([^\/,]+)/', (string)$value, $m)) {
                                $ip = $m[1];
                                if ($ip && $ip !== '127.0.0.1' && strtolower($ip) !== 'dhcp') {
                                    $ips[] = $ip;
                                }
                            }
                        }
                    } catch (\Exception $e) { /* config not available */ }
                }
            }
        } catch (\Exception $e) { /* skip this guest */ }

        $uniqueIps = array_values(array_unique($ips));
        $result["{$vmid}-{$node}"] = $uniqueIps;

        // Persist to DB
        $db = \App\Database::connection();
        $stmt = $db->prepare('INSERT INTO guest_ips (vmid, node, ips, updated_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)
            ON CONFLICT (vmid, node) DO UPDATE SET ips = EXCLUDED.ips, updated_at = CURRENT_TIMESTAMP');
        $stmt->execute([$vmid, $node, json_encode($uniqueIps)]);
    }

    Response::success($result);
} catch (\Exception $e) {
    Response::error($e->getMessage(), 500);
}
