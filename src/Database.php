<?php

namespace App;

use PDO;

class Database
{
    private static ?PDO $pdo = null;

    /**
     * Return DB credentials array: [host, port, name, user, pass].
     */
    public static function credentials(): array
    {
        $host = getenv('DB_HOST') ?: 'db';
        $port = getenv('DB_PORT') ?: '5432';
        $name = getenv('DB_NAME') ?: 'proxmoxdeploy';
        $user = getenv('DB_USER') ?: 'proxmoxdeploy';
        $pass = getenv('DB_PASSWORD') ?: '';
        if (empty($pass) && is_readable('/tmp/db_password')) {
            $pass = trim(file_get_contents('/tmp/db_password'));
        }
        return compact('host', 'port', 'name', 'user', 'pass');
    }

    public static function connection(): PDO
    {
        if (self::$pdo === null) {
            $creds = self::credentials();
            $host = $creds['host'];
            $port = $creds['port'];
            $name = $creds['name'];
            $user = $creds['user'];
            $pass = $creds['pass'];
            if (empty($pass)) {
                throw new \RuntimeException(
                    'DB_PASSWORD is not set. Provide it via environment variable, '
                    . 'Docker secret (secrets/db_password.txt), or /tmp/db_password.'
                );
            }

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
