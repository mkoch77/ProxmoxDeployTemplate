<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\Response;
use App\Request;
use App\Auth;
use App\Database;

Bootstrap::init();
$user = Auth::requireAuth();

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        Response::success($user);
        break;

    case 'POST':
        Request::validateCsrf();
        $body = Request::jsonBody();

        // Update theme preference
        if (isset($body['theme'])) {
            $theme = $body['theme'];
            if (!in_array($theme, ['auto', 'light', 'dark'], true)) {
                Response::error('Invalid theme', 400);
            }
            $db = Database::connection();
            $stmt = $db->prepare('UPDATE users SET theme = ? WHERE id = ?');
            $stmt->execute([$theme, $user['id']]);
            Response::success(['theme' => $theme]);
        }

        Response::error('No valid field to update', 400);
        break;

    default:
        Response::error('Method not allowed', 405);
}
