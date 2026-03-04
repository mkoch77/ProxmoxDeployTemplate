<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\Request;
use App\Response;
use App\Auth;

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
    Response::error('Invalid credentials', 401);
}

Response::success($user);
