<?php

namespace App;

use PDO;

class Auth
{
    private const COOKIE_NAME = 'app_session';
    private const SESSION_LIFETIME = 86400; // 24 hours

    public static function login(string $username, string $password): ?array
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT * FROM users WHERE username = ? AND auth_provider = ? AND is_active = 1');
        $stmt->execute([$username, 'local']);
        $user = $stmt->fetch();

        if (!$user || !$user['password_hash'] || !password_verify($password, $user['password_hash'])) {
            return null;
        }

        $sessionId = self::createSession($user['id']);
        self::setSessionCookie($sessionId);

        $user['permissions'] = self::getUserPermissions($user['id']);
        unset($user['password_hash']);
        return $user;
    }

    public static function loginEntraID(array $tokenData): array
    {
        $db = Database::connection();

        $stmt = $db->prepare('SELECT * FROM users WHERE entraid_oid = ? AND auth_provider = ?');
        $stmt->execute([$tokenData['oid'], 'entraid']);
        $user = $stmt->fetch();

        if ($user) {
            // Update existing user
            $stmt = $db->prepare('UPDATE users SET display_name = ?, email = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
            $stmt->execute([$tokenData['name'] ?? $user['display_name'], $tokenData['email'] ?? $user['email'], $user['id']]);
            $user['display_name'] = $tokenData['name'] ?? $user['display_name'];
            $user['email'] = $tokenData['email'] ?? $user['email'];
        } else {
            // Create new user
            $username = $tokenData['preferred_username'] ?? $tokenData['email'] ?? $tokenData['oid'];
            $isFirstUser = UserManager::isFirstUser();

            $stmt = $db->prepare('INSERT INTO users (username, display_name, email, auth_provider, entraid_oid) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([
                $username,
                $tokenData['name'] ?? $username,
                $tokenData['email'] ?? '',
                'entraid',
                $tokenData['oid'],
            ]);
            $userId = (int) $db->lastInsertId();

            if ($isFirstUser) {
                UserManager::assignRole($userId, self::getRoleId('admin'));
            }

            $stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
        }

        if (!$user['is_active']) {
            throw new \RuntimeException('User is deactivated');
        }

        $sessionId = self::createSession($user['id']);
        self::setSessionCookie($sessionId);

        $user['permissions'] = self::getUserPermissions($user['id']);
        unset($user['password_hash']);
        return $user;
    }

    public static function check(): ?array
    {
        $sessionId = $_COOKIE[self::COOKIE_NAME] ?? null;
        if (!$sessionId) {
            return null;
        }

        $db = Database::connection();
        $stmt = $db->prepare('
            SELECT us.*, u.username, u.display_name, u.email, u.auth_provider, u.is_active
            FROM user_sessions us
            JOIN users u ON u.id = us.user_id
            WHERE us.id = ? AND us.expires_at > datetime(\'now\')
        ');
        $stmt->execute([$sessionId]);
        $session = $stmt->fetch();

        if (!$session || !$session['is_active']) {
            self::clearSessionCookie();
            return null;
        }

        return [
            'id' => $session['user_id'],
            'username' => $session['username'],
            'display_name' => $session['display_name'],
            'email' => $session['email'],
            'auth_provider' => $session['auth_provider'],
            'permissions' => self::getUserPermissions($session['user_id']),
            'roles' => self::getUserRoles($session['user_id']),
        ];
    }

    public static function requireAuth(): array
    {
        $user = self::check();
        if (!$user) {
            Response::error('Not authenticated', 401);
            exit;
        }
        return $user;
    }

    public static function requirePermission(string $permission): array
    {
        $user = self::requireAuth();
        if (!in_array($permission, $user['permissions'], true)) {
            Response::error('Permission denied', 403);
            exit;
        }
        return $user;
    }

    public static function logout(): void
    {
        $sessionId = $_COOKIE[self::COOKIE_NAME] ?? null;
        if ($sessionId) {
            $db = Database::connection();
            $stmt = $db->prepare('DELETE FROM user_sessions WHERE id = ?');
            $stmt->execute([$sessionId]);
        }
        self::clearSessionCookie();
    }

    public static function getUserRoles(int $userId): array
    {
        $db = Database::connection();
        $stmt = $db->prepare('
            SELECT r.name
            FROM roles r
            JOIN user_roles ur ON ur.role_id = r.id
            WHERE ur.user_id = ?
        ');
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public static function getUserPermissions(int $userId): array
    {
        $db = Database::connection();
        $stmt = $db->prepare('
            SELECT DISTINCT p.key
            FROM permissions p
            JOIN role_permissions rp ON rp.permission_id = p.id
            JOIN user_roles ur ON ur.role_id = rp.role_id
            WHERE ur.user_id = ?
        ');
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public static function createSession(int $userId): string
    {
        $sessionId = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + self::SESSION_LIFETIME);

        $db = Database::connection();
        $stmt = $db->prepare('INSERT INTO user_sessions (id, user_id, ip_address, user_agent, expires_at) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([
            $sessionId,
            $userId,
            $_SERVER['REMOTE_ADDR'] ?? '',
            $_SERVER['HTTP_USER_AGENT'] ?? '',
            $expiresAt,
        ]);

        // Cleanup expired sessions
        $db->exec("DELETE FROM user_sessions WHERE expires_at < datetime('now')");

        return $sessionId;
    }

    private static function setSessionCookie(string $sessionId): void
    {
        setcookie(self::COOKIE_NAME, $sessionId, [
            'expires' => time() + self::SESSION_LIFETIME,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => isset($_SERVER['HTTPS']),
        ]);
    }

    private static function clearSessionCookie(): void
    {
        setcookie(self::COOKIE_NAME, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function getRoleId(string $name): int
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT id FROM roles WHERE name = ?');
        $stmt->execute([$name]);
        return (int) $stmt->fetchColumn();
    }
}
