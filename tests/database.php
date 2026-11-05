<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

use Novikov\Database;
use Novikov\Finance\Ledger;
use Novikov\Migrator;

require __DIR__ . '/database-support.php';
// All persistent fixtures live in a new random schema, never the configured
// application's schema. No fallback to temporary or application tables.
$checks = 0;
$workers = [];
$schema = 'novikov_test_' . bin2hex(random_bytes(8));
$created = false;
$failed = false;
function verify(bool $ok, string $label): void {
    global $checks;
    if (!$ok) throw new RuntimeException($label);
    $checks++;
    echo "PASS $label\n";
}
function reject(callable $operation, string $label): void {
    try { $operation(); } catch (DomainException) { verify(true, $label); return; }
    throw new RuntimeException($label);
}
try {
    $control = Database::connect();
    $control->exec("CREATE DATABASE `$schema` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $created = true;
    $db = testConnection($schema);
    $files = glob(dirname(__DIR__) . '/database/migrations/*.sql');
    verify(count(Migrator::run($db)) === count($files), 'fresh migrations');
    verify(Migrator::run($db) === [], 'migration rerun is a no-op');
    // Simulate DDL committed before migration bookkeeping was saved.
    $db->exec('DELETE FROM schema_migrations');
    verify(count(Migrator::run($db)) === count($files), 'recovery after partially recorded migrations');
    $db->exec("UPDATE schema_migrations SET checksum = REPEAT('0',64) LIMIT 1");
    try { Migrator::run($db); throw new LogicException('Checksum mismatch accepted'); }
    catch (RuntimeException) { verify(true, 'migration checksum mismatch rejected'); }
    $db->exec('DELETE FROM schema_migrations');
    Migrator::run($db);
    $db->exec("INSERT INTO ledger_accounts (id,code,normal_side,prevent_negative) VALUES (1,'test_asset','debit',1),(2,'test_user','credit',1)");
    $ledger = new Ledger($db);
    $entries = [
        ['account'=>1,'side'=>'debit','amount'=>'5.050000000000000001'],
        ['account'=>2,'side'=>'credit','amount'=>'5.050000000000000001'],
    ];
    $id = $ledger->post('test-credit','test','Temporary integration fixture',$entries);
    verify($id > 0, 'journal creation');
    verify((int)$db->query('SELECT COUNT(*) FROM ledger_entries')->fetchColumn() === 2, 'balanced pair persisted');
    verify($db->query('SELECT amount FROM ledger_entries LIMIT 1')->fetchColumn() === '5.050000000000000001', 'decimal precision');
    verify($ledger->post('test-credit','test','Temporary integration fixture',$entries) === $id, 'idempotent replay');
    verify((int)$db->query('SELECT COUNT(*) FROM ledger_entries')->fetchColumn() === 2, 'replay no duplicate');
    reject(fn() => $ledger->post('test-credit','changed','Temporary integration fixture',$entries), 'key payload mismatch');
    $unbalanced = $entries;
    $unbalanced[0]['amount'] = '6';
    reject(fn() => $ledger->post('unbalanced','test','Fixture',$unbalanced), 'unbalanced rejection');
    $spend = [
        ['account'=>1,'side'=>'credit','amount'=>'6'],
        ['account'=>2,'side'=>'debit','amount'=>'6'],
    ];
    reject(fn() => $ledger->post('overspend','test','Fixture',$spend), 'negative balance rejection');
    verify((int)$db->query('SELECT COUNT(*) FROM ledger_journals')->fetchColumn() === 1, 'failed operations roll back');
    $spend[0]['amount'] = $spend[1]['amount'] = '5.050000000000000001';
    $ledger->post('spend','test','Fixture',$spend);
    verify(bccomp((string)$db->query("SELECT SUM(CASE WHEN side='credit' THEN amount ELSE -amount END) FROM ledger_entries WHERE account_id=2")->fetchColumn(), '0',18) === 0, 'exact depletion');
    reject(fn() => $ledger->post('spend-again','test','Fixture',$spend), 'second spend rejected');
    require __DIR__ . '/database-concurrency.php';
    require __DIR__ . '/database-events.php';
    require __DIR__ . '/database-constraints.php';
    echo "$checks database checks passed in a disposable persistent schema.\n";
} catch (Throwable $e) {
    $failed = true;
    $driver = $e instanceof PDOException ? (int)($e->errorInfo[1] ?? 0) : 0;
    fwrite(STDERR, "Database test failed (type " . get_class($e) . ", driver $driver, at " . basename($e->getFile()) . ':' . $e->getLine() . "). Details suppressed to protect connection information.\n");
} finally {
    foreach ($workers as [$process, $pipes]) {
        if (proc_get_status($process)['running']) proc_terminate($process, 9);
        foreach ($pipes as $pipe) fclose($pipe);
        proc_close($process);
    }
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    if (isset($locker) && $locker->inTransaction()) $locker->rollBack();
    if ($created) {
        try {
            $control->exec("DROP DATABASE `$schema`");
            echo "Disposable test schema removed.\n";
        } catch (Throwable) {
            $failed = true;
            fwrite(STDERR, "Cleanup failed; remove test schema $schema manually.\n");
        }
    }
}
exit($failed ? 1 : 0);
