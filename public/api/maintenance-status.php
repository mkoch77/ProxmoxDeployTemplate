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
            AppLogger::info('maintenance', 'Checking migration task status', [
                'vmid' => $mig['vmid'], 'taskNode' => $taskNode, 'upid' => $mig['upid'],
            ]);
            try {
                $taskStatus = $api->getTaskStatus($taskNode, $mig['upid']);
                $data = $taskStatus['data'] ?? [];
                AppLogger::info('maintenance', 'Task status API response', [
                    'vmid' => $mig['vmid'], 'status' => $data['status'] ?? 'N/A',
                    'exitstatus' => $data['exitstatus'] ?? 'N/A',
                ]);

                if (($data['status'] ?? '') === 'stopped') {
                    $mig['status'] = ($data['exitstatus'] ?? '') === 'OK' ? 'completed' : 'error';
                    if ($mig['status'] === 'error') {
                        $mig['error'] = $data['exitstatus'] ?? 'Unknown error';
                        $allSuccess = false;
                    }
                    $taskDone = true;
                }
            } catch (\Exception $e) {
                AppLogger::warning('maintenance', 'Task status check FAILED', [
                    'node' => $taskNode, 'vmid' => $mig['vmid'],
                    'error' => $e->getMessage(), 'upid' => $mig['upid'],
                ]);
            }

            // 2. Fallback: if task not marked done, check if VM is already on target node
            if (!$taskDone) {
                $targetNode = $mig['target'] ?? '';
                $type = $mig['type'] ?? 'qemu';
                AppLogger::info('maintenance', 'Fallback: checking if VM is on target node', [
                    'vmid' => $mig['vmid'], 'target' => $targetNode, 'type' => $type,
                ]);
                try {
                    if ($targetNode) {
                        $guestStatus = $api->get("/nodes/{$targetNode}/{$type}/{$mig['vmid']}/status/current");
                        AppLogger::info('maintenance', 'Fallback guest check result', [
                            'vmid' => $mig['vmid'], 'target' => $targetNode,
                            'guest_status' => $guestStatus['data']['status'] ?? 'N/A',
                        ]);
                        if (!empty($guestStatus['data']['status'])) {
                            $mig['status'] = 'completed';
                            $taskDone = true;
                        }
                    }
                } catch (\Exception $e2) {
                    AppLogger::debug('maintenance', 'Fallback guest check failed', [
                        'vmid' => $mig['vmid'], 'target' => $targetNode, 'error' => $e2->getMessage(),
                    ]);
                }
            }

            if (!$taskDone) {
                // Only auto-skip if the Proxmox task is no longer running
                $taskStillActive = false;
                if (!empty($mig['upid'])) {
                    try {
                        $taskCheck = $api->getTaskStatus($taskNode, $mig['upid']);
                        $taskStillActive = (($taskCheck['data']['status'] ?? '') === 'running');
                    } catch (\Exception $e) {
                        // Can't determine task status — assume still active to be safe
                        $taskStillActive = true;
                    }
                }

                if ($taskStillActive) {
                    // Task is still running in Proxmox — keep waiting regardless of elapsed time
                    $allDone = false;
                } elseif (!empty($mig['started_at']) && (time() - strtotime($mig['started_at'])) > $autoSkipMinutes * 60) {
                    // Task is NOT running but we couldn't detect completion — timeout
                    $mig['status'] = 'timeout';
                    AppLogger::warning('maintenance', 'Migration auto-skipped after timeout (task no longer active)', [
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

        // If all done, update status.
        // Always transition to 'maintenance' even if some migrations failed —
        // otherwise the node stays stuck in 'entering' and the Exit button never appears.
        AppLogger::info('maintenance', 'Migration check result', [
            'node' => $nodeName, 'allDone' => $allDone, 'currentStatus' => $maintNode['status'],
        ]);
        if ($allDone) {
            if ($maintNode['status'] === 'entering') {
                $newStatus = 'maintenance';
                $stmt = $db->prepare('UPDATE maintenance_nodes SET status = ? WHERE node_name = ?');
                $stmt->execute([$newStatus, $nodeName]);
                $maintNode['status'] = $newStatus;
                AppLogger::info('maintenance', 'Status transitioned to maintenance', ['node' => $nodeName]);

                // Enable Proxmox built-in maintenance mode (blue wrench icon)
                // Done AFTER migrations to avoid lock conflicts
                try {
                    $sshResult = SSH::enableNodeMaintenance($nodeName);
                    AppLogger::info('maintenance', 'PVE maintenance mode enabled via SSH', ['node' => $nodeName, 'result' => $sshResult]);
                } catch (\Exception $e) {
                    AppLogger::warning('maintenance', 'Could not enable PVE maintenance mode via SSH', ['node' => $nodeName, 'error' => $e->getMessage()]);
                }
            } elseif ($maintNode['status'] === 'leaving') {
                // Back-migrations done, remove maintenance record
                $stmt = $db->prepare('DELETE FROM maintenance_nodes WHERE node_name = ?');
                $stmt->execute([$nodeName]);
                AppLogger::info('maintenance', 'Maintenance record deleted (leaving done)', ['node' => $nodeName]);
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
