#!/usr/bin/env php
<?php
/**
 * Pre-warms caches and runs migrations before the web server accepts requests.
 * Called from entrypoint.sh on container start.
 */

require_once __DIR__ . '/../vendor/autoload.php';

$start = microtime(true);

// 1. Run migrations + seed
echo "Running migrations... ";
try {
    App\Migrator::run();
    App\Migrator::seed();
    echo "done.\n";
} catch (\Throwable $e) {
    echo "error: " . $e->getMessage() . "\n";
}

// 2. Migrate secrets from .env to vault
echo "Migrating vault... ";
try {
    App\Vault::migrateFromEnv();
    echo "done.\n";
} catch (\Throwable $e) {
    echo "skipped.\n";
}

// 3. Migrate settings from vault/env to app_settings
echo "Migrating settings... ";
try {
    App\AppSettings::migrateFromVaultAndEnv();
    echo "done.\n";
} catch (\Throwable $e) {
    echo "skipped.\n";
}

// 4. Write migration flag so Bootstrap skips all of the above
@file_put_contents('/tmp/.pdt_migrations_done', (string)App\Bootstrap::MIGRATION_VERSION_NUMBER);

// 5. Pre-warm Proxmox API connection + guest list cache
echo "Pre-fetching Proxmox data... ";
try {
    $api = App\Helpers::createAPI();
    $guests = $api->getGuests();
    $nodes = $api->getNodes();
    echo count($guests) . " guests, " . count($nodes['data'] ?? []) . " nodes.\n";
} catch (\Throwable $e) {
    echo "skipped (" . $e->getMessage() . ").\n";
}

$elapsed = round((microtime(true) - $start) * 1000);
echo "Warmup completed in {$elapsed}ms.\n";
