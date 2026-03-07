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
Auth::requireAuth();

AppLogger::debug('api', 'Fetching guest IPs');

$node  = Request::get('node');
$type  = Request::get('type');
$vmid  = (int) Request::get('vmid');

if (!$node || !$type || !$vmid) {
    Response::error('node, type and vmid required', 400);
}

if (!in_array($type, ['qemu', 'lxc'], true)) {
    Response::error('type must be qemu or lxc', 400);
}

try {
    $api = Helpers::createAPI();
    $ips = [];

    if ($type === 'lxc') {
        $result = $api->getLxcInterfaces($node, $vmid);
        $ifaces = $result['data'] ?? $result;
        foreach ($ifaces as $iface) {
            // Proxmox returns inet/inet6 as a plain string "a.b.c.d/prefix", not an array
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
        // Try QEMU guest agent first
        try {
            $result = $api->getQemuAgentNetworks($node, $vmid);
            $ifaces = $result['data']['result'] ?? $result['result'] ?? [];
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
        } catch (\Exception $e) {
            // Agent not running — fall through to cloud-init fallback
        }

        // Fallback: read static IP from cloud-init config (ipconfig0=ip=x.x.x.x/24,gw=...)
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
            } catch (\Exception $e) {
                // Config not available
            }
        }
    }

    Response::success(['ips' => array_values(array_unique($ips))]);
} catch (\Exception $e) {
    Response::success(['ips' => []]);
}
