<?php
declare(strict_types=1);
namespace Novikov\Finance;
use PDO;
use DomainException;

final class Automation
{
    public function __construct(private PDO $db) {}
    public function run():string
    {
        $accounts=new Accounts($this->db);$blocked=0;$reinvested=0;$withdrawals=0;
        $q=$this->db->query("SELECT i.* FROM investments i JOIN user_settings s ON s.user_id=i.user_id WHERE s.auto_reinvest=1 AND i.status='completed' AND i.settlement_amount>0 ORDER BY i.settled_at LIMIT 100");
        foreach($q->fetchAll() as $i) {
            $id=substr(hash('sha256','reinvest:'.$i['id']),0,32);
            $check=$this->db->prepare('SELECT id FROM investments WHERE id=?');$check->execute([$id]);if($check->fetchColumn())continue;
            try {
                (new \Novikov\Investment\Service($this->db))->open((int)$i['user_id'],(int)$i['package_id'],$i['settlement_amount'],$id);$reinvested++;
            }catch(DomainException){$blocked++;}
        }
        $policy=new Policy($this->db);
        $rows=$this->db->query('SELECT user_id FROM user_settings WHERE auto_withdraw=1 ORDER BY user_id LIMIT 100')->fetchAll();
        foreach($rows as $row) {
            $user=(int)$row['user_id'];$id=substr(hash('sha256','auto-withdraw:'.$user.':'.gmdate('Y-m-d')),0,32);
            $check=$this->db->prepare('SELECT id FROM withdrawals WHERE id=?');$check->execute([$id]);if($check->fetchColumn())continue;
            try {
                $balance=$accounts->balance($accounts->user($user));
                $rate=(string)$policy->value('withdrawal_fee_percent','1');
                $net=bcdiv($balance,bcadd('1',bcdiv($rate,'100',18),18),18);
                if(bccomp($net,(string)$policy->value('minimum_withdrawal','1'),18)<0)continue;
                (new Withdrawals($this->db))->request($user,$net,$id);$withdrawals++;
            }catch(DomainException){$blocked++;}
        }
        return "$reinvested reinvestments; $withdrawals withdrawal requests; $blocked blocked by policy, availability or funding.";
    }
}
