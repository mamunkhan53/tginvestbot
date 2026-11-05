<?php
declare(strict_types=1);
namespace Novikov;

use PDO;

final class AdminMfa
{
    public function __construct(private PDO $db, private string $key, private AdminAuth $auth) {}

    public function security(int $id, bool $lock = false): array
    {
        $this->db->prepare("INSERT IGNORE INTO admin_security(admin_id,recovery_codes) VALUES (?,'[]')")->execute([$id]);
        $q = $this->db->prepare('SELECT * FROM admin_security WHERE admin_id=?' . ($lock ? ' FOR UPDATE' : ''));
        $q->execute([$id]);
        return $q->fetch();
    }

    private function encrypt(string $value): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($value, $nonce, hash('sha256', $this->key, true)));
    }

    private function decrypt(string $value): string
    {
        $raw = base64_decode($value, true);
        if ($raw === false || strlen($raw) < 40) throw new \RuntimeException('Invalid MFA storage');
        $plain = sodium_crypto_secretbox_open(substr($raw, 24), substr($raw, 0, 24), hash('sha256', $this->key, true));
        if ($plain === false) throw new \RuntimeException('MFA key unavailable');
        return $plain;
    }

    public function begin(array $admin): string
    {
        $security = $this->security((int)$admin['id']);
        if ($security['reset_hash'] !== null) return 'invalid';
        session_regenerate_id(true);
        $_SESSION = [
            'pending_id' => $admin['id'], 'pending_credential' => hash('sha256', $admin['password_hash']),
            'pending_version' => $security['version'], 'pending_until' => time() + 300,
            'csrf' => bin2hex(random_bytes(32)),
        ];
        if (!$security['mfa_secret']) $_SESSION['enroll_secret'] = Totp::secret();
        return 'mfa';
    }

    public function verify(string $code): string
    {
        $id = (int)($_SESSION['pending_id'] ?? 0);
        if (!$id || time() >= ($_SESSION['pending_until'] ?? 0)) {
            unset($_SESSION['pending_id'], $_SESSION['enroll_secret']);
            $this->auth->audit(null, 'mfa_expired', 'Second-factor challenge expired');
            return 'expired';
        }
        if (!$this->auth->reserveAttempts('mfa:' . $id, 5)) return 'throttled';
        $this->db->beginTransaction();
        try {
            $q = $this->db->prepare('SELECT * FROM admin_users WHERE id=? FOR UPDATE');
            $q->execute([$id]);
            $admin = $q->fetch();
            $s = $this->security($id, true);
            if (!$admin || !$admin['active'] || !AdminAuth::allowed($admin['role'], 'dashboard')
                || !hash_equals($_SESSION['pending_credential'], hash('sha256', $admin['password_hash']))
                || (int)$s['version'] !== (int)$_SESSION['pending_version'] || $s['reset_hash'] !== null) {
                $result = 'invalid';
            } else {
                $enrolling = !$s['mfa_secret'];
                $secret = $enrolling ? ($_SESSION['enroll_secret'] ?? '') : $this->decrypt($s['mfa_secret']);
                $step = $secret ? Totp::verify($secret, $code, (int)$s['last_step']) : null;
                $codes = json_decode($s['recovery_codes'], true, 512, JSON_THROW_ON_ERROR);
                $recoveryIndex = $enrolling ? false : array_search(hash('sha256', $code), $codes, true);
                if ($step === null && $recoveryIndex === false) {
                    $result = 'invalid';
                } else {
                    $fresh = [];
                    if ($enrolling) {
                        for ($i = 0; $i < 10; $i++) $fresh[] = bin2hex(random_bytes(16));
                        $codes = array_map(fn($v) => hash('sha256', $v), $fresh);
                    } elseif ($recoveryIndex !== false) {
                        unset($codes[$recoveryIndex]);
                    }
                    $this->db->prepare('UPDATE admin_security SET mfa_secret=?,last_step=?,recovery_codes=? WHERE admin_id=?')
                        ->execute([$enrolling ? $this->encrypt($secret) : $s['mfa_secret'], $step ?? $s['last_step'], json_encode(array_values($codes)), $id]);
                    $this->auth->audit($id, $enrolling ? 'mfa_enrolled' : ($recoveryIndex !== false ? 'recovery_code_used' : 'mfa_verified'), 'Second factor accepted');
                    $this->auth->audit($id, 'login_success', 'Password and second factor verified');
                    $admin['security_version'] = $s['version'];
                    $result = 'ok';
                }
            }
            if ($result !== 'ok') $this->auth->audit($id, 'mfa_failed', 'Invalid second factor or changed account access');
            $this->db->commit();
            if ($result === 'ok') {
                AdminSession::establish($admin);
                if ($fresh) $_SESSION['fresh_codes'] = $fresh;
            }
            return $result;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /** CLI-only operator authorization; token is displayed once and never stored in plaintext. */
    public function issueReset(string $email, string $reference): string
    {
        if (PHP_SAPI !== 'cli' || !preg_match('/^[A-Za-z0-9_-]{3,80}$/D', $reference)) throw new \RuntimeException('Operator reference required');
        $this->db->beginTransaction();
        try {
            $q = $this->db->prepare('SELECT id FROM admin_users WHERE email=? AND active=1 FOR UPDATE');
            $q->execute([strtolower(trim($email))]);
            $id = $q->fetchColumn();
            if (!$id) throw new \RuntimeException('Active account not found');
            $this->security((int)$id, true);
            $token = bin2hex(random_bytes(32));
            $this->db->prepare('UPDATE admin_security SET version=version+1,reset_hash=?,reset_expires=? WHERE admin_id=?')
                ->execute([hash('sha256', $token), time() + 900, $id]);
            $this->auth->audit((int)$id, 'recovery_authorized', 'Operator identity check reference: ' . $reference);
            $this->db->commit();
            return $token;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function reset(string $email, string $token, string $password, string $ip): string
    {
        if (!$this->auth->reserveAttempts('reset-ip:' . $ip, 20)
            || !$this->auth->reserveAttempts('reset-email:' . strtolower(trim($email)), 5)) return 'throttled';
        if (strlen($password) < 16 || strlen($password) > 1024) return 'invalid';
        $this->db->beginTransaction();
        try {
            $q = $this->db->prepare('SELECT * FROM admin_users WHERE email=? FOR UPDATE');
            $q->execute([strtolower(trim($email))]);
            $admin = $q->fetch();
            $s = $admin ? $this->security((int)$admin['id'], true) : null;
            if (!$admin || !$admin['active'] || !$s['reset_hash'] || (int)$s['reset_expires'] <= time()
                || !hash_equals($s['reset_hash'], hash('sha256', $token))) {
                $this->auth->audit(null, 'recovery_failed', 'Invalid or expired recovery authorization');
                $this->db->commit();
                return 'invalid';
            }
            $this->db->prepare('UPDATE admin_users SET password_hash=? WHERE id=?')->execute([password_hash($password, PASSWORD_ARGON2ID), $admin['id']]);
            $this->db->prepare("UPDATE admin_security SET version=version+1,mfa_secret=NULL,last_step=-1,recovery_codes='[]',reset_hash=NULL,reset_expires=NULL WHERE admin_id=?")->execute([$admin['id']]);
            $this->auth->audit((int)$admin['id'], 'credentials_reset', 'Operator recovery completed; all sessions and factors revoked; enrollment required');
            $this->db->commit();
            return 'ok';
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }
}
