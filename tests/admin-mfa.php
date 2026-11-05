<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
use Novikov\{AdminAuth, AdminSession, Database, Migrator, Totp};

$schema = 'novikov_test_' . bin2hex(random_bytes(8));
$checks = 0;
function verify(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
    $GLOBALS['checks']++;
}
try {
    $db = Database::connect();
    $db->exec("CREATE DATABASE `$schema` CHARACTER SET utf8mb4");
    $created = true;
    $db->exec("USE `$schema`");
    Migrator::run($db);
    $password = bin2hex(random_bytes(24));
    $email = 'mfa@example.test';
    $db->prepare("INSERT INTO admin_users(email,password_hash,role) VALUES (?,?,'super_admin')")->execute([$email,password_hash($password,PASSWORD_ARGON2ID)]);
    $id = (int)$db->lastInsertId();
    // Exercise every authentication operation under the documented runtime role,
    // keeping privileged setup/clock/disabled-account fixtures on $db only.
    $role = 'nt_mfa_role_' . bin2hex(random_bytes(8));
    $user = 'nt_mfa_user_' . bin2hex(random_bytes(8));
    foreach (Novikov\RuntimeGrants::statements($schema, $role) as $index => $sql) {
        $db->exec($sql);
        if ($index === 0) $roleCreated = true;
    }
    $runtimePassword = bin2hex(random_bytes(24));
    $db->exec("CREATE USER '$user'@'localhost' IDENTIFIED BY " . $db->quote($runtimePassword));
    $userCreated = true;
    $db->exec("GRANT `$role` TO '$user'@'localhost'");
    $maria = stripos((string)$db->query('SELECT VERSION()')->fetchColumn(), 'MariaDB') !== false;
    $db->exec($maria ? "SET DEFAULT ROLE `$role` FOR '$user'@'localhost'" : "SET DEFAULT ROLE `$role` TO '$user'@'localhost'");
    $dsn = preg_replace('/;?dbname=[^;]*/i', '', getenv('MYSQL_DSN'));
    $runtime = new PDO($dsn . ";dbname=$schema", $user, $runtimePassword, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    foreach (['DELETE FROM admin_security', 'ALTER TABLE admin_security ADD COLUMN forbidden INT'] as $sql) {
        try {
            $runtime->exec($sql);
            throw new RuntimeException('Runtime must not delete or alter MFA records');
        } catch (PDOException $e) {
            verify((int)$e->errorInfo[1] === 1142, 'Runtime MFA destructive privilege denied');
        }
    }
    $auth = new AdminAuth($runtime, bin2hex(random_bytes(32)));
    $mfa = $auth->mfa();
    AdminSession::start('');
    $login = static function () use ($db, $auth, $email, &$password): void {
        $db->exec('UPDATE admin_login_limits SET reset_at=0');
        verify($auth->login($email,$password,'test-ip') === 'mfa', 'Password starts MFA challenge');
        verify($auth->current() === null, 'Pending session denied dashboard');
    };
    // RFC 6238 SHA-1 test vector truncated to six digits.
    verify(Totp::code('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 1) === '287082', 'RFC TOTP vector');
    $login();
    $secret = $_SESSION['enroll_secret'];
    verify($mfa->verify('bad') === 'invalid', 'Wrong enrollment code denied');
    verify($mfa->verify(Totp::code($secret,intdiv(time(),30)-3)) === 'invalid', 'Expired TOTP denied');
    verify($mfa->verify(Totp::code($secret,intdiv(time(),30))) === 'ok', 'Enrollment succeeds');
    $codes = $_SESSION['fresh_codes'];
    verify(count($codes) === 10 && count(array_unique($codes)) === 10, 'Ten unique recovery codes');
    $savedSession = $_SESSION;
    $s = $mfa->security($id);
    verify(!str_contains($s['mfa_secret'], $secret), 'Secret encrypted');
    verify(!str_contains($s['recovery_codes'], $codes[0]), 'Recovery codes hashed');
    verify($auth->current()['id'] == $id, 'Enrolled administrator authenticated');
    $login();
    verify($mfa->verify(Totp::code($secret,intdiv(time(),30))) === 'invalid', 'TOTP replay rejected');
    // Permit a fresh normal authenticator verification without sleeping in tests.
    $db->exec("UPDATE admin_security SET last_step=-1 WHERE admin_id=$id");
    verify($mfa->verify(Totp::code($secret,intdiv(time(),30))) === 'ok', 'Normal two-step login succeeds');
    $login();
    verify($mfa->verify($codes[0]) === 'ok', 'Recovery code login succeeds');
    $login();
    verify($mfa->verify($codes[0]) === 'invalid', 'Used recovery code rejected');
    $_SESSION['pending_until'] = time()-1;
    verify($mfa->verify($codes[1]) === 'expired', 'Expired pending challenge rejected');
    $login();
    $db->exec("UPDATE admin_users SET active=0 WHERE id=$id");
    verify($mfa->verify($codes[1]) === 'invalid', 'Disabled account rejected between factors');
    $db->exec("UPDATE admin_users SET active=1 WHERE id=$id");
    $login();
    for ($i=0;$i<5;$i++) verify($mfa->verify('bad') === 'invalid', 'Invalid attempts counted');
    verify($mfa->verify($codes[1]) === 'throttled', 'MFA throttled before valid code');
    $login();
    $token = $mfa->issueReset($email,'verified-test-reference');
    verify($mfa->verify($codes[1]) === 'invalid', 'Operator recovery invalidates pending challenge');
    $_SESSION = $savedSession;
    verify($auth->current() === null, 'Operator recovery revokes existing session immediately');
    AdminSession::start('');
    $db->exec('UPDATE admin_login_limits SET reset_at=0');
    verify($auth->login($email,$password,'test-ip') === 'invalid', 'Login suspended during recovery');
    $newPassword = bin2hex(random_bytes(24));
    verify($mfa->reset($email,'bad',$newPassword,'test-ip') === 'invalid', 'Invalid reset authorization rejected');
    verify($mfa->reset($email,$token,'short','test-ip') === 'invalid', 'Short reset password rejected');
    $db->exec("UPDATE admin_security SET reset_expires=0 WHERE admin_id=$id");
    verify($mfa->reset($email,$token,$newPassword,'test-ip') === 'invalid', 'Expired reset authorization rejected');
    $newToken = $mfa->issueReset($email,'verified-reissue');
    verify($mfa->reset($email,$token,$newPassword,'test-ip') === 'invalid', 'Replaced reset token rejected');
    verify($mfa->reset($email,$newToken,$newPassword,'test-ip') === 'ok', 'Operator recovery succeeds');
    $db->exec('UPDATE admin_login_limits SET reset_at=0');
    verify($mfa->reset($email,$newToken,$newPassword,'test-ip') === 'invalid', 'Reset token single use');
    verify($auth->login($email,$password,'test-ip') === 'invalid', 'Old password rejected');
    $password = $newPassword;
    $login();
    verify(isset($_SESSION['enroll_secret']) && $_SESSION['enroll_secret'] !== $secret, 'Recovery requires new MFA enrollment');
    verify($mfa->verify($codes[2]) === 'invalid', 'Old recovery codes revoked');
    verify($mfa->verify(Totp::code($_SESSION['enroll_secret'],intdiv(time(),30))) === 'ok', 'New enrollment restores access');
    $logs = json_encode($db->query('SELECT * FROM admin_activity_logs')->fetchAll());
    foreach ([$secret,$codes[0],$token,$newToken,$password] as $sensitive) verify(!str_contains($logs,$sensitive), 'No credentials in audit');
    foreach (['mfa_enrolled','mfa_verified','recovery_code_used','recovery_authorized','credentials_reset','mfa_failed','mfa_expired','security_throttled'] as $event) verify(str_contains($logs,$event), "$event audited");
    AdminSession::clear();
    echo "PASS: $checks MFA and recovery checks\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: ' . ($e instanceof PDOException ? 'Test database setup/SQL failed: ' . $e->getCode() : $e->getMessage()) . "\n");
    $failed = true;
} finally {
    $runtime = null;
    if (isset($userCreated)) $db->exec("DROP USER '$user'@'localhost'");
    if (isset($roleCreated)) $db->exec("DROP ROLE `$role`");
    if (isset($created)) $db->exec("DROP DATABASE `$schema`");
}
exit(isset($failed) ? 1 : 0);
