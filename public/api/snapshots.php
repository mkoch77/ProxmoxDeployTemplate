<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\Auth;
use App\Request;
use App\Response;
use App\Helpers;
use App\AppLogger;

Bootstrap::init();
Auth::requirePermission('vm.snapshot');

$method = Request::method();

if ($method === 'GET') {
    $node = Request::get('node', '');
    $type = Request::get('type', '');
    $vmid = (int) Request::get('vmid', 0);

    if (!$node || !$type || !$vmid) {
        Response::error('Missing node, type, or vmid', 400);
    }
    if (!Helpers::validateNodeName($node)) Response::error('Invalid node name', 400);
    if (!Helpers::validateType($type)) Response::error('Invalid type', 400);
    if (!Helpers::validateVmid($vmid)) Response::error('Invalid VMID', 400);

    try {
        $api = Helpers::createAPI();
        $result = $api->getSnapshots($node, $type, $vmid);
        Response::success($result['data'] ?? []);
    } catch (\Exception $e) {
        Response::error($e->getMessage(), 500);
    }
}

if ($method === 'POST') {
    Request::validateCsrf();
    $body = Request::jsonBody();
    Request::requireParams(['node', 'type', 'vmid', 'action'], $body);

    $node = $body['node'];
    $type = $body['type'];
    $vmid = (int) $body['vmid'];
    $action = $body['action'];

    if (!Helpers::validateNodeName($node)) Response::error('Invalid node name', 400);
    if (!Helpers::validateType($type)) Response::error('Invalid type', 400);
    if (!Helpers::validateVmid($vmid)) Response::error('Invalid VMID', 400);

    if (!in_array($action, ['create', 'delete', 'delete-all'], true)) {
        Response::error('Invalid action', 400);
    }

    try {
        $api = Helpers::createAPI();
        $user = Auth::check();

        if ($action === 'create') {
            $snapname = $body['snapname'] ?? '';
            if (!$snapname || !preg_match('/^[a-zA-Z0-9_\-]+$/', $snapname)) {
                Response::error('Invalid snapshot name (alphanumeric, dash, underscore only)', 400);
            }
            $description = $body['description'] ?? '';
            $vmstate = !empty($body['vmstate']);

            $result = $api->createSnapshot($node, $type, $vmid, $snapname, $description, $vmstate);
            $upid = $result['data'] ?? null;
            // Wait for task completion (max ~15s) so frontend refresh sees the new snapshot
            if ($upid) {
                for ($i = 0; $i < 30; $i++) {
                    usleep(500000);
                    try {
                        $taskStatus = $api->getTaskStatus($node, $upid);
                        if (($taskStatus['data']['status'] ?? '') === 'stopped') break;
                    } catch (\Exception $e) { break; }
                }
                // Brief settle time — Proxmox snapshot list may lag behind task completion
                usleep(500000);
            }
            AppLogger::info('snapshot', "Created snapshot '{$snapname}' for VM {$vmid}", ['node' => $node, 'type' => $type], $user['id'] ?? null);
            Response::success(['upid' => $upid]);
        }

        if ($action === 'delete') {
            $snapname = $body['snapname'] ?? '';
            if (!$snapname || !preg_match('/^[a-zA-Z0-9_\-]+$/', $snapname)) {
                Response::error('Invalid snapshot name', 400);
            }
            $result = $api->deleteSnapshot($node, $type, $vmid, $snapname);
            $upid = $result['data'] ?? null;
            // Wait for task completion (max ~15s) so frontend refresh sees updated list
            if ($upid) {
                for ($i = 0; $i < 30; $i++) {
                    usleep(500000);
                    try {
                        $taskStatus = $api->getTaskStatus($node, $upid);
                        if (($taskStatus['data']['status'] ?? '') === 'stopped') break;
                    } catch (\Exception $e) { break; }
                }
                // Brief settle time — Proxmox snapshot list may lag behind task completion
                usleep(500000);
            }
            AppLogger::info('snapshot', "Deleted snapshot '{$snapname}' for VM {$vmid}", ['node' => $node, 'type' => $type], $user['id'] ?? null);
            Response::success(['upid' => $upid]);
        }

        if ($action === 'delete-all') {
            $snapshots = $api->getSnapshots($node, $type, $vmid);
            $snaps = $snapshots['data'] ?? [];
            // Filter out 'current' (virtual snapshot representing live state)
            $snaps = array_filter($snaps, fn($s) => ($s['name'] ?? '') !== 'current');
            if (empty($snaps)) {
                Response::error('No snapshots to delete', 400);
            }

            // Delete leaf snapshots first (those that are not parents of others)
            // Build parent map
            $parentOf = [];
            foreach ($snaps as $s) {
                $parent = $s['parent'] ?? '';
                if ($parent) $parentOf[$parent] = true;
            }
            // Sort: leaves first
            usort($snaps, function ($a, $b) use ($parentOf) {
                $aIsParent = isset($parentOf[$a['name']]);
                $bIsParent = isset($parentOf[$b['name']]);
                return $aIsParent <=> $bIsParent;
            });

            $deleted = 0;
            $errors = [];
            foreach ($snaps as $s) {
                try {
                    $result = $api->deleteSnapshot($node, $type, $vmid, $s['name']);
                    $upid = $result['data'] ?? null;
                    // Wait for task to finish before deleting next (VM is locked during delete)
                    if ($upid) {
                        for ($i = 0; $i < 30; $i++) {
                            usleep(500000); // 500ms
                            try {
                                $taskStatus = $api->getTaskStatus($node, $upid);
                                $status = $taskStatus['data']['status'] ?? '';
                                if ($status === 'stopped') {
                                    $exit = trim($taskStatus['data']['exitstatus'] ?? 'OK');
                                    if ($exit !== 'OK') {
                                        $errors[] = $s['name'] . ': ' . $exit;
                                    }
                                    break;
                                }
                            } catch (\Exception $e) { break; }
                        }
                        usleep(500000); // settle time
                    }
                    $deleted++;
                } catch (\Exception $e) {
                    $errors[] = $s['name'] . ': ' . $e->getMessage();
                }
            }

            // Brief settle time after last deletion
            if ($deleted > 0) usleep(500000);
            AppLogger::info('snapshot', "Deleted all snapshots for VM {$vmid} ({$deleted} deleted)", ['node' => $node, 'errors' => $errors], $user['id'] ?? null);
            Response::success(['deleted' => $deleted, 'errors' => $errors]);
        }
    } catch (\Exception $e) {
        AppLogger::error('snapshot', "Snapshot action '{$action}' failed for VM {$vmid}: " . $e->getMessage(), null, Auth::check()['id'] ?? null);
        Response::error($e->getMessage(), 500);
    }
}

Response::error('Method not allowed', 405);
