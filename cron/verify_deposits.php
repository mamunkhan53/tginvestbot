<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
\Novikov\Job::run('deposit_monitor',static function(PDO $db):string {
    return (new \Novikov\Blockchain\Deposits($db))->scan().' transfer events inspected.';
});
