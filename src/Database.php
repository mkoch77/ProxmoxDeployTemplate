<?php

namespace App;

use PDO;

class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo === null) {
            $host = getenv('DB_HOST') ?: 'db';
            $port = getenv('DB_PORT') ?: '5432';
            $name = getenv('DB_NAME') ?: 'proxmoxdeploy';
            $user = getenv('DB_USER') ?: 'proxmoxdeploy';
            $pass = getenv('DB_PASSWORD') ?: 'changeme';

            $dsn = "pgsql:host={$host};port={$port};dbname={$name}";
            self::$pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);

            // Safe to log here: self::$pdo is already set, so AppLogger calling
            // Database::connection() will not re-enter this block (no recursion).
            AppLogger::debug('system', 'Database connection established');
        }
        return self::$pdo;
    }
}
