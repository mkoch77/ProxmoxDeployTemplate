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

    $guests = $api->getGuests();

    $nodeFilter = Request::get('node');
    if ($nodeFilter) {
        $guests = array_values(array_filter($guests, fn($g) => $g['node'] === $nodeFilter));
    }

    $typeFilter = Request::get('type');
    if ($typeFilter) {
        $guests = array_values(array_filter($guests, fn($g) => $g['type'] === $typeFilter));
    }

    Response::success($guests);
} catch (\Exception $e) {
    Response::error($e->getMessage(), 500);
}
