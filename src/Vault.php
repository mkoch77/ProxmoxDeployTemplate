<?php

namespace App;

use PDO;

/**
 * Encrypted key-value store for secrets.
 *
 * Uses AES-256-GCM with the ENCRYPTION_KEY from .env as master key.
 * All secrets (Proxmox tokens, SSH passwords, EntraID, etc.) are stored
 * encrypted in the `vault` database table so only the master key remains
 * in the .env file.
 */
class Vault
{
    private const CIPHER = 'aes-256-gcm';
    private const TAG_LENGTH = 16;

    private static ?array $cache = null;

    /**
     * Keys that should be stored in the vault (moved from .env).
     */
    public const VAULT_KEYS = [
        'PROXMOX_HOST',
        'PROXMOX_PORT',
        'PROXMOX_VERIFY_SSL',
        'PROXMOX_FALLBACK_HOSTS',
        'PROXMOX_TOKEN_ID',
        'PROXMOX_TOKEN_SECRET',
        'APP_SECRET',
        'SSH_ENABLED',
        'SSH_PORT',
        'SSH_USER',
        'SSH_KEY_PATH',
        'SSH_PRIVATE_KEY',
        'SSH_PASSWORD',
        'ENTRAID_TENANT_ID',
        'ENTRAID_CLIENT_ID',
        'ENTRAID_CLIENT_SECRET',
        'ENTRAID_REDIRECT_URI',
        'CLOUD_DISTROS',
        'DOMAIN',
        'LETSENCRYPT_EMAIL',
    ];

    /**
     * Derive a 256-bit encryption key from the master key.
     */
    private static function deriveKey(): string
    {
        $master = self::getMasterKey();
        // HKDF with SHA-256 to get exactly 32 bytes
        return hash_hkdf('sha256', $master, 32, 'vault-encryption', 'proxmox-deploy');
    }

    /**
     * Get master key from environment or Docker secret file.
     * Resolution order: ENCRYPTION_KEY_FILE → ENCRYPTION_KEY env var → .env
     */
    private static function getMasterKey(): string
    {
        // 1. Docker secret file — entrypoint copies to /tmp for www-data access
        foreach ([
            getenv('ENCRYPTION_KEY_FILE') ?: ($_ENV['ENCRYPTION_KEY_FILE'] ?? ''),
            '/tmp/encryption_key',
            '/run/secrets/encryption_key',
        ] as $file) {
            if (!empty($file) && is_readable($file)) {
                $key = trim(file_get_contents($file));
                if (!empty($key)) return $key;
            }
        }

        // 2. Real env vars, then .env via Config
        $key = getenv('ENCRYPTION_KEY') ?: ($_ENV['ENCRYPTION_KEY'] ?? '');
        if (empty($key)) {
            $key = Config::get('ENCRYPTION_KEY', '');
        }
        if (empty($key)) {
            throw new \RuntimeException('ENCRYPTION_KEY is not set. Add it to .env or use Docker secrets.');
        }
        return $key;
    }

    /**
     * Check whether the vault is available (key set + table exists).
     */
    public static function isAvailable(): bool
    {
        try {
            // Check Docker secret files first, then env vars
            $master = '';
            foreach ([
                getenv('ENCRYPTION_KEY_FILE') ?: ($_ENV['ENCRYPTION_KEY_FILE'] ?? ''),
                '/tmp/encryption_key',
                '/run/secrets/encryption_key',
            ] as $f) {
                if (!empty($f) && is_readable($f)) {
                    $master = trim(file_get_contents($f));
                    if (!empty($master)) break;
                }
            }
            if (empty($master)) {
                $master = getenv('ENCRYPTION_KEY') ?: ($_ENV['ENCRYPTION_KEY'] ?? '');
            }
            if (empty($master)) {
                $master = Config::get('ENCRYPTION_KEY', '');
            }
            if (empty($master)) return false;

            $db = Database::connection();
            $db->query('SELECT 1 FROM vault LIMIT 1');
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Encrypt a plaintext value.
     * Returns base64(iv + tag + ciphertext).
     */
    public static function encrypt(string $plaintext): string
    {
        $key = self::deriveKey();
        $iv = random_bytes(12); // 96-bit nonce for GCM
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed: ' . openssl_error_string());
        }

        // Pack: iv (12) + tag (16) + ciphertext
        return base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Decrypt a vault value.
     */
    public static function decrypt(string $encoded): string
    {
        $key = self::deriveKey();
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 12 + self::TAG_LENGTH + 1) {
            throw new \RuntimeException('Invalid vault data');
        }

        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, self::TAG_LENGTH);
        $ciphertext = substr($raw, 12 + self::TAG_LENGTH);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            throw new \RuntimeException('Decryption failed — wrong ENCRYPTION_KEY?');
        }

        return $plaintext;
    }

    /**
     * Get a single value from the vault.
     */
    public static function get(string $key): ?string
    {
        $all = self::getAll();
        return $all[$key] ?? null;
    }

    /**
     * Get all decrypted vault entries as key => value.
     */
    public static function getAll(): array
    {
        if (self::$cache !== null) return self::$cache;

        try {
            $db = Database::connection();
            $rows = $db->query('SELECT key, encrypted_value FROM vault')->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            self::$cache = [];
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            try {
                $result[$row['key']] = self::decrypt($row['encrypted_value']);
            } catch (\Exception $e) {
                // Skip entries that can't be decrypted (wrong key?)
                AppLogger::warning('vault', "Failed to decrypt vault key '{$row['key']}': " . $e->getMessage());
            }
        }

        self::$cache = $result;
        return $result;
    }

    /**
     * Set (insert or update) a vault entry.
     */
    public static function set(string $key, string $value): void
    {
        $db = Database::connection();
        $encrypted = self::encrypt($value);

        $stmt = $db->prepare('INSERT INTO vault (key, encrypted_value, updated_at)
            VALUES (?, ?, CURRENT_TIMESTAMP)
            ON CONFLICT (key) DO UPDATE SET encrypted_value = EXCLUDED.encrypted_value, updated_at = CURRENT_TIMESTAMP');
        $stmt->execute([$key, $encrypted]);

        // Invalidate cache
        self::$cache = null;
    }

    /**
     * Set multiple vault entries at once.
     */
    public static function setMany(array $entries): void
    {
        $db = Database::connection();
        $stmt = $db->prepare('INSERT INTO vault (key, encrypted_value, updated_at)
            VALUES (?, ?, CURRENT_TIMESTAMP)
            ON CONFLICT (key) DO UPDATE SET encrypted_value = EXCLUDED.encrypted_value, updated_at = CURRENT_TIMESTAMP');

        $db->beginTransaction();
        try {
            foreach ($entries as $key => $value) {
                if ($value === '' || $value === null) continue;
                $stmt->execute([$key, self::encrypt($value)]);
            }
            $db->commit();
        } catch (\Exception $e) {
            $db->rollBack();
            throw $e;
        }

        self::$cache = null;
    }

    /**
     * Delete a vault entry.
     */
    public static function delete(string $key): void
    {
        $db = Database::connection();
        $stmt = $db->prepare('DELETE FROM vault WHERE key = ?');
        $stmt->execute([$key]);
        self::$cache = null;
    }

    /**
     * List all vault keys (without decrypting values).
     */
    public static function listKeys(): array
    {
        try {
            $db = Database::connection();
            return $db->query('SELECT key, updated_at FROM vault ORDER BY key')
                ->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Migrate secrets from .env / Config into the vault.
     * Only migrates non-empty values that aren't already in the vault.
     * Returns count of migrated entries.
     */
    public static function migrateFromEnv(): int
    {
        if (!self::isAvailable()) return 0;

        $existing = array_column(self::listKeys(), 'key');
        $toMigrate = [];

        foreach (self::VAULT_KEYS as $key) {
            if (in_array($key, $existing, true)) continue;
            $value = Config::get($key, '');
            if ($value !== '' && $value !== null) {
                $toMigrate[$key] = (string)$value;
            }
        }

        if (empty($toMigrate)) return 0;

        self::setMany($toMigrate);
        return count($toMigrate);
    }

    /**
     * Re-encrypt all vault entries with a new master key.
     * Call this AFTER updating ENCRYPTION_KEY in .env.
     */
    public static function reEncryptAll(string $oldMasterKey): int
    {
        $db = Database::connection();
        $rows = $db->query('SELECT key, encrypted_value FROM vault')->fetchAll(PDO::FETCH_ASSOC);

        // Decrypt with old key
        $oldDerived = hash_hkdf('sha256', $oldMasterKey, 32, 'vault-encryption', 'proxmox-deploy');
        $decrypted = [];
        foreach ($rows as $row) {
            $raw = base64_decode($row['encrypted_value'], true);
            $iv = substr($raw, 0, 12);
            $tag = substr($raw, 12, self::TAG_LENGTH);
            $ciphertext = substr($raw, 12 + self::TAG_LENGTH);
            $plain = openssl_decrypt($ciphertext, self::CIPHER, $oldDerived, OPENSSL_RAW_DATA, $iv, $tag);
            if ($plain !== false) {
                $decrypted[$row['key']] = $plain;
            }
        }

        // Re-encrypt with new (current) key
        $stmt = $db->prepare('UPDATE vault SET encrypted_value = ?, updated_at = CURRENT_TIMESTAMP WHERE key = ?');
        $count = 0;
        foreach ($decrypted as $key => $value) {
            $stmt->execute([self::encrypt($value), $key]);
            $count++;
        }

        self::$cache = null;
        return $count;
    }

    /**
     * Clear the in-memory cache.
     */
    public static function clearCache(): void
    {
        self::$cache = null;
    }
}
