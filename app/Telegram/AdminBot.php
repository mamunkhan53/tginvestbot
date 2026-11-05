<?php
declare(strict_types=1);
namespace Novikov\Telegram;
use PDO;
use DomainException;

final class AdminBot
{
    public function __construct(private PDO $db) {}
    public function handles(array $update):bool
    {
        return preg_match('/^\/(?:admin|stats|user|maintenance|broadcast)(?:\s|$)/D',$update['text'])===1
            || str_starts_with($update['text'],'admin:');
    }
    public function respond(array $update):array
    {
        $allow=explode(',',getenv('TELEGRAM_ADMIN_IDS')?:'');
        if(!in_array((string)$update['telegram_id'],array_map('trim',$allow),true))return ['text'=>'Administrative access denied.'];
        $text=$update['text'];$id=$update['telegram_id'];
        if($text==='/admin')return ['text'=>"Administrator commands\n/stats\n/user TELEGRAM_ID\n/maintenance\n/broadcast all|active|ids:INTERNAL_ID,INTERNAL_ID MESSAGE\nAll financial operations remain subject to policy checks."];
        if($text==='/stats'){
            $users=$this->db->query('SELECT COUNT(*) FROM users')->fetchColumn();
            $active=$this->db->query("SELECT COUNT(*) FROM investments WHERE status='active'")->fetchColumn();
            $pending=$this->db->query("SELECT COUNT(*) FROM withdrawals WHERE status='pending'")->fetchColumn();
            $broadcasts=$this->db->query('SELECT COUNT(*) FROM telegram_broadcasts')->fetchColumn();
            return ['text'=>"Platform statistics\nUsers: $users\nActive investments: $active\nPending withdrawals: $pending\nBroadcasts: $broadcasts\nBSC testnet only."];
        }
        if(preg_match('/^\/user ([0-9]{1,20})$/D',$text,$match)){
            $q=$this->db->prepare('SELECT id,telegram_id,username,status,created_at FROM users WHERE telegram_id=?');$q->execute([$match[1]]);$u=$q->fetch();
            return ['text'=>$u?'User ID: '.$u['id']."\nTelegram ID: ".$u['telegram_id']."\nUsername: ".($u['username']??'Not set')."\nStatus: ".$u['status']."\nRegistered: ".$u['created_at'].' UTC':'User not found.'];
        }
        if($text==='/maintenance'){
            $nonce=$this->save($id,['type'=>'maintenance']);
            return $this->preview('Toggle maintenance mode? New financial bot actions will be blocked while enabled.',$nonce);
        }
        if(preg_match('/^\/broadcast (all|active|ids:[0-9,]{1,200}) ([\s\S]{1,3000})$/D',$text,$match)){
            $nonce=$this->save($id,['type'=>'broadcast','audience'=>$match[1],'message'=>$match[2]]);
            return $this->preview("Broadcast preview\nAudience: ".$match[1]."\n\n".$match[2],$nonce);
        }
        if(preg_match('/^admin:confirm:([a-f0-9]{32})$/D',$text,$match)){
            $this->db->beginTransaction();
            try{
                $q=$this->db->prepare('SELECT * FROM telegram_admin_sessions WHERE telegram_id=? AND nonce=? AND created_at>TIMESTAMPADD(MINUTE,-10,UTC_TIMESTAMP(6)) FOR UPDATE');$q->execute([$id,$match[1]]);$session=$q->fetch();
                if(!$session)throw new DomainException('Expired or already submitted confirmation.');
                $intent=json_decode($session['intent'],true,32,JSON_THROW_ON_ERROR);$notice='';
                if($intent['type']==='maintenance') {
                    $this->db->exec("INSERT IGNORE INTO system_settings VALUES('maintenance_mode','false')");
                    $q=$this->db->query("SELECT setting_value FROM system_settings WHERE setting_key='maintenance_mode' FOR UPDATE");
                    $enabled=!json_decode($q->fetchColumn(),true);
                    $this->db->prepare("UPDATE system_settings SET setting_value=? WHERE setting_key='maintenance_mode'")->execute([json_encode($enabled)]);
                    $notice='Maintenance mode '.($enabled?'enabled':'disabled').'.';
                }else{
                    $audience=$intent['audience'];$params=[];$sql='SELECT id FROM users';
                    if($audience==='active')$sql.=" WHERE status='active'";
                    elseif(str_starts_with($audience,'ids:')){
                        $ids=array_values(array_unique(array_map('intval',explode(',',substr($audience,4)))));
                        $sql.=' WHERE id IN ('.implode(',',array_fill(0,count($ids),'?')).')';$params=$ids;
                    }
                    $q=$this->db->prepare($sql);$q->execute($params);$users=$q->fetchAll(PDO::FETCH_COLUMN);
                    $this->db->prepare('INSERT INTO telegram_broadcasts(id,admin_telegram_id,audience,message,recipient_count) VALUES (?,?,?,?,?)')->execute([$match[1],$id,$audience,$intent['message'],count($users)]);
                    foreach($users as $user) {
                        \Novikov\Finance\Accounts::notify($this->db,'broadcast:'.$match[1].':'.$user,(int)$user,$intent['message']);
                        $notification=(int)$this->db->lastInsertId();
                        $this->db->prepare('INSERT INTO telegram_broadcast_recipients VALUES (?,?,?)')->execute([$match[1],$user,$notification]);
                    }
                    $notice=count($users).' broadcast recipients queued. Delivery status is recorded separately.';
                }
                $this->db->prepare('DELETE FROM telegram_admin_sessions WHERE telegram_id=? AND nonce=?')->execute([$id,$match[1]]);
                $this->db->prepare("INSERT INTO admin_activity_logs(admin_id,action,reason) VALUES(NULL,'telegram_admin_action',?)")->execute(['Authorized Telegram operator '.$id.' | '.$intent['type']]);
                $this->db->commit();return ['text'=>$notice];
            }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();if($e instanceof DomainException)return ['text'=>$e->getMessage()];throw $e;}
        }
        return ['text'=>'Invalid administrator command or expired confirmation. Use /admin.'];
    }
    private function save(int $id,array $intent):string
    {
        $nonce=bin2hex(random_bytes(16));
        $this->db->prepare('INSERT INTO telegram_admin_sessions(telegram_id,nonce,intent) VALUES (?,?,?) ON DUPLICATE KEY UPDATE nonce=VALUES(nonce),intent=VALUES(intent),created_at=UTC_TIMESTAMP(6)')->execute([$id,$nonce,json_encode($intent,JSON_THROW_ON_ERROR)]);
        return $nonce;
    }
    private function preview(string $text,string $nonce):array
    {
        return ['text'=>$text,'reply_markup'=>['inline_keyboard'=>[
            [['text'=>'Confirm','callback_data'=>'admin:confirm:'.$nonce]],
            [['text'=>'Cancel','callback_data'=>'home']],
        ]]];
    }
}
