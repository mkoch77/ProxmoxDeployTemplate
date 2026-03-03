<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Config;
use App\Session;
use App\Response;
use App\Helpers;

Session::start();

try {
    $api = Helpers::createAPI();
    $api->get('/version');

    Response::success([
        'authenticated' => true,
        'token_id'      => Config::get('PROXMOX_TOKEN_ID'),
    ]);
} catch (\Exception $e) {
    Response::error('Connection failed: ' . $e->getMessage(), 500);
}
