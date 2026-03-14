#!/usr/bin/env php
<?php
/**
 * Rotate the container SSH key pair (two-phase deployment):
 * Phase 1: Add the new public key to all nodes (keep old key)
 * Phase 2: Remove the old public key from all nodes
 * Phase 3: Replace local key files
 *
 * This ensures the old key remains valid if phase 1 fails on any node.
 *
 * Runs via cron every 4 hours and on container start.
 * Requires key-based SSH auth (old key) OR password auth as fallback.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Migrator;
use App\Config;
use App\Helpers;
use App\AppLogger;
use phpseclib3\Net\SSH2;
use phpseclib3\Crypt\PublicKeyLoader;

Migrator::run();

$keyPath    = Config::get('SSH_KEY_PATH', '');
$pubKeyPath = $keyPath . '.pub';
$password   = Config::get('SSH_PASSWORD', '');
$port       = (int) Config::get('SSH_PORT', 22);
$user       = Config::get('SSH_USER', 'root');
$sshEnabled = Config::get('SSH_ENABLED', 'true');

if ($sshEnabled === 'false') {
    exit(0);
}

if (!$keyPath) {
    echo date('Y-m-d H:i:s') . " SSH_KEY_PATH not configured — skipping rotation.\n";
    exit(0);
}

// Check if key exists at all (first run handled by entrypoint.sh)
if (!file_exists($keyPath) || !file_exists($pubKeyPath)) {
    echo date('Y-m-d H:i:s') . " No existing SSH key found — skipping rotation (entrypoint handles initial generation).\n";
    exit(0);
}

$oldPubKey = trim(file_get_contents($pubKeyPath));
if (!$oldPubKey) {
    echo date('Y-m-d H:i:s') . " Existing public key is empty — skipping.\n";
    exit(1);
}

// Generate new key pair in temp location
$tmpKey = $keyPath . '.new';
$tmpPub = $tmpKey . '.pub';

// Clean up any leftover temp keys from a previous failed rotation
@unlink($tmpKey);
@unlink($tmpPub);

$cmd = sprintf(
    'ssh-keygen -t ed25519 -f %s -N "" -C "proxmox-deploy-rotated" -q 2>&1',
    escapeshellarg($tmpKey)
);
exec($cmd, $output, $exitCode);

if ($exitCode !== 0 || !file_exists($tmpKey) || !file_exists($tmpPub)) {
    echo date('Y-m-d H:i:s') . " Failed to generate new key pair: " . implode("\n", $output) . "\n";
    @unlink($tmpKey);
    @unlink($tmpPub);
    exit(1);
}

$newPubKey = trim(file_get_contents($tmpPub));
echo date('Y-m-d H:i:s') . " New key pair generated.\n";

// Get node IPs
try {
    $api    = Helpers::createAPI();
    $status = $api->getClusterStatus();
    $nodes  = [];
    foreach ($status['data'] ?? [] as $entry) {
        if (($entry['type'] ?? '') === 'node') {
            $nodes[] = ['name' => $entry['name'], 'ip' => $entry['ip'] ?? $entry['name']];
        }
    }
} catch (\Exception $e) {
    echo date('Y-m-d H:i:s') . " Failed to get cluster nodes: " . $e->getMessage() . "\n";
    @unlink($tmpKey);
    @unlink($tmpPub);
    exit(1);
}

if (empty($nodes)) {
    echo date('Y-m-d H:i:s') . " No nodes found — skipping.\n";
    @unlink($tmpKey);
    @unlink($tmpPub);
    exit(1);
}

/**
 * Connect to a node using the old key or password as fallback.
 */
function connectNode(string $host, int $port, string $user, string $keyPath, string $password): ?SSH2
{
    $ssh = new SSH2($host, $port, 10);
    $authenticated = false;

    // Try key-based auth with old key
    if ($keyPath && file_exists($keyPath)) {
        try {
            $keyContents = file_get_contents($keyPath);
            $key = $password
                ? PublicKeyLoader::load($keyContents, $password)
                : PublicKeyLoader::load($keyContents);
            $authenticated = $ssh->login($user, $key);
        } catch (\Exception $e) {
            // Key auth failed, try password
        }
    }

    // Fallback to password auth
    if (!$authenticated && $password) {
        $authenticated = $ssh->login($user, $password);
    }

    return $authenticated ? $ssh : null;
}

// ── Phase 1: Add new key to ALL nodes (keep old key) ────────────────────────
$addCmd = 'mkdir -p ~/.ssh && chmod 700 ~/.ssh && '
    . '(grep -qF ' . escapeshellarg($newPubKey) . ' ~/.ssh/authorized_keys 2>/dev/null '
    . '|| echo ' . escapeshellarg($newPubKey) . ' >> ~/.ssh/authorized_keys) '
    . '&& chmod 600 ~/.ssh/authorized_keys';

$phase1Ok = true;
$phase1Succeeded = [];

foreach ($nodes as $node) {
    try {
        $ssh = connectNode($node['ip'], $port, $user, $keyPath, $password);
        if (!$ssh) {
            echo date('Y-m-d H:i:s') . " [{$node['name']}] Phase 1: Auth failed.\n";
            $phase1Ok = false;
            continue;
        }
        $ssh->exec($addCmd);
        $exitStatus = $ssh->getExitStatus();
        if ($exitStatus === 0) {
            echo date('Y-m-d H:i:s') . " [{$node['name']}] Phase 1: New key added.\n";
            $phase1Succeeded[] = $node;
        } else {
            echo date('Y-m-d H:i:s') . " [{$node['name']}] Phase 1: Failed (exit {$exitStatus}).\n";
            $phase1Ok = false;
        }
    } catch (\Exception $e) {
        echo date('Y-m-d H:i:s') . " [{$node['name']}] Phase 1 error: " . $e->getMessage() . "\n";
        $phase1Ok = false;
    }
}

if (!$phase1Ok) {
    echo date('Y-m-d H:i:s') . " Phase 1 incomplete — rolling back (removing new key from succeeded nodes).\n";
    // Roll back: remove the new key from nodes that got it
    $rollbackCmd = 'grep -vF ' . escapeshellarg($newPubKey) . ' ~/.ssh/authorized_keys > ~/.ssh/authorized_keys.tmp 2>/dev/null '
        . '&& mv ~/.ssh/authorized_keys.tmp ~/.ssh/authorized_keys '
        . '&& chmod 600 ~/.ssh/authorized_keys';
    foreach ($phase1Succeeded as $node) {
        try {
            $ssh = connectNode($node['ip'], $port, $user, $keyPath, $password);
            if ($ssh) {
                $ssh->exec($rollbackCmd);
            }
        } catch (\Exception $e) { /* best effort */ }
    }
    @unlink($tmpKey);
    @unlink($tmpPub);
    exit(1);
}

// ── Phase 2: Remove old key from ALL nodes ───────────────────────────────────
$removeCmd = 'grep -vF ' . escapeshellarg($oldPubKey) . ' ~/.ssh/authorized_keys > ~/.ssh/authorized_keys.tmp 2>/dev/null '
    . '&& mv ~/.ssh/authorized_keys.tmp ~/.ssh/authorized_keys '
    . '&& chmod 600 ~/.ssh/authorized_keys';

foreach ($nodes as $node) {
    try {
        // Try new key first (just deployed), then old key as fallback
        $ssh = new SSH2($node['ip'], $port, 10);
        $authenticated = false;
        try {
            $newKeyContents = file_get_contents($tmpKey);
            $newKey = PublicKeyLoader::load($newKeyContents);
            $authenticated = $ssh->login($user, $newKey);
        } catch (\Exception $e) { /* try old */ }

        if (!$authenticated) {
            $ssh = connectNode($node['ip'], $port, $user, $keyPath, $password);
        }

        if ($ssh) {
            $ssh->exec($removeCmd);
            echo date('Y-m-d H:i:s') . " [{$node['name']}] Phase 2: Old key removed.\n";
        } else {
            echo date('Y-m-d H:i:s') . " [{$node['name']}] Phase 2: Auth failed (old key lingers, not critical).\n";
        }
    } catch (\Exception $e) {
        echo date('Y-m-d H:i:s') . " [{$node['name']}] Phase 2 warning: " . $e->getMessage() . "\n";
    }
}

// ── Phase 3: Replace local key files ─────────────────────────────────────────
$newKeyContents = file_get_contents($tmpKey);
rename($tmpKey, $keyPath);
rename($tmpPub, $pubKeyPath);
chmod($keyPath, 0600);
chmod($pubKeyPath, 0644);
chown($keyPath, 'www-data');
chown($pubKeyPath, 'www-data');

// Update vault with new private key so SSH::loadPrivateKeyContent() picks it up
if (\App\Vault::isAvailable()) {
    try {
        \App\Vault::set('SSH_PRIVATE_KEY', $newKeyContents);
        echo date('Y-m-d H:i:s') . " Vault updated with new SSH private key.\n";
    } catch (\Exception $e) {
        echo date('Y-m-d H:i:s') . " WARNING: Failed to update vault: " . $e->getMessage() . "\n";
    }
}

// Remove the needs_deploy flag if present (rotation handles deployment)
$flagFile = dirname($keyPath) . '/needs_deploy';
@unlink($flagFile);

AppLogger::info('security', 'SSH key rotated and deployed to all nodes', ['node_count' => count($nodes)]);
echo date('Y-m-d H:i:s') . " SSH key rotation complete — new key deployed to " . count($nodes) . " node(s).\n";
