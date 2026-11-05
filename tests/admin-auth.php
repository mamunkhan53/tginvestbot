<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

use Novikov\AdminAuth;
use Novikov\AdminSession;
use Novikov\Database;
use Novikov\Migrator;
use Novikov\Totp;

// Requires a disposable test server with CREATE/DROP DATABASE privileges.
// Never falls back to application tables.
$schema = 'novikov_test_' . bin2hex(random_bytes(8));
$checks = 0;
function check(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    $GLOBALS['checks']++;
}
function signIn(AdminAuth $auth, string $password): string {
    $result = $auth->login('admin@example.test', $password, '127.0.0.1');
    if ($result !== 'mfa') return $result;
    if (isset($_SESSION['enroll_secret'])) {
        $result = $auth->mfa()->verify(Totp::code($_SESSION['enroll_secret'], intdiv(time(), 30)));
        $GLOBALS['codes'] = $_SESSION['fresh_codes'];
        return $result;
    }
    return $auth->mfa()->verify(array_shift($GLOBALS['codes']));
}
try {
    $db = Database::connect();
    $db->exec("CREATE DATABASE `$schema` CHARACTER SET utf8mb4");
    $created = true;
    $db->exec("USE `$schema`");
    Migrator::run($db);
    check(Migrator::run($db) === [], 'Migrations repeat safely');
    $password = bin2hex(random_bytes(20));
    $hash = password_hash($password, PASSWORD_ARGON2ID);
    $db->prepare("INSERT INTO admin_users(email,password_hash,role) VALUES ('admin@example.test',?,'super_admin')")
        ->execute([$hash]);
    $id = (int)$db->lastInsertId();
    $auth = new AdminAuth($db, str_repeat('test-only-', 5));
    AdminSession::start('');
    $old = session_id();
    $csrf = $_SESSION['csrf'];
    check(!AdminSession::csrfValid(null), 'Missing CSRF rejected');
    check(!AdminSession::csrfValid(['x']), 'Array CSRF rejected');
    check(!AdminSession::csrfValid('wrong'), 'Wrong CSRF rejected');
    check(AdminSession::csrfValid($csrf), 'Valid CSRF accepted');
    check($auth->login(' ADMIN@EXAMPLE.TEST ', $password, '127.0.0.1') === 'mfa', 'Normalized email accepted; second factor required');
    check($auth->current() === null, 'Password alone never authenticates');
    $secret = $_SESSION['enroll_secret'];
    check($auth->mfa()->verify(Totp::code($secret, intdiv(time(), 30))) === 'ok', 'Enrollment confirmed');
    $GLOBALS['codes'] = $_SESSION['fresh_codes'];
    check(session_id() !== $old, 'Session ID rotated');
    check(!AdminSession::csrfValid($csrf), 'Pre-login CSRF invalidated');
    check($auth->current()['id'] == $id, 'Authenticated identity reloaded');
    foreach (['super_admin','administrator','finance_manager','support_manager','analyst'] as $role) {
        check(AdminAuth::allowed($role, 'dashboard'), "$role dashboard permission");
        check(AdminAuth::allowed($role, 'audit') === in_array($role, ['super_admin','administrator']), "$role audit permission");
        check(!AdminAuth::allowed($role, 'withdraw'), "$role cannot withdraw");
    }
    check(!AdminAuth::allowed('unknown', 'dashboard'), 'Unknown roles denied');
    $db->exec("UPDATE admin_users SET role='analyst' WHERE id=$id");
    check($auth->current()['role'] === 'analyst', 'Role changes apply immediately');
    $db->exec("UPDATE admin_users SET active=0 WHERE id=$id");
    check($auth->current() === null, 'Disabled account revoked');
    check($auth->login('admin@example.test', $password, '127.0.0.1') === 'invalid', 'Disabled login denied');
    $db->exec("UPDATE admin_users SET active=1 WHERE id=$id");
    AdminSession::start('');
    check(signIn($auth, $password) === 'ok', 'Re-enabled login works');
    $_SESSION['seen'] = time() - 1800;
    check($auth->current() === null, 'Idle timeout enforced');
    AdminSession::start('');
    check(signIn($auth, $password) === 'ok', 'Login after timeout');
    $_SESSION['issued'] = time() - 28800;
    check($auth->current() === null, 'Absolute timeout enforced');
    AdminSession::start('');
    // This assertion tests credential revocation, not attempts accumulated above.
    // Separate rate-limit assertions below exercise the real production limits.
    $db->exec('UPDATE admin_login_limits SET reset_at=0');
    check(signIn($auth, $password) === 'ok', 'Login before credential change');
    $db->prepare('UPDATE admin_users SET password_hash=? WHERE id=?')->execute([password_hash('changed-password-for-test', PASSWORD_ARGON2ID), $id]);
    check($auth->current() === null, 'Password change revokes session');
    for ($i = 0; $i < 5; $i++) check($auth->login('unknown@example.test', 'bad', 'ip-' . $i) === 'invalid', 'Unknown identity is generic');
    check($auth->login('unknown@example.test', 'bad', 'different-ip') === 'throttled', 'Account limit survives source changes');
    // A fresh PDO connection must select the disposable schema too.
    $otherDb = Database::connect();
    $otherDb->exec("USE `$schema`");
    $other = new AdminAuth($otherDb, str_repeat('test-only-', 5));
    check($other->login('unknown@example.test', 'bad', 'new-source') === 'throttled', 'Limits persist across connections');
    for ($i = 0; $i < 30; $i++) check($auth->login("unknown-$i@example.test", 'bad', 'shared-source') === 'invalid', 'Source attempts recorded');
    check($auth->login('new@example.test', 'bad', 'shared-source') === 'throttled', 'Source limit covers different accounts');
    $db->exec('UPDATE admin_login_limits SET reset_at=0');
    check($auth->login('unknown@example.test', 'bad', 'shared-source') === 'invalid', 'Expired limits reset');
    $actions = $db->query('SELECT DISTINCT action FROM admin_activity_logs')->fetchAll(PDO::FETCH_COLUMN);
    foreach (['login_success','login_failed','login_throttled','session_expired'] as $action) check(in_array($action, $actions), "$action audited");
    check(!str_contains(json_encode($db->query('SELECT * FROM admin_activity_logs')->fetchAll()), $password), 'Passwords excluded from audit');
    if (session_status() === PHP_SESSION_ACTIVE) AdminSession::clear();
    echo "PASS: $checks administrator authentication checks\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: administrator tests (' . get_class($e) . '); check test database permissions and last assertion. ' . ($e instanceof PDOException ? '' : $e->getMessage()) . "\n");
    $failed = true;
} finally {
    if (isset($created)) $db->exec("DROP DATABASE `$schema`");
}
exit(isset($failed) ? 1 : 0);
