<?php
declare(strict_types=1);
namespace Novikov;

final class AdminSession
{
    public static function start(string $base): void
    {
        $directory = sys_get_temp_dir() . '/novikov-admin-sessions';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Session storage unavailable');
        }
        session_save_path($directory);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.gc_maxlifetime', '1800');
        session_name('NOVIKOV_ADMIN');
        session_set_cookie_params([
            'lifetime' => 0, 'path' => $base . '/', 'secure' => true,
            'httponly' => true, 'samesite' => 'Strict',
        ]);
        if (!session_start()) throw new \RuntimeException('Session unavailable');
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    public static function csrfValid(mixed $token): bool
    {
        return is_string($token) && isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
    }

    public static function establish(array $admin): void
    {
        if (!session_regenerate_id(true)) throw new \RuntimeException('Session rotation failed');
        $_SESSION = [
            'admin_id' => $admin['id'], 'credential' => hash('sha256', $admin['password_hash']),
            'security_version' => $admin['security_version'],
            'issued' => time(), 'seen' => time(), 'rotated' => time(),
            'csrf' => bin2hex(random_bytes(32)),
        ];
    }

    public static function clear(): void
    {
        $_SESSION = [];
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 3600, 'path' => $params['path'],
            'secure' => true, 'httponly' => true, 'samesite' => 'Strict',
        ]);
        session_destroy();
    }
}
