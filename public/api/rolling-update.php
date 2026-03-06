<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\Auth;
use App\Request;
use App\Response;
use App\Database;
use App\Helpers;

Bootstrap::init();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    Auth::requirePermission('cluster.update');
    $db = Database::connection();
    // Return latest session
    $row = $db->query('SELECT * FROM rolling_update_sessions ORDER BY id DESC LIMIT 1')->fetch();
    if (!$row) {
        Response::success(null);
    }
    $row['nodes']         = json_decode($row['nodes'], true);
    $row['node_statuses'] = json_decode($row['node_statuses'], true);
    Response::success($row);
}

if ($method === 'POST') {
    Request::validateCsrf();
    $user = Auth::requirePermission('cluster.update');
    $body = Request::jsonBody();
    $action = $body['action'] ?? '';
    $db = Database::connection();

    switch ($action) {
        case 'start':
            $nodes = $body['nodes'] ?? [];
            if (empty($nodes) || !is_array($nodes)) {
                Response::error('No nodes specified', 400);
            }
            // Validate node names
            foreach ($nodes as $n) {
                if (!Helpers::validateNodeName($n)) {
                    Response::error('Invalid node name: ' . $n, 400);
                }
            }
            // Cancel any existing running session
            $db->exec("UPDATE rolling_update_sessions SET status = 'cancelled' WHERE status = 'running'");

            $nodeStatuses = [];
            foreach ($nodes as $n) {
                $nodeStatuses[$n] = ['step' => 'pending', 'log' => null, 'upgraded' => null, 'error' => null];
            }

            $stmt = $db->prepare('INSERT INTO rolling_update_sessions (nodes, node_statuses, status, started_by) VALUES (?, ?, ?, ?)');
            $stmt->execute([json_encode($nodes), json_encode($nodeStatuses), 'running', $user['id']]);
            $id = (int) $db->lastInsertId();

            Response::success(['id' => $id, 'nodes' => $nodes, 'node_statuses' => $nodeStatuses]);
            break;

        case 'update-node':
            $id   = (int) ($body['id'] ?? 0);
            $node = $body['node'] ?? '';
            $step = $body['step'] ?? '';
            if (!$id || !$node) {
                Response::error('Missing id or node', 400);
            }
            $allowed = ['pending','entering_maintenance','updating','leaving_maintenance','completed','failed','no_updates'];
            if (!in_array($step, $allowed, true)) {
                Response::error('Invalid step', 400);
            }

            $stmt = $db->prepare('SELECT node_statuses FROM rolling_update_sessions WHERE id = ?');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) {
                Response::error('Session not found', 404);
            }

            $statuses = json_decode($row['node_statuses'], true);
            $statuses[$node] = [
                'step'     => $step,
                'log'      => $body['log'] ?? $statuses[$node]['log'] ?? null,
                'upgraded' => $body['upgraded'] ?? $statuses[$node]['upgraded'] ?? null,
                'error'    => $body['error'] ?? null,
            ];

            $stmt = $db->prepare('UPDATE rolling_update_sessions SET node_statuses = ? WHERE id = ?');
            $stmt->execute([json_encode($statuses), $id]);
            Response::success(['ok' => true]);
            break;

        case 'finish':
            $id     = (int) ($body['id'] ?? 0);
            $status = ($body['status'] ?? 'completed');
            if (!in_array($status, ['completed', 'failed', 'cancelled'], true)) {
                $status = 'completed';
            }
            $stmt = $db->prepare('UPDATE rolling_update_sessions SET status = ? WHERE id = ?');
            $stmt->execute([$status, $id]);
            Response::success(['ok' => true]);
            break;

        default:
            Response::error('Unknown action', 400);
    }
}

Response::error('Method not allowed', 405);
