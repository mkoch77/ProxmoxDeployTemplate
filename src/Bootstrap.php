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
            ini_set('display_errors', '0');
            error_reporting(E_ALL);
            ini_set('log_errors', '1');
        }

        Session::start();
        Migrator::run();
        Migrator::seed();
    }
}
