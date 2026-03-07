<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\Auth;
use App\Request;
use App\Response;
use App\Helpers;
use App\AppLogger;
use App\Database;

Bootstrap::init();
Request::requireMethod('POST');
Request::validateCsrf();
Auth::requirePermission('vm.delete');

$body = Request::jsonBody();
Request::requireParams(['node', 'type', 'vmid'], $body);

if (!Helpers::validateNodeName($body['node'])) {
    Response::error('Invalid node name', 400);
}
if (!Helpers::validateType($body['type'])) {
    Response::error('Invalid type (must be qemu or lxc)', 400);
}
if (!Helpers::validateVmid($body['vmid'])) {
    Response::error('Invalid VMID', 400);
}

try {
    $api = Helpers::createAPI();
    $result = $api->deleteGuest($body['node'], $body['type'], (int) $body['vmid']);

    // Clean up monitoring metrics for deleted VM
    $db = Database::connection();
    $db->prepare('DELETE FROM vm_metrics WHERE vmid = ?')->execute([(int) $body['vmid']]);

    AppLogger::warning('delete', "Deleted VM {$body['vmid']} on {$body['node']}", ['type' => $body['type']], Auth::check()['id'] ?? null);
    Response::success(['upid' => $result['data'] ?? null]);
} catch (\Exception $e) {
    AppLogger::error('delete', "Failed to delete VM {$body['vmid']}: {$e->getMessage()}", null, Auth::check()['id'] ?? null);
    Response::error($e->getMessage(), 500);
}
