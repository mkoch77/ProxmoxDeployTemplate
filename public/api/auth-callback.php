<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\EntraID;
use App\Auth;

Bootstrap::init();

if (!EntraID::isConfigured()) {
    http_response_code(404);
    echo 'EntraID is not configured';
    exit;
}

// Validate state
$state = $_GET['state'] ?? '';
$expectedState = $_SESSION['entraid_state'] ?? '';
unset($_SESSION['entraid_state']);

if ($state === '' || !hash_equals($expectedState, $state)) {
    http_response_code(400);
    echo 'Invalid state parameter';
    exit;
}

// Check for error from Microsoft
if (isset($_GET['error'])) {
    http_response_code(400);
    echo 'Microsoft error: ' . htmlspecialchars($_GET['error_description'] ?? $_GET['error']);
    exit;
}

$code = $_GET['code'] ?? '';
if ($code === '') {
    http_response_code(400);
    echo 'No authorization code received';
    exit;
}

try {
    $tokens = EntraID::exchangeCode($code);
    $tokenData = EntraID::parseIdToken($tokens['id_token']);
    Auth::loginEntraID($tokenData);
    header('Location: ../index.php#dashboard');
    exit;
} catch (\Exception $e) {
    http_response_code(500);
    echo 'Login failed: ' . htmlspecialchars($e->getMessage());
    exit;
}
