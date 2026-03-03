<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Session;
use App\Request;
use App\Response;
use App\Helpers;

Session::start();
Request::requireMethod('GET');

try {
    $api = Helpers::createAPI();

    $result = $api->getNodes();
    Response::success($result['data'] ?? []);
} catch (\Exception $e) {
    Response::error($e->getMessage(), 500);
}
