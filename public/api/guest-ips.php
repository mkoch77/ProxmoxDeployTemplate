<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\Auth;
use App\Request;
use App\Response;
use App\Helpers;
use App\Config;
use App\SSH;
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
    $agentRunning = false;
    $ipSource = 'none';
    $quickOpts = ['connect_timeout' => 2, 'timeout' => 4];

    if ($type === 'lxc') {
        $result = $api->getLxcInterfaces($node, $vmid);
        $ifaces = $result['data'] ?? $result;
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
        $agentRunning = true;
        $ipSource = 'lxc';
    } else {
        // Try QEMU guest agent first
        $cfg = null;
        try {
            $result = $api->get("/nodes/{$node}/qemu/{$vmid}/agent/network-get-interfaces", [], $quickOpts);
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
            if (!empty($ips)) {
                $agentRunning = true;
                $ipSource = 'agent';
            }
        } catch (\Exception $e) {
            // Agent not running — fall through to fallbacks
        }

        // Fallback 1: static IP from cloud-init config
        if (empty($ips)) {
            try {
                $config = $api->getGuestConfig($node, 'qemu', $vmid, $quickOpts);
                $cfg = $config['data'] ?? $config;
                foreach ($cfg as $key => $value) {
                    if (!preg_match('/^ipconfig\d+$/', $key) || !$value) continue;
                    if (preg_match('/(?:^|,)ip=([^\/,]+)/', (string)$value, $m)) {
                        $ip = $m[1];
                        if ($ip && $ip !== '127.0.0.1' && strtolower($ip) !== 'dhcp') {
                            $ips[] = $ip;
                            $ipSource = 'cloudinit';
                        }
                    }
                }
            } catch (\Exception $e) { /* config not available */ }
        }

        // Fallback 2: ARP lookup on Proxmox node
        if (empty($ips) && Config::get('SSH_ENABLED', 'true') !== 'false') {
            try {
                if (!$cfg) {
                    $config = $api->getGuestConfig($node, 'qemu', $vmid, $quickOpts);
                    $cfg = $config['data'] ?? $config;
                }
                $mac = null;
                foreach ($cfg as $key => $value) {
                    if (preg_match('/^net\d+$/', $key) && $value) {
                        if (preg_match('/([0-9A-Fa-f]{2}(?::[0-9A-Fa-f]{2}){5})/', $value, $m)) {
                            $mac = strtolower($m[1]);
                            break;
                        }
                    }
                }

                if ($mac) {
                    // Resolve node SSH host
                    $envKey = 'SSH_HOST_' . strtoupper(str_replace('-', '_', $node));
                    $sshHost = Config::get($envKey, '');
                    if (!$sshHost) {
                        $sshHost = $node;
                        try {
                            $status = $api->getClusterStatus();
                            foreach ($status['data'] ?? [] as $entry) {
                                if (($entry['type'] ?? '') === 'node' &&
                                    strtolower($entry['name'] ?? '') === strtolower($node) &&
                                    !empty($entry['ip'])) {
                                    $sshHost = $entry['ip'];
                                    break;
                                }
                            }
                        } catch (\Exception $e) { /* use node name */ }
                    }

                    $arpOutput = SSH::exec($sshHost,
                        'cat /proc/net/arp 2>/dev/null; echo "---"; '
                        . 'ip neigh show 2>/dev/null; echo "---"; '
                        . 'arp-scan --localnet --interface=vmbr0 -q 2>/dev/null || true',
                        8  // Short timeout for ARP fallback
                    );
                    foreach (explode("\n", $arpOutput) as $line) {
                        if (stripos($line, $mac) !== false) {
                            if (preg_match('/^(\d+\.\d+\.\d+\.\d+)\s/', trim($line), $m)) {
                                $ips[] = $m[1];
                                $ipSource = 'arp';
                            } elseif (preg_match('/\((\d+\.\d+\.\d+\.\d+)\)/', $line, $m)) {
                                $ips[] = $m[1];
                                $ipSource = 'arp';
                            }
                        }
                    }
                }
            } catch (\Exception $e) { /* ARP fallback failed */ }
        }
    }

    Response::success([
        'ips' => array_values(array_unique($ips)),
        'agent' => $agentRunning,
        'source' => $ipSource,
    ]);
} catch (\Exception $e) {
    Response::success(['ips' => [], 'agent' => false, 'source' => 'none']);
}
