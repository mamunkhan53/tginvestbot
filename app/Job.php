<?php
declare(strict_types=1);
namespace Novikov;
use PDO;

final class Job
{
    public static function run(string $name,callable $work):void
    {
        if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
        $db=Database::connect(); $q=$db->prepare('SELECT GET_LOCK(?,0)');$q->execute(['novikov:job:'.$name]);
        if(!$q->fetchColumn()){echo "Another instance is running.\n";return;}
        try {
            $db->prepare("INSERT INTO job_runs(name,last_started_at,status,summary) VALUES (?,UTC_TIMESTAMP(6),'running','Started') ON DUPLICATE KEY UPDATE last_started_at=UTC_TIMESTAMP(6),status='running',summary='Started'")->execute([$name]);
            $summary=$work($db);
            $db->prepare("UPDATE job_runs SET last_succeeded_at=UTC_TIMESTAMP(6),status='ok',summary=? WHERE name=?")->execute([mb_substr((string)$summary,0,500),$name]);
            echo $name.": ".$summary."\n";
        }catch(\Throwable $e) {
            if($db->inTransaction())$db->rollBack();
            $db->prepare("UPDATE job_runs SET status='failed',summary='Failed; inspect configuration and operational records. No automatic financial repair.' WHERE name=?")->execute([$name]);
            fwrite(STDERR,"$name failed; sensitive details suppressed. Review configuration and operational records.\n");
            exit(1);
        }finally{$q=$db->prepare('SELECT RELEASE_LOCK(?)');$q->execute(['novikov:job:'.$name]);}
    }
}
