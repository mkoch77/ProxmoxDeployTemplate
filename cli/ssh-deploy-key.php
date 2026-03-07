#!/usr/bin/env php
<?php
/**
 * Deploy the auto-generated SSH public key to all Proxmox nodes.
 * Runs automatically via cron when a new key is detected (needs_deploy flag).
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Migrator;
use App\Config;
use App\Helpers;
use App\AppLogger;
use phpseclib3\Net\SSH2;

Migrator::run();

AppLogger::debug('ssh', 'CLI SSH key deploy started');

$keyPath    = Config::get('SSH_KEY_PATH', '');
$pubKeyPath = $keyPath . '.pub';
$flagFile   = dirname($keyPath) . '/needs_deploy';
$password   = Config::get('SSH_PASSWORD', '');
$port       = (int) Config::get('SSH_PORT', 22);
$user       = Config::get('SSH_USER', 'root');

if (!file_exists($flagFile)) {
    exit(0); // Nothing to do
}

if (!$password) {
    echo date('Y-m-d H:i:s') . " SSH key deploy skipped: SSH_PASSWORD not configured.\n";
    exit(0);
}

if (!file_exists($pubKeyPath)) {
    echo date('Y-m-d H:i:s') . " SSH key deploy skipped: public key not found.\n";
    exit(1);
}

$pubKey = trim(file_get_contents($pubKeyPath));

// Get node IPs from cluster status
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
    echo date('Y-m-d H:i:s') . " SSH key deploy failed: " . $e->getMessage() . "\n";
    exit(1);
}

$cmd = 'mkdir -p ~/.ssh && chmod 700 ~/.ssh && '
    . '(grep -qF ' . escapeshellarg($pubKey) . ' ~/.ssh/authorized_keys 2>/dev/null '
    . '|| echo ' . escapeshellarg($pubKey) . ' >> ~/.ssh/authorized_keys) '
    . '&& chmod 600 ~/.ssh/authorized_keys';

$allOk = true;
foreach ($nodes as $node) {
    try {
        $ssh = new SSH2($node['ip'], $port, 10);
        if (!$ssh->login($user, $password)) {
            echo date('Y-m-d H:i:s') . " [{$node['name']}] Auth failed.\n";
            $allOk = false;
            continue;
        }
        $ssh->exec($cmd);
        $exitCode = $ssh->getExitStatus();
        if ($exitCode === 0) {
            echo date('Y-m-d H:i:s') . " [{$node['name']}] Key deployed successfully.\n";
        } else {
            echo date('Y-m-d H:i:s') . " [{$node['name']}] Deploy failed (exit {$exitCode}).\n";
            $allOk = false;
        }
    } catch (\Exception $e) {
        echo date('Y-m-d H:i:s') . " [{$node['name']}] Error: " . $e->getMessage() . "\n";
        $allOk = false;
    }
}

if ($allOk) {
    unlink($flagFile);
    echo date('Y-m-d H:i:s') . " SSH key deployed to all nodes.\n";
} else {
    echo date('Y-m-d H:i:s') . " SSH key deploy incomplete — will retry next minute.\n";
}
