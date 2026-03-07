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

// ── GET: list Windows images ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = $db->query('SELECT * FROM windows_images ORDER BY name')->fetchAll(\PDO::FETCH_ASSOC);
    Response::success(['images' => $rows]);
}

// ── POST: register a Windows image ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Request::validateCsrf();

    $body = Request::jsonBody();
    $name = trim($body['name'] ?? '');
    $isoFilename = trim($body['iso_filename'] ?? '');
    $autounattendXml = trim($body['autounattend_xml'] ?? '') ?: null;
    $productKey = trim($body['product_key'] ?? '') ?: null;
    $installGuestTools = ($body['install_guest_tools'] ?? true) ? true : false;
    $notes = trim($body['notes'] ?? '') ?: null;

    if (!$name) Response::error('Name is required', 400);
    if (!$isoFilename) Response::error('ISO filename is required', 400);

    $id = $body['id'] ?? null;

    if ($id) {
        // Update existing
        $stmt = $db->prepare('UPDATE windows_images SET name = ?, iso_filename = ?, autounattend_xml = ?, product_key = ?, install_guest_tools = ?, notes = ? WHERE id = ?');
        $stmt->execute([$name, $isoFilename, $autounattendXml, $productKey, $installGuestTools, $notes, (int)$id]);
        AppLogger::info('deploy', 'Windows image updated', ['id' => (int)$id, 'name' => $name, 'iso' => $isoFilename], $user['id']);
        Response::success(['id' => (int)$id, 'updated' => true]);
    }

    $stmt = $db->prepare('INSERT INTO windows_images (name, iso_filename, autounattend_xml, product_key, install_guest_tools, notes, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING id');
    $stmt->execute([$name, $isoFilename, $autounattendXml, $productKey, $installGuestTools, $notes, $user['id']]);
    $newId = $stmt->fetchColumn();
    AppLogger::info('deploy', 'Windows image registered', ['id' => $newId, 'name' => $name, 'iso' => $isoFilename], $user['id']);
    Response::success(['id' => $newId]);
}

// ── DELETE ───────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    Request::validateCsrf();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) Response::error('Missing id', 400);

    $stmt = $db->prepare('DELETE FROM windows_images WHERE id = ?');
    $stmt->execute([$id]);
    AppLogger::warning('deploy', 'Windows image deleted', ['id' => $id], $user['id']);
    Response::success(['deleted' => true]);
}

Response::error('Method not allowed', 405);
