<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\EntraID;

Bootstrap::init();

if (!EntraID::isConfigured()) {
    http_response_code(404);
    echo 'EntraID is not configured';
    exit;
}

$state = bin2hex(random_bytes(16));
$_SESSION['entraid_state'] = $state;

$url = EntraID::getAuthorizationUrl($state);
header('Location: ' . $url);
exit;
