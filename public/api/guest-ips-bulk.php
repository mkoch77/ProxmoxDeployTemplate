<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\Auth;
use App\Request;
use App\Response;
use App\Helpers;
use App\AppLogger;
use App\Config;
use App\SSH;

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
    $quickOpts = ['connect_timeout' => 2, 'timeout' => 3];

    // Build set of online nodes to skip API calls to unreachable nodes
    $onlineNodes = [];
    try {
        $nodesResult = $api->getNodes();
        foreach ($nodesResult['data'] ?? [] as $n) {
            if (($n['status'] ?? '') === 'online') {
                $onlineNodes[$n['node']] = true;
            }
        }
    } catch (\Exception $e) { /* assume all online if check fails */ }

    // Collect node SSH hosts for ARP fallback (resolved once per node)
    $nodeHosts = [];
    $arpCaches = []; // ARP table cache per node

    foreach ($guests as $g) {
        $node = $g['node'] ?? '';
        $type = $g['type'] ?? '';
        $vmid = (int)($g['vmid'] ?? 0);
        if (!$node || !$type || !$vmid) continue;

        $ips = [];
        $agentRunning = false;
        $ipSource = 'none';

        // Skip API calls for guests on offline nodes — use cached IPs from DB
        if (!empty($onlineNodes) && !isset($onlineNodes[$node])) {
            $result["{$vmid}-{$node}"] = ['ips' => [], 'agent' => false, 'source' => 'none'];
            continue;
        }

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
                $agentRunning = true; // LXC always has direct interface access
                $ipSource = 'lxc';
            } else {
                // Try QEMU guest agent first
                try {
                    $res = $api->get("/nodes/{$node}/qemu/{$vmid}/agent/network-get-interfaces", [], $quickOpts);
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
                    if (!empty($ips)) {
                        $agentRunning = true;
                        $ipSource = 'agent';
                    }
                } catch (\Exception $e) { /* agent not running */ }

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

                // Fallback 2: ARP lookup on Proxmox node (matches VM MAC address)
                if (empty($ips) && Config::get('SSH_ENABLED', 'true') !== 'false') {
                    try {
                        // Get MAC from VM config
                        if (!isset($cfg)) {
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
                            // Resolve node SSH host (once per node)
                            if (!isset($nodeHosts[$node])) {
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
                                $nodeHosts[$node] = $sshHost;
                            }

                            // Get ARP + bridge neighbour table (cached per node)
                            if (!isset($arpCaches[$node])) {
                                try {
                                    // Combine: /proc/net/arp + bridge FDB + ip neigh for comprehensive lookup
                                    $arpCaches[$node] = SSH::exec($nodeHosts[$node],
                                        'cat /proc/net/arp 2>/dev/null; echo "---"; '
                                        . 'ip neigh show 2>/dev/null; echo "---"; '
                                        . 'arp-scan --localnet --interface=vmbr0 -q 2>/dev/null || true',
                                        8  // Short timeout for ARP fallback
                                    );
                                } catch (\Exception $e) {
                                    $arpCaches[$node] = '';
                                }
                            }

                            // Search for MAC in ARP table
                            if ($arpCaches[$node]) {
                                foreach (explode("\n", $arpCaches[$node]) as $line) {
                                    if (stripos($line, $mac) !== false) {
                                        // /proc/net/arp format: "IP HW_type Flags MAC Mask Device"
                                        if (preg_match('/^(\d+\.\d+\.\d+\.\d+)\s/', trim($line), $m)) {
                                            $ips[] = $m[1];
                                            $ipSource = 'arp';
                                        }
                                        // arp -an format: "? (IP) at MAC [ether] on device"
                                        elseif (preg_match('/\((\d+\.\d+\.\d+\.\d+)\)/', $line, $m)) {
                                            $ips[] = $m[1];
                                            $ipSource = 'arp';
                                        }
                                    }
                                }
                            }
                        }
                    } catch (\Exception $e) { /* ARP fallback failed */ }
                }
            }
        } catch (\Exception $e) { /* skip this guest */ }

        $uniqueIps = array_values(array_unique($ips));
        $result["{$vmid}-{$node}"] = [
            'ips' => $uniqueIps,
            'agent' => $agentRunning,
            'source' => $ipSource,
        ];

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
