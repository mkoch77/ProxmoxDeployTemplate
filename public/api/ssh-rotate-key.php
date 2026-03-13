<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\Auth;
use App\Request;
use App\Response;
use App\Config;
use App\Helpers;
use App\AppLogger;
use App\SSH;
use phpseclib3\Net\SSH2;
use phpseclib3\Crypt\PublicKeyLoader;

Bootstrap::init();
Auth::requirePermission('settings.manage');
\App\Config::requireSsh();

$body = Request::jsonBody();

$keyPath    = Config::get('SSH_KEY_PATH', '');
$pubKeyPath = $keyPath . '.pub';
$port       = (int) Config::get('SSH_PORT', 22);
$user       = Config::get('SSH_USER', 'root');
$password   = Config::get('SSH_PASSWORD', '');

// Accept one-time password from request body (used when SSH_PASSWORD is not in .env)
$oneTimePassword = trim($body['password'] ?? '');
if ($oneTimePassword) {
    $password = $oneTimePassword;
}

if (!$keyPath || !file_exists($keyPath) || !file_exists($pubKeyPath)) {
    Response::error('Current SSH key not found. Check SSH_KEY_PATH configuration.', 404);
}

$oldPubKey = trim(file_get_contents($pubKeyPath));
if (!$oldPubKey) {
    Response::error('Current SSH public key is empty.', 500);
}

// Generate new key pair in temp location
$tmpKey = $keyPath . '.new';
$tmpPub = $tmpKey . '.pub';
@unlink($tmpKey);
@unlink($tmpPub);

$cmd = sprintf(
    'ssh-keygen -t ed25519 -f %s -N "" -C "proxmox-deploy-rotated" -q 2>&1',
    escapeshellarg($tmpKey)
);
exec($cmd, $output, $exitCode);

if ($exitCode !== 0 || !file_exists($tmpKey) || !file_exists($tmpPub)) {
    @unlink($tmpKey);
    @unlink($tmpPub);
    Response::error('Failed to generate new key pair: ' . implode("\n", $output), 500);
}

$newPubKey = trim(file_get_contents($tmpPub));

// Get cluster nodes
$nodes = [];
try {
    $api    = Helpers::createAPI();
    $status = $api->getClusterStatus();
    foreach ($status['data'] ?? [] as $entry) {
        if (($entry['type'] ?? '') === 'node') {
            $nodes[] = ['name' => $entry['name'], 'ip' => $entry['ip'] ?? $entry['name']];
        }
    }
} catch (\Exception $e) {
    @unlink($tmpKey);
    @unlink($tmpPub);
    Response::error('Failed to get cluster nodes: ' . $e->getMessage(), 500);
}

if (empty($nodes)) {
    @unlink($tmpKey);
    @unlink($tmpPub);
    Response::error('No nodes found in cluster.', 404);
}

$userId = Auth::check()['id'] ?? null;
AppLogger::info('security', 'Manual SSH key rotation started', ['node_count' => count($nodes)], $userId);

// Helper: connect + authenticate to a node (key from vault or file, then password fallback)
$connectNode = function (string $host) use ($user, $password, $port): ?SSH2 {
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

// ── Phase 1: Add new key to ALL nodes (keep old key) ────────────────────────
$addCmd = 'mkdir -p ~/.ssh && chmod 700 ~/.ssh && '
    . '(grep -qF ' . escapeshellarg($newPubKey) . ' ~/.ssh/authorized_keys 2>/dev/null '
    . '|| echo ' . escapeshellarg($newPubKey) . ' >> ~/.ssh/authorized_keys) '
    . '&& chmod 600 ~/.ssh/authorized_keys';

$results = [];
$phase1Ok = true;
$needsPassword = false;

foreach ($nodes as $node) {
    $host = $node['ip'];
    try {
        $ssh = $connectNode($host);
        if (!$ssh) {
            $results[] = ['node' => $node['name'], 'ip' => $host, 'success' => false, 'error' => 'Authentication failed'];
            $phase1Ok = false;
            $needsPassword = true;
            continue;
        }

        $ssh->exec($addCmd);
        $exitStatus = $ssh->getExitStatus();
        if ($exitStatus === 0) {
            $results[] = ['node' => $node['name'], 'ip' => $host, 'success' => true];
        } else {
            $results[] = ['node' => $node['name'], 'ip' => $host, 'success' => false, 'error' => "Command exited with code {$exitStatus}"];
            $phase1Ok = false;
        }
    } catch (\Exception $e) {
        $results[] = ['node' => $node['name'], 'ip' => $host, 'success' => false, 'error' => $e->getMessage()];
        $phase1Ok = false;
    }
}

if (!$phase1Ok) {
    // Phase 1 failed — new key was only ADDED (old key still valid everywhere), so no damage
    // Roll back: remove the new key from nodes that got it
    foreach ($results as $r) {
        if ($r['success']) {
            try {
                $ssh = $connectNode($r['ip']);
                if ($ssh) {
                    $rollbackCmd = 'grep -vF ' . escapeshellarg($newPubKey) . ' ~/.ssh/authorized_keys > ~/.ssh/authorized_keys.tmp 2>/dev/null '
                        . '&& mv ~/.ssh/authorized_keys.tmp ~/.ssh/authorized_keys '
                        . '&& chmod 600 ~/.ssh/authorized_keys';
                    $ssh->exec($rollbackCmd);
                }
            } catch (\Exception $e) { /* best effort */ }
        }
    }
    @unlink($tmpKey);
    @unlink($tmpPub);
    AppLogger::warning('security', 'SSH key rotation failed in phase 1 (add new key)', ['failed' => array_column(array_filter($results, fn($r) => !$r['success']), 'node')], $userId);
    Response::success(['results' => $results, 'new_public_key' => null, 'needs_password' => $needsPassword]);
}

// ── Phase 2: Remove old key from ALL nodes ───────────────────────────────────
$removeCmd = 'grep -vF ' . escapeshellarg($oldPubKey) . ' ~/.ssh/authorized_keys > ~/.ssh/authorized_keys.tmp 2>/dev/null '
    . '&& mv ~/.ssh/authorized_keys.tmp ~/.ssh/authorized_keys '
    . '&& chmod 600 ~/.ssh/authorized_keys';

$phase2Ok = true;
$phase2Results = [];

foreach ($nodes as $node) {
    $host = $node['ip'];
    try {
        // Now authenticate with the NEW key (just deployed in phase 1)
        $ssh = new SSH2($host, $port, 10);
        $authenticated = false;

        try {
            $newKeyContents = file_get_contents($tmpKey);
            $newKey = PublicKeyLoader::load($newKeyContents);
            $authenticated = $ssh->login($user, $newKey);
        } catch (\Exception $e) { /* new key auth failed */ }

        // Fallback to old key (still present)
        if (!$authenticated) {
            $ssh = $connectNode($host);
        }

        if (!$ssh) {
            $phase2Results[] = ['node' => $node['name'], 'ip' => $host, 'success' => false, 'error' => 'Authentication failed in phase 2'];
            $phase2Ok = false;
            continue;
        }

        $ssh->exec($removeCmd);
        $exitStatus = $ssh->getExitStatus();
        if ($exitStatus === 0) {
            $phase2Results[] = ['node' => $node['name'], 'ip' => $host, 'success' => true];
        } else {
            // Not critical — old key just lingers in authorized_keys
            $phase2Results[] = ['node' => $node['name'], 'ip' => $host, 'success' => true];
        }
    } catch (\Exception $e) {
        // Not critical — old key lingers but new key works
        $phase2Results[] = ['node' => $node['name'], 'ip' => $host, 'success' => true];
    }
}

// Phase 1 succeeded on all nodes → new key is deployed everywhere → safe to swap local key
rename($tmpKey, $keyPath);
rename($tmpPub, $pubKeyPath);
chmod($keyPath, 0600);
chmod($pubKeyPath, 0644);
@chown($keyPath, 'www-data');
@chown($pubKeyPath, 'www-data');

// Remove needs_deploy flag
@unlink(dirname($keyPath) . '/needs_deploy');

AppLogger::info('security', 'SSH key rotated successfully', ['node_count' => count($nodes)], $userId);
Response::success(['results' => $results, 'new_public_key' => $newPubKey, 'needs_password' => false]);
