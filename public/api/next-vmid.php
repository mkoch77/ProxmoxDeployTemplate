<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\Auth;
use App\Request;
use App\Response;
use App\Helpers;

Bootstrap::init();
Request::requireMethod('GET');
Auth::requirePermission('template.deploy');

try {
    $api = Helpers::createAPI();

    $vmid = $api->getNextVmid();
    Response::success(['vmid' => $vmid]);
} catch (\Exception $e) {
    Response::error($e->getMessage(), 500);
}
