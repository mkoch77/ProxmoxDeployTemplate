<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\Request;
use App\Response;
use App\Auth;
use App\AppLogger;

Bootstrap::init();
Request::requireMethod('POST');

$userId = Auth::check()['id'] ?? null;
Auth::logout();
AppLogger::info('auth', 'User logged out', null, $userId);
Response::success(['message' => 'Abgemeldet']);
