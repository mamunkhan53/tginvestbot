<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
\Novikov\Job::run('user_automation',static fn(PDO $db):string=>(new \Novikov\Finance\Automation($db))->run());
