<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\Auth;
use App\Request;
use App\Response;
use App\Loadbalancer;
use App\AppLogger;

Bootstrap::init();
Request::requireMethod('GET');
Auth::requirePermission('loadbalancer.view');

AppLogger::debug('monitoring', 'Fetching loadbalancer history');

try {
    $runId = Request::get('run_id');

    if ($runId) {
        $recommendations = Loadbalancer::getRunRecommendations((int)$runId);
        Response::success(['run_id' => (int)$runId, 'recommendations' => $recommendations]);
    } else {
        $limit = max(1, min(100, (int)(Request::get('limit') ?: 20)));
        $offset = max(0, (int)(Request::get('offset') ?: 0));
        Response::success(Loadbalancer::getRunHistory($limit, $offset));
    }
} catch (\Exception $e) {
    Response::error($e->getMessage(), 500);
}
