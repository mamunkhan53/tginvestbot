<?php
declare(strict_types=1);

sqlReject($db, "INSERT INTO users (id,telegram_id,first_name,referral_code,referrer_id) VALUES (100,100,'Fixture','self',100)", 1644, 'insert self-referral trigger rejects');
verify((int)$db->query('SELECT COUNT(*) FROM users WHERE id=100')->fetchColumn() === 0, 'rejected self-referral is rolled back');
$db->exec("INSERT INTO users (id,telegram_id,first_name,referral_code) VALUES (101,101,'Fixture','parent')");
$db->exec("INSERT INTO users (id,telegram_id,first_name,referral_code,referrer_id) VALUES (102,102,'Fixture','child',101)");
sqlReject($db, 'UPDATE users SET referrer_id=102 WHERE id=102', 1644, 'update self-referral trigger rejects');
verify((int)$db->query('SELECT referrer_id FROM users WHERE id=102')->fetchColumn() === 101, 'failed update preserves valid referral');
sqlReject($db, 'UPDATE users SET referrer_id=999999 WHERE id=102', 1452, 'referrer foreign key enforced');
sqlReject($db, "INSERT INTO ledger_accounts (code,user_id,normal_side) VALUES ('orphan',999999,'credit')", 1452, 'account owner foreign key enforced');
sqlReject($db, "INSERT INTO ledger_entries (journal_id,account_id,side,amount) VALUES (999999,1,'debit',1)", 1452, 'entry journal foreign key enforced');
$journal = (int)$db->query('SELECT MIN(id) FROM ledger_journals')->fetchColumn();
sqlReject($db, "INSERT INTO ledger_entries (journal_id,account_id,side,amount) VALUES ($journal,999999,'debit',1)", 1452, 'entry account foreign key enforced');
sqlReject($db, 'DELETE FROM ledger_accounts WHERE id=1', 1451, 'referenced account cannot be deleted');
sqlReject($db, "DELETE FROM ledger_journals WHERE id=$journal", 1644, 'journal deletion trigger rejects');
foreach (['0','-1'] as $amount) {
    try {
        $db->exec("INSERT INTO ledger_entries (journal_id,account_id,side,amount) VALUES ($journal,1,'debit',$amount)");
        throw new RuntimeException('Non-positive amount accepted');
    } catch (PDOException $e) {
        verify(in_array((int)$e->errorInfo[1], [3819,4025], true), 'positive amount check enforced');
    }
}

// Use the migration owner here, so denied DML proves triggers, not grants.
$beforeJournals = $db->query('SELECT * FROM ledger_journals ORDER BY id')->fetchAll();
$beforeEntries = $db->query('SELECT * FROM ledger_entries ORDER BY id')->fetchAll();
// Reproduce upgrading an existing populated database, then recovering a
// partially applied DDL migration. No fixtures leave the disposable schema.
$protectionTriggers = ['ledger_journals_no_update', 'ledger_journals_no_delete',
    'ledger_entries_no_update', 'ledger_entries_no_delete', 'ledger_accounts_no_update'];
foreach ($protectionTriggers as $trigger) $db->exec("DROP TRIGGER `$trigger`");
$db->exec("DELETE FROM schema_migrations WHERE name='011_append_only_ledger.sql'");
verify(Novikov\Migrator::run($db) === ['011_append_only_ledger.sql'], 'append-only migration upgrades existing ledger');
$db->exec('DROP TRIGGER ledger_entries_no_delete');
$db->exec("DELETE FROM schema_migrations WHERE name='011_append_only_ledger.sql'");
verify(Novikov\Migrator::run($db) === ['011_append_only_ledger.sql'], 'partial append-only migration resumes');
verify(Novikov\Migrator::run($db) === [], 'append-only upgrade rerun is a no-op');
foreach ([
    'id' => 'id+1000000',
    'idempotency_key' => "'tampered'",
    'payload_hash' => "REPEAT('0',64)",
    'reference' => "'tampered'",
    'reason' => "'tampered'",
    'created_at' => "'2000-01-01 00:00:00'",
] as $column => $value) {
    sqlReject($db, "UPDATE ledger_journals SET $column=$value WHERE id=$journal", 1644, "journal $column is immutable");
}
foreach (['id'=>'id+1000000', 'journal_id'=>'journal_id+1', 'account_id'=>'2', 'side'=>"'credit'", 'amount'=>'999'] as $column => $value) {
    sqlReject($db, "UPDATE ledger_entries SET $column=$value WHERE journal_id=$journal", 1644, "entry $column is immutable");
}
foreach (['ledger_entries', 'ledger_journals'] as $table) {
    sqlReject($db, "DELETE FROM $table", 1644, "$table bulk delete rejected");
    sqlReject($db, "UPDATE $table SET id=id", 1644, "$table no-op update rejected");
}
sqlReject($db, "INSERT INTO ledger_journals (idempotency_key,payload_hash,reference,reason)
 SELECT idempotency_key,payload_hash,reference,reason FROM ledger_journals WHERE id=$journal
 ON DUPLICATE KEY UPDATE reason='tampered'", 1644, 'journal upsert mutation rejected');
sqlReject($db, "REPLACE INTO ledger_entries (id,journal_id,account_id,side,amount)
 SELECT id,journal_id,account_id,side,amount FROM ledger_entries WHERE journal_id=$journal", 1644, 'entry replace rejected');
sqlReject($db, "REPLACE INTO ledger_journals (id,idempotency_key,payload_hash,reference,reason)
 SELECT id,idempotency_key,payload_hash,reference,reason FROM ledger_journals WHERE id=$journal", 1644, 'journal replace rejected');
sqlReject($db, "INSERT INTO ledger_entries (id,journal_id,account_id,side,amount)
 SELECT id,journal_id,account_id,side,amount FROM ledger_entries WHERE journal_id=$journal
 ON DUPLICATE KEY UPDATE amount=999", 1644, 'entry upsert mutation rejected');
verify($db->query('SELECT * FROM ledger_journals ORDER BY id')->fetchAll() === $beforeJournals, 'journal mutation attempts leave exact original rows');
verify($db->query('SELECT * FROM ledger_entries ORDER BY id')->fetchAll() === $beforeEntries, 'entry mutation attempts leave exact original rows');
// Rollbacks must remain possible: immutable does not mean prematurely committed.
$db->beginTransaction();
$db->exec("INSERT INTO ledger_journals (idempotency_key,payload_hash,reference,reason) VALUES ('rollback-fixture',REPEAT('a',64),'test','test')");
$rollbackId = (int)$db->lastInsertId();
$db->exec("INSERT INTO ledger_entries (journal_id,account_id,side,amount) VALUES ($rollbackId,1,'debit',1)");
$db->rollBack();
verify(countKey($db, 'rollback-fixture') === 0, 'append-only journal can roll back');
verify($db->query('SELECT * FROM ledger_entries ORDER BY id')->fetchAll() === $beforeEntries, 'append-only entries can roll back');
require __DIR__ . '/database-runtime-role.php';
