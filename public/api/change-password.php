<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\Auth;
use App\Request;
use App\Response;
use App\Database;
use App\AppLogger;

Bootstrap::init();
Request::requireMethod('POST');
Request::validateCsrf();

$user = Auth::requireAuth();

// Only local accounts can change password
$db = Database::connection();
$stmt = $db->prepare('SELECT auth_provider, password_hash FROM users WHERE id = ?');
$stmt->execute([$user['id']]);
$row = $stmt->fetch();

if (($row['auth_provider'] ?? '') !== 'local') {
    Response::error('Password change is only available for local accounts', 403);
}

$body = Request::jsonBody();
$currentPassword = $body['current_password'] ?? '';
$newPassword = $body['new_password'] ?? '';

if ($currentPassword === '' || $newPassword === '') {
    Response::error('Current and new password are required', 400);
}

if (strlen($newPassword) < 8) {
    Response::error('New password must be at least 8 characters', 400);
}

// Verify current password
if (!password_verify($currentPassword, $row['password_hash'])) {
    AppLogger::warning('auth', 'Failed password change attempt (wrong current password)', null, $user['id']);
    Response::error('Current password is incorrect', 403);
}

// Update password
$hash = password_hash($newPassword, PASSWORD_DEFAULT);
$stmt = $db->prepare('UPDATE users SET password_hash = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
$stmt->execute([$hash, $user['id']]);

AppLogger::info('auth', 'Password changed', null, $user['id']);
Response::success(['message' => 'Password changed successfully']);
