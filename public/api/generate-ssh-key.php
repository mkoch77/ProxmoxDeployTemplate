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

// Generate Ed25519 keypair using ssh-keygen in a temp directory
$tmpDir = sys_get_temp_dir() . '/ssh_keygen_' . bin2hex(random_bytes(8));
mkdir($tmpDir, 0700, true);
$keyFile = $tmpDir . '/id_ed25519';

$comment = ($user['username'] ?? 'user') . '@proxmox-deploy';

$cmd = sprintf(
    'ssh-keygen -t ed25519 -f %s -N "" -C %s -q 2>&1',
    escapeshellarg($keyFile),
    escapeshellarg($comment)
);

exec($cmd, $output, $exitCode);

if ($exitCode !== 0 || !file_exists($keyFile) || !file_exists($keyFile . '.pub')) {
    // Cleanup
    array_map('unlink', glob($tmpDir . '/*'));
    rmdir($tmpDir);
    Response::error('Failed to generate SSH key: ' . implode("\n", $output), 500);
}

$privateKey = file_get_contents($keyFile);
$publicKey = trim(file_get_contents($keyFile . '.pub'));

// Cleanup temp files
unlink($keyFile);
unlink($keyFile . '.pub');
rmdir($tmpDir);

// Save public key to user profile (append to existing keys)
$db = Database::connection();
$stmt = $db->prepare('SELECT ssh_public_keys FROM users WHERE id = ?');
$stmt->execute([$user['id']]);
$existing = trim($stmt->fetchColumn() ?: '');

// Replace or append
if ($existing) {
    $newKeys = $existing . "\n" . $publicKey;
} else {
    $newKeys = $publicKey;
}

$stmt = $db->prepare('UPDATE users SET ssh_public_keys = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
$stmt->execute([$newKeys, $user['id']]);

AppLogger::info('security', 'SSH keypair generated', ['comment' => $comment], $user['id']);

Response::success([
    'private_key' => $privateKey,
    'public_key' => $publicKey,
]);
