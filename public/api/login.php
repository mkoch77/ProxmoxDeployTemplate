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

// Bruteforce protection
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$wait = Auth::checkBruteforce($ip);
if ($wait > 0) {
    AppLogger::warning('auth', "Login rate-limited for IP {$ip}", ['wait' => $wait, 'username' => $username]);
    Response::error("Too many failed attempts. Please wait {$wait} seconds.", 429);
}

$user = Auth::login($username, $password);
if (!$user) {
    Auth::recordFailedLogin($ip, $username);
    AppLogger::warning('auth', "Failed login attempt for user '{$username}'");
    Response::error('Invalid credentials', 401);
}

// Clear attempts on successful login
Auth::clearLoginAttempts($ip);
AppLogger::info('auth', "User '{$username}' logged in", null, $user['id'] ?? null);
Response::success($user);
