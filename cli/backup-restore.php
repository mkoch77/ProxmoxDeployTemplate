#!/usr/bin/env php
<?php
/**
 * CLI backup restore script.
 * Used by setup.sh for restoring a backup during initial setup.
 *
 * Usage: php cli/backup-restore.php <filename> [encryption-key-hex]
 *
 * The filename must exist in data/backups/.
 * If the backup is encrypted (.enc), the encryption key must be provided
 * as 64 hex characters OR already be set in the vault.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Migrator;
use App\BackupManager;
use App\Vault;
use App\AppLogger;

// Run migrations first so DB schema is ready
Migrator::run();

$filename = $argv[1] ?? '';
$encKeyArg = $argv[2] ?? '';

if (!$filename) {
    fwrite(STDERR, "Usage: php cli/backup-restore.php <filename> [encryption-key-hex]\n");
    exit(1);
}

try {
    // If encryption key provided, store it in vault before restore
    if ($encKeyArg !== '') {
        if (strlen($encKeyArg) !== 64 || @hex2bin($encKeyArg) === false) {
            fwrite(STDERR, "ERROR: Encryption key must be 64 hex characters.\n");
            exit(1);
        }
        Vault::set('BACKUP_ENCRYPTION_KEY', $encKeyArg);
        echo "Encryption key stored in vault.\n";
    }

    echo "Restoring backup: {$filename} ...\n";
    $result = BackupManager::restoreBackup($filename);
    echo "Backup restored successfully.\n";

    // Re-run migrations in case backup DB is from an older version
    Migrator::run();
    echo "Migrations applied.\n";

} catch (\Exception $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
