<?php

namespace App;

use PDO;

class AppLogger
{
    public static function log(string $level, string $category, string $message, ?array $context = null, ?int $userId = null): void
    {
        try {
            $db = Database::connection();
            $stmt = $db->prepare('INSERT INTO app_logs (level, category, message, context, user_id) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([
                $level,
                $category,
                $message,
                $context ? json_encode($context) : null,
                $userId,
            ]);
        } catch (\Exception $e) {
            // Fallback to stderr if DB logging fails
            fwrite(STDERR, date('Y-m-d H:i:s') . " [{$level}] [{$category}] {$message}\n");
        }
    }

    public static function info(string $category, string $message, ?array $context = null, ?int $userId = null): void
    {
        self::log('info', $category, $message, $context, $userId);
    }

    public static function warning(string $category, string $message, ?array $context = null, ?int $userId = null): void
    {
        self::log('warning', $category, $message, $context, $userId);
    }

    public static function error(string $category, string $message, ?array $context = null, ?int $userId = null): void
    {
        self::log('error', $category, $message, $context, $userId);
    }

    public static function debug(string $category, string $message, ?array $context = null, ?int $userId = null): void
    {
        self::log('debug', $category, $message, $context, $userId);
    }

    public static function getLogs(int $limit = 100, int $offset = 0, ?string $level = null, ?string $category = null): array
    {
        $db = Database::connection();
        $where = [];
        $params = [];

        if ($level === 'no-debug') {
            $where[] = "l.level != 'debug'";
        } elseif ($level) {
            $where[] = 'l.level = ?';
            $params[] = $level;
        }
        if ($category) {
            $where[] = 'l.category = ?';
            $params[] = $category;
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $stmt = $db->prepare("SELECT l.*, u.username FROM app_logs l LEFT JOIN users u ON l.user_id = u.id {$whereClause} ORDER BY l.created_at DESC LIMIT ? OFFSET ?");
        $params[] = $limit;
        $params[] = $offset;
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getCategories(): array
    {
        $db = Database::connection();
        return $db->query('SELECT DISTINCT category FROM app_logs ORDER BY category')->fetchAll(PDO::FETCH_COLUMN);
    }

    public static function cleanup(int $days = 90): int
    {
        $db = Database::connection();
        $stmt = $db->prepare("DELETE FROM app_logs WHERE created_at < NOW() - make_interval(days := ?)");
        $stmt->execute([$days]);
        return $stmt->rowCount();
    }
}
