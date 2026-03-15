<?php

namespace App;

use PDO;
use phpseclib3\Net\SFTP;
use phpseclib3\Crypt\PublicKeyLoader;

class BackupManager
{
    private const BACKUP_DIR = __DIR__ . '/../data/backups';
    private const CIPHER = 'aes-256-gcm';

    public static function ensureBackupDir(): string
    {
        $dir = self::BACKUP_DIR;
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        return $dir;
    }

    // ── Configuration ───────────────────────────────────────────────

    public static function getConfig(): array
    {
        $db = Database::connection();
        $row = $db->query('SELECT * FROM backup_config WHERE id = 1')->fetch();
        if (!$row) {
            return [
                'remote_enabled' => false,
                'remote_host' => '',
                'remote_port' => 22,
                'remote_user' => '',
                'remote_path' => '/backups',
                'backup_time' => '02:00',
                'backup_encrypted' => true,
                'auto_backup_enabled' => false,
                'backup_retention' => 30,
            ];
        }
        $row['remote_enabled'] = (bool)($row['remote_enabled'] ?? false);
        $row['remote_port'] = (int)($row['remote_port'] ?? 22);
        $row['backup_encrypted'] = (bool)($row['backup_encrypted'] ?? true);
        $row['auto_backup_enabled'] = (bool)($row['auto_backup_enabled'] ?? false);
        $row['backup_retention'] = (int)($row['backup_retention'] ?? 30);
        $row['backup_time'] = $row['backup_time'] ?? '02:00';
        // Check if remote credentials exist in vault
        $row['has_password'] = !empty(Vault::get('BACKUP_REMOTE_PASSWORD'));
        $row['has_key'] = !empty(Vault::get('BACKUP_REMOTE_KEY'));
        $row['has_encryption_key'] = self::isValidKey(self::getEncryptionKey());
        unset($row['id']);
        return $row;
    }

    public static function saveConfig(array $data): void
    {
        $db = Database::connection();

        // Build SET clause dynamically to handle missing columns gracefully
        $fields = [
            'remote_enabled' => !empty($data['remote_enabled']) ? 1 : 0,
            'remote_host' => $data['remote_host'] ?? '',
            'remote_port' => (int)($data['remote_port'] ?? 22),
            'remote_user' => $data['remote_user'] ?? '',
            'remote_path' => $data['remote_path'] ?? '/backups',
            'backup_time' => $data['backup_time'] ?? '02:00',
            'backup_encrypted' => !empty($data['backup_encrypted']) ? 1 : 0,
            'auto_backup_enabled' => !empty($data['auto_backup_enabled']) ? 1 : 0,
            'backup_retention' => max(1, (int)($data['backup_retention'] ?? 30)),
        ];

        // Check which columns actually exist
        $cols = $db->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'backup_config'")->fetchAll(\PDO::FETCH_COLUMN);
        $sets = [];
        $values = [];
        foreach ($fields as $col => $val) {
            if (in_array($col, $cols, true)) {
                $sets[] = "{$col} = ?";
                $values[] = $val;
            }
        }
        $sets[] = 'updated_at = CURRENT_TIMESTAMP';
        $values[] = 1;

        $stmt = $db->prepare('UPDATE backup_config SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $stmt->execute($values);

        // Store credentials in vault
        if (isset($data['remote_password']) && $data['remote_password'] !== '') {
            Vault::set('BACKUP_REMOTE_PASSWORD', $data['remote_password']);
        }
        if (isset($data['remote_key']) && $data['remote_key'] !== '') {
            Vault::set('BACKUP_REMOTE_KEY', $data['remote_key']);
        }
    }

    // ── Encryption ──────────────────────────────────────────────────

    /**
     * Get the backup encryption key from vault.
     * Returns the raw string (hex) as stored.
     */
    public static function getEncryptionKey(): string
    {
        return Vault::get('BACKUP_ENCRYPTION_KEY') ?? '';
    }

    /**
     * Check if a hex key is a valid 256-bit key.
     */
    private static function isValidKey(string $hexKey): bool
    {
        if (strlen($hexKey) !== 64) return false;
        return @hex2bin($hexKey) !== false;
    }

    /**
     * Ensure a valid encryption key exists. Auto-generates if missing or invalid.
     * Used before creating backups.
     */
    public static function ensureEncryptionKey(): string
    {
        $key = self::getEncryptionKey();
        if (!self::isValidKey($key)) {
            $key = bin2hex(random_bytes(32));
            Vault::set('BACKUP_ENCRYPTION_KEY', $key);
            AppLogger::info('backup', 'Auto-generated backup encryption key');
        }
        return $key;
    }

    /**
     * Derive a binary key for encrypt/decrypt. Used for creating backups.
     * Auto-generates a new key if the current one is invalid.
     */
    private static function deriveFileKeyForCreate(): string
    {
        $hexKey = self::ensureEncryptionKey();
        return hex2bin($hexKey);
    }

    /**
     * Derive a binary key for decryption. Fails if key is invalid.
     */
    private static function deriveFileKeyForRestore(): string
    {
        $hexKey = self::getEncryptionKey();
        if (!self::isValidKey($hexKey)) {
            throw new \RuntimeException('Backup encryption key is missing or invalid — cannot decrypt backup');
        }
        return hex2bin($hexKey);
    }

    /**
     * Encrypt a file using AES-256-GCM. Returns path to encrypted file.
     */
    private static function encryptFile(string $inputPath): string
    {
        $key = self::deriveFileKeyForCreate();
        $iv = random_bytes(12);
        $tag = '';

        $plaintext = file_get_contents($inputPath);
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($ciphertext === false) {
            throw new \RuntimeException('Backup encryption failed');
        }

        $outputPath = $inputPath . '.enc';
        // Format: iv (12) + tag (16) + ciphertext
        file_put_contents($outputPath, $iv . $tag . $ciphertext);
        unlink($inputPath);
        return $outputPath;
    }

    /**
     * Decrypt a .enc file back to .tar.gz. Returns path to decrypted file.
     */
    private static function decryptFile(string $inputPath): string
    {
        $key = self::deriveFileKeyForRestore();
        $raw = file_get_contents($inputPath);

        if (strlen($raw) < 29) {
            throw new \RuntimeException('Encrypted backup file is corrupted');
        }

        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ciphertext = substr($raw, 28);

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plaintext === false) {
            throw new \RuntimeException('Backup decryption failed — wrong encryption key?');
        }

        $outputPath = preg_replace('/\.enc$/', '', $inputPath);
        if ($outputPath === $inputPath) {
            $outputPath = $inputPath . '.tar.gz';
        }
        file_put_contents($outputPath, $plaintext);
        return $outputPath;
    }

    // ── Create Backup ───────────────────────────────────────────────

    public static function createBackup(?int $userId = null): array
    {
        $dir = self::ensureBackupDir();
        $timestamp = date('Ymd_His');
        $config = self::getConfig();
        $encrypted = $config['backup_encrypted'];
        $baseFilename = "backup_{$timestamp}.tar.gz";
        $tmpDir = sys_get_temp_dir() . '/pdt_backup_' . $timestamp;

        try {
            mkdir($tmpDir, 0750, true);

            // 1. Database dump (includes all tables: monitoring data, settings, etc.)
            $db = Database::credentials();

            $dumpFile = $tmpDir . '/database.sql';
            $cmd = sprintf(
                'PGPASSWORD=%s pg_dump -h %s -p %s -U %s -Fc %s > %s 2>&1',
                escapeshellarg($db['pass']),
                escapeshellarg($db['host']),
                escapeshellarg($db['port']),
                escapeshellarg($db['user']),
                escapeshellarg($db['name']),
                escapeshellarg($dumpFile)
            );
            exec($cmd, $output, $exitCode);
            if ($exitCode !== 0) {
                throw new \RuntimeException('pg_dump failed: ' . implode("\n", $output));
            }

            // 2. Vault export (already encrypted in DB, export the raw encrypted values)
            $vaultData = [];
            try {
                $db = Database::connection();
                $rows = $db->query('SELECT key, encrypted_value FROM vault')->fetchAll(PDO::FETCH_ASSOC);
                $vaultData = $rows;
            } catch (\Exception $e) {}
            file_put_contents($tmpDir . '/vault.json', json_encode($vaultData, JSON_PRETTY_PRINT));

            // 3. Copy .env if exists
            $envFile = __DIR__ . '/../.env';
            if (file_exists($envFile)) {
                copy($envFile, $tmpDir . '/.env');
            }

            // 4. Backup metadata
            file_put_contents($tmpDir . '/metadata.json', json_encode([
                'created_at' => date('c'),
                'version' => '1.1',
                'app' => 'ProxmoxDeployTemplate',
                'encrypted' => $encrypted,
                'includes' => ['database', 'vault', 'env', 'monitoring_data'],
            ], JSON_PRETTY_PRINT));

            // Create tar.gz
            $archivePath = $dir . '/' . $baseFilename;
            $tarCmd = sprintf(
                'tar -czf %s -C %s .',
                escapeshellarg($archivePath),
                escapeshellarg($tmpDir)
            );
            exec($tarCmd, $tarOutput, $tarExit);
            if ($tarExit !== 0) {
                throw new \RuntimeException('tar failed: ' . implode("\n", $tarOutput));
            }

            // Encrypt if enabled
            $filename = $baseFilename;
            if ($encrypted) {
                $encPath = self::encryptFile($archivePath);
                $filename = basename($encPath);
                $archivePath = $encPath;
            }

            $size = filesize($archivePath);

            // Record in history
            $db = Database::connection();
            $stmt = $db->prepare('INSERT INTO backup_history (filename, location, size_bytes, status, created_by) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$filename, 'local', $size, 'completed', $userId]);

            AppLogger::info('backup', "Backup created: {$filename} ({$size} bytes)", [], $userId);

            // Auto-upload to remote if enabled
            $remoteUploaded = false;
            if ($config['remote_enabled']) {
                try {
                    self::uploadToRemote($filename);
                    $remoteUploaded = true;
                } catch (\Exception $e) {
                    AppLogger::warning('backup', 'Auto-upload to remote failed: ' . $e->getMessage());
                }
            }

            return [
                'filename' => $filename,
                'size' => $size,
                'location' => 'local',
                'remote_uploaded' => $remoteUploaded,
            ];
        } finally {
            // Cleanup temp dir
            if (is_dir($tmpDir)) {
                exec('rm -rf ' . escapeshellarg($tmpDir));
            }
        }
    }

    // ── Restore Backup ──────────────────────────────────────────────

    /**
     * Validate that an encrypted backup can be decrypted with the current key.
     */
    public static function validateBackup(string $filename): array
    {
        $dir = self::ensureBackupDir();
        $archivePath = $dir . '/' . basename($filename);

        if (!file_exists($archivePath)) {
            throw new \RuntimeException('Backup file not found: ' . $filename);
        }

        $encrypted = str_ends_with($archivePath, '.enc');
        if (!$encrypted) {
            return ['valid' => true, 'encrypted' => false];
        }

        // Try decrypting just the first bytes to validate the key
        $key = self::deriveFileKeyForRestore();
        $raw = file_get_contents($archivePath);
        if (strlen($raw) < 29) {
            throw new \RuntimeException('Encrypted backup file is corrupted');
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ciphertext = substr($raw, 28);
        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plaintext === false) {
            throw new \RuntimeException('Backup decryption failed — the current encryption key does not match this backup');
        }

        return ['valid' => true, 'encrypted' => true];
    }

    public static function restoreBackup(string $filename): array
    {
        $dir = self::ensureBackupDir();
        $archivePath = $dir . '/' . basename($filename);

        if (!file_exists($archivePath)) {
            throw new \RuntimeException('Backup file not found: ' . $filename);
        }

        $tmpDir = sys_get_temp_dir() . '/pdt_restore_' . time();
        mkdir($tmpDir, 0750, true);
        $decryptedTmp = null;

        try {
            // Decrypt if encrypted (.enc extension)
            $tarPath = $archivePath;
            if (str_ends_with($archivePath, '.enc')) {
                $decryptedTmp = sys_get_temp_dir() . '/pdt_dec_' . time() . '.tar.gz';
                // Decrypt to temp location (don't modify backup dir)
                $key = self::deriveFileKeyForRestore();
                $raw = file_get_contents($archivePath);
                if (strlen($raw) < 29) {
                    throw new \RuntimeException('Encrypted backup file is corrupted');
                }
                $iv = substr($raw, 0, 12);
                $tag = substr($raw, 12, 16);
                $ciphertext = substr($raw, 28);
                $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
                if ($plaintext === false) {
                    throw new \RuntimeException('Backup decryption failed — wrong encryption key?');
                }
                file_put_contents($decryptedTmp, $plaintext);
                $tarPath = $decryptedTmp;
            }

            // Extract
            $cmd = sprintf('tar -xzf %s -C %s', escapeshellarg($tarPath), escapeshellarg($tmpDir));
            exec($cmd, $output, $exitCode);
            if ($exitCode !== 0) {
                throw new \RuntimeException('Failed to extract backup');
            }

            // 1. Restore database
            $dumpFile = $tmpDir . '/database.sql';
            if (file_exists($dumpFile)) {
                $db = Database::credentials();

                $restoreCmd = sprintf(
                    'PGPASSWORD=%s pg_restore -h %s -p %s -U %s -d %s --clean --if-exists %s 2>&1',
                    escapeshellarg($db['pass']),
                    escapeshellarg($db['host']),
                    escapeshellarg($db['port']),
                    escapeshellarg($db['user']),
                    escapeshellarg($db['name']),
                    escapeshellarg($dumpFile)
                );
                exec($restoreCmd, $restoreOutput, $restoreExit);
                if ($restoreExit !== 0) {
                    AppLogger::warning('backup', 'pg_restore warnings: ' . implode("\n", $restoreOutput));
                }
            }

            // 2. Restore vault entries
            $vaultFile = $tmpDir . '/vault.json';
            if (file_exists($vaultFile)) {
                $vaultData = json_decode(file_get_contents($vaultFile), true);
                if (is_array($vaultData)) {
                    $db = Database::connection();
                    $stmt = $db->prepare('INSERT INTO vault (key, encrypted_value, updated_at)
                        VALUES (?, ?, CURRENT_TIMESTAMP)
                        ON CONFLICT (key) DO UPDATE SET encrypted_value = EXCLUDED.encrypted_value, updated_at = CURRENT_TIMESTAMP');
                    foreach ($vaultData as $row) {
                        if (!empty($row['key']) && !empty($row['encrypted_value'])) {
                            $stmt->execute([$row['key'], $row['encrypted_value']]);
                        }
                    }
                    Vault::clearCache();
                }
            }

            // 3. Restore .env (optional — only if not already existing)
            $envBackup = $tmpDir . '/.env';
            $envTarget = __DIR__ . '/../.env';
            if (file_exists($envBackup) && !file_exists($envTarget)) {
                copy($envBackup, $envTarget);
            }

            AppLogger::info('backup', "Backup restored: {$filename}");

            return ['success' => true, 'message' => 'Backup restored successfully'];
        } finally {
            if (is_dir($tmpDir)) {
                exec('rm -rf ' . escapeshellarg($tmpDir));
            }
            if ($decryptedTmp && file_exists($decryptedTmp)) {
                unlink($decryptedTmp);
            }
        }
    }

    // ── Local Backups ───────────────────────────────────────────────

    public static function listLocalBackups(): array
    {
        $dir = self::ensureBackupDir();
        $files = array_merge(
            glob($dir . '/backup_*.tar.gz') ?: [],
            glob($dir . '/backup_*.tar.gz.enc') ?: []
        );
        // Deduplicate (a .tar.gz and .tar.gz.enc with same timestamp)
        $seen = [];
        $backups = [];
        foreach ($files as $f) {
            $name = basename($f);
            if (isset($seen[$name])) continue;
            $seen[$name] = true;
            $backups[] = [
                'filename' => $name,
                'size' => filesize($f),
                'created_at' => date('Y-m-d H:i:s', filemtime($f)),
                'encrypted' => str_ends_with($name, '.enc'),
            ];
        }
        usort($backups, fn($a, $b) => strcmp($b['filename'], $a['filename']));
        return $backups;
    }

    public static function deleteLocalBackup(string $filename): void
    {
        $path = self::ensureBackupDir() . '/' . basename($filename);
        if (file_exists($path)) {
            unlink($path);
        }
        $db = Database::connection();
        $db->prepare('DELETE FROM backup_history WHERE filename = ?')->execute([basename($filename)]);
    }

    public static function getLocalBackupPath(string $filename): ?string
    {
        $path = self::ensureBackupDir() . '/' . basename($filename);
        return file_exists($path) ? $path : null;
    }

    // ── Remote (SFTP) ───────────────────────────────────────────────

    private static function connectSftp(): SFTP
    {
        $config = self::getConfig();
        if (!$config['remote_host']) {
            throw new \RuntimeException('Remote host not configured');
        }

        $sftp = new SFTP($config['remote_host'], $config['remote_port'], 15);

        $user = $config['remote_user'];
        $password = Vault::get('BACKUP_REMOTE_PASSWORD') ?: '';
        $keyContent = Vault::get('BACKUP_REMOTE_KEY') ?: '';

        $authenticated = false;
        if ($keyContent) {
            $key = $password
                ? PublicKeyLoader::load($keyContent, $password)
                : PublicKeyLoader::load($keyContent);
            $authenticated = $sftp->login($user, $key);
        }
        if (!$authenticated && $password) {
            $authenticated = $sftp->login($user, $password);
        }
        if (!$authenticated) {
            throw new \RuntimeException("SFTP authentication failed for {$user}@{$config['remote_host']}");
        }

        return $sftp;
    }

    public static function testRemoteConnection(): array
    {
        $sftp = self::connectSftp();
        $config = self::getConfig();
        $remotePath = rtrim($config['remote_path'], '/');

        if (!$sftp->is_dir($remotePath)) {
            if (!$sftp->mkdir($remotePath, -1, true)) {
                throw new \RuntimeException("Remote path does not exist and cannot be created: {$remotePath}");
            }
        }

        $files = $sftp->nlist($remotePath);
        return [
            'success' => true,
            'message' => 'Connection successful',
            'files_count' => count(array_filter($files ?: [], fn($f) => $f !== '.' && $f !== '..')),
        ];
    }

    public static function uploadToRemote(string $filename): array
    {
        $localPath = self::getLocalBackupPath($filename);
        if (!$localPath) {
            throw new \RuntimeException('Local backup not found: ' . $filename);
        }

        $sftp = self::connectSftp();
        $config = self::getConfig();
        $remotePath = rtrim($config['remote_path'], '/');

        if (!$sftp->is_dir($remotePath)) {
            $sftp->mkdir($remotePath, -1, true);
        }

        $remoteFile = $remotePath . '/' . basename($filename);
        if (!$sftp->put($remoteFile, $localPath, SFTP::SOURCE_LOCAL_FILE)) {
            throw new \RuntimeException('Failed to upload file to remote server');
        }

        // Record in history
        $db = Database::connection();
        $stmt = $db->prepare('INSERT INTO backup_history (filename, location, size_bytes, status) VALUES (?, ?, ?, ?)');
        $stmt->execute([basename($filename), 'remote', filesize($localPath), 'completed']);

        AppLogger::info('backup', "Backup uploaded to remote: {$filename}");

        return ['success' => true, 'filename' => basename($filename)];
    }

    public static function downloadFromRemote(string $filename): string
    {
        $sftp = self::connectSftp();
        $config = self::getConfig();
        $remotePath = rtrim($config['remote_path'], '/') . '/' . basename($filename);

        $localDir = self::ensureBackupDir();
        $localPath = $localDir . '/' . basename($filename);

        if (!$sftp->get($remotePath, $localPath)) {
            throw new \RuntimeException('Failed to download file from remote server');
        }

        AppLogger::info('backup', "Backup downloaded from remote: {$filename}");
        return basename($filename);
    }

    public static function listRemoteBackups(): array
    {
        try {
            $sftp = self::connectSftp();
            $config = self::getConfig();
            $remotePath = rtrim($config['remote_path'], '/');

            if (!$sftp->is_dir($remotePath)) {
                return [];
            }

            $files = $sftp->nlist($remotePath);
            $backups = [];
            foreach ($files ?: [] as $f) {
                if ($f === '.' || $f === '..') continue;
                if (!str_starts_with($f, 'backup_')) continue;
                if (!str_ends_with($f, '.tar.gz') && !str_ends_with($f, '.tar.gz.enc')) continue;
                $stat = $sftp->stat($remotePath . '/' . $f);
                $backups[] = [
                    'filename' => $f,
                    'size' => $stat['size'] ?? 0,
                    'created_at' => isset($stat['mtime']) ? date('Y-m-d H:i:s', $stat['mtime']) : '',
                    'encrypted' => str_ends_with($f, '.enc'),
                ];
            }
            usort($backups, fn($a, $b) => strcmp($b['filename'], $a['filename']));
            return $backups;
        } catch (\Exception $e) {
            return [];
        }
    }

    public static function deleteRemoteBackup(string $filename): void
    {
        $sftp = self::connectSftp();
        $config = self::getConfig();
        $remotePath = rtrim($config['remote_path'], '/') . '/' . basename($filename);
        $sftp->delete($remotePath);

        AppLogger::info('backup', "Remote backup deleted: {$filename}");
    }

    // ── History ─────────────────────────────────────────────────────

    public static function getHistory(int $limit = 50, int $offset = 0): array
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT bh.*, u.username as created_by_name FROM backup_history bh LEFT JOIN users u ON u.id = bh.created_by ORDER BY bh.created_at DESC LIMIT ? OFFSET ?');
        $stmt->execute([$limit, $offset]);
        return $stmt->fetchAll();
    }
}
