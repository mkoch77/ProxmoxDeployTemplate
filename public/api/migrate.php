<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\Auth;
use App\Request;
use App\Response;
use App\Helpers;
use App\AppLogger;

Bootstrap::init();
Request::requireMethod('POST');
Request::validateCsrf();
Auth::requirePermission('vm.migrate');

$body = Request::jsonBody();
Request::requireParams(['node', 'type', 'vmid', 'target'], $body);

if (!Helpers::validateNodeName($body['node'])) {
    Response::error('Invalid source node name', 400);
}
if (!Helpers::validateType($body['type'])) {
    Response::error('Invalid type (must be qemu or lxc)', 400);
}
if (!Helpers::validateVmid($body['vmid'])) {
    Response::error('Invalid VMID', 400);
}
if (!Helpers::validateNodeName($body['target'])) {
    Response::error('Invalid target node name', 400);
}
if ($body['node'] === $body['target']) {
    Response::error('Target node must differ from source node', 400);
}

try {
    $api = Helpers::createAPI();
    $target = $body['target'];

    // Verify target node is online
    $nodes = $api->getNodes()['data'] ?? [];
    $targetOnline = false;
    foreach ($nodes as $n) {
        if (($n['node'] ?? '') === $target) {
            $targetOnline = ($n['status'] ?? '') === 'online';
            break;
        }
    }
    if (!$targetOnline) {
        Response::error('Target node is not online', 400);
    }

    // Verify target node is not in maintenance mode
    $db = \App\Database::connection();
    $stmt = $db->prepare('SELECT 1 FROM maintenance_nodes WHERE node_name = ?');
    $stmt->execute([$target]);
    if ($stmt->fetch()) {
        Response::error('Target node is in maintenance mode', 400);
    }

    $online = !empty($body['online']);
    $result = $api->migrateGuest(
        $body['node'],
        $body['type'],
        (int) $body['vmid'],
        $body['target'],
        $online
    );

    AppLogger::info('migrate', "Migrate VM {$body['vmid']} from {$body['node']} to {$body['target']}", ['online' => $online], Auth::check()['id'] ?? null);
    Response::success(['upid' => $result['data'] ?? null]);
} catch (\Exception $e) {
    AppLogger::error('migrate', "Failed to migrate VM {$body['vmid']}: {$e->getMessage()}", null, Auth::check()['id'] ?? null);
    Response::error($e->getMessage(), 500);
}
