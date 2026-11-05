<?php
declare(strict_types=1);

// This suite requires an isolated test server with CREATE USER/ROLE authority.
// All grant targets are the random disposable schema; never the application DB.
$role = 'nt_role_' . bin2hex(random_bytes(8));
$user = 'nt_user_' . bin2hex(random_bytes(8));
$password = bin2hex(random_bytes(24));
$roleCreated = $userCreated = false;
$runtime = null;
try {
    foreach (Novikov\RuntimeGrants::statements($schema, $role) as $index => $sql) {
        $db->exec($sql);
        if ($index === 0) $roleCreated = true;
    }
    $db->exec("CREATE USER '$user'@'localhost' IDENTIFIED BY " . $db->quote($password));
    $userCreated = true;
    $db->exec("GRANT `$role` TO '$user'@'localhost'");
    $maria = stripos((string)$db->query('SELECT VERSION()')->fetchColumn(), 'MariaDB') !== false;
    $db->exec($maria
        ? "SET DEFAULT ROLE `$role` FOR '$user'@'localhost'"
        : "SET DEFAULT ROLE `$role` TO '$user'@'localhost'");
    // Reconnect using only the role-bearing account and the disposable schema.
    $dsn = preg_replace('/;?dbname=[^;]*/i', '', getenv('MYSQL_DSN'));
    $runtime = new PDO($dsn . ";dbname=$schema", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $runtime->exec("SET time_zone='+00:00'");
    $runtime->exec("INSERT INTO ledger_accounts (code,normal_side) VALUES ('runtime_asset','debit'),('runtime_user','credit')");
    $a = (int)$runtime->query("SELECT id FROM ledger_accounts WHERE code='runtime_asset'")->fetchColumn();
    $b = (int)$runtime->query("SELECT id FROM ledger_accounts WHERE code='runtime_user'")->fetchColumn();
    $runtimeLedger = new Novikov\Finance\Ledger($runtime);
    $posted = $runtimeLedger->post('runtime-post', 'runtime', 'Role fixture', pair($a, $b, '2'));
    verify($posted > 0, 'runtime role can post with account locks');
    verify($runtimeLedger->post('runtime-post', 'runtime', 'Role fixture', pair($a, $b, '2')) === $posted, 'runtime role can replay idempotently');
    reject(fn() => $runtimeLedger->post('runtime-fail', 'runtime', 'Role fixture', pair($a, $b, '3', true)), 'runtime posting preserves overdraft protection');
    verify(countKey($db, 'runtime-fail') === 0, 'runtime failed posting rolls back');
    foreach (['ledger_journals', 'ledger_entries', 'ledger_accounts'] as $table) {
        sqlReject($runtime, "UPDATE $table SET id=id", $table === 'ledger_accounts' ? 1644 : 1142, "runtime cannot update $table");
        sqlReject($runtime, "DELETE FROM $table", 1142, "runtime cannot delete $table");
        sqlReject($runtime, "TRUNCATE TABLE $table", 1142, "runtime cannot truncate $table");
        sqlReject($runtime, "ALTER TABLE $table ADD COLUMN tampered INT", 1142, "runtime cannot alter $table");
        sqlReject($runtime, "DROP TABLE $table", 1142, "runtime cannot drop $table");
    }
    sqlReject($runtime, 'DROP TRIGGER ledger_entries_no_delete', 1142, 'runtime cannot remove protections');
    sqlReject($runtime, 'DELETE FROM schema_migrations', 1142, 'runtime cannot edit migration history');
    $runtime->beginTransaction();
    $runtime->query('SELECT * FROM users WHERE id=101 FOR UPDATE')->fetch();
    $runtime->rollBack();
    verify(true, 'runtime user registration can lock existing users');
    sqlReject($runtime, "UPDATE system_settings SET setting_value='true' WHERE setting_key LIKE 'real_money_%'", 1142, 'runtime cannot enable real money');
    $switches = $db->query("SELECT setting_value FROM system_settings WHERE setting_key LIKE 'real_money_%'")->fetchAll(PDO::FETCH_COLUMN);
    verify(count($switches) === 3 && array_reduce($switches, fn(bool $disabled, string $value): bool =>
        $disabled && json_decode($value, true, 512, JSON_THROW_ON_ERROR) === false, true), 'all real-money switches remain disabled');
} finally {
    $runtime = null;
    if ($userCreated) $db->exec("DROP USER '$user'@'localhost'");
    if ($roleCreated) $db->exec("DROP ROLE `$role`");
}
