#!/usr/bin/env php
<?php
/**
 * Collects node and VM metrics from Proxmox API.
 * Intended to be called every 10 seconds via a loop in entrypoint.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Migrator;
use App\Helpers;
use App\MonitoringCollector;
use App\AppLogger;

Migrator::run();

AppLogger::debug('monitoring', 'CLI monitoring collection started');

try {
    $api = Helpers::createAPI();
    $stats = MonitoringCollector::collect($api);

    // Cleanup old data once per hour (when minute and second are both low)
    $min = (int)date('i');
    $sec = (int)date('s');
    if ($min === 0 && $sec < 15) {
        $db = \App\Database::connection();
        $row = $db->query('SELECT retention_days FROM monitoring_settings WHERE id = 1')->fetch();
        $days = (int)($row['retention_days'] ?? 30);
        MonitoringCollector::cleanup($days);
    }
} catch (\Exception $e) {
    fwrite(STDERR, date('Y-m-d H:i:s') . " Monitoring collect error: " . $e->getMessage() . "\n");
    exit(1);
}
