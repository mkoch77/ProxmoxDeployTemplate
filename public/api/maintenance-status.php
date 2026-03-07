<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\Auth;
use App\Request;
use App\Response;
use App\Helpers;
use App\Database;
use App\AppLogger;

Bootstrap::init();
Request::requireMethod('GET');
Auth::requirePermission('cluster.maintenance');

AppLogger::debug('maintenance', 'Fetching maintenance status');

$nodeName = Request::get('node');
if (!$nodeName || !Helpers::validateNodeName($nodeName)) {
    Response::error('Node parameter required', 400);
}

$db = Database::connection();
$stmt = $db->prepare('SELECT * FROM maintenance_nodes WHERE node_name = ?');
$stmt->execute([$nodeName]);
$maintNode = $stmt->fetch();

if (!$maintNode) {
    Response::error('Node is not in maintenance mode', 404);
}

$migrations = json_decode($maintNode['migration_tasks'] ?? '[]', true);

// Check task status for active migrations (both entering and leaving)
if (in_array($maintNode['status'], ['entering', 'leaving']) && !empty($migrations)) {
    try {
        $api = Helpers::createAPI();
        $allDone = true;
        $allSuccess = true;

        foreach ($migrations as &$mig) {
            if (empty($mig['upid']) || $mig['status'] === 'error') {
                continue;
            }
            if ($mig['status'] === 'completed') {
                continue;
            }

            try {
                // Task runs on the source node (where migration was initiated)
                $taskNode = $mig['source'] ?? $nodeName;
                $taskStatus = $api->getTaskStatus($taskNode, $mig['upid']);
                $data = $taskStatus['data'] ?? [];

                if (($data['status'] ?? '') === 'stopped') {
                    $mig['status'] = ($data['exitstatus'] ?? '') === 'OK' ? 'completed' : 'error';
                    if ($mig['status'] === 'error') {
                        $mig['error'] = $data['exitstatus'] ?? 'Unknown error';
                        $allSuccess = false;
                    }
                } else {
                    $allDone = false;
                }
            } catch (\Exception $e) {
                $allDone = false;
            }
        }
        unset($mig);

        // Update stored data
        $stmt = $db->prepare('UPDATE maintenance_nodes SET migration_tasks = ? WHERE node_name = ?');
        $stmt->execute([json_encode($migrations), $nodeName]);

        // If all done, update status.
        // Always transition to 'maintenance' even if some migrations failed —
        // otherwise the node stays stuck in 'entering' and the Exit button never appears.
        if ($allDone) {
            if ($maintNode['status'] === 'entering') {
                $newStatus = 'maintenance';
                $stmt = $db->prepare('UPDATE maintenance_nodes SET status = ? WHERE node_name = ?');
                $stmt->execute([$newStatus, $nodeName]);
                $maintNode['status'] = $newStatus;
            } elseif ($maintNode['status'] === 'leaving') {
                // Back-migrations done, remove maintenance record
                $stmt = $db->prepare('DELETE FROM maintenance_nodes WHERE node_name = ?');
                $stmt->execute([$nodeName]);
                Response::success([
                    'node' => $nodeName,
                    'status' => 'done',
                    'migrations' => $migrations,
                ]);
            }
        }
    } catch (\Exception $e) {
        // Proxmox API error - return what we have
    }
}

Response::success([
    'node' => $maintNode['node_name'],
    'status' => $maintNode['status'],
    'started_at' => $maintNode['started_at'],
    'migrations' => $migrations,
]);
