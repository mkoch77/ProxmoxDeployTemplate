<?php

namespace App;

class Config
{
    private static ?array $config = null;

    public static function load(): void
    {
        $configFile = __DIR__ . '/../config/config.php';
        $defaults = file_exists($configFile) ? require $configFile : [];
        self::$config = $defaults;

        // .env file overrides config.php
        $envFile = __DIR__ . '/../.env';
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                $pos = strpos($line, '=');
                if ($pos === false) {
                    continue;
                }
                $key = trim(substr($line, 0, $pos));
                $value = trim(substr($line, $pos + 1));
                // Remove surrounding quotes
                if ((str_starts_with($value, '"') && str_ends_with($value, '"'))
                    || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                    $value = substr($value, 1, -1);
                }
                self::$config[$key] = $value;
            }
        }

        // Real environment variables override everything (Docker / system env support)
        foreach ($_ENV as $key => $value) {
            self::$config[$key] = $value;
        }
        foreach (getenv() ?: [] as $key => $value) {
            self::$config[$key] = $value;
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (self::$config === null) {
            self::load();
        }
        return self::$config[$key] ?? $default;
    }
}
