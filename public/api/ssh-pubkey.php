<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\Auth;
use App\Response;
use App\Config;

Bootstrap::init();
Auth::requireAuth();

$keyPath = Config::get('SSH_KEY_PATH', '');
$pubKeyPath = $keyPath . '.pub';

if (!$keyPath || !file_exists($pubKeyPath)) {
    Response::error('No SSH public key found. Check SSH_KEY_PATH configuration.', 404);
}

$pubKey = trim(file_get_contents($pubKeyPath));
if (!$pubKey) {
    Response::error('SSH public key is empty.', 500);
}

Response::success(['public_key' => $pubKey]);
