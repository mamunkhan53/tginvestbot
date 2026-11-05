<?php
declare(strict_types=1);

// Synthetic business state exists only in this suite's disposable schema.
$db->exec('CREATE TABLE test_business (
 id INT PRIMARY KEY, changes INT NOT NULL DEFAULT 0, journal_id BIGINT UNSIGNED NULL,
 FOREIGN KEY (journal_id) REFERENCES ledger_journals(id)
) ENGINE=InnoDB');
$db->exec('INSERT INTO test_business (id) VALUES (1),(2),(3),(4),(5),(6)');
$intent = ['type'=>'test-transition', 'version'=>1, 'entity'=>1, 'amount'=>'1'];
$eventEntries = pair(5, 6, '1');
$mutate = function (PDO $connection, int $journal) use ($db): void {
    verify($connection === $db && $connection->inTransaction(), 'callback uses owning transaction');
    $connection->prepare('UPDATE test_business SET changes=changes+1,journal_id=? WHERE id=1')
        ->execute([$journal]);
};
$postEvent = fn(string $key, callable $callback, array $data = []) =>
    $ledger->postEvent($key, 'test', 'Isolated fixture', $eventEntries, $data ?: $intent, $callback);
$state = fn(int $entity) => $db->query("SELECT changes,journal_id FROM test_business WHERE id=$entity")->fetch();
$mustNotRun = function (): void { throw new LogicException('Replay invoked business callback'); };
$committed = $postEvent('event-success', $mutate);
verify((int)$state(1)['changes'] === 1 && (int)$state(1)['journal_id'] === $committed,
    'business record linked to committed journal');
verify($postEvent('event-success', $mustNotRun) === $committed, 'event retry skips business callback');
$freshLedger = new Novikov\Finance\Ledger(testConnection($schema));
verify($freshLedger->postEvent('event-success', 'test', 'Isolated fixture', $eventEntries,
    $intent, $mustNotRun) === $committed, 'lost response replay from new connection');
reject(fn() => $postEvent('event-success', $mustNotRun, array_replace($intent, ['entity'=>2])),
    'changed business intent rejected even with identical entries');
reject(fn() => $ledger->post('event-success', 'test', 'Isolated fixture', $eventEntries),
    'event cannot replay as standalone posting');
$ledger->post('event-standalone', 'test', 'Isolated fixture', $eventEntries);
reject(fn() => $postEvent('event-standalone', $mustNotRun), 'standalone journal cannot replay as event');
$db->beginTransaction();
reject(fn() => $postEvent('event-nested', $mutate), 'event rejects caller-owned transaction');
verify($db->inTransaction(), 'event rejection preserves caller transaction');
$db->rollBack();

$beforeEntries = (int)$db->query('SELECT COUNT(*) FROM ledger_entries')->fetchColumn();
try {
    $postEvent('event-callback-fail', function (PDO $connection, int $journal) use ($mutate): void {
        $mutate($connection, $journal);
        throw new RuntimeException('Injected callback failure');
    });
    throw new LogicException('Callback failure ignored');
} catch (RuntimeException $e) {
    verify($e->getMessage() === 'Injected callback failure', 'callback exception reaches caller');
}
verify(!$db->inTransaction() && countKey($db, 'event-callback-fail') === 0
    && (int)$state(1)['changes'] === 1
    && (int)$db->query('SELECT COUNT(*) FROM ledger_entries')->fetchColumn() === $beforeEntries,
    'callback failure rolls back business and journal');
verify($postEvent('event-callback-fail', $mutate) > 0, 'callback failure permits whole-event retry');

// Fail on the second entry: the business write and first entry already ran.
$db->exec("CREATE TRIGGER test_event_entry_failure BEFORE INSERT ON ledger_entries FOR EACH ROW
 BEGIN IF NEW.account_id=6 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected event failure'; END IF; END");
$beforeEntries = (int)$db->query('SELECT COUNT(*) FROM ledger_entries')->fetchColumn();
try {
    $postEvent('event-entry-fail', $mutate);
    throw new LogicException('Entry failure ignored');
} catch (PDOException $e) {
    verify((int)$e->errorInfo[1] === 1644, 'entry failure after business change reaches caller');
}
verify(!$db->inTransaction() && countKey($db, 'event-entry-fail') === 0
    && (int)$state(1)['changes'] === 2
    && (int)$db->query('SELECT COUNT(*) FROM ledger_entries')->fetchColumn() === $beforeEntries,
    'partial entry failure rolls back business state and all ledger writes');
$db->exec('DROP TRIGGER test_event_entry_failure');
verify($postEvent('event-entry-fail', $mutate) > 0 && (int)$state(1)['changes'] === 3,
    'entry failure retry changes business state exactly once');

$concurrentData = array_replace($intent, ['entity'=>2]);
$first = startWorker($schema, 'event-race', $eventEntries, ['data'=>$concurrentData]);
$second = startWorker($schema, 'event-race', $eventEntries, ['data'=>$concurrentData]);
go($first);
go($second);
$one = result($first);
$two = result($second);
verify($one['status'] === 'ok' && $two['status'] === 'ok' && $one['id'] === $two['id']
    && (int)$state(2)['changes'] === 1, 'concurrent event retries commit one business change');

$first = startWorker($schema, 'event-conflict', $eventEntries, ['data'=>array_replace($intent, ['entity'=>3])]);
$second = startWorker($schema, 'event-conflict', $eventEntries, ['data'=>array_replace($intent, ['entity'=>4])]);
go($first);
go($second);
$statuses = [result($first)['status'], result($second)['status']];
sort($statuses);
verify($statuses === ['domain','ok'] && (int)$state(3)['changes'] + (int)$state(4)['changes'] === 1,
    'concurrent conflicting business intents cannot both commit');

$crashData = array_replace($intent, ['entity'=>5]);
$dying = startWorker($schema, 'event-crash', $eventEntries, ['data'=>$crashData, 'pause'=>true]);
go($dying);
verify(trim((string)fgets($dying[1][1])) === 'business-written', 'worker paused after business write');
verify((int)$state(5)['changes'] === 0 && countKey($db, 'event-crash') === 0,
    'business state and journal remain invisible before commit');
proc_terminate($dying[0], 9);
$retry = startWorker($schema, 'event-crash', $eventEntries, ['data'=>$crashData]);
go($retry);
$recovered = result($retry);
verify($recovered['status'] === 'ok' && (int)$state(5)['changes'] === 1
    && (int)$state(5)['journal_id'] === $recovered['id'] && countKey($db, 'event-crash') === 1,
    'process death rolls back business write and same-key retry recovers');
verify((int)$db->query('SELECT COUNT(*) FROM (SELECT journal_id FROM ledger_entries GROUP BY journal_id
 HAVING SUM(CASE WHEN side="debit" THEN amount ELSE -amount END) <> 0 OR COUNT(*) < 2) broken')->fetchColumn() === 0,
    'event failures leave only balanced complete journals');
