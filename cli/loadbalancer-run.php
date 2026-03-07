#!/usr/bin/env php
<?php
/**
 * Loadbalancer cron job script.
 * Usage: php cli/loadbalancer-run.php
 * Crontab: * * * * * php /var/www/html/cli/loadbalancer-run.php >> /var/www/html/data/loadbalancer.log 2>&1
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Migrator;
use App\Helpers;
use App\Loadbalancer;
use App\AppLogger;

Migrator::run();

AppLogger::debug('drs', 'CLI loadbalancer run started');

$settings = Loadbalancer::getSettings();
if (!$settings['enabled']) {
    echo date('Y-m-d H:i:s') . " Loadbalancer is disabled.\n";
    exit(0);
}

try {
    $api = Helpers::createAPI();
    $result = Loadbalancer::evaluate($api, 'cron');

    $count = count($result['recommendations'] ?? []);
    $executed = $result['executed'] ?? 0;

    echo date('Y-m-d H:i:s') . " Loadbalancer run complete. {$count} recommendation(s)";
    if ($executed > 0) {
        echo ", {$executed} auto-applied";
    }
    echo ".\n";

    // Cleanup old runs
    $deleted = Loadbalancer::cleanupOldRuns(30);
    if ($deleted > 0) {
        echo date('Y-m-d H:i:s') . " Cleaned up {$deleted} old run(s).\n";
    }
} catch (\Exception $e) {
    echo date('Y-m-d H:i:s') . " ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
