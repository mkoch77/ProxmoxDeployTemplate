<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\Request;
use App\Response;
use App\Auth;

Bootstrap::init();
Request::requireMethod('POST');

Auth::logout();
Response::success(['message' => 'Abgemeldet']);
