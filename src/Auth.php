<?php

namespace App;

use PDO;

class Auth
{
    private const COOKIE_NAME = 'app_session';
    private const SESSION_LIFETIME = 3600; // 1 hour

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

            $stmt = $db->prepare('INSERT INTO users (username, display_name, email, auth_provider, entraid_oid) VALUES (?, ?, ?, ?, ?) RETURNING id');
            $stmt->execute([
                $username,
                $tokenData['name'] ?? $username,
                $tokenData['email'] ?? '',
                'entraid',
                $tokenData['oid'],
            ]);
            $userId = (int) $stmt->fetchColumn();

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
            AppLogger::debug('auth', 'No session cookie found');
            return null;
        }

        $db = Database::connection();
        $stmt = $db->prepare('
            SELECT us.*, u.username, u.display_name, u.email, u.auth_provider, u.is_active, u.ssh_public_keys, u.default_storage
            FROM user_sessions us
            JOIN users u ON u.id = us.user_id
            WHERE us.id = ? AND us.expires_at > NOW()
        ');
        $stmt->execute([$sessionId]);
        $session = $stmt->fetch();

        if (!$session || !$session['is_active']) {
            AppLogger::debug('auth', 'Invalid or expired session', ['session_id_prefix' => substr($sessionId, 0, 8)]);
            self::clearSessionCookie();
            return null;
        }

        AppLogger::debug('auth', 'Session validated', ['user_id' => $session['user_id'], 'username' => $session['username']]);

        return [
            'id' => $session['user_id'],
            'username' => $session['username'],
            'display_name' => $session['display_name'],
            'email' => $session['email'],
            'auth_provider' => $session['auth_provider'],
            'ssh_public_keys' => $session['ssh_public_keys'] ?? '',
            'default_storage' => $session['default_storage'] ?? '',
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

        // Role-based permissions
        $stmt = $db->prepare('
            SELECT DISTINCT p.key
            FROM permissions p
            JOIN role_permissions rp ON rp.permission_id = p.id
            JOIN user_roles ur ON ur.role_id = rp.role_id
            WHERE ur.user_id = ?
        ');
        $stmt->execute([$userId]);
        $perms = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));

        // User-level overrides (granted=1 adds, granted=0 removes)
        $stmt = $db->prepare('
            SELECT p.key, upo.granted
            FROM user_permission_overrides upo
            JOIN permissions p ON p.id = upo.permission_id
            WHERE upo.user_id = ?
        ');
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll() as $row) {
            if ($row['granted']) {
                $perms[$row['key']] = true;
            } else {
                unset($perms[$row['key']]);
            }
        }

        return array_keys($perms);
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
        $db->exec("DELETE FROM user_sessions WHERE expires_at < NOW()");

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

    // ── Bruteforce Protection ──────────────────────────────────────────

    /**
     * Check if IP is rate-limited. Returns seconds to wait, or 0 if allowed.
     * Progressive delay: 1s after 3 fails, 5s after 5, 15s after 8, 60s after 10.
     */
    public static function checkBruteforce(string $ip): int
    {
        $db = Database::connection();

        // Count failed attempts in last 15 minutes
        $stmt = $db->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND attempted_at > NOW() - INTERVAL '15 minutes'");
        $stmt->execute([$ip]);
        $count = (int) $stmt->fetchColumn();

        if ($count < 3) return 0;

        // Progressive delay
        $delay = match (true) {
            $count >= 10 => 60,
            $count >= 8  => 15,
            $count >= 5  => 5,
            default      => 1,
        };

        // Check if enough time passed since last attempt
        $stmt = $db->prepare("SELECT EXTRACT(EPOCH FROM (NOW() - MAX(attempted_at))) FROM login_attempts WHERE ip_address = ? AND attempted_at > NOW() - INTERVAL '15 minutes'");
        $stmt->execute([$ip]);
        $elapsed = (float) $stmt->fetchColumn();

        if ($elapsed < $delay) {
            return (int) ceil($delay - $elapsed);
        }

        return 0;
    }

    public static function recordFailedLogin(string $ip, string $username = ''): void
    {
        $db = Database::connection();
        $stmt = $db->prepare('INSERT INTO login_attempts (ip_address, username) VALUES (?, ?)');
        $stmt->execute([$ip, $username]);

        // Cleanup attempts older than 1 hour
        $db->exec("DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL '1 hour'");
    }

    public static function clearLoginAttempts(string $ip): void
    {
        $db = Database::connection();
        $stmt = $db->prepare('DELETE FROM login_attempts WHERE ip_address = ?');
        $stmt->execute([$ip]);
    }

    private static function getRoleId(string $name): int
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT id FROM roles WHERE name = ?');
        $stmt->execute([$name]);
        return (int) $stmt->fetchColumn();
    }
}
