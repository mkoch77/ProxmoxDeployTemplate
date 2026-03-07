<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\Auth;
use App\Request;
use App\Response;
use App\AppLogger;

Bootstrap::init();
Auth::requirePermission('logs.view');

if (Request::method() === 'GET') {
    $limit = min((int)(Request::get('limit') ?: 100), 500);
    $offset = max((int)(Request::get('offset') ?: 0), 0);
    $level = Request::get('level') ?: null;
    $category = Request::get('category') ?: null;

    $logs = AppLogger::getLogs($limit, $offset, $level, $category);
    $categories = AppLogger::getCategories();

    Response::success([
        'logs' => $logs,
        'categories' => $categories,
    ]);
} elseif (Request::method() === 'DELETE') {
    Request::validateCsrf();
    $days = (int)(Request::get('days') ?: 90);
    $deleted = AppLogger::cleanup($days);
    Response::success(['deleted' => $deleted]);
} else {
    Response::error('Method not allowed', 405);
}
