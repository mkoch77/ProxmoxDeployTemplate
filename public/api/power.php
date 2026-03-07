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

$body = Request::jsonBody();
Request::requireParams(['node', 'type', 'vmid', 'action'], $body);

// Permission check based on action
$permMap = [
    'start' => 'vm.start',
    'stop' => 'vm.stop',
    'shutdown' => 'vm.shutdown',
    'reboot' => 'vm.reboot',
    'reset' => 'vm.stop',
];
$requiredPerm = $permMap[$body['action']] ?? null;
if ($requiredPerm) {
    Auth::requirePermission($requiredPerm);
} else {
    Auth::requireAuth();
}

if (!Helpers::validateNodeName($body['node'])) {
    Response::error('Invalid node name', 400);
}
if (!Helpers::validateType($body['type'])) {
    Response::error('Invalid type (must be qemu or lxc)', 400);
}
if (!Helpers::validateVmid($body['vmid'])) {
    Response::error('Invalid VMID', 400);
}
if (!Helpers::validatePowerAction($body['action'])) {
    Response::error('Invalid action (must be start, stop, shutdown, reboot, or reset)', 400);
}

try {
    $api = Helpers::createAPI();

    $node = $body['node'];
    $type = $body['type'];
    $vmid = (int) $body['vmid'];

    $result = match ($body['action']) {
        'start'    => $api->startGuest($node, $type, $vmid),
        'stop'     => $api->stopGuest($node, $type, $vmid),
        'shutdown' => $api->shutdownGuest($node, $type, $vmid),
        'reboot'   => $api->rebootGuest($node, $type, $vmid),
        'reset'    => $api->resetGuest($node, $type, $vmid),
    };

    $user = Auth::check();
    AppLogger::info('power', "{$body['action']} VM {$vmid} on {$node}", ['type' => $type, 'action' => $body['action']], $user['id'] ?? null);
    Response::success(['upid' => $result['data'] ?? null]);
} catch (\Exception $e) {
    AppLogger::error('power', "Failed to {$body['action']} VM {$body['vmid']}: {$e->getMessage()}", null, Auth::check()['id'] ?? null);
    Response::error($e->getMessage(), 500);
}
