<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\Auth;
use App\Request;
use App\Response;
use App\BackupManager;
use App\AppLogger;

Bootstrap::init();
$user = Auth::requirePermission('backup.manage');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

try {
    if ($method === 'GET') {
        if ($action === 'config') {
            Response::success(BackupManager::getConfig());
        } elseif ($action === 'list') {
            $config = BackupManager::getConfig();
            $local = BackupManager::listLocalBackups();
            $remote = $config['remote_enabled'] ? BackupManager::listRemoteBackups() : [];
            Response::success([
                'local' => $local,
                'remote' => $remote,
                'config' => $config,
            ]);
        } elseif ($action === 'history') {
            $limit = (int)($_GET['limit'] ?? 50);
            $offset = (int)($_GET['offset'] ?? 0);
            Response::success(BackupManager::getHistory($limit, $offset));
        } elseif ($action === 'encryption-key') {
            $key = BackupManager::getEncryptionKey();
            Response::success(['key' => $key, 'valid' => strlen($key) === 64 && @hex2bin($key) !== false]);
        } elseif ($action === 'download') {
            $filename = $_GET['filename'] ?? '';
            if (!$filename) Response::error('Filename required', 400);
            $path = BackupManager::getLocalBackupPath($filename);
            if (!$path) Response::error('Backup not found', 404);
            header('Content-Type: application/gzip');
            header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
            header('Content-Length: ' . filesize($path));
            readfile($path);
            exit;
        } else {
            Response::error('Unknown action', 400);
        }
    } elseif ($method === 'POST') {
        Request::validateCsrf();
        $body = Request::jsonBody();
        $action = $body['action'] ?? $action;

        if ($action === 'create') {
            $result = BackupManager::createBackup($user['id']);
            Response::success($result);
        } elseif ($action === 'restore') {
            $filename = $body['filename'] ?? '';
            if (!$filename) Response::error('Filename required', 400);
            if (empty($body['confirm'])) Response::error('Confirmation required', 400);
            $result = BackupManager::restoreBackup($filename);
            Response::success($result);
        } elseif ($action === 'delete') {
            $filename = $body['filename'] ?? '';
            if (!$filename) Response::error('Filename required', 400);
            BackupManager::deleteLocalBackup($filename);
            Response::success(['deleted' => $filename]);
        } elseif ($action === 'upload-remote') {
            $filename = $body['filename'] ?? '';
            if (!$filename) Response::error('Filename required', 400);
            $result = BackupManager::uploadToRemote($filename);
            Response::success($result);
        } elseif ($action === 'download-remote') {
            $filename = $body['filename'] ?? '';
            if (!$filename) Response::error('Filename required', 400);
            $localFile = BackupManager::downloadFromRemote($filename);
            Response::success(['filename' => $localFile]);
        } elseif ($action === 'delete-remote') {
            $filename = $body['filename'] ?? '';
            if (!$filename) Response::error('Filename required', 400);
            BackupManager::deleteRemoteBackup($filename);
            Response::success(['deleted' => $filename]);
        } elseif ($action === 'config') {
            BackupManager::saveConfig($body);
            Response::success(['saved' => true]);
        } elseif ($action === 'test-remote') {
            $result = BackupManager::testRemoteConnection();
            Response::success($result);
        } else {
            Response::error('Unknown action', 400);
        }
    } else {
        Response::error('Method not allowed', 405);
    }
} catch (\Exception $e) {
    AppLogger::error('backup', $e->getMessage());
    Response::error($e->getMessage(), 500);
}
