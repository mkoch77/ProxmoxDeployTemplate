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

    $result = $api->getNetworks($node, 'bridge');
    Response::success($result['data'] ?? []);
} catch (\Exception $e) {
    Response::error($e->getMessage(), 500);
}
