<?php

namespace App;

class Bootstrap
{
    private static bool $initialized = false;
    private const MIGRATION_FLAG = '/tmp/.pdt_migrations_done';
    // Bump this when adding migrations so the flag auto-invalidates
    public const MIGRATION_VERSION_NUMBER = 1;

    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;

        // Prevent PHP warnings/notices from corrupting JSON API responses
        if (php_sapi_name() !== 'cli') {
            if (ob_get_level() > 0) ob_end_clean();
            ob_start();
            ini_set('display_errors', '0');
            error_reporting(E_ALL);
            ini_set('log_errors', '1');

            set_exception_handler(function (\Throwable $e) {
                while (ob_get_level() > 0) ob_end_clean();
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => true, 'message' => 'Internal error: ' . $e->getMessage()]);
                exit;
            });

            register_shutdown_function(function () {
                $err = error_get_last();
                if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                    while (ob_get_level() > 0) ob_end_clean();
                    if (!headers_sent()) {
                        http_response_code(500);
                        header('Content-Type: application/json; charset=utf-8');
                    }
                    echo json_encode(['error' => true, 'message' => 'Fatal error: ' . $err['message']]);
                }
            });
        }

        Session::start();

        // Run migrations only once per container lifetime per migration version
        $flagVersion = @file_get_contents(self::MIGRATION_FLAG);
        if ((int)$flagVersion < self::MIGRATION_VERSION_NUMBER) {
            try {
                Migrator::run();
                Migrator::seed();
            } catch (\Throwable $e) {
                error_log('Bootstrap migration error: ' . $e->getMessage());
            }

            try {
                Vault::migrateFromEnv();
            } catch (\Throwable $e) {}

            try {
                AppSettings::migrateFromVaultAndEnv();
            } catch (\Throwable $e) {}

            @file_put_contents(self::MIGRATION_FLAG, (string)self::MIGRATION_VERSION_NUMBER);
        }
    }
}
