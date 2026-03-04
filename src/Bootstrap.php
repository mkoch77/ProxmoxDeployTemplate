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
        Session::start();
        Migrator::run();
    }
}
