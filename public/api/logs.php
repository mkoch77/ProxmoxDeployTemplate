<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\Auth;
use App\Request;
use App\Response;
use App\Helpers;
use App\AppLogger;

Bootstrap::init();
Auth::requirePermission('logs.view');

if (Request::method() === 'GET') {
    $limit = min((int)(Request::get('limit') ?: 100), 500);
    $offset = max((int)(Request::get('offset') ?: 0), 0);
    $level = Request::get('level') ?: null;
    $category = Request::get('category') ?: null;
    $source = Request::get('source') ?: 'all'; // all, app, proxmox

    $logs = [];
    $categories = [];

    // Fetch app logs
    if ($source !== 'proxmox') {
        $logs = AppLogger::getLogs($limit, $offset, $level, $category);
        $categories = AppLogger::getCategories();
        // Tag each with source
        foreach ($logs as &$log) {
            $log['source'] = 'app';
        }
        unset($log);
    }

    // Fetch Proxmox tasks and merge
    if ($source !== 'app' && !$category) {
        try {
            $api = Helpers::createAPI();
            $nodesResult = $api->getNodes();
            $onlineNodes = array_filter($nodesResult['data'] ?? [], fn($n) => ($n['status'] ?? '') === 'online');

            $taskLevelFilter = $level;
            // Map task status to log levels for filtering
            $tasks = [];
            foreach ($onlineNodes as $node) {
                try {
                    $result = $api->getNodeTasks($node['node'], ['limit' => 200]);
                    foreach ($result['data'] ?? [] as $t) {
                        $status = $t['status'] ?? '';
                        $isRunning = $status === '';
                        $isOk = $status === 'OK';
                        $isFailed = !$isOk && !$isRunning;

                        // Map to log level
                        $taskLevel = $isRunning ? 'info' : ($isOk ? 'info' : 'error');

                        // Apply level filter
                        if ($taskLevelFilter && $taskLevelFilter !== 'no-debug') {
                            if ($taskLevelFilter === 'error' && !$isFailed) continue;
                            if ($taskLevelFilter === 'warning' && !$isFailed) continue;
                            if ($taskLevelFilter === 'info' && $isFailed) continue;
                        }

                        $type = $t['type'] ?? 'unknown';
                        $vmid = $t['id'] ?? '';
                        $user = $t['user'] ?? '';
                        $startTime = $t['starttime'] ?? 0;
                        $endTime = $t['endtime'] ?? 0;

                        // Build readable message
                        $typeLabels = [
                            'qmigrate' => 'VM Live-Migration',
                            'vzmigrate' => 'CT Migration',
                            'qmstart' => 'VM Start',
                            'vzstart' => 'CT Start',
                            'qmstop' => 'VM Stop',
                            'vzstop' => 'CT Stop',
                            'qmshutdown' => 'VM Shutdown',
                            'vzshutdown' => 'CT Shutdown',
                            'qmreboot' => 'VM Reboot',
                            'qmreset' => 'VM Reset',
                            'qmclone' => 'VM Clone',
                            'vzclone' => 'CT Clone',
                            'qmcreate' => 'VM Create',
                            'vzcreate' => 'CT Create',
                            'qmdestroy' => 'VM Destroy',
                            'vzdestroy' => 'CT Destroy',
                            'qmsnapshot' => 'VM Snapshot',
                            'vzsnapshot' => 'CT Snapshot',
                            'vzdump' => 'Backup',
                            'vzrestore' => 'Restore',
                            'qmrestore' => 'Restore',
                            'aptupdate' => 'APT Update',
                            'startall' => 'Start All',
                            'stopall' => 'Stop All',
                            'hamigrate' => 'HA Migration',
                            'hastart' => 'HA Start',
                            'hastop' => 'HA Stop',
                            'download' => 'Download',
                            'imgcopy' => 'Image Copy',
                            'move_volume' => 'Move Volume',
                            'resize' => 'Disk Resize',
                        ];

                        $label = $typeLabels[$type] ?? $type;
                        $msg = $label;
                        if ($vmid) $msg .= " ({$vmid})";
                        $msg .= " on {$node['node']}";

                        $statusText = $isRunning ? 'running' : ($isOk ? 'OK' : $status);
                        $duration = '';
                        if ($endTime && $startTime) {
                            $dur = $endTime - $startTime;
                            if ($dur >= 60) $duration = floor($dur / 60) . 'm ' . ($dur % 60) . 's';
                            else $duration = $dur . 's';
                        }

                        $context = json_encode(array_filter([
                            'status' => $statusText,
                            'duration' => $duration,
                            'node' => $node['node'],
                            'user' => $user,
                        ]));

                        $tasks[] = [
                            'id' => null,
                            'level' => $taskLevel,
                            'category' => 'task:' . $type,
                            'message' => $msg,
                            'context' => $context,
                            'user_id' => null,
                            'username' => $user,
                            'created_at' => date('Y-m-d H:i:s', $startTime),
                            'source' => 'proxmox',
                            '_sort_ts' => $startTime,
                        ];
                    }
                } catch (\Exception $e) {
                    // skip unreachable node
                }
            }

            // Add sort timestamp to app logs
            foreach ($logs as &$log) {
                $log['_sort_ts'] = strtotime($log['created_at']);
            }
            unset($log);

            // Merge and sort by time descending
            $logs = array_merge($logs, $tasks);
            usort($logs, fn($a, $b) => ($b['_sort_ts'] ?? 0) <=> ($a['_sort_ts'] ?? 0));

            // Trim to limit
            $logs = array_slice($logs, 0, $limit);

            // Clean up sort key
            foreach ($logs as &$log) {
                unset($log['_sort_ts']);
            }
            unset($log);
        } catch (\Exception $e) {
            // Proxmox API unavailable — return app logs only
        }
    }

    Response::success([
        'logs' => $logs,
        'categories' => $categories,
    ]);
} elseif (Request::method() === 'DELETE') {
    Request::validateCsrf();
    $days = (int)(Request::get('days') ?: 90);
    $deleted = AppLogger::cleanup($days);
    Response::success(['deleted' => $deleted]);
} else {
    Response::error('Method not allowed', 405);
}
