<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\Request;
use App\Response;
use App\Auth;
use App\AppLogger;

Bootstrap::init();
Request::requireMethod('POST');
Request::validateCsrf();

$body = Request::jsonBody();
$username = trim($body['username'] ?? '');
$password = $body['password'] ?? '';

if ($username === '' || $password === '') {
    Response::error('Username and password required', 400);
}

$user = Auth::login($username, $password);
if (!$user) {
    AppLogger::warning('auth', "Failed login attempt for user '{$username}'");
    Response::error('Invalid credentials', 401);
}

AppLogger::info('auth', "User '{$username}' logged in", null, $user['id'] ?? null);
Response::success($user);
