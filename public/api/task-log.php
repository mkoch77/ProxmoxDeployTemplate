<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\Auth;
use App\Request;
use App\Response;
use App\Helpers;

Bootstrap::init();
Request::requireMethod('GET');
Auth::requireAuth();
Request::requireParams(['node', 'upid'], $_GET);

$node = Request::get('node');
if (!Helpers::validateNodeName($node)) {
    Response::error('Invalid node name', 400);
}

$upid = Request::get('upid');
$start = (int) Request::get('start', 0);
$limit = (int) Request::get('limit', 500);

try {
    $api = Helpers::createAPI();

    $result = $api->getTaskLog($node, $upid, $start, $limit);
    Response::success($result['data'] ?? []);
} catch (\Exception $e) {
    Response::error($e->getMessage(), 500);
}
