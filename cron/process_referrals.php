<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
\Novikov\Job::run('referral_commissions',static function(PDO $db):string {
    $rows=$db->query("SELECT id FROM investments WHERE status='completed' ORDER BY settled_at DESC LIMIT 100")->fetchAll();
    $service=new \Novikov\Investment\Service($db);$blocked=0;
    foreach($rows as $row){try{$service->commission($row['id']);}catch(DomainException){$blocked++;}}
    return count($rows)." reviewed; $blocked blocked by policy or reserve funding.";
});
