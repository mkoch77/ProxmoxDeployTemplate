<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\Auth;
use App\Request;
use App\Response;
use App\Helpers;

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

    $online = !empty($body['online']);
    $result = $api->migrateGuest(
        $body['node'],
        $body['type'],
        (int) $body['vmid'],
        $body['target'],
        $online
    );

    Response::success(['upid' => $result['data'] ?? null]);
} catch (\Exception $e) {
    Response::error($e->getMessage(), 500);
}
