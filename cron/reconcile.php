<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
\Novikov\Job::run('ledger_reconciliation',static function(PDO $db):string {
    $findings=(new \Novikov\Finance\Reconciliation($db))->run();
    return array_sum(array_map('count',$findings)).' discrepancies detected; no automatic financial modifications.';
});
