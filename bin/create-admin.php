<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

// Installation-only credentials are supplied through the secret store.
$email = strtolower(trim(getenv('ADMIN_EMAIL') ?: ''));
$password = getenv('ADMIN_INITIAL_PASSWORD');
if (!$email || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$password || strlen($password) < 16 || strlen($password) > 1024) {
    fwrite(STDERR, "Set ADMIN_EMAIL and ADMIN_INITIAL_PASSWORD (at least 16 characters) through secure configuration.\n");
    exit(1);
}
try {
    $db = Novikov\Database::connect();
    $db->beginTransaction();
    $q = $db->prepare("INSERT INTO admin_users (email,password_hash,role) VALUES (?,?,'super_admin')");
    $q->execute([$email, password_hash($password, PASSWORD_ARGON2ID)]);
    $id = $db->lastInsertId();
    $db->prepare("INSERT INTO admin_activity_logs (admin_id,action,reason) VALUES (?,'admin_created','Secure CLI installation')")->execute([$id]);
    $db->commit();
    echo "Administrator created. Remove ADMIN_INITIAL_PASSWORD from configuration now.\n";
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    fwrite(STDERR, "Administrator creation failed. Check configuration; existing accounts are never overwritten.\n");
    exit(1);
}
