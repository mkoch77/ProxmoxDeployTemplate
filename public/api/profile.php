<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\Auth;
use App\Response;
use App\Database;

Bootstrap::init();
$user = Auth::requireAuth();

$db = Database::connection();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $row = $db->prepare('SELECT ssh_public_keys, default_storage FROM users WHERE id = ?');
    $row->execute([$user['id']]);
    $data = $row->fetch();
    Response::success([
        'ssh_public_keys' => $data['ssh_public_keys'] ?? '',
        'default_storage' => $data['default_storage'] ?? '',
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $sshKeys        = trim($body['ssh_public_keys'] ?? '');
    $defaultStorage = trim($body['default_storage'] ?? '');

    $stmt = $db->prepare('UPDATE users SET ssh_public_keys = ?, default_storage = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
    $stmt->execute([$sshKeys, $defaultStorage, $user['id']]);

    Response::success(['ssh_public_keys' => $sshKeys, 'default_storage' => $defaultStorage]);
}

Response::error('Method not allowed', 405);
