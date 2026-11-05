<?php
declare(strict_types=1);

$db->exec("INSERT INTO ledger_accounts (id,code,normal_side,prevent_negative) VALUES
 (3,'race_asset','debit',1),(4,'race_user','credit',1),
 (5,'other_asset','debit',1),(6,'other_user','credit',1)");
$credit = pair(3, 4, '10');
$results = race($schema, 'concurrent-credit', $credit, 'concurrent-credit', $credit);
verify($results[0]['status'] === 'ok' && $results[1]['status'] === 'ok'
    && $results[0]['id'] === $results[1]['id'], 'concurrent identical retries return one journal');
verify(countKey($db, 'concurrent-credit') === 1, 'concurrent retry persists once');
$results = race($schema, 'spend-a', pair(3,4,'7',true), 'spend-b', array_reverse(pair(3,4,'7',true)));
$statuses = array_column($results, 'status');
sort($statuses);
verify($statuses === ['domain','ok'], 'competing spends cannot overdraw with reversed account order');
verify(bccomp((string)$db->query("SELECT SUM(CASE WHEN side='credit' THEN amount ELSE -amount END) FROM ledger_entries WHERE account_id=4")->fetchColumn(), '3', 18) === 0, 'concurrent spend leaves exact remaining balance');
$results = race($schema, 'disjoint-key', pair(3,4,'1'), 'disjoint-key', pair(5,6,'1'));
$statuses = array_column($results, 'status');
sort($statuses);
verify($statuses === ['domain','ok'], 'duplicate key across disjoint accounts is a domain rejection');
verify(countKey($db, 'disjoint-key') === 1, 'disjoint key produces one journal');

// Hold a real account lock while an independent worker attempts posting.
$locker = testConnection($schema);
$locker->beginTransaction();
$locker->query('SELECT id FROM ledger_accounts WHERE id=3 FOR UPDATE')->fetch();
$blocked = startWorker($schema, 'locked-post', pair(3,4,'1'));
go($blocked);
// An unrelated pair must remain usable while that account is locked.
$free = startWorker($schema, 'unrelated-post', pair(5,6,'1'));
go($free);
verify(result($free)['status'] === 'ok', 'unrelated accounts are not blocked');
$timeout = result($blocked);
verify($timeout['status'] === 'error' && $timeout['driver'] === 1205, 'held account lock causes bounded timeout');
verify(countKey($db, 'locked-post') === 0, 'lock timeout rolls back journal claim');
$locker->rollBack();
verify($ledger->post('locked-post','test','Isolated fixture',pair(3,4,'1')) > 0, 'same key succeeds after lock release');

// Force a failure after the first entry has been inserted.
$db->exec("CREATE TRIGGER test_entry_failure BEFORE INSERT ON ledger_entries FOR EACH ROW
 BEGIN IF NEW.account_id=6 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected test failure'; END IF; END");
$before = (int)$db->query('SELECT COUNT(*) FROM ledger_entries')->fetchColumn();
try {
    $ledger->post('recover','test','Isolated fixture',pair(5,6,'2'));
    throw new RuntimeException('Injected failure was ignored');
} catch (PDOException $e) {
    verify((int)$e->errorInfo[1] === 1644, 'injected mid-post failure reaches caller');
}
verify(!$db->inTransaction() && countKey($db, 'recover') === 0
    && (int)$db->query('SELECT COUNT(*) FROM ledger_entries')->fetchColumn() === $before, 'mid-post failure rolls back journal and first entry');
$db->exec('DROP TRIGGER test_entry_failure');
$recovered = $ledger->post('recover','test','Isolated fixture',pair(5,6,'2'));
$independent = new Novikov\Finance\Ledger(testConnection($schema));
verify($independent->post('recover','test','Isolated fixture',pair(5,6,'2')) === $recovered, 'retry after lost response returns committed journal');
reject(fn() => $independent->post('recover','test','Isolated fixture',pair(5,6,'3')), 'independent connection rejects changed payload');
reject(fn() => $ledger->post('missing','test','Isolated fixture',pair(5,999999,'1')), 'missing account rejected');
verify(countKey($db, 'missing') === 0 && !$db->inTransaction(), 'missing account rolls back key');
$db->beginTransaction();
reject(fn() => $ledger->post('nested','test','Isolated fixture',pair(5,6,'1')), 'caller-owned transaction rejected');
verify($db->inTransaction(), 'caller transaction is preserved');
$db->rollBack();

// Abrupt client death while inside post must release its key and locks.
$locker->beginTransaction();
$locker->query('SELECT id FROM ledger_accounts WHERE id=3 FOR UPDATE')->fetch();
$dying = startWorker($schema, 'interrupted', pair(3,4,'1'));
go($dying);
// The uncommitted journal is invisible at READ COMMITTED. READ UNCOMMITTED
// is used only by this observer to synchronize with the actual claim.
$observer = testConnection($schema);
$observer->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ UNCOMMITTED');
$deadline = microtime(true) + 1.5;
do {
    $claimed = countKey($observer, 'interrupted') === 1;
    if (!$claimed) usleep(10000);
} while (!$claimed && microtime(true) < $deadline);
verify($claimed, 'interrupted worker entered posting transaction');
proc_terminate($dying[0], 9);
$locker->rollBack();
verify($ledger->post('interrupted','test','Isolated fixture',pair(3,4,'1')) > 0, 'retry recovers after abrupt connection loss');
verify((int)$db->query('SELECT COUNT(*) FROM (SELECT journal_id FROM ledger_entries GROUP BY journal_id HAVING SUM(CASE WHEN side="debit" THEN amount ELSE -amount END) <> 0 OR COUNT(*) < 2) broken')->fetchColumn() === 0, 'all persisted journals remain balanced');
verify((int)$db->query('SELECT COUNT(*) FROM ledger_journals j LEFT JOIN ledger_entries e ON e.journal_id=j.id WHERE e.id IS NULL')->fetchColumn() === 0, 'no partial journals survive failures');
