<?php

namespace App;

use PDO;

class UserManager
{
    public static function isFirstUser(): bool
    {
        $db = Database::connection();
        return (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0;
    }

    public static function create(array $data): int
    {
        $db = Database::connection();
        $stmt = $db->prepare('INSERT INTO users (username, display_name, email, password_hash, auth_provider) VALUES (?, ?, ?, ?, ?) RETURNING id');
        $stmt->execute([
            $data['username'],
            $data['display_name'] ?? $data['username'],
            $data['email'] ?? '',
            isset($data['password']) ? password_hash($data['password'], PASSWORD_BCRYPT) : null,
            $data['auth_provider'] ?? 'local',
        ]);
        return (int) $stmt->fetchColumn();
    }

    public static function update(int $id, array $data): void
    {
        $db = Database::connection();
        $fields = [];
        $values = [];

        if (isset($data['display_name'])) {
            $fields[] = 'display_name = ?';
            $values[] = $data['display_name'];
        }
        if (isset($data['email'])) {
            $fields[] = 'email = ?';
            $values[] = $data['email'];
        }
        if (isset($data['password']) && $data['password'] !== '') {
            $fields[] = 'password_hash = ?';
            $values[] = password_hash($data['password'], PASSWORD_BCRYPT);
        }
        if (isset($data['is_active'])) {
            $fields[] = 'is_active = ?';
            $values[] = (int) $data['is_active'];
        }

        if (empty($fields)) {
            return;
        }

        $fields[] = 'updated_at = CURRENT_TIMESTAMP';
        $values[] = $id;

        $sql = 'UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?';
        $db->prepare($sql)->execute($values);
    }

    public static function delete(int $id): void
    {
        $db = Database::connection();
        $db->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    }

    public static function getAll(): array
    {
        $db = Database::connection();
        $users = $db->query('SELECT id, username, display_name, email, auth_provider, is_active, created_at FROM users ORDER BY username')->fetchAll();

        foreach ($users as &$user) {
            $user['roles'] = self::getUserRoles((int) $user['id']);
            $user['permission_overrides'] = self::getUserPermissionOverrides((int) $user['id']);
        }
        return $users;
    }

    public static function getById(int $id): ?array
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT id, username, display_name, email, auth_provider, is_active, created_at FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        if ($user) {
            $user['roles'] = self::getUserRoles((int) $user['id']);
            $user['permission_overrides'] = self::getUserPermissionOverrides((int) $user['id']);
        }
        return $user ?: null;
    }

    public static function getUserPermissionOverrides(int $userId): array
    {
        $db = Database::connection();
        $stmt = $db->prepare('
            SELECT p.key, upo.granted
            FROM user_permission_overrides upo
            JOIN permissions p ON p.id = upo.permission_id
            WHERE upo.user_id = ?
        ');
        $stmt->execute([$userId]);
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[$row['key']] = (bool) $row['granted'];
        }
        return $result;
    }

    public static function setUserPermissionOverrides(int $userId, array $overrides): void
    {
        $db = Database::connection();
        $db->prepare('DELETE FROM user_permission_overrides WHERE user_id = ?')->execute([$userId]);

        if (empty($overrides)) {
            return;
        }

        $stmt = $db->prepare('
            INSERT INTO user_permission_overrides (user_id, permission_id, granted)
            SELECT ?, p.id, ? FROM permissions p WHERE p.key = ?
        ');
        foreach ($overrides as $key => $granted) {
            $stmt->execute([$userId, $granted ? 1 : 0, $key]);
        }
    }

    public static function getByUsername(string $username): ?array
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT * FROM users WHERE username = ?');
        $stmt->execute([$username]);
        return $stmt->fetch() ?: null;
    }

    public static function assignRole(int $userId, int $roleId): void
    {
        $db = Database::connection();
        $stmt = $db->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?) ON CONFLICT DO NOTHING');
        $stmt->execute([$userId, $roleId]);
    }

    public static function removeRole(int $userId, int $roleId): void
    {
        $db = Database::connection();
        $stmt = $db->prepare('DELETE FROM user_roles WHERE user_id = ? AND role_id = ?');
        $stmt->execute([$userId, $roleId]);
    }

    public static function setRoles(int $userId, array $roleIds): void
    {
        $db = Database::connection();
        $db->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$userId]);
        $stmt = $db->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)');
        foreach ($roleIds as $roleId) {
            $stmt->execute([$userId, (int) $roleId]);
        }
    }

    public static function getUserRoles(int $userId): array
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT r.id, r.name, r.description FROM roles r JOIN user_roles ur ON ur.role_id = r.id WHERE ur.user_id = ?');
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public static function getRoles(): array
    {
        $db = Database::connection();
        return $db->query('SELECT * FROM roles ORDER BY id')->fetchAll();
    }

    public static function getPermissions(): array
    {
        $db = Database::connection();
        return $db->query('SELECT * FROM permissions ORDER BY key')->fetchAll();
    }

    public static function getRolePermissions(int $roleId): array
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT p.key FROM permissions p JOIN role_permissions rp ON rp.permission_id = p.id WHERE rp.role_id = ?');
        $stmt->execute([$roleId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}
