<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

// A separate PHP process guarantees a genuinely independent PDO connection.
try {
    $job = json_decode(trim(fgets(STDIN)), true, 512, JSON_THROW_ON_ERROR);
    if (!preg_match('/^novikov_test_[a-f0-9]{16}$/D', $job['schema'])) {
        throw new RuntimeException('Invalid test schema');
    }
    $db = Novikov\Database::connect();
    $db->exec("USE `{$job['schema']}`");
    $db->exec('SET SESSION innodb_lock_wait_timeout = 2');
    echo "ready\n";
    flush();
    if (trim(fgets(STDIN)) !== 'go') throw new RuntimeException('Missing start signal');
    $ledger = new Novikov\Finance\Ledger($db);
    if ($job['event'] ?? null) {
        $id = $ledger->postEvent($job['key'], 'test', 'Isolated fixture', $job['entries'],
            $job['event']['data'], function (PDO $connection, int $journal) use ($job): void {
                $connection->prepare('UPDATE test_business SET changes=changes+1,journal_id=? WHERE id=?')
                    ->execute([$journal, $job['event']['data']['entity']]);
                if ($job['event']['pause'] ?? false) {
                    echo "business-written\n";
                    flush();
                    // The parent kills this process at a known pre-commit point.
                    fgets(STDIN);
                    throw new RuntimeException('Unexpected resume');
                }
            });
    } else {
        $id = $ledger->post($job['key'], 'test', 'Isolated fixture', $job['entries']);
    }
    echo json_encode(['status'=>'ok', 'id'=>$id, 'transaction'=>$db->inTransaction()]) . "\n";
} catch (Throwable $e) {
    echo json_encode([
        'status'=>$e instanceof DomainException ? 'domain' : 'error',
        'driver'=>$e instanceof PDOException ? (int)($e->errorInfo[1] ?? 0) : 0,
        'transaction'=>isset($db) && $db->inTransaction(),
    ]) . "\n";
}
