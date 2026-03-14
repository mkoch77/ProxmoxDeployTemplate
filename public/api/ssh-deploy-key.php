<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\Auth;
use App\Request;
use App\Response;
use App\Config;
use App\Helpers;
use App\SSH;
use phpseclib3\Net\SSH2;
use phpseclib3\Crypt\PublicKeyLoader;
use App\AppLogger;

Bootstrap::init();
Auth::requireAuth();
\App\Config::requireSsh();

$body = Request::jsonBody();

$keyPath  = Config::get('SSH_KEY_PATH', '');
$pubKeyPath = $keyPath . '.pub';
$password = Config::get('SSH_PASSWORD', '');
$port     = (int) Config::get('SSH_PORT', 22);
$user     = Config::get('SSH_USER', 'root');

// Accept one-time password from request body (used when SSH_PASSWORD is not in .env)
$oneTimePassword = trim($body['password'] ?? '');
if ($oneTimePassword) {
    $password = $oneTimePassword;
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

// Helper: connect + authenticate to a node (key-based first, then password fallback)
$connectNode = function (string $host) use ($keyPath, $user, $password, $port): ?SSH2 {
    $ssh = new SSH2($host, $port, 10);
    $authenticated = false;

    // Try key-based auth with current key (from vault or file)
    $keyContents = SSH::loadPrivateKeyContent();
    if ($keyContents) {
        try {
            $key = $password
                ? PublicKeyLoader::load($keyContents, $password)
                : PublicKeyLoader::load($keyContents);
            $authenticated = $ssh->login($user, $key);
        } catch (\Exception $e) { /* key auth failed */ }
    }
    // Fallback to password
    if (!$authenticated && $password) {
        $authenticated = $ssh->login($user, $password);
    }

    return $authenticated ? $ssh : null;
};

// Deploy key to each node
$results = [];
$needsPassword = false;
$cmd = 'mkdir -p ~/.ssh && chmod 700 ~/.ssh && '
    . '(grep -qF ' . escapeshellarg($pubKey) . ' ~/.ssh/authorized_keys 2>/dev/null '
    . '|| echo ' . escapeshellarg($pubKey) . ' >> ~/.ssh/authorized_keys) '
    . '&& chmod 600 ~/.ssh/authorized_keys';

foreach ($nodes as $node) {
    $host = $node['ip'];
    try {
        $ssh = $connectNode($host);
        if (!$ssh) {
            $results[] = ['node' => $node['name'], 'ip' => $host, 'success' => false, 'error' => 'Authentication failed'];
            $needsPassword = true;
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
if (!empty($failedNodes) && $needsPassword && !$oneTimePassword) {
    // Auth failed without password — prompt user
    AppLogger::warning('security', 'SSH key deployment needs password', ['failed_nodes' => array_column($failedNodes, 'node')], $userId);
    Response::error('SSH authentication failed. Enter the root password to deploy the key.', 422, ['needs_password' => true]);
} elseif (!empty($failedNodes)) {
    AppLogger::warning('security', 'SSH key deployment had failures', ['failed_nodes' => array_column($failedNodes, 'node')], $userId);
} else {
    AppLogger::info('security', 'SSH key deployment completed successfully', ['node_count' => count($nodes)], $userId);

    // Key deployed — remove password from .env (no longer needed)
    $envFile = dirname(__DIR__, 2) . '/.env';
    if (file_exists($envFile)) {
        $envContent = file_get_contents($envFile);
        $envContent = preg_replace('/^SSH_PASSWORD=.*/m', 'SSH_PASSWORD=', $envContent);
        file_put_contents($envFile, $envContent);
        AppLogger::info('security', 'SSH_PASSWORD removed from .env after successful key deployment', null, $userId);
    }

    // Remove needs_deploy flag
    $flagFile = dirname($keyPath) . '/needs_deploy';
    @unlink($flagFile);
}

Response::success(['results' => $results, 'needs_password' => false]);
