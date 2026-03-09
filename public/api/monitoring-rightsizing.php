<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\Auth;
use App\Request;
use App\Response;
use App\Helpers;
use App\Database;
use App\RightSizing;
use App\AppLogger;

Bootstrap::init();

// ── GET: fetch recommendations ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    Auth::requirePermission('monitoring.view');

    $timerange = $_GET['timerange'] ?? '24h';
    AppLogger::debug('monitoring', 'Fetching rightsizing recommendations');
    $recommendations = RightSizing::analyze($timerange);

    Response::success(['recommendations' => $recommendations, 'timerange' => $timerange]);
}

// ── POST: apply a recommendation ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Request::validateCsrf();
    $user = Auth::requirePermission('monitoring.view');

    $body = Request::jsonBody();
    $vmid = (int)($body['vmid'] ?? 0);
    $node = $body['node'] ?? '';
    $vmType = $body['vm_type'] ?? 'qemu';
    $cores = isset($body['cpu_cores']) ? (int)$body['cpu_cores'] : null;
    $memBytes = isset($body['mem_bytes']) ? (int)$body['mem_bytes'] : null;

    if (!$vmid || !$node) Response::error('Missing vmid or node', 400);
    if ($cores === null && $memBytes === null) Response::error('No changes to apply', 400);
    if (!in_array($vmType, ['qemu', 'lxc'], true)) Response::error('Invalid vm_type', 400);

    $api = Helpers::createAPI();

    $config = [];
    $changes = [];
    if ($cores !== null && $cores >= 1 && $cores <= 128) {
        $config['cores'] = $cores;
        $changes[] = "cores={$cores}";
    }
    if ($memBytes !== null) {
        $memMb = (int)round($memBytes / 1048576);
        if ($memMb >= 128 && $memMb <= 131072) {
            $config['memory'] = $memMb;
            $changes[] = "memory={$memMb}MB";
        }
    }

    if (empty($config)) Response::error('Invalid values', 400);

    try {
        // Resolve the current node for this VM (it may have been migrated)
        $resources = $api->getClusterResources();
        $actualNode = null;
        $vmStatus = 'unknown';
        foreach ($resources['data'] ?? [] as $res) {
            if (($res['vmid'] ?? 0) == $vmid && in_array($res['type'] ?? '', ['qemu', 'lxc'], true)) {
                $actualNode = $res['node'];
                $vmType = $res['type'];
                $vmStatus = $res['status'] ?? 'unknown';
                break;
            }
        }

        if (!$actualNode) {
            Response::error("VM {$vmid} not found in cluster — it may have been deleted or migrated", 404);
        }

        // Check vCPU capacity if cores are being increased
        if (isset($config['cores'])) {
            Helpers::checkNodeCpuCapacity($api, $actualNode, $config['cores']);
        }

        $api->setGuestConfig($actualNode, $vmType, $vmid, $config);

        // Record apply so the suggestion is suppressed until VM reboots and new data flows in
        $db = Database::connection();
        $stmt = $db->prepare('INSERT INTO rightsizing_applied (vmid, applied_at) VALUES (?, CURRENT_TIMESTAMP) ON CONFLICT (vmid) DO UPDATE SET applied_at = CURRENT_TIMESTAMP');
        $stmt->execute([$vmid]);

        AppLogger::info('monitoring', "Right-sizing applied to VM {$vmid}", [
            'vmid' => $vmid, 'node' => $actualNode, 'changes' => $changes,
        ], $user['id']);

        $isRunning = $vmStatus === 'running';
        Response::success([
            'applied' => true,
            'changes' => $changes,
            'node' => $actualNode,
            'vm_type' => $vmType,
            'vm_status' => $vmStatus,
            'restart_required' => $isRunning,
            'message' => $isRunning
                ? "Configuration updated. Restart VM {$vmid} to apply changes."
                : "Configuration updated for VM {$vmid}. Changes take effect on next start.",
        ]);
    } catch (\Exception $e) {
        AppLogger::error('monitoring', "Right-sizing failed for VM {$vmid}", [
            'vmid' => $vmid, 'error' => $e->getMessage(),
        ], $user['id']);
        Response::error('Failed to apply: ' . $e->getMessage(), 500);
    }
}

Response::error('Method not allowed', 405);
