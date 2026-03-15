<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\Auth;
use App\Request;
use App\Response;
use App\Database;
use App\MonitoringCollector;
use App\Helpers;
use App\AppLogger;

Bootstrap::init();
$user = Auth::requirePermission('monitoring.view');

$action = $_GET['action'] ?? '';

// GET: metrics data
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $timerange = $_GET['timerange'] ?? '1h';
    $smoothing = max(0, min(100, (int)($_GET['smoothing'] ?? 0)));

    AppLogger::debug('monitoring', 'Fetching monitoring data', ['type' => $action, 'timerange' => $timerange]);

    if ($action === 'node') {
        $node = $_GET['node'] ?? '';
        if (!$node) Response::error('Missing node', 400);
        $metrics = MonitoringCollector::getNodeMetrics($node, $timerange, $smoothing);
        Response::success(['metrics' => $metrics]);
    }

    if ($action === 'vm') {
        $vmid = (int)($_GET['vmid'] ?? 0);
        if (!$vmid) Response::error('Missing vmid', 400);
        $metrics = MonitoringCollector::getVmMetrics($vmid, $timerange, $smoothing);
        Response::success(['metrics' => $metrics]);
    }

    if ($action === 'vm-summary') {
        $vmid = (int)($_GET['vmid'] ?? 0);
        if (!$vmid) Response::error('Missing vmid', 400);
        $summary = MonitoringCollector::getVmSummary($vmid, $timerange);
        Response::success(['summary' => $summary]);
    }

    if ($action === 'overview') {
        // Get all online nodes and list of VMs for selection
        try {
            $api = Helpers::createAPI();
            $nodesResult = $api->getNodes();
            $nodes = array_values(array_filter(
                $nodesResult['data'] ?? [],
                fn($n) => ($n['status'] ?? '') === 'online'
            ));
            $nodeNames = array_map(fn($n) => $n['node'], $nodes);

            $guests = $api->getGuests();
            $vmList = array_map(fn($g) => [
                'vmid' => $g['vmid'],
                'name' => $g['name'] ?? 'VM ' . $g['vmid'],
                'node' => $g['node'] ?? '',
                'type' => $g['type'] ?? 'qemu',
                'status' => $g['status'] ?? 'unknown',
            ], $guests);
            usort($vmList, fn($a, $b) => $a['vmid'] - $b['vmid']);
        } catch (\Exception $e) {
            $nodeNames = [];
            $vmList = [];
        }

        // Get monitoring settings
        $db = Database::connection();
        $settings = $db->query('SELECT * FROM monitoring_settings WHERE id = 1')->fetch(\PDO::FETCH_ASSOC);

        // Data stats
        $nodeCount = $db->query('SELECT COUNT(DISTINCT node) FROM node_metrics')->fetchColumn();
        $vmCount = $db->query('SELECT COUNT(DISTINCT vmid) FROM vm_metrics')->fetchColumn();
        $oldestNode = $db->query('SELECT MIN(ts) FROM node_metrics')->fetchColumn();
        $oldestVm = $db->query('SELECT MIN(ts) FROM vm_metrics')->fetchColumn();
        $totalRows = (int)$db->query('SELECT COUNT(*) FROM node_metrics')->fetchColumn()
            + (int)$db->query('SELECT COUNT(*) FROM vm_metrics')->fetchColumn();

        Response::success([
            'nodes' => $nodeNames,
            'vms' => $vmList,
            'settings' => $settings,
            'stats' => [
                'node_count' => (int)$nodeCount,
                'vm_count' => (int)$vmCount,
                'oldest_data' => $oldestNode ?: $oldestVm,
                'total_rows' => $totalRows,
            ],
        ]);
    }

    if ($action === 'ceph') {
        $metrics = MonitoringCollector::getCephMetrics($timerange, $smoothing);
        $pools = MonitoringCollector::getCephPoolMetrics($timerange);
        Response::success(['metrics' => $metrics, 'pools' => $pools]);
    }

    if ($action === 'vm-alerts') {
        $alerts = MonitoringCollector::getVmAlerts(5);
        Response::success(['alerts' => $alerts]);
    }

    Response::error('Unknown action', 400);
}

// POST: settings update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Request::validateCsrf();
    Auth::requirePermission('monitoring.manage');

    if ($action === 'settings') {
        $body = Request::jsonBody();
        $db = Database::connection();

        $retention = max(1, min(365, (int)($body['retention_days'] ?? 30)));
        $interval = max(5, min(300, (int)($body['collection_interval'] ?? 10)));

        $stmt = $db->prepare('UPDATE monitoring_settings SET retention_days = ?, collection_interval = ?, updated_at = CURRENT_TIMESTAMP WHERE id = 1');
        $stmt->execute([$retention, $interval]);

        Response::success(['retention_days' => $retention, 'collection_interval' => $interval]);
    }

    Response::error('Unknown action', 400);
}

Response::error('Method not allowed', 405);
