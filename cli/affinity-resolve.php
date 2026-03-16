#!/usr/bin/env php
<?php
/**
 * Affinity auto-resolve cron job.
 * Checks for affinity/anti-affinity violations and migrates VMs to fix them.
 * Usage: php cli/affinity-resolve.php
 * Crontab: * * * * * php /var/www/html/cli/affinity-resolve.php >> /var/www/html/data/affinity-resolve.log 2>&1
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Migrator;
use App\Helpers;
use App\AffinityHelper;
use App\AppLogger;

Migrator::run();

$zones = AffinityHelper::getNodeZones();
$rules = AffinityHelper::getRules();

if (empty($zones) || empty($rules)) {
    exit(0);
}

try {
    $api = Helpers::createAPI();
    $migrations = AffinityHelper::resolveViolations($api);

    if (empty($migrations)) {
        exit(0);
    }

    echo date('Y-m-d H:i:s') . " Found " . count($migrations) . " violation(s) to resolve.\n";

    $succeeded = 0;
    $failed = 0;

    foreach ($migrations as $mig) {
        // Detach local CD-ROMs that would block migration
        $detachedCds = \App\MaintenanceManager::detachLocalCdRoms($api, $mig['source_node'], $mig['vm_type'], $mig['vmid']);

        try {
            $result = $api->migrateGuest(
                $mig['source_node'],
                $mig['vm_type'],
                $mig['vmid'],
                $mig['target_node'],
                true
            );
            $succeeded++;
            echo date('Y-m-d H:i:s') . "   VM {$mig['vmid']} ({$mig['vm_name']}): {$mig['source_node']} -> {$mig['target_node']} — started\n";
        } catch (\Exception $e) {
            // Re-attach CDs on failure
            if (!empty($detachedCds)) {
                \App\MaintenanceManager::reattachCdRoms($api, $mig['source_node'], $mig['vm_type'], $mig['vmid'], $detachedCds);
            }
            $failed++;
            echo date('Y-m-d H:i:s') . "   VM {$mig['vmid']} ({$mig['vm_name']}): FAILED — {$e->getMessage()}\n";
        }
    }

    AppLogger::info('affinity', "Auto-resolve: {$succeeded} migrations started, {$failed} failed", [
        'total' => count($migrations),
        'succeeded' => $succeeded,
        'failed' => $failed,
    ]);

    echo date('Y-m-d H:i:s') . " Done. {$succeeded} started, {$failed} failed.\n";
} catch (\Exception $e) {
    echo date('Y-m-d H:i:s') . " ERROR: " . $e->getMessage() . "\n";
    AppLogger::error('affinity', 'Auto-resolve cron failed', ['error' => $e->getMessage()]);
    exit(1);
}
