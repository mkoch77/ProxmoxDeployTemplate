<?php

namespace App;

class Bootstrap
{
    private static bool $initialized = false;

    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;

        // Prevent PHP warnings/notices from corrupting JSON API responses
        if (php_sapi_name() !== 'cli') {
            // Clean any output that leaked before this point (e.g. PHP startup warnings)
            if (ob_get_level() > 0) ob_end_clean();
            ob_start(); // Capture any stray output — Response::json() will discard it
            ini_set('display_errors', '0');
            error_reporting(E_ALL);
            ini_set('log_errors', '1');

            // Catch uncaught exceptions → return valid JSON instead of empty response
            set_exception_handler(function (\Throwable $e) {
                while (ob_get_level() > 0) ob_end_clean();
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => true, 'message' => 'Internal error: ' . $e->getMessage()]);
                exit;
            });

            // Catch fatal errors → return valid JSON
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
        try {
            Migrator::run();
            Migrator::seed();
        } catch (\Throwable $e) {
            // Log but don't crash — migration may be running concurrently
            error_log('Bootstrap migration error: ' . $e->getMessage());
        }

        // Auto-migrate .env secrets to vault on first boot (if ENCRYPTION_KEY is set)
        try {
            Vault::migrateFromEnv();
        } catch (\Throwable $e) {
            // Vault not ready yet — skip silently
        }
    }
}
