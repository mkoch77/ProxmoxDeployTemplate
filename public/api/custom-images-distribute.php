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

// Remote destination on Proxmox nodes: ISOs go to iso/, everything else to custom/
$isIso = (bool)preg_match('/\.iso$/i', $image['filename']);
$remoteDest = $isIso ? '/var/lib/vz/template/iso/' : '/var/lib/vz/template/custom/';

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
foreach ($nodes as $node) {
    $nodeName = $node['node'];

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

    // Create remote directory
    $mkdirCmd = "ssh {$sshOpts} root@" . escapeshellarg($sshHost) . " 'mkdir -p " . escapeshellarg($remoteDest) . "'";
    exec($mkdirCmd . ' 2>&1', $mkdirOut, $mkdirCode);

    if ($mkdirCode !== 0) {
        $results[$nodeName] = ['ok' => false, 'error' => 'SSH failed: ' . implode(' ', $mkdirOut)];
        continue;
    }

    // SCP the file
    $scpCmd = "scp {$sshOpts} " . escapeshellarg($localPath) . " root@" . escapeshellarg($sshHost) . ":" . escapeshellarg($remoteDest . $image['filename']);
    exec($scpCmd . ' 2>&1', $scpOut, $scpCode);

    if ($scpCode !== 0) {
        $results[$nodeName] = ['ok' => false, 'error' => 'SCP failed: ' . implode(' ', $scpOut)];
    } else {
        $results[$nodeName] = ['ok' => true];
    }
}

$failedNodes = array_keys(array_filter($results, fn($r) => !$r['ok']));
if (!empty($failedNodes)) {
    AppLogger::error('deploy', 'Custom image distribution had failures', ['image_id' => $imageId, 'failed_nodes' => $failedNodes], $userId);
}

Response::success(['results' => $results]);
