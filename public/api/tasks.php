<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Session;
use App\Request;
use App\Response;
use App\Helpers;

Session::start();
Request::requireMethod('GET');
Request::requireParams(['node'], $_GET);

$node = Request::get('node');
if (!Helpers::validateNodeName($node)) {
    Response::error('Invalid node name', 400);
}

try {
    $api = Helpers::createAPI();

    $upid = Request::get('upid');
    if ($upid) {
        $result = $api->getTaskStatus($node, $upid);
        Response::success($result['data'] ?? []);
    } else {
        $params = ['limit' => (int) Request::get('limit', 50)];
        $vmid = Request::get('vmid');
        if ($vmid) {
            $params['vmid'] = (int) $vmid;
        }
        $result = $api->getNodeTasks($node, $params);
        Response::success($result['data'] ?? []);
    }
} catch (\Exception $e) {
    Response::error($e->getMessage(), 500);
}
