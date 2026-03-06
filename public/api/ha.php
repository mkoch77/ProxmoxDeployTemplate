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
Auth::requirePermission('cluster.ha');

$body = Request::jsonBody();

$action = $body['action'] ?? '';
if (!in_array($action, ['enable', 'disable', 'add', 'remove'], true)) {
    Response::error('Invalid action', 400);
}

// Validate SID format: "vm:123" or "ct:123"
$sid = $body['sid'] ?? '';
if (!preg_match('/^(vm|ct):\d+$/', $sid)) {
    Response::error('Invalid resource SID (expected vm:VMID or ct:VMID)', 400);
}

try {
    $api = Helpers::createAPI();

    switch ($action) {
        case 'enable':
            $api->updateHAResource($sid, ['state' => 'started']);
            Response::success(['message' => 'HA enabled for ' . $sid]);
            break;

        case 'disable':
            $api->updateHAResource($sid, ['state' => 'stopped']);
            Response::success(['message' => 'HA disabled for ' . $sid]);
            break;

        case 'add':
            $group = $body['group'] ?? '';
            $state = $body['state'] ?? 'started';
            if (!in_array($state, ['started', 'stopped'], true)) {
                $state = 'started';
            }
            $api->addHAResource($sid, $state, $group);
            Response::success(['message' => $sid . ' added to HA']);
            break;

        case 'remove':
            $api->removeHAResource($sid);
            Response::success(['message' => $sid . ' removed from HA']);
            break;
    }
} catch (\Exception $e) {
    Response::error($e->getMessage(), 500);
}
