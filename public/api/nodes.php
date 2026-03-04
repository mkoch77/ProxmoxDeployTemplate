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

try {
    $api = Helpers::createAPI();

    $result = $api->getNodes();
    Response::success($result['data'] ?? []);
} catch (\Exception $e) {
    Response::error($e->getMessage(), 500);
}
