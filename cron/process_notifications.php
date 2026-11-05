<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
\Novikov\Job::run('financial_notifications',static function(PDO $db):string {
    $client=new \Novikov\Telegram\Client(getenv('TELEGRAM_BOT_TOKEN')?:'');
    $db->exec("UPDATE financial_notifications SET status='uncertain',updated_at=UTC_TIMESTAMP(6) WHERE status='sending'");
    $rows=$db->query("SELECT n.*,u.telegram_id FROM financial_notifications n JOIN users u ON u.id=n.user_id WHERE n.status='pending' AND n.available_at<=UTC_TIMESTAMP(6) ORDER BY n.id LIMIT 50")->fetchAll();
    $sent=0;
    foreach($rows as $row) {
        $db->prepare("UPDATE financial_notifications SET status='sending',attempts=attempts+1,updated_at=UTC_TIMESTAMP(6) WHERE id=?")->execute([$row['id']]);
        $result=$client->call('sendMessage',['chat_id'=>$row['telegram_id'],'text'=>$row['text']]);
        $state=$result['state']==='retry'?($row['attempts']>=9?'failed':'pending'):$result['state'];
        $db->prepare('UPDATE financial_notifications SET status=?,available_at=TIMESTAMPADD(SECOND,?,UTC_TIMESTAMP(6)),updated_at=UTC_TIMESTAMP(6) WHERE id=?')->execute([$state,$result['delay']??0,$row['id']]);
        if($state==='sent')$sent++;
        if($result['state']==='retry')break;
    }
    return "$sent notifications sent.";
});
