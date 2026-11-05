<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
\Novikov\Job::run('investment_maturity',static function(PDO $db):string {
    $rows=$db->query("SELECT id FROM investments WHERE status IN ('active','matured') AND matures_at<=UTC_TIMESTAMP(6) ORDER BY matures_at LIMIT 100")->fetchAll();
    $service=new \Novikov\Investment\Service($db);$processed=0;$blocked=0;
    foreach($rows as $row){try{$service->settle($row['id']);$processed++;}catch(DomainException){$blocked++;}}
    return "$processed settled; $blocked require funding or result review.";
});
