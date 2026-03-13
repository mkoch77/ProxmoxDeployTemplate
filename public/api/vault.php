<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\Auth;
use App\Request;
use App\Response;
use App\Vault;
use App\Config;
use App\AppLogger;

Bootstrap::init();

$method = $_SERVER['REQUEST_METHOD'];

// ── GET: list vault keys + status ────────────────────────────────────────────
if ($method === 'GET') {
    $user = Auth::requirePermission('users.manage');

    $available = Vault::isAvailable();
    $keys = $available ? Vault::listKeys() : [];

    // Show which vault-eligible keys are set
    $entries = [];
    $vaultData = $available ? Vault::getAll() : [];
    foreach (Vault::VAULT_KEYS as $k) {
        $inVault = isset($vaultData[$k]);
        $inEnv = Config::get($k, '') !== '';
        $entries[] = [
            'key' => $k,
            'in_vault' => $inVault,
            'in_env' => $inEnv,
            'has_value' => $inVault || $inEnv,
            'updated_at' => null,
        ];
    }
    // Add updated_at from DB
    $keyDates = array_column($keys, 'updated_at', 'key');
    foreach ($entries as &$e) {
        $e['updated_at'] = $keyDates[$e['key']] ?? null;
    }
    unset($e);

    Response::success([
        'available' => $available,
        'entries' => $entries,
        'total_in_vault' => count(array_filter($entries, fn($e) => $e['in_vault'])),
    ]);
}

// ── POST: save secrets to vault ──────────────────────────────────────────────
if ($method === 'POST') {
    Request::validateCsrf();
    $user = Auth::requirePermission('users.manage');

    if (!Vault::isAvailable()) {
        Response::error('Vault not available — set ENCRYPTION_KEY in .env first', 400);
    }

    $body = Request::jsonBody();
    $action = $body['action'] ?? 'save';

    // Action: migrate all from .env into vault
    if ($action === 'migrate') {
        $count = Vault::migrateFromEnv();
        Config::reload();
        AppLogger::info('vault', "Migrated {$count} secrets from .env to vault", null, $user['id']);
        Response::success(['migrated' => $count, 'message' => "{$count} secrets migrated to vault"]);
    }

    // Action: save individual secrets
    if ($action === 'save') {
        $secrets = $body['secrets'] ?? [];
        if (!is_array($secrets) || empty($secrets)) {
            Response::error('No secrets provided', 400);
        }

        // Validate keys
        $allowed = Vault::VAULT_KEYS;
        $toSave = [];
        foreach ($secrets as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                Response::error("Key '{$key}' is not a vault-eligible secret", 400);
            }
            if ($value !== null && $value !== '') {
                $toSave[$key] = (string)$value;
            }
        }

        if (empty($toSave)) {
            Response::error('No non-empty secrets to save', 400);
        }

        Vault::setMany($toSave);
        Config::reload();

        $keyNames = array_keys($toSave);
        AppLogger::info('vault', 'Vault secrets updated', ['keys' => $keyNames], $user['id']);
        Response::success(['saved' => count($toSave), 'keys' => $keyNames]);
    }

    // Action: delete a key from vault
    if ($action === 'delete') {
        $key = $body['key'] ?? '';
        if (!$key || !in_array($key, Vault::VAULT_KEYS, true)) {
            Response::error('Invalid key', 400);
        }
        Vault::delete($key);
        Config::reload();
        AppLogger::info('vault', "Vault key deleted: {$key}", null, $user['id']);
        Response::success(['deleted' => $key]);
    }

    Response::error('Unknown action', 400);
}

Response::error('Method not allowed', 405);
