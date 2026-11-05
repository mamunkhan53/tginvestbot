<?php
declare(strict_types=1);

function testConnection(string $schema): PDO {
    if (!preg_match('/^novikov_test_[a-f0-9]{16}$/D', $schema)) throw new RuntimeException('Unsafe schema name');
    $db = Novikov\Database::connect();
    $db->exec("USE `$schema`");
    return $db;
}

function pair(int $a, int $b, string $amount, bool $spend = false): array {
    return [
        ['account'=>$a, 'side'=>$spend ? 'credit' : 'debit', 'amount'=>$amount],
        ['account'=>$b, 'side'=>$spend ? 'debit' : 'credit', 'amount'=>$amount],
    ];
}

function startWorker(string $schema, string $key, array $entries, ?array $event = null): array {
    $process = proc_open([PHP_BINARY, __DIR__ . '/database-worker.php'],
        [0=>['pipe','r'], 1=>['pipe','w'], 2=>['pipe','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start database worker');
    $worker = [$process, $pipes];
    $GLOBALS['workers'][] = $worker;
    stream_set_timeout($pipes[1], 15);
    fwrite($pipes[0], json_encode(compact('schema','key','entries','event'), JSON_THROW_ON_ERROR) . "\n");
    verify(trim((string)fgets($pipes[1])) === 'ready', 'independent connection ready');
    return $worker;
}

function go(array $worker): void { fwrite($worker[1][0], "go\n"); }
function result(array $worker): array {
    $line = fgets($worker[1][1]);
    verify($line !== false, 'worker completes within deadline');
    $result = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    verify($result['transaction'] === false, 'worker leaves no open transaction');
    return $result;
}
function race(string $schema, string $keyA, array $a, string $keyB, array $b): array {
    $first = startWorker($schema, $keyA, $a);
    $second = startWorker($schema, $keyB, $b);
    go($first);
    go($second);
    return [result($first), result($second)];
}

function sqlReject(PDO $db, string $sql, int $driver, string $label): void {
    try { $db->exec($sql); }
    catch (PDOException $e) {
        verify((int)($e->errorInfo[1] ?? 0) === $driver, $label);
        return;
    }
    throw new RuntimeException($label);
}

function countKey(PDO $db, string $key): int {
    $q = $db->prepare('SELECT COUNT(*) FROM ledger_journals WHERE idempotency_key = ?');
    $q->execute([$key]);
    return (int)$q->fetchColumn();
}
