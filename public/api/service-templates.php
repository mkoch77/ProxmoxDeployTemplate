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

// ── GET: list service templates ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = $db->query('SELECT * FROM service_templates ORDER BY name')
        ->fetchAll(\PDO::FETCH_ASSOC);
    Response::success($rows);
}

// ── POST: create, update, or delete ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Request::validateCsrf();
    $data = Request::jsonBody();
    $action = $data['action'] ?? 'save';

    // ── Delete ───────────────────────────────────────────────────────────────
    if ($action === 'delete') {
        $id = (int) ($data['id'] ?? 0);
        if ($id <= 0) {
            Response::error('Invalid template ID', 400);
        }

        // Look up name before deleting (needed to prevent re-seeding of builtins)
        $check = $db->prepare('SELECT name FROM service_templates WHERE id = ?');
        $check->execute([$id]);
        $name = $check->fetchColumn();

        $stmt = $db->prepare('DELETE FROM service_templates WHERE id = ?');
        $stmt->execute([$id]);

        if ($stmt->rowCount() === 0) {
            Response::error('Template not found', 404);
        }

        // Record deletion so Migrator::seed() won't re-insert this builtin
        if ($name) {
            try {
                $db->prepare('INSERT INTO deleted_builtins (name) VALUES (?) ON CONFLICT (name) DO NOTHING')
                    ->execute([$name]);
            } catch (\Exception $e) {
                // table might not exist yet — not critical
            }
        }

        Response::success(['deleted' => $id]);
    }

    // ── Create / Update ──────────────────────────────────────────────────────
    $name       = trim($data['name'] ?? '');
    $description = trim($data['description'] ?? '');
    $baseImage  = trim($data['base_image'] ?? '');
    $icon       = trim($data['icon'] ?? 'bi-box-seam');
    $color      = trim($data['color'] ?? '#6c757d');
    $cores      = (int) ($data['cores'] ?? 2);
    $memory     = (int) ($data['memory'] ?? 2048);
    $diskSize   = (int) ($data['disk_size'] ?? 10);
    $packages   = trim($data['packages'] ?? '');
    $runcmd     = trim($data['runcmd'] ?? '');
    $tags       = trim($data['tags'] ?? '');
    $id         = isset($data['id']) ? (int) $data['id'] : null;

    if ($name === '' || $baseImage === '') {
        Response::error('Name and base image are required', 400);
    }

    if (strlen($name) > 255) {
        Response::error('Name too long (max 255 characters)', 400);
    }

    // Validate base image exists in known cloud images or custom images
    // (loose check — just ensure it's not empty)

    if ($cores < 1 || $cores > 128) $cores = 2;
    if ($memory < 256 || $memory > 131072) $memory = 2048;
    if ($diskSize < 2 || $diskSize > 10000) $diskSize = 10;

    if ($id) {
        // Update existing
        $stmt = $db->prepare('UPDATE service_templates SET
            name = ?, description = ?, base_image = ?, icon = ?, color = ?,
            cores = ?, memory = ?, disk_size = ?, packages = ?, runcmd = ?,
            tags = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?');
        $stmt->execute([
            $name, $description, $baseImage, $icon, $color,
            $cores, $memory, $diskSize, $packages, $runcmd,
            $tags, $id,
        ]);
        Response::success(['id' => $id]);
    } else {
        // Create new
        $stmt = $db->prepare('INSERT INTO service_templates
            (name, description, base_image, icon, color, cores, memory, disk_size, packages, runcmd, tags, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $name, $description, $baseImage, $icon, $color,
            $cores, $memory, $diskSize, $packages, $runcmd,
            $tags, $user['id'],
        ]);
        Response::success(['id' => $db->lastInsertId()]);
    }
}
