<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\Auth;
use App\Request;
use App\Response;
use App\Helpers;
use App\Database;
use App\SSH;
use App\AppLogger;

Bootstrap::init();
Request::requireMethod('GET');
Auth::requirePermission('cluster.maintenance');

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
        $autoSkipMinutes = 15;

        foreach ($migrations as &$mig) {
            // Calculate elapsed time for running migrations
            if ($mig['status'] === 'running' && !empty($mig['started_at'])) {
                $mig['elapsed_seconds'] = time() - strtotime($mig['started_at']);
            }

            if (empty($mig['upid']) || $mig['status'] === 'error' || $mig['status'] === 'skipped') {
                continue;
            }
            if ($mig['status'] === 'completed') {
                continue;
            }

            // 1. Try Proxmox task status API
            $taskDone = false;
            $taskNode = $mig['source'] ?? $nodeName;
            $taskData = null;
            try {
                $taskStatus = $api->getTaskStatus($taskNode, $mig['upid']);
                $taskData = $taskStatus['data'] ?? [];

                if (($taskData['status'] ?? '') === 'stopped') {
                    $mig['status'] = ($taskData['exitstatus'] ?? '') === 'OK' ? 'completed' : 'error';
                    if ($mig['status'] === 'error') {
                        $mig['error'] = $taskData['exitstatus'] ?? 'Unknown error';
                        $allSuccess = false;
                    }
                    $taskDone = true;
                }
            } catch (\Exception $e) {
                AppLogger::debug('maintenance', 'Task status check failed', [
                    'vmid' => $mig['vmid'], 'error' => $e->getMessage(),
                ]);
            }

            // 2. Fallback: if task not marked done, check if VM is already on target node
            if (!$taskDone) {
                $targetNode = $mig['target'] ?? '';
                $type = $mig['type'] ?? 'qemu';
                try {
                    if ($targetNode) {
                        $guestStatus = $api->get("/nodes/{$targetNode}/{$type}/{$mig['vmid']}/status/current");
                        if (!empty($guestStatus['data']['status'])) {
                            $mig['status'] = 'completed';
                            $taskDone = true;
                        }
                    }
                } catch (\Exception $e2) {
                    // VM not on target yet
                }
            }

            if (!$taskDone) {
                // Reuse task data from first API call to avoid duplicate request
                $taskStillActive = false;
                if ($taskData !== null) {
                    $taskStillActive = (($taskData['status'] ?? '') === 'running');
                } elseif (!empty($mig['upid'])) {
                    // First call failed — retry once
                    try {
                        $taskCheck = $api->getTaskStatus($taskNode, $mig['upid']);
                        $taskStillActive = (($taskCheck['data']['status'] ?? '') === 'running');
                    } catch (\Exception $e) {
                        $taskStillActive = true; // Assume active to be safe
                    }
                }

                if ($taskStillActive) {
                    $allDone = false;
                } elseif (!empty($mig['started_at']) && (time() - strtotime($mig['started_at'])) > $autoSkipMinutes * 60) {
                    $mig['status'] = 'timeout';
                    AppLogger::warning('maintenance', 'Migration auto-skipped after timeout', [
                        'node' => $nodeName, 'vmid' => $mig['vmid'], 'minutes' => $autoSkipMinutes,
                    ]);
                } else {
                    $allDone = false;
                }
            }
        }
        unset($mig);

        // Update stored data
        $stmt = $db->prepare('UPDATE maintenance_nodes SET migration_tasks = ? WHERE node_name = ?');
        $stmt->execute([json_encode($migrations), $nodeName]);

        if ($allDone) {
            if ($maintNode['status'] === 'entering') {
                // Verify no guests are still running on the node before transitioning
                $guestsStillRunning = false;
                try {
                    $nodeGuests = \App\MaintenanceManager::getNodeGuests($api, $nodeName);
                    if (!empty($nodeGuests)) {
                        $guestsStillRunning = true;
                        AppLogger::warning('maintenance', 'Migrations done but guests still on node', [
                            'node' => $nodeName,
                            'remaining_guests' => count($nodeGuests),
                            'vmids' => array_map(fn($g) => $g['vmid'] ?? '?', $nodeGuests),
                        ]);
                    }
                } catch (\Exception $e) {
                    AppLogger::warning('maintenance', 'Could not verify guest status', ['node' => $nodeName, 'error' => $e->getMessage()]);
                }

                if ($guestsStillRunning) {
                    // Don't transition — keep 'entering' so the updater keeps waiting
                    AppLogger::info('maintenance', 'Holding in entering state — guests still present', ['node' => $nodeName]);
                } else {
                    $newStatus = 'maintenance';
                    $stmt = $db->prepare('UPDATE maintenance_nodes SET status = ? WHERE node_name = ?');
                    $stmt->execute([$newStatus, $nodeName]);
                    $maintNode['status'] = $newStatus;
                    AppLogger::info('maintenance', 'Status transitioned to maintenance', ['node' => $nodeName]);

                    // Enable Proxmox built-in maintenance mode (blue wrench icon)
                    try {
                        SSH::enableNodeMaintenance($nodeName);
                    } catch (\Exception $e) {
                        AppLogger::warning('maintenance', 'Could not enable PVE maintenance mode via SSH', ['node' => $nodeName, 'error' => $e->getMessage()]);
                    }
                }
            } elseif ($maintNode['status'] === 'leaving') {
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
        AppLogger::error('maintenance', 'Exception in status check', [
            'node' => $nodeName, 'error' => $e->getMessage(),
        ]);
    }
}

Response::success([
    'node' => $maintNode['node_name'],
    'status' => $maintNode['status'],
    'started_at' => $maintNode['started_at'],
    'migrations' => $migrations,
]);
