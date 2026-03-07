<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\Auth;
use App\Request;
use App\Response;
use App\Helpers;
use App\AppLogger;

Bootstrap::init();
Request::requireMethod('GET');
Auth::requireAuth();
Request::requireParams(['node', 'type', 'vmid'], $_GET);

$node = Request::get('node');
$type = Request::get('type');
$vmid = (int) Request::get('vmid');

AppLogger::debug('api', 'Fetching guest config', ['vmid' => $vmid]);

if (!Helpers::validateNodeName($node)) {
    Response::error('Invalid node name', 400);
}
if (!Helpers::validateType($type)) {
    Response::error('Invalid type (must be qemu or lxc)', 400);
}
if (!Helpers::validateVmid($vmid)) {
    Response::error('Invalid VMID', 400);
}

try {
    $api = Helpers::createAPI();

    $result = $api->getGuestConfig($node, $type, $vmid);
    Response::success($result['data'] ?? []);
} catch (\Exception $e) {
    Response::error($e->getMessage(), 500);
}
