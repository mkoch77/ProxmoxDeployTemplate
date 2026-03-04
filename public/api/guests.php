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

    $guests = $api->getGuests();

    $nodeFilter = Request::get('node');
    if ($nodeFilter) {
        $guests = array_values(array_filter($guests, fn($g) => $g['node'] === $nodeFilter));
    }

    $typeFilter = Request::get('type');
    if ($typeFilter) {
        $guests = array_values(array_filter($guests, fn($g) => $g['type'] === $typeFilter));
    }

    // Enrich with OS type from guest config
    foreach ($guests as &$guest) {
        try {
            $config = $api->getGuestConfig($guest['node'], $guest['type'], (int)$guest['vmid']);
            $guest['ostype'] = $config['data']['ostype'] ?? null;
        } catch (\Exception $e) {
            $guest['ostype'] = null;
        }
    }
    unset($guest);

    Response::success($guests);
} catch (\Exception $e) {
    Response::error($e->getMessage(), 500);
}
