<?php

namespace App;

use PDO;

/**
 * Plaintext key-value store for non-secret application settings.
 *
 * Settings like PROXMOX_HOST, SSH_PORT, DOMAIN etc. are configuration
 * values, not secrets. They are stored in plaintext in the app_settings
 * table and can be displayed/edited directly in the UI.
 */
class AppSettings
{
    private static ?array $cache = null;

    /**
     * Keys that belong in app_settings (not vault).
     * These are configuration values, not secrets.
     */
    public const SETTING_KEYS = [
        'PROXMOX_HOST',
        'PROXMOX_PORT',
        'PROXMOX_VERIFY_SSL',
        'PROXMOX_FALLBACK_HOSTS',
        'SSH_ENABLED',
        'SSH_PORT',
        'CLOUD_DISTROS',
        'DOMAIN',
        'LETSENCRYPT_EMAIL',
    ];

    /**
     * Metadata for UI display: label, description, type, default.
     */
    public const SETTING_META = [
        'PROXMOX_HOST' => [
            'label' => 'Proxmox Host',
            'description' => 'Hostname or IP of the Proxmox node/cluster',
            'group' => 'Proxmox',
            'type' => 'text',
            'placeholder' => '192.168.1.100',
            'default' => '',
        ],
        'PROXMOX_PORT' => [
            'label' => 'Proxmox Port',
            'description' => 'API port',
            'group' => 'Proxmox',
            'type' => 'number',
            'placeholder' => '8006',
            'default' => '8006',
        ],
        'PROXMOX_VERIFY_SSL' => [
            'label' => 'Verify SSL',
            'description' => 'Verify Proxmox SSL certificate',
            'group' => 'Proxmox',
            'type' => 'boolean',
            'default' => 'false',
        ],
        'PROXMOX_FALLBACK_HOSTS' => [
            'label' => 'Fallback Hosts',
            'description' => 'Comma-separated list of fallback Proxmox hosts',
            'group' => 'Proxmox',
            'type' => 'text',
            'placeholder' => '192.168.1.101,192.168.1.102',
            'default' => '',
        ],
        'SSH_ENABLED' => [
            'label' => 'SSH Enabled',
            'description' => 'Enable SSH features (key deployment, updates, terminal)',
            'group' => 'SSH',
            'type' => 'boolean',
            'default' => 'true',
        ],
        'SSH_PORT' => [
            'label' => 'SSH Port',
            'description' => 'SSH port for node connections',
            'group' => 'SSH',
            'type' => 'number',
            'placeholder' => '22',
            'default' => '22',
        ],
        'CLOUD_DISTROS' => [
            'label' => 'Cloud-Init Distros',
            'description' => 'Comma-separated list of enabled cloud-init distributions',
            'group' => 'Application',
            'type' => 'text',
            'placeholder' => 'ubuntu,debian,rocky,alma',
            'default' => '',
        ],
        'DOMAIN' => [
            'label' => 'Domain',
            'description' => 'Application domain for SSL/access',
            'group' => 'Application',
            'type' => 'text',
            'placeholder' => 'proxmox-deploy.example.com',
            'default' => '',
        ],
        'LETSENCRYPT_EMAIL' => [
            'label' => "Let's Encrypt Email",
            'description' => 'Email for SSL certificate notifications',
            'group' => 'Application',
            'type' => 'email',
            'placeholder' => 'admin@example.com',
            'default' => '',
        ],
    ];

    public static function get(string $key): ?string
    {
        $all = self::getAll();
        return $all[$key] ?? null;
    }

    public static function getAll(): array
    {
        if (self::$cache !== null) return self::$cache;

        try {
            $db = Database::connection();
            $rows = $db->query('SELECT key, value FROM app_settings')->fetchAll(PDO::FETCH_KEY_PAIR);
            self::$cache = $rows;
            return $rows;
        } catch (\Exception $e) {
            self::$cache = [];
            return [];
        }
    }

    public static function set(string $key, string $value): void
    {
        $db = Database::connection();
        $stmt = $db->prepare('INSERT INTO app_settings (key, value, updated_at)
            VALUES (?, ?, CURRENT_TIMESTAMP)
            ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = CURRENT_TIMESTAMP');
        $stmt->execute([$key, $value]);
        self::$cache = null;
    }

    public static function setMany(array $entries): void
    {
        $db = Database::connection();
        $stmt = $db->prepare('INSERT INTO app_settings (key, value, updated_at)
            VALUES (?, ?, CURRENT_TIMESTAMP)
            ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = CURRENT_TIMESTAMP');

        $db->beginTransaction();
        try {
            foreach ($entries as $key => $value) {
                if (!in_array($key, self::SETTING_KEYS, true)) continue;
                $stmt->execute([$key, (string)$value]);
            }
            $db->commit();
        } catch (\Exception $e) {
            $db->rollBack();
            throw $e;
        }
        self::$cache = null;
    }

    public static function delete(string $key): void
    {
        $db = Database::connection();
        $db->prepare('DELETE FROM app_settings WHERE key = ?')->execute([$key]);
        self::$cache = null;
    }

    /**
     * List all settings with metadata for UI display.
     */
    public static function listAll(): array
    {
        $stored = self::getAll();
        $timestamps = [];
        try {
            $db = Database::connection();
            $rows = $db->query('SELECT key, updated_at FROM app_settings')->fetchAll(PDO::FETCH_KEY_PAIR);
            $timestamps = $rows;
        } catch (\Exception $e) {}

        $result = [];
        foreach (self::SETTING_KEYS as $key) {
            $meta = self::SETTING_META[$key] ?? [];
            $defaultVal = $meta['default'] ?? '';
            $inDb = isset($stored[$key]);
            $envVal = self::getEnvValue($key);
            $effectiveValue = $stored[$key] ?? $envVal ?? $defaultVal;

            $source = 'default';
            if ($inDb) {
                $source = 'database';
            } elseif ($envVal !== null) {
                $source = 'env';
            }

            $result[] = [
                'key' => $key,
                'value' => $effectiveValue,
                'default' => $defaultVal,
                'in_db' => $inDb,
                'source' => $source,
                'updated_at' => $timestamps[$key] ?? null,
                'label' => $meta['label'] ?? $key,
                'description' => $meta['description'] ?? '',
                'group' => $meta['group'] ?? 'Other',
                'type' => $meta['type'] ?? 'text',
                'placeholder' => $meta['placeholder'] ?? '',
            ];
        }
        return $result;
    }

    /**
     * Get value from .env / environment (not from app_settings).
     */
    private static function getEnvValue(string $key): ?string
    {
        // Check real env vars
        $val = getenv($key);
        if ($val !== false && $val !== '') return $val;
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') return $_ENV[$key];

        // Check .env file
        $envFile = __DIR__ . '/../.env';
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) continue;
                $pos = strpos($line, '=');
                if ($pos === false) continue;
                $k = trim(substr($line, 0, $pos));
                if ($k === $key) {
                    $v = trim(substr($line, $pos + 1));
                    if ((str_starts_with($v, '"') && str_ends_with($v, '"'))
                        || (str_starts_with($v, "'") && str_ends_with($v, "'"))) {
                        $v = substr($v, 1, -1);
                    }
                    return $v !== '' ? $v : null;
                }
            }
        }
        return null;
    }

    /**
     * Migrate settings from vault/env to app_settings table.
     * Moves non-secret values out of the vault into plaintext app_settings.
     */
    public static function migrateFromVaultAndEnv(): int
    {
        $stored = self::getAll();
        $count = 0;

        foreach (self::SETTING_KEYS as $key) {
            if (isset($stored[$key])) continue;

            // Try vault first
            $val = null;
            try {
                $val = Vault::get($key);
            } catch (\Exception $e) {}

            // Fall back to env
            if (empty($val)) {
                $val = self::getEnvValue($key);
            }

            if ($val !== null && $val !== '') {
                self::set($key, $val);
                // Remove from vault if it was there (it's not a secret)
                try {
                    Vault::delete($key);
                } catch (\Exception $e) {}
                $count++;
            }
        }

        return $count;
    }

    public static function clearCache(): void
    {
        self::$cache = null;
    }
}
