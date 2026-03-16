<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\Auth;
use App\Request;
use App\Response;
use App\Database;
use App\Helpers;
use App\Config;
use App\AppLogger;

Bootstrap::init();
Request::requireMethod('POST');
Request::validateCsrf();
Auth::requirePermission('template.deploy');
\App\Config::requireSsh();

$body = Request::jsonBody();
$imageId = (int)($body['id'] ?? 0);
if (!$imageId) Response::error('Missing image id', 400);

$db = Database::connection();
$stmt = $db->prepare('SELECT * FROM custom_images WHERE id = ?');
$stmt->execute([$imageId]);
$image = $stmt->fetch();
if (!$image) Response::error('Image not found', 404);

$imagesDir = realpath(__DIR__ . '/../../data/images');
$localPath = $imagesDir . '/' . $image['filename'];
if (!file_exists($localPath)) {
    Response::error("File '{$image['filename']}' not found locally", 404);
}

// Remote destination on Proxmox nodes
// For ISOs: use configured ISO_STORAGE path if set, otherwise /var/lib/vz/template/iso/
// For non-ISOs: always /var/lib/vz/template/custom/
$isIso = (bool)preg_match('/\.iso$/i', $image['filename']);
$configuredIsoStorage = Config::get('ISO_STORAGE', '');
$remoteDest = $isIso ? '/var/lib/vz/template/iso/' : '/var/lib/vz/template/custom/';
$resolveRemoteDest = $isIso && $configuredIsoStorage; // need to resolve path per-node via pvesm

// Get SSH key path
$keyDir = getenv('SSH_KEY_DIR') ?: '/var/www/html/data/.ssh';
$keyPath = $keyDir . '/id_ed25519';
if (!file_exists($keyPath)) {
    Response::error('SSH key not found — check SSH setup', 500);
}

// Get online nodes
try {
    $api = Helpers::createAPI();
    $nodesResult = $api->getNodes();
    $nodes = array_filter($nodesResult['data'] ?? [], fn($n) => ($n['status'] ?? '') === 'online');
} catch (\Exception $e) {
    Response::error('Failed to get nodes: ' . $e->getMessage(), 500);
}

if (empty($nodes)) {
    Response::error('No online nodes found', 404);
}

$userId = Auth::check()['id'] ?? null;
AppLogger::info('deploy', 'Custom image distribution started', ['image_id' => $imageId, 'filename' => $image['filename'], 'node_count' => count($nodes)], $userId);

$results = [];
$sharedDone = false; // For shared storage, only need to copy once
foreach ($nodes as $node) {
    $nodeName = $node['node'];

    // For shared ISO storage: once copied to one node, it's available on all
    if ($resolveRemoteDest && $sharedDone) {
        $results[$nodeName] = ['ok' => true, 'note' => 'shared storage — already distributed'];
        continue;
    }

    // Resolve SSH host
    $envKey  = 'SSH_HOST_' . strtoupper(str_replace('-', '_', $nodeName));
    $sshHost = Config::get($envKey, '');
    if (!$sshHost) {
        try {
            $status = $api->getClusterStatus();
            foreach ($status['data'] ?? [] as $entry) {
                if (($entry['type'] ?? '') === 'node' &&
                    strtolower($entry['name'] ?? '') === strtolower($nodeName) &&
                    !empty($entry['ip'])) {
                    $sshHost = $entry['ip'];
                    break;
                }
            }
        } catch (\Exception $e) {}
        if (!$sshHost) $sshHost = $nodeName;
    }

    // Create remote dir + SCP the file
    $sshOpts = "-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o LogLevel=ERROR -i " . escapeshellarg($keyPath);

    // Resolve remote destination: for configured ISO storage, find actual filesystem path
    $nodeRemoteDest = $remoteDest;
    if ($resolveRemoteDest) {
        $storageName = preg_replace('/[^a-zA-Z0-9_-]/', '', $configuredIsoStorage);
        $resolveScript = 'P=$(dirname "$(pvesm path ' . escapeshellarg($configuredIsoStorage . ':iso/dummy_probe') . ' 2>/dev/null)" 2>/dev/null);'
            . ' if [ -z "$P" ] || [ "$P" = "." ]; then'
            . '   P=$(awk "/^[a-z]+: ' . $storageName . '$/,/^$/{if(/^\\s+path /){print \\$2}}" /etc/pve/storage.cfg 2>/dev/null);'
            . '   if [ -n "$P" ]; then P="${P}/template/iso"; fi;'
            . ' fi;'
            . ' echo "$P"';
        $resolveCmd = "ssh {$sshOpts} root@" . escapeshellarg($sshHost)
            . " " . escapeshellarg($resolveScript);
        $resolved = trim(shell_exec($resolveCmd . ' 2>/dev/null') ?? '');
        if ($resolved) {
            $nodeRemoteDest = rtrim($resolved, '/') . '/';
        }
    }

    // Create remote directory
    $mkdirCmd = "ssh {$sshOpts} root@" . escapeshellarg($sshHost) . " 'mkdir -p " . escapeshellarg($nodeRemoteDest) . "'";
    $mkdirOut = [];
    exec($mkdirCmd . ' 2>&1', $mkdirOut, $mkdirCode);

    if ($mkdirCode !== 0) {
        $results[$nodeName] = ['ok' => false, 'error' => 'SSH failed: ' . implode(' ', $mkdirOut)];
        continue;
    }

    // SCP the file
    $scpCmd = "scp {$sshOpts} " . escapeshellarg($localPath) . " root@" . escapeshellarg($sshHost) . ":" . escapeshellarg($nodeRemoteDest . $image['filename']);
    $scpOut = [];
    exec($scpCmd . ' 2>&1', $scpOut, $scpCode);

    if ($scpCode !== 0) {
        $results[$nodeName] = ['ok' => false, 'error' => 'SCP failed: ' . implode(' ', $scpOut)];
    } else {
        $results[$nodeName] = ['ok' => true];
        if ($resolveRemoteDest) $sharedDone = true;
    }
}

$failedNodes = array_keys(array_filter($results, fn($r) => !$r['ok']));
if (!empty($failedNodes)) {
    AppLogger::error('deploy', 'Custom image distribution had failures', ['image_id' => $imageId, 'failed_nodes' => $failedNodes], $userId);
}

Response::success(['results' => $results]);
