<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
\Novikov\Job::run('withdrawal_processing',static function(PDO $db):string {
    return (new \Novikov\Blockchain\Payouts($db))->process().' payouts verified and settled.';
});
