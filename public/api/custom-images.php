<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\Auth;
use App\Request;
use App\Response;
use App\Database;
use App\AppLogger;

Bootstrap::init();
$user = Auth::requirePermission('template.deploy');
$db = Database::connection();

$imagesDir = realpath(__DIR__ . '/../../data/images');
if (!$imagesDir || !is_dir($imagesDir)) {
    @mkdir(__DIR__ . '/../../data/images', 0750, true);
    $imagesDir = realpath(__DIR__ . '/../../data/images');
}

// ── GET: list custom images ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = $db->query('SELECT * FROM custom_images ORDER BY name')->fetchAll(\PDO::FETCH_ASSOC);

    // Enrich with file info
    foreach ($rows as &$row) {
        $path = $imagesDir . '/' . $row['filename'];
        $row['file_exists'] = file_exists($path);
        $row['file_size'] = $row['file_exists'] ? filesize($path) : 0;
    }

    // Also list unregistered files in the images directory
    $registeredFiles = array_column($rows, 'filename');
    $unregistered = [];
    foreach (glob($imagesDir . '/*.{qcow2,img,raw,iso,vhd,vhdx}', GLOB_BRACE) as $file) {
        $basename = basename($file);
        if (!in_array($basename, $registeredFiles, true)) {
            $unregistered[] = [
                'filename'  => $basename,
                'file_size' => filesize($file),
            ];
        }
    }

    Response::success(['images' => $rows, 'unregistered' => $unregistered]);
}

// ── POST: register or upload ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Detect if PHP silently discarded POST data due to post_max_size being exceeded
    if (empty($_FILES) && empty($_POST) && isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > 0) {
        $maxSize = ini_get('post_max_size');
        Response::error("Upload failed: file exceeds server limit ({$maxSize}). Increase post_max_size and upload_max_filesize in PHP configuration.", 413);
    }

    Request::validateCsrf();

    // File upload
    if (!empty($_FILES['image_file'])) {
        $file = $_FILES['image_file'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors = [
                UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit',
                UPLOAD_ERR_FORM_SIZE  => 'File exceeds form upload limit',
                UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded',
                UPLOAD_ERR_NO_FILE    => 'No file was uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            ];
            AppLogger::error('deploy', 'Image upload failed', ['error_code' => $file['error'], 'filename' => $file['name'] ?? ''], $user['id']);
            Response::error($errors[$file['error']] ?? 'Upload error', 400);
        }

        // Sanitize filename
        $origName = preg_replace('/[^a-zA-Z0-9._\-]/', '_', basename($file['name']));
        if (!preg_match('/\.(qcow2|img|raw|iso|vhd|vhdx)$/i', $origName)) {
            Response::error('Unsupported file type. Allowed: qcow2, img, raw, iso, vhd, vhdx', 400);
        }

        $dest = $imagesDir . '/' . $origName;
        if (file_exists($dest)) {
            Response::error("File '{$origName}' already exists", 409);
        }

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            AppLogger::error('deploy', 'Failed to save uploaded image file', ['filename' => $origName], $user['id']);
            Response::error('Failed to save uploaded file', 500);
        }

        // Auto-register
        $name        = $_POST['name'] ?? pathinfo($origName, PATHINFO_FILENAME);
        $defaultUser = $_POST['default_user'] ?? 'user';
        $ostype      = $_POST['ostype'] ?? 'l26';

        $stmt = $db->prepare('INSERT INTO custom_images (name, filename, default_user, ostype, uploaded_by) VALUES (?, ?, ?, ?, ?) RETURNING id');
        $stmt->execute([$name, $origName, $defaultUser, $ostype, $user['id']]);
        $newId = $stmt->fetchColumn();

        AppLogger::info('deploy', 'Custom image uploaded and registered', ['id' => $newId, 'filename' => $origName, 'name' => $name], $user['id']);
        Response::success(['id' => $newId, 'filename' => $origName]);
    }

    // Register existing file (no upload)
    $body = Request::jsonBody();
    $filename    = basename($body['filename'] ?? '');
    $name        = trim($body['name'] ?? '');
    $defaultUser = trim($body['default_user'] ?? 'user');
    $ostype      = trim($body['ostype'] ?? 'l26');

    if (!$filename || !$name) {
        Response::error('Name and filename are required', 400);
    }

    if (!file_exists($imagesDir . '/' . $filename)) {
        Response::error("File '{$filename}' not found in images directory", 404);
    }

    // Upsert
    $existing = $db->prepare('SELECT id FROM custom_images WHERE filename = ?');
    $existing->execute([$filename]);
    if ($row = $existing->fetch()) {
        $stmt = $db->prepare('UPDATE custom_images SET name = ?, default_user = ?, ostype = ? WHERE id = ?');
        $stmt->execute([$name, $defaultUser, $ostype, $row['id']]);
        AppLogger::info('deploy', 'Custom image registration updated', ['id' => $row['id'], 'filename' => $filename, 'name' => $name], $user['id']);
        Response::success(['id' => $row['id'], 'updated' => true]);
    }

    $stmt = $db->prepare('INSERT INTO custom_images (name, filename, default_user, ostype, uploaded_by) VALUES (?, ?, ?, ?, ?) RETURNING id');
    $stmt->execute([$name, $filename, $defaultUser, $ostype, $user['id']]);
    $newId = $stmt->fetchColumn();
    AppLogger::info('deploy', 'Custom image registered', ['id' => $newId, 'filename' => $filename, 'name' => $name], $user['id']);
    Response::success(['id' => $newId]);
}

// ── DELETE: remove image ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) Response::error('Missing id', 400);

    $stmt = $db->prepare('SELECT * FROM custom_images WHERE id = ?');
    $stmt->execute([$id]);
    $image = $stmt->fetch();
    if (!$image) Response::error('Image not found', 404);

    // Delete file if requested
    if (isset($_GET['delete_file'])) {
        $path = $imagesDir . '/' . $image['filename'];
        if (file_exists($path)) unlink($path);
    }

    $stmt = $db->prepare('DELETE FROM custom_images WHERE id = ?');
    $stmt->execute([$id]);

    AppLogger::warning('deploy', 'Custom image deleted', ['id' => $id, 'filename' => $image['filename'], 'file_also_deleted' => isset($_GET['delete_file'])], $user['id']);
    Response::success(['deleted' => true]);
}

Response::error('Method not allowed', 405);
