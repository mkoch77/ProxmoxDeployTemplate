<?php

namespace App;

class Response
{
    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function success(mixed $data): never
    {
        self::json(['success' => true, 'data' => $data]);
    }

    public static function error(string $message, int $status = 400, mixed $details = null): never
    {
        $body = ['error' => true, 'message' => $message];
        if ($details !== null) {
            $body['details'] = $details;
        }
        self::json($body, $status);
    }
}
