#!/usr/bin/env php
<?php
/**
 * Scheduled backup cron job script.
 * Runs every minute, checks if it's time for the daily backup.
 * Usage: php cli/backup-run.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Migrator;
use App\BackupManager;
use App\AppLogger;

Migrator::run();

try {
    $config = BackupManager::getConfig();

    if (empty($config['auto_backup_enabled'])) {
        exit(0);
    }

    // Check if current time matches configured backup_time (HH:MM)
    $backupTime = $config['backup_time'] ?? '02:00';
    $currentTime = date('H:i');

    if ($currentTime !== $backupTime) {
        exit(0);
    }

    // Prevent duplicate runs within the same minute using a lock file
    $lockFile = sys_get_temp_dir() . '/pdt_backup_' . date('Ymd_Hi') . '.lock';
    if (file_exists($lockFile)) {
        exit(0);
    }
    file_put_contents($lockFile, getmypid());

    echo date('Y-m-d H:i:s') . " Starting scheduled backup...\n";

    $result = BackupManager::createBackup(null);

    echo date('Y-m-d H:i:s') . " Backup completed: {$result['filename']} (" . round($result['size'] / 1024 / 1024, 2) . " MB)\n";

    if (!empty($result['remote_uploaded'])) {
        echo date('Y-m-d H:i:s') . " Backup also uploaded to remote storage.\n";
    }

    // Cleanup old lock files (older than 2 hours)
    foreach (glob(sys_get_temp_dir() . '/pdt_backup_*.lock') as $f) {
        if (filemtime($f) < time() - 7200) {
            @unlink($f);
        }
    }

    // Cleanup old local backups (keep configured retention count)
    $retention = max(1, (int)($config['backup_retention'] ?? 30));
    $locals = BackupManager::listLocalBackups();
    if (count($locals) > $retention) {
        $toDelete = array_slice($locals, $retention);
        foreach ($toDelete as $old) {
            BackupManager::deleteLocalBackup($old['filename']);
            echo date('Y-m-d H:i:s') . " Deleted old backup: {$old['filename']}\n";
        }
    }

} catch (\Exception $e) {
    AppLogger::error('backup', 'Scheduled backup failed: ' . $e->getMessage());
    echo date('Y-m-d H:i:s') . " ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
