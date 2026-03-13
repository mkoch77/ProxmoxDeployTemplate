<?php

namespace App;

class Config
{
    private static ?array $config = null;
    private static bool $vaultLoaded = false;

    public static function load(): void
    {
        $configFile = __DIR__ . '/../config/config.php';
        $defaults = file_exists($configFile) ? require $configFile : [];
        self::$config = $defaults;

        // .env file overrides config.php
        $envFile = __DIR__ . '/../.env';
        if (file_exists($envFile) && is_readable($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                $pos = strpos($line, '=');
                if ($pos === false) {
                    continue;
                }
                $key = trim(substr($line, 0, $pos));
                $value = trim(substr($line, $pos + 1));
                // Remove surrounding quotes
                if ((str_starts_with($value, '"') && str_ends_with($value, '"'))
                    || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                    $value = substr($value, 1, -1);
                }
                self::$config[$key] = $value;
            }
        }

        // Real environment variables override everything (Docker / system env support)
        foreach ($_ENV as $key => $value) {
            self::$config[$key] = $value;
        }
        foreach (getenv() ?: [] as $key => $value) {
            self::$config[$key] = $value;
        }
    }

    /**
     * Load vault secrets and overlay them on top of .env / env vars.
     * Called lazily on first get() that hits a vault-eligible key.
     */
    private static function loadVault(): void
    {
        if (self::$vaultLoaded) return;
        self::$vaultLoaded = true;

        try {
            if (!Vault::isAvailable()) return;

            $vaultData = Vault::getAll();
            foreach ($vaultData as $key => $value) {
                self::$config[$key] = $value;
            }
        } catch (\Exception $e) {
            // Vault unavailable — fall back to .env values silently
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (self::$config === null) {
            self::load();
        }

        // Lazy-load vault on first access (after DB is available)
        if (!self::$vaultLoaded && $key !== 'ENCRYPTION_KEY'
            && !str_starts_with($key, 'DB_')) {
            self::loadVault();
        }

        return self::$config[$key] ?? $default;
    }

    public static function sshEnabled(): bool
    {
        return filter_var(self::get('SSH_ENABLED', 'true'), FILTER_VALIDATE_BOOLEAN);
    }

    public static function requireSsh(): void
    {
        if (!self::sshEnabled()) {
            Response::error('This feature requires SSH to be enabled (SSH_ENABLED=true)', 403);
        }
    }

    /**
     * Force reload config and vault.
     */
    public static function reload(): void
    {
        self::$config = null;
        self::$vaultLoaded = false;
        Vault::clearCache();
    }
}
