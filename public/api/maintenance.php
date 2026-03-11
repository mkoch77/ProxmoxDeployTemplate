<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\Auth;
use App\Request;
use App\Response;
use App\Helpers;
use App\Database;
use App\MaintenanceManager;
use App\SSH;
use App\AppLogger;

Bootstrap::init();

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        Auth::requirePermission('cluster.maintenance');
        $db = Database::connection();
        $rows = $db->query('SELECT * FROM maintenance_nodes')->fetchAll();
        Response::success($rows);
        break;

    case 'POST':
        Request::validateCsrf();
        $user = Auth::requirePermission('cluster.maintenance');
        $body = Request::jsonBody();
        $nodeName = $body['node'] ?? '';

        if (!$nodeName || !Helpers::validateNodeName($nodeName)) {
            Response::error('Invalid node name', 400);
        }

        $db = Database::connection();

        // Check if already in maintenance
        $stmt = $db->prepare('SELECT * FROM maintenance_nodes WHERE node_name = ?');
        $stmt->execute([$nodeName]);
        if ($stmt->fetch()) {
            Response::error('Node is already in maintenance mode', 409);
        }

        try {
            $api = Helpers::createAPI();

            // Verify node exists and is online
            $nodes = $api->getNodes()['data'] ?? [];
            $nodeExists = false;
            foreach ($nodes as $n) {
                if ($n['node'] === $nodeName && ($n['status'] ?? '') === 'online') {
                    $nodeExists = true;
                    break;
                }
            }
            if (!$nodeExists) {
                Response::error('Node not found or offline', 404);
            }

            // Enable Proxmox built-in maintenance mode via SSH
            try {
                SSH::enableNodeMaintenance($nodeName);
            } catch (\Exception $e) {
                // SSH may not be configured - continue with manual migration only
            }

            // Get all running guests on the node
            $guests = MaintenanceManager::getNodeGuests($api, $nodeName);
            $migrations = [];

            // Migrate each guest
            foreach ($guests as $guest) {
                $vmid = (int) $guest['vmid'];
                $target = MaintenanceManager::selectTargetNode($api, $nodeName, $vmid);
                if (!$target) {
                    Response::error('No target node available for migration', 400);
                }
                $type = $guest['type'];

                try {
                    $result = $api->migrateGuest($nodeName, $type, $vmid, $target, true);
                    $migrations[] = [
                        'vmid' => $vmid,
                        'type' => $type,
                        'name' => $guest['name'] ?? "VM $vmid",
                        'source' => $nodeName,
                        'target' => $target,
                        'upid' => $result['data'] ?? '',
                        'status' => 'running',
                        'started_at' => date('c'),
                    ];
                } catch (\Exception $e) {
                    $migrations[] = [
                        'vmid' => $vmid,
                        'type' => $type,
                        'name' => $guest['name'] ?? "VM $vmid",
                        'source' => $nodeName,
                        'target' => $target,
                        'upid' => '',
                        'status' => 'error',
                        'error' => $e->getMessage(),
                    ];
                }
            }

            // Store maintenance state
            $stmt = $db->prepare('INSERT INTO maintenance_nodes (node_name, status, started_by, migration_tasks) VALUES (?, ?, ?, ?)');
            $status = empty($guests) ? 'maintenance' : 'entering';
            $stmt->execute([$nodeName, $status, $user['id'], json_encode($migrations)]);

            $failedCount = count(array_filter($migrations, fn($m) => $m['status'] === 'error'));
            AppLogger::info('maintenance', 'Node entering maintenance mode', [
                'node' => $nodeName,
                'status' => $status,
                'total_migrations' => count($migrations),
                'failed_migrations' => $failedCount,
            ], $user['id']);

            Response::success([
                'node' => $nodeName,
                'status' => $status,
                'migrations' => $migrations,
            ]);
        } catch (\Exception $e) {
            AppLogger::error('maintenance', 'Failed to enter maintenance mode', ['node' => $nodeName, 'error' => $e->getMessage()], $user['id']);
            Response::error($e->getMessage(), 500);
        }
        break;

    case 'DELETE':
        Request::validateCsrf();
        Auth::requirePermission('cluster.maintenance');
        $body = Request::jsonBody();
        $nodeName = $body['node'] ?? '';

        if (!$nodeName || !Helpers::validateNodeName($nodeName)) {
            Response::error('Invalid node name', 400);
        }

        $db = Database::connection();
        $stmt = $db->prepare('SELECT * FROM maintenance_nodes WHERE node_name = ?');
        $stmt->execute([$nodeName]);
        $maintNode = $stmt->fetch();

        if (!$maintNode) {
            Response::error('Node is not in maintenance mode', 404);
        }

        try {
            $api = Helpers::createAPI();

            // Disable Proxmox built-in maintenance mode via SSH
            try {
                SSH::disableNodeMaintenance($nodeName);
            } catch (\Exception $e) {
                // SSH may not be configured - continue with manual back-migration only
            }

            // Migrate VMs back to original node
            $forwardMigrations = json_decode($maintNode['migration_tasks'] ?? '[]', true);
            $backMigrations = [];

            foreach ($forwardMigrations as $mig) {
                // Only migrate back successfully forwarded VMs
                if ($mig['status'] !== 'completed') continue;

                $vmid = (int) $mig['vmid'];
                $type = $mig['type'];
                $currentNode = $mig['target'];  // VM is now on the target node
                $originalNode = $mig['source'];  // Migrate back to source

                try {
                    $result = $api->migrateGuest($currentNode, $type, $vmid, $originalNode, true);
                    $backMigrations[] = [
                        'vmid' => $vmid,
                        'type' => $type,
                        'name' => $mig['name'],
                        'source' => $currentNode,
                        'target' => $originalNode,
                        'upid' => $result['data'] ?? '',
                        'status' => 'running',
                        'started_at' => date('c'),
                    ];
                } catch (\Exception $e) {
                    $backMigrations[] = [
                        'vmid' => $vmid,
                        'type' => $type,
                        'name' => $mig['name'],
                        'source' => $currentNode,
                        'target' => $originalNode,
                        'upid' => '',
                        'status' => 'error',
                        'error' => $e->getMessage(),
                    ];
                }
            }

            if (empty($backMigrations)) {
                // No VMs to migrate back, just delete the record
                $stmt = $db->prepare('DELETE FROM maintenance_nodes WHERE node_name = ?');
                $stmt->execute([$nodeName]);
                AppLogger::info('maintenance', 'Node exited maintenance mode', ['node' => $nodeName], Auth::check()['id'] ?? null);
                Response::success(['message' => 'Maintenance mode ended', 'node' => $nodeName]);
            } else {
                // Update status to 'leaving' and store back-migration tasks
                $stmt = $db->prepare('UPDATE maintenance_nodes SET status = ?, migration_tasks = ? WHERE node_name = ?');
                $stmt->execute(['leaving', json_encode($backMigrations), $nodeName]);
                AppLogger::info('maintenance', 'Node leaving maintenance mode, migrating VMs back', [
                    'node' => $nodeName,
                    'back_migrations' => count($backMigrations),
                ], Auth::check()['id'] ?? null);
                Response::success([
                    'message' => 'Exiting maintenance mode, VMs being migrated back',
                    'node' => $nodeName,
                    'status' => 'leaving',
                    'migrations' => $backMigrations,
                ]);
            }
        } catch (\Exception $e) {
            AppLogger::error('maintenance', 'Failed to exit maintenance mode', ['node' => $nodeName, 'error' => $e->getMessage()], Auth::check()['id'] ?? null);
            Response::error($e->getMessage(), 500);
        }
        break;

    case 'PATCH':
        Request::validateCsrf();
        Auth::requirePermission('cluster.maintenance');
        $body = Request::jsonBody();
        $nodeName = $body['node'] ?? '';
        $action = $body['action'] ?? '';

        if (!$nodeName || !Helpers::validateNodeName($nodeName)) {
            Response::error('Invalid node name', 400);
        }

        $db = Database::connection();
        $stmt = $db->prepare('SELECT * FROM maintenance_nodes WHERE node_name = ?');
        $stmt->execute([$nodeName]);
        $maintNode = $stmt->fetch();

        if (!$maintNode) {
            Response::error('Node is not in maintenance mode', 404);
        }

        if (!in_array($maintNode['status'], ['entering', 'leaving'])) {
            Response::error('Node is not in a transitional state', 400);
        }

        $migrations = json_decode($maintNode['migration_tasks'] ?? '[]', true);

        if ($action === 'skip-vm') {
            $vmid = (int)($body['vmid'] ?? 0);
            if (!$vmid) Response::error('Missing vmid', 400);

            $found = false;
            foreach ($migrations as &$mig) {
                if ((int)$mig['vmid'] === $vmid && $mig['status'] === 'running') {
                    $mig['status'] = 'skipped';
                    $found = true;

                    // Try to stop the Proxmox task
                    if (!empty($mig['upid'])) {
                        try {
                            $api = Helpers::createAPI();
                            $taskNode = $mig['source'] ?? $nodeName;
                            $api->stopTask($taskNode, $mig['upid']);
                        } catch (\Exception $e) {}
                    }
                    break;
                }
            }
            unset($mig);

            if (!$found) Response::error('Migration not found or not running', 404);

            AppLogger::info('maintenance', 'Migration skipped', [
                'node' => $nodeName, 'vmid' => $vmid,
            ], Auth::check()['id'] ?? null);
        } elseif ($action === 'force-complete') {
            // Mark all running migrations as skipped
            foreach ($migrations as &$mig) {
                if ($mig['status'] === 'running') {
                    $mig['status'] = 'skipped';
                    // Try to stop the Proxmox task
                    if (!empty($mig['upid'])) {
                        try {
                            $api = $api ?? Helpers::createAPI();
                            $taskNode = $mig['source'] ?? $nodeName;
                            $api->stopTask($taskNode, $mig['upid']);
                        } catch (\Exception $e) {}
                    }
                }
            }
            unset($mig);

            AppLogger::info('maintenance', 'Maintenance force-completed', [
                'node' => $nodeName,
            ], Auth::check()['id'] ?? null);
        } else {
            Response::error('Unknown action', 400);
        }

        // Check if all migrations are now done
        $hasRunning = false;
        foreach ($migrations as $m) {
            if ($m['status'] === 'running') { $hasRunning = true; break; }
        }

        if (!$hasRunning || $action === 'force-complete') {
            if ($maintNode['status'] === 'entering') {
                $stmt = $db->prepare('UPDATE maintenance_nodes SET status = ?, migration_tasks = ? WHERE node_name = ?');
                $stmt->execute(['maintenance', json_encode($migrations), $nodeName]);
                Response::success(['status' => 'maintenance', 'migrations' => $migrations]);
            } elseif ($maintNode['status'] === 'leaving') {
                $stmt = $db->prepare('DELETE FROM maintenance_nodes WHERE node_name = ?');
                $stmt->execute([$nodeName]);
                Response::success(['status' => 'done', 'migrations' => $migrations]);
            }
        }

        // Still has running migrations
        $stmt = $db->prepare('UPDATE maintenance_nodes SET migration_tasks = ? WHERE node_name = ?');
        $stmt->execute([json_encode($migrations), $nodeName]);
        Response::success(['status' => $maintNode['status'], 'migrations' => $migrations]);
        break;

    default:
        Response::error('Method not allowed', 405);
}
