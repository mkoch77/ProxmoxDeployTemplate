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
Auth::requirePermission('template.deploy');
Request::requireParams(['node'], $_GET);

$node = Request::get('node');

AppLogger::debug('api', 'Fetching network list');
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
