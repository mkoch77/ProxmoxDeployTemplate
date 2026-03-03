<?php

namespace App;

class Request
{
    public static function method(): string
    {
        return $_SERVER['REQUEST_METHOD'];
    }

    public static function requireMethod(string $method): void
    {
        if (self::method() !== strtoupper($method)) {
            Response::error('Method not allowed', 405);
        }
    }

    public static function jsonBody(): array
    {
        return json_decode(file_get_contents('php://input'), true) ?? [];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    public static function requireParams(array $keys, array $source): void
    {
        $missing = [];
        foreach ($keys as $key) {
            if (!isset($source[$key]) || $source[$key] === '') {
                $missing[] = $key;
            }
        }
        if (!empty($missing)) {
            Response::error('Missing required parameters: ' . implode(', ', $missing), 400);
        }
    }

    public static function validateCsrf(): void
    {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!Session::validateCsrfToken($token)) {
            Response::error('Invalid CSRF token', 403);
        }
    }
}
