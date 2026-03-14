<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\Auth;
use App\Request;
use App\Response;
use App\Helpers;

Bootstrap::init();
Request::requireMethod('GET');
Auth::requirePermission('cluster.health.view');

$nodeName = Request::get('node');
if (!$nodeName || !Helpers::validateNodeName($nodeName)) {
    Response::error('Node parameter required', 400);
}

try {
    $api = Helpers::createAPI();
    $quickOpts = ['connect_timeout' => 2, 'timeout' => 4];
    $status = $api->getNodeStatus($nodeName, $quickOpts);
    $data = $status['data'] ?? $status;

    // Extract hardware and version info
    $cpuInfo = $data['cpuinfo'] ?? [];
    $memInfo = $data['memory'] ?? [];
    $rootFs  = $data['rootfs'] ?? [];

    // Extract primary IP from network interfaces
    $primaryIp = null;
    try {
        $networks = $api->getNetworks($nodeName);
        $ifaces = $networks['data'] ?? $networks;
        // Prefer vmbr0, then first active bridge/eth with an address
        usort($ifaces, function ($a, $b) {
            $aScore = ($a['iface'] ?? '') === 'vmbr0' ? 0 : 1;
            $bScore = ($b['iface'] ?? '') === 'vmbr0' ? 0 : 1;
            return $aScore - $bScore;
        });
        foreach ($ifaces as $iface) {
            $addr = $iface['address'] ?? $iface['cidr'] ?? null;
            if ($addr && ($iface['active'] ?? 0) && ($iface['iface'] ?? '') !== 'lo') {
                // Strip CIDR notation if present
                $primaryIp = explode('/', $addr)[0];
                break;
            }
        }
    } catch (\Exception $e) {
        // Network info is optional
    }

    Response::success([
        'node'        => $nodeName,
        'pve_version' => $data['pveversion'] ?? null,
        'kernel'      => $data['kversion'] ?? null,
        'uptime'      => $data['uptime'] ?? 0,
        'ip'          => $primaryIp,
        'cpu' => [
            'model'   => $cpuInfo['model'] ?? null,
            'cores'   => $cpuInfo['cores'] ?? null,
            'sockets' => $cpuInfo['sockets'] ?? null,
            'threads' => $cpuInfo['cpus'] ?? null,
            'mhz'     => $cpuInfo['mhz'] ?? null,
            'flags'   => $cpuInfo['flags'] ?? null,
            'hvm'     => $cpuInfo['hvm'] ?? null,
        ],
        'memory' => [
            'total' => $memInfo['total'] ?? 0,
            'used'  => $memInfo['used'] ?? 0,
            'free'  => $memInfo['free'] ?? 0,
        ],
        'rootfs' => [
            'total' => $rootFs['total'] ?? 0,
            'used'  => $rootFs['used'] ?? 0,
            'free'  => $rootFs['free'] ?? 0,
            'avail' => $rootFs['avail'] ?? 0,
        ],
        'load_avg'  => $data['loadavg'] ?? [],
        'idle'      => $data['idle'] ?? null,
        'wait'      => $data['wait'] ?? null,
    ]);
} catch (\Exception $e) {
    Response::error('Failed to fetch node info: ' . $e->getMessage());
}
