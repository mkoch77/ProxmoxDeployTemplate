<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\Auth;
use App\Request;
use App\Response;
use App\Helpers;
use App\CephCollector;

Bootstrap::init();
Request::requireMethod('GET');
Auth::requirePermission('monitoring.view');

$action = $_GET['action'] ?? 'status';

try {
    $api = Helpers::createAPI();
    $nodesResult = $api->getNodes();
    $onlineNodes = [];
    foreach ($nodesResult['data'] ?? [] as $n) {
        if (($n['status'] ?? '') === 'online') {
            $onlineNodes[] = $n['node'];
        }
    }

    if ($action === 'status') {
        $status = CephCollector::getStatus($api, $onlineNodes);
        if ($status['available'] && !empty($status['queried_node'])) {
            $status['osds_detail'] = CephCollector::getOsdDetails($api, $status['queried_node']);
            $status['pools'] = CephCollector::getPoolDetails($api, $status['queried_node']);
        }
        Response::success($status);
    } elseif ($action === 'pools') {
        $node = $_GET['node'] ?? ($onlineNodes[0] ?? '');
        if (!$node) Response::error('No online node available', 400);
        $pools = CephCollector::getPoolDetails($api, $node);
        Response::success($pools);
    } else {
        Response::error('Unknown action', 400);
    }
} catch (\Exception $e) {
    Response::error($e->getMessage(), 500);
}
