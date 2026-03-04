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

    $templates = $api->getTemplates();

    $nodeFilter = Request::get('node');
    if ($nodeFilter) {
        $templates = array_values(array_filter($templates, fn($t) => $t['node'] === $nodeFilter));
    }

    Response::success($templates);
} catch (\Exception $e) {
    Response::error($e->getMessage(), 500);
}
