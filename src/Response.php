<?php

namespace App;

class Response
{
    public static function json(mixed $data, int $status = 200): never
    {
        // Discard any buffered output (stray warnings/notices) to keep JSON clean
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            http_response_code(500);
            echo json_encode(['error' => true, 'message' => 'JSON encoding failed: ' . json_last_error_msg()]);
        } else {
            echo $json;
        }
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
