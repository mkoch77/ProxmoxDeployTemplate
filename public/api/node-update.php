<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\Auth;
use App\Request;
use App\Response;
use App\Helpers;
use App\SSH;
use App\AppLogger;

Bootstrap::init();
Auth::requirePermission('cluster.update');

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Check for available updates via Proxmox API
    Request::requireMethod('GET');
    $node = Request::get('node');
    if (!$node || !Helpers::validateNodeName($node)) {
        Response::error('Invalid node name', 400);
    }

    try {
        $api = Helpers::createAPI();
        $result = $api->getAptUpdates($node);
        $packages = $result['data'] ?? [];
        Response::success([
            'node'     => $node,
            'count'    => count($packages),
            'packages' => array_map(fn($p) => [
                'name'        => $p['Package'] ?? $p['name'] ?? '',
                'new_version' => $p['Version'] ?? $p['new_version'] ?? '',
                'old_version' => $p['OldVersion'] ?? $p['old_version'] ?? '',
            ], $packages),
        ]);
    } catch (\Exception $e) {
        Response::error($e->getMessage(), 500);
    }
}

if ($method === 'POST') {
    Request::validateCsrf();
    $body = Request::jsonBody();
    $node = $body['node'] ?? '';

    if (!$node || !Helpers::validateNodeName($node)) {
        Response::error('Invalid node name', 400);
    }

    // Resolve SSH host: env override → cluster status IP → node name fallback
    $envKey  = 'SSH_HOST_' . strtoupper(str_replace('-', '_', $node));
    $sshHost = \App\Config::get($envKey, '');
    if (!$sshHost) {
        $sshHost = $node;
        try {
            $api    = Helpers::createAPI();
            $status = $api->getClusterStatus();
            foreach ($status['data'] ?? [] as $entry) {
                if (($entry['type'] ?? '') === 'node' &&
                    strtolower($entry['name'] ?? '') === strtolower($node) &&
                    !empty($entry['ip'])) {
                    $sshHost = $entry['ip'];
                    break;
                }
            }
        } catch (\Exception $e) { /* fall back to node name */ }
    }

    $userId = Auth::check()['id'] ?? null;
    AppLogger::info('system', 'Node update started', ['node' => $node], $userId);

    // Long-running: allow up to 10 minutes
    set_time_limit(600);

    $cmd = 'DEBIAN_FRONTEND=noninteractive apt-get update -qq 2>&1 || true; '
         . 'DEBIAN_FRONTEND=noninteractive apt-get dist-upgrade -y -q 2>&1';

    try {
        $result = SSH::execInstall($sshHost, $cmd, 600);
    } catch (\Exception $e) {
        AppLogger::error('system', 'Node update SSH connection failed', ['node' => $node, 'error' => $e->getMessage()], $userId);
        Response::error('SSH connection to node "' . $node . '" failed: ' . $e->getMessage(), 500);
    }

    $log     = $result['output'] ?? '';
    $success = $result['success'] ?? false;

    // Try to detect number of upgraded packages from apt output
    $upgraded = 0;
    if (preg_match('/(\d+) upgraded/', $log, $m)) {
        $upgraded = (int) $m[1];
    }

    if ($success) {
        AppLogger::info('system', 'Node update completed', ['node' => $node, 'upgraded_packages' => $upgraded], $userId);
    } else {
        AppLogger::warning('system', 'Node update completed with issues', ['node' => $node, 'upgraded_packages' => $upgraded], $userId);
    }

    Response::success([
        'node'     => $node,
        'success'  => $success,
        'upgraded' => $upgraded,
        'log'      => $log,
    ]);
}

Response::error('Method not allowed', 405);
