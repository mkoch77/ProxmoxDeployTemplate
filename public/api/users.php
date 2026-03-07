<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\Request;
use App\Response;
use App\Auth;
use App\UserManager;
use App\AppLogger;

Bootstrap::init();
$currentUser = Auth::requirePermission('users.manage');

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        $users = UserManager::getAll();
        $roles = UserManager::getRoles();
        $permissions = UserManager::getPermissions();
        $rolePermissions = [];
        foreach ($roles as $role) {
            $rolePermissions[$role['id']] = UserManager::getRolePermissions((int) $role['id']);
        }
        Response::success([
            'users' => $users,
            'roles' => $roles,
            'permissions' => $permissions,
            'role_permissions' => $rolePermissions,
        ]);
        break;

    case 'POST':
        Request::validateCsrf();
        $body = Request::jsonBody();

        $username = trim($body['username'] ?? '');
        $password = $body['password'] ?? '';
        $displayName = trim($body['display_name'] ?? '');
        $email = trim($body['email'] ?? '');
        $roleIds = $body['role_ids'] ?? [];

        if ($username === '' || $password === '') {
            Response::error('Username and password required', 400);
        }

        if (UserManager::getByUsername($username)) {
            AppLogger::warning('admin', 'User creation failed: username already taken', ['username' => $username], $currentUser['id']);
            Response::error('Username already taken', 409);
        }

        $userId = UserManager::create([
            'username' => $username,
            'display_name' => $displayName ?: $username,
            'email' => $email,
            'password' => $password,
        ]);

        if (!empty($roleIds)) {
            UserManager::setRoles($userId, $roleIds);
        }

        AppLogger::info('admin', 'User created', ['new_user_id' => $userId, 'username' => $username], $currentUser['id']);
        Response::success(UserManager::getById($userId));
        break;

    case 'PUT':
        Request::validateCsrf();
        $body = Request::jsonBody();
        $id = (int) ($body['id'] ?? 0);

        if ($id <= 0) {
            Response::error('User ID required', 400);
        }

        $user = UserManager::getById($id);
        if (!$user) {
            Response::error('User not found', 404);
        }

        $updateData = [];
        if (isset($body['display_name'])) {
            $updateData['display_name'] = trim($body['display_name']);
        }
        if (isset($body['email'])) {
            $updateData['email'] = trim($body['email']);
        }
        if (isset($body['password']) && $body['password'] !== '') {
            $updateData['password'] = $body['password'];
        }
        if (isset($body['is_active'])) {
            // Prevent deactivating yourself
            if ($id === (int) $currentUser['id'] && !$body['is_active']) {
                Response::error('You cannot deactivate yourself', 400);
            }
            $updateData['is_active'] = $body['is_active'];
        }

        UserManager::update($id, $updateData);

        if (isset($body['role_ids'])) {
            UserManager::setRoles($id, $body['role_ids']);
        }

        if (isset($body['permission_overrides'])) {
            UserManager::setUserPermissionOverrides($id, $body['permission_overrides']);
        }

        AppLogger::info('admin', 'User updated', ['target_user_id' => $id, 'fields' => array_keys($updateData)], $currentUser['id']);
        Response::success(UserManager::getById($id));
        break;

    case 'DELETE':
        Request::validateCsrf();
        $body = Request::jsonBody();
        $id = (int) ($body['id'] ?? 0);

        if ($id <= 0) {
            Response::error('User ID required', 400);
        }

        if ($id === (int) $currentUser['id']) {
            Response::error('You cannot delete yourself', 400);
        }

        $user = UserManager::getById($id);
        if (!$user) {
            Response::error('User not found', 404);
        }

        UserManager::delete($id);
        AppLogger::warning('admin', 'User deleted', ['deleted_user_id' => $id, 'username' => $user['username'] ?? ''], $currentUser['id']);
        Response::success(['message' => 'User deleted']);
        break;

    default:
        Response::error('Method not allowed', 405);
}
