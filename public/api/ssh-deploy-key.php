<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\Auth;
use App\Response;
use App\Config;
use App\Helpers;
use phpseclib3\Net\SSH2;
use App\AppLogger;

Bootstrap::init();
Auth::requireAuth();
\App\Config::requireSsh();

$keyPath  = Config::get('SSH_KEY_PATH', '');
$pubKeyPath = $keyPath . '.pub';
$password = Config::get('SSH_PASSWORD', '');
$port     = (int) Config::get('SSH_PORT', 22);
$user     = Config::get('SSH_USER', 'root');

if (!$password) {
    Response::error('SSH_PASSWORD is not configured. Cannot deploy key without password auth.', 400);
}

if (!$keyPath || !file_exists($pubKeyPath)) {
    Response::error('SSH public key not found. Check SSH_KEY_PATH configuration.', 404);
}

$pubKey = trim(file_get_contents($pubKeyPath));
if (!$pubKey) {
    Response::error('SSH public key is empty.', 500);
}

// Get node IPs from cluster status
$nodes = [];
try {
    $api    = Helpers::createAPI();
    $status = $api->getClusterStatus();
    foreach ($status['data'] ?? [] as $entry) {
        if (($entry['type'] ?? '') === 'node') {
            $nodes[] = [
                'name' => $entry['name'],
                'ip'   => $entry['ip'] ?? $entry['name'],
            ];
        }
    }
} catch (\Exception $e) {
    Response::error('Failed to get cluster nodes: ' . $e->getMessage(), 500);
}

if (empty($nodes)) {
    Response::error('No nodes found in cluster.', 404);
}

$userId = Auth::check()['id'] ?? null;
AppLogger::info('security', 'SSH key deployment started', ['node_count' => count($nodes)], $userId);

// Deploy key to each node via password SSH
$results = [];
$cmd = 'mkdir -p ~/.ssh && chmod 700 ~/.ssh && '
    . '(grep -qF ' . escapeshellarg($pubKey) . ' ~/.ssh/authorized_keys 2>/dev/null '
    . '|| echo ' . escapeshellarg($pubKey) . ' >> ~/.ssh/authorized_keys) '
    . '&& chmod 600 ~/.ssh/authorized_keys';

foreach ($nodes as $node) {
    $host = $node['ip'];
    try {
        $ssh = new SSH2($host, $port, 10);
        if (!$ssh->login($user, $password)) {
            $results[] = ['node' => $node['name'], 'ip' => $host, 'success' => false, 'error' => 'Authentication failed'];
            continue;
        }
        $ssh->exec($cmd);
        $exitCode = $ssh->getExitStatus();
        if ($exitCode === 0) {
            $results[] = ['node' => $node['name'], 'ip' => $host, 'success' => true];
        } else {
            $results[] = ['node' => $node['name'], 'ip' => $host, 'success' => false, 'error' => "Command exited with code {$exitCode}"];
        }
    } catch (\Exception $e) {
        $results[] = ['node' => $node['name'], 'ip' => $host, 'success' => false, 'error' => $e->getMessage()];
    }
}

$failedNodes = array_filter($results, fn($r) => !$r['success']);
if (!empty($failedNodes)) {
    AppLogger::warning('security', 'SSH key deployment had failures', ['failed_nodes' => array_column($failedNodes, 'node')], $userId);
} else {
    AppLogger::info('security', 'SSH key deployment completed successfully', ['node_count' => count($nodes)], $userId);
}

Response::success(['results' => $results]);
