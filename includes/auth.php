<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

class Auth {

    public static function start(): void {
        if (session_status() === PHP_SESSION_NONE) {
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
            session_name('bulkstk_session');
            session_set_cookie_params([
                'lifetime' => SESSION_TIMEOUT,
                'path'     => '/',
                'secure'   => $isHttps,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
    }

    public static function login(string $email, string $password): array {
        $user = Database::fetchOne(
            "SELECT * FROM admin_users WHERE email = ? AND status = 1 LIMIT 1",
            [trim($email)]
        );

        if (!$user || !password_verify($password, $user['password'])) {
            logActivity(0, 'login_failed', 'auth', "Failed login attempt for: {$email}");
            return ['success' => false, 'message' => 'Invalid email or password.'];
        }

        $_SESSION['user_id']    = $user['id'];
        $_SESSION['user_name']  = $user['name'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_role']  = $user['role'];
        $_SESSION['logged_in']  = true;
        $_SESSION['last_active'] = time();

        Database::query(
            "UPDATE admin_users SET last_login = NOW() WHERE id = ?",
            [$user['id']]
        );

        logActivity($user['id'], 'login', 'auth', 'Successful login');
        return ['success' => true, 'message' => 'Login successful.'];
    }

    public static function logout(): void {
        if (self::isLoggedIn()) {
            logActivity($_SESSION['user_id'], 'logout', 'auth', 'User logged out');
        }
        $_SESSION = [];
        session_destroy();
    }

    public static function isLoggedIn(): bool {
        if (empty($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
            return false;
        }
        // Session timeout check
        if (isset($_SESSION['last_active']) && (time() - $_SESSION['last_active']) > SESSION_TIMEOUT) {
            self::logout();
            return false;
        }
        $_SESSION['last_active'] = time();
        return true;
    }

    public static function requireLogin(string $redirect = '/index.php'): void {
        self::start();
        if (!self::isLoggedIn()) {
            $base = defined('APP_URL') ? APP_URL : '';
            header('Location: ' . $base . $redirect);
            exit;
        }
    }

    public static function requireRole(string ...$roles): void {
        self::requireLogin();
        if (!in_array($_SESSION['user_role'] ?? '', $roles, true)) {
            http_response_code(403);
            die('<h3>Access Denied – insufficient permissions.</h3>');
        }
    }

    public static function currentUser(): ?array {
        if (!self::isLoggedIn()) return null;
        return Database::fetchOne(
            "SELECT id, name, email, role, last_login FROM admin_users WHERE id = ?",
            [$_SESSION['user_id']]
        );
    }

    public static function userId(): int {
        return (int) ($_SESSION['user_id'] ?? 0);
    }

    public static function userRole(): string {
        return $_SESSION['user_role'] ?? '';
    }

    public static function hasRole(string ...$roles): bool {
        return in_array(self::userRole(), $roles, true);
    }
}
