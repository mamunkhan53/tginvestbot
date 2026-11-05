<?php
declare(strict_types=1);
namespace Novikov;

use PDO;

final class AdminAuth
{
    // Valid Argon2id hash: unknown accounts still perform password verification.
    private const DUMMY = '$argon2id$v=19$m=65536,t=4,p=1$ZHVtbXlzYWx0MTIzNDU2Nw$1bCVoLHMl3NGBjkLxNBY2Gm8pfzGEo7BV30Q8zd3B5Y';

    public function __construct(private PDO $db, private string $secret)
    {
        if (strlen($secret) < 32) throw new \RuntimeException('SESSION_SECRET must contain at least 32 characters');
    }

    public function audit(?int $id, string $action, string $reason): void
    {
        $this->db->prepare('INSERT INTO admin_activity_logs (admin_id,action,reason) VALUES (?,?,?)')
            ->execute([$id, $action, $reason]);
    }

    public static function allowed(string $role, string $permission): bool
    {
        $roles = ['super_admin', 'administrator', 'finance_manager', 'support_manager', 'analyst'];
        return match ($permission) {
            'dashboard' => in_array($role, $roles, true),
            'audit' => in_array($role, ['super_admin', 'administrator'], true),
            default => false,
        };
    }

    public function current(): ?array
    {
        if (!isset($_SESSION['admin_id'])) return null;
        $q = $this->db->prepare('SELECT * FROM admin_users WHERE id=?');
        $q->execute([$_SESSION['admin_id']]);
        $admin = $q->fetch();
        $security = $admin ? $this->mfa()->security((int)$admin['id']) : null;
        if (!$admin || !$admin['active'] || !$security['mfa_secret'] || $security['reset_hash'] !== null
            || (int)($_SESSION['security_version'] ?? 0) !== (int)$security['version']
            || !self::allowed($admin['role'], 'dashboard')
            || !hash_equals($_SESSION['credential'] ?? '', hash('sha256', $admin['password_hash']))
            || time() - ($_SESSION['seen'] ?? 0) >= 1800
            || time() - ($_SESSION['issued'] ?? 0) >= 28800) {
            $id = $admin ? (int)$admin['id'] : null;
            AdminSession::clear();
            $this->audit($id, 'session_expired', 'Session expired or account credentials/access changed');
            return null;
        }
        $_SESSION['seen'] = time();
        if (time() - $_SESSION['rotated'] >= 900) {
            if (!session_regenerate_id(true)) throw new \RuntimeException('Session rotation failed');
            $_SESSION['rotated'] = time();
        }
        return $admin;
    }

    /** Returns ok, invalid or throttled. Limits are reserved before verification. */
    public function login(string $email, string $password, string $ip): string
    {
        $email = strtolower(trim($email));
        $identity = hash_hmac('sha256', 'email:' . $email, $this->secret);
        $source = hash_hmac('sha256', 'ip:' . $ip, $this->secret);
        // Lock in deterministic order; shared DB makes limits survive worker restarts.
        $buckets = [$identity => 5, $source => 30];
        ksort($buckets);
        $this->db->beginTransaction();
        try {
            $limited = false;
            foreach ($buckets as $key => $limit) {
                $this->db->prepare('INSERT IGNORE INTO admin_login_limits VALUES (?,0,?)')->execute([$key, time() + 900]);
                $q = $this->db->prepare('SELECT attempts,reset_at FROM admin_login_limits WHERE bucket=? FOR UPDATE');
                $q->execute([$key]);
                $row = $q->fetch();
                $count = (int)$row['reset_at'] <= time() ? 0 : (int)$row['attempts'];
                $reset = (int)$row['reset_at'] <= time() ? time() + 900 : (int)$row['reset_at'];
                $limited = $limited || $count >= $limit;
                $this->db->prepare('UPDATE admin_login_limits SET attempts=?,reset_at=? WHERE bucket=?')
                    ->execute([min($count + 1, $limit), $reset, $key]);
            }
            if ($limited) {
                $this->audit(null, 'login_throttled', 'Login rate limit reached; identity=' . $identity);
                $this->db->commit();
                return 'throttled';
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        $q = $this->db->prepare('SELECT * FROM admin_users WHERE email=?');
        $q->execute([$email]);
        $admin = $q->fetch();
        $valid = password_verify($password, $admin['password_hash'] ?? self::DUMMY);
        if (!$valid || !$admin || !$admin['active'] || !self::allowed($admin['role'], 'dashboard')) {
            $this->audit(null, 'login_failed', 'Invalid credentials or inactive account; identity=' . $identity);
            return 'invalid';
        }
        if (password_needs_rehash($admin['password_hash'], PASSWORD_ARGON2ID)) {
            $hash = password_hash($password, PASSWORD_ARGON2ID);
            $this->db->prepare('UPDATE admin_users SET password_hash=? WHERE id=? AND password_hash=?')
                ->execute([$hash, $admin['id'], $admin['password_hash']]);
            $admin['password_hash'] = $hash;
        }
        return $this->mfa()->begin($admin);
    }

    public function mfa(): AdminMfa { return new AdminMfa($this->db, $this->secret, $this); }

    public function reserveAttempts(string $identity, int $limit): bool
    {
        $key = hash_hmac('sha256', $identity, $this->secret);
        $this->db->beginTransaction();
        try {
            $this->db->prepare('INSERT IGNORE INTO admin_login_limits VALUES (?,0,?)')->execute([$key, time()+900]);
            $q = $this->db->prepare('SELECT * FROM admin_login_limits WHERE bucket=? FOR UPDATE');
            $q->execute([$key]);
            $row = $q->fetch();
            $count = (int)$row['reset_at'] <= time() ? 0 : (int)$row['attempts'];
            $this->db->prepare('UPDATE admin_login_limits SET attempts=?,reset_at=? WHERE bucket=?')
                ->execute([min($count+1, $limit), $count === 0 ? time()+900 : $row['reset_at'], $key]);
            if ($count >= $limit) $this->audit(null, 'security_throttled', 'Security verification rate limit reached');
            $this->db->commit();
            return $count < $limit;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }
}
