<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\Auth;
use App\Request;
use App\Response;
use App\AppSettings;
use App\Config;
use App\AppLogger;

Bootstrap::init();

$method = $_SERVER['REQUEST_METHOD'];

// ── GET: list all settings with metadata ─────────────────────────────────────
if ($method === 'GET') {
    $user = Auth::requirePermission('settings.manage');

    $entries = AppSettings::listAll();
    Response::success([
        'entries' => $entries,
    ]);
}

// ── POST: save or migrate settings ───────────────────────────────────────────
if ($method === 'POST') {
    Request::validateCsrf();
    $user = Auth::requirePermission('settings.manage');

    $body = Request::jsonBody();
    $action = $body['action'] ?? 'save';

    if ($action === 'migrate') {
        $count = AppSettings::migrateFromVaultAndEnv();
        Config::reload();
        AppLogger::info('settings', "Migrated {$count} settings from vault/env to app_settings", null, $user['id']);
        Response::success(['migrated' => $count, 'message' => "{$count} settings migrated"]);
    }

    if ($action === 'save') {
        $settings = $body['settings'] ?? [];
        if (!is_array($settings) || empty($settings)) {
            Response::error('No settings provided', 400);
        }

        $allowed = AppSettings::SETTING_KEYS;
        $toSave = [];
        foreach ($settings as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                Response::error("Key '{$key}' is not a valid setting", 400);
            }
            $toSave[$key] = (string)($value ?? '');
        }

        AppSettings::setMany($toSave);
        Config::reload();

        AppLogger::info('settings', 'Settings updated', ['keys' => array_keys($toSave)], $user['id']);
        Response::success(['saved' => count($toSave), 'keys' => array_keys($toSave)]);
    }

    if ($action === 'delete') {
        $key = $body['key'] ?? '';
        if (!$key || !in_array($key, AppSettings::SETTING_KEYS, true)) {
            Response::error('Invalid key', 400);
        }
        AppSettings::delete($key);
        Config::reload();
        AppLogger::info('settings', "Setting deleted: {$key}", null, $user['id']);
        Response::success(['deleted' => $key]);
    }

    Response::error('Unknown action', 400);
}

Response::error('Method not allowed', 405);
