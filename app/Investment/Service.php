<?php
declare(strict_types=1);
namespace Novikov\Investment;
use PDO;
use DomainException;
use Novikov\Finance\{Accounts,Amount,Ledger,Policy};

final class Service
{
    private Accounts $accounts;
    private Policy $policy;
    public function __construct(private PDO $db)
    {
        $this->accounts=new Accounts($db); $this->policy=new Policy($db);
    }
    public function open(int $user,int $package,string $amount,string $requestId): string
    {
        $amount=Amount::positive($amount);
        if (!preg_match('/^[a-f0-9]{32}$/D',$requestId)) throw new DomainException('Invalid investment request.');
        $q=$this->db->prepare('SELECT * FROM investments WHERE id=?');
        $q->execute([$requestId]);
        if ($old=$q->fetch()) {
            if ((int)$old['user_id']!==$user || (int)$old['package_id']!==$package || bccomp($old['principal'],$amount,18)!==0) throw new DomainException('Investment request reused.');
            return $requestId;
        }
        $this->policy->enabled('investments'); $this->policy->user($user);
        $q=$this->db->prepare('SELECT * FROM investment_packages WHERE id=? AND active=1');
        $q->execute([$package]); $p=$q->fetch();
        if (!$p || bccomp($amount,$p['minimum'],18)<0 || bccomp($amount,$p['maximum'],18)>0) throw new DomainException('Package unavailable or amount outside package limits.');
        $available=$this->accounts->user($user);
        $invested=$this->accounts->user($user,'invested');
        $terms=['name'=>$p['name'],'rate'=>$p['rate'],'model'=>$p['model'],'duration_hours'=>(int)$p['duration_hours'],'version'=>(int)$p['terms_version'],'description'=>$p['description']];
        (new Ledger($this->db))->postEvent("investment:$requestId",$requestId,'Investment principal reservation',[
            Accounts::entry($available,'debit',$amount),Accounts::entry($invested,'credit',$amount),
        ],['type'=>'investment-open','id'=>$requestId,'user'=>$user,'package'=>$package,'amount'=>$amount,'terms'=>$terms],
        function(PDO $db,int $journal) use ($requestId,$user,$package,$amount,$terms,$p): void {
            $this->policy->enabled('investments'); $this->policy->user($user);
            $q=$db->prepare('SELECT * FROM investment_packages WHERE id=? FOR UPDATE'); $q->execute([$package]); $current=$q->fetch();
            if (!$current['active'] || (int)$current['terms_version']!==(int)$p['terms_version']) throw new DomainException('Package terms changed. Review again.');
            $db->prepare('INSERT INTO investments(id,user_id,package_id,principal,terms,matures_at,journal_id) VALUES (?,?,?,?,?,TIMESTAMPADD(HOUR,?,UTC_TIMESTAMP(6)),?)')
                ->execute([$requestId,$user,$package,$amount,json_encode($terms,JSON_THROW_ON_ERROR),$terms['duration_hours'],$journal]);
            Accounts::notify($db,"investment:$requestId",$user,"Investment activated\nReference: $requestId\nPackage: ".$terms['name']."\nPrincipal: $amount USDT\nTestnet only. Projected returns are not received earnings.");
        });
        return $requestId;
    }
    public function settle(string $id): void
    {
        $q=$this->db->prepare("SELECT * FROM investments WHERE id=? AND status IN ('active','matured') AND matures_at<=UTC_TIMESTAMP(6)");
        $q->execute([$id]); $i=$q->fetch(); if (!$i) return;
        $terms=json_decode($i['terms'],true,32,JSON_THROW_ON_ERROR);
        $user=(int)$i['user_id']; $principal=$i['principal'];
        $profit=bcdiv(bcmul($principal,$terms['rate'],22),'100',18);
        $loss='0';
        if ($terms['model']==='performance') {
            $q=$this->db->prepare('SELECT * FROM performance_results WHERE investment_id=?');$q->execute([$id]);$result=$q->fetch();
            if(!$result)throw new DomainException('Approved realized-performance evidence required; no earnings are manufactured.');
            $difference=bcsub($result['payout'],$principal,18);
            $profit=bccomp($difference,'0',18)>0?$difference:'0';
            $loss=bccomp($difference,'0',18)<0?bcsub('0',$difference,18):'0';
        }
        $available=$this->accounts->user($user); $invested=$this->accounts->user($user,'invested');
        $returned=bcsub($principal,$loss,18);
        $entries=[Accounts::entry($invested,'debit',$principal)];
        if(bccomp($returned,'0',18)>0)$entries[]=Accounts::entry($available,'credit',$returned);
        if(bccomp($loss,'0',18)>0)$entries[]=Accounts::entry($this->accounts->find('platform:custody'),'credit',$loss);
        if (bccomp($profit,'0',18)>0) {
            $entries[]=Accounts::entry($this->accounts->find('platform:reserve'),'debit',$profit);
            $entries[]=Accounts::entry($available,'credit',$profit);
        }
        (new Ledger($this->db))->postEvent("maturity:$id",$id,'Funded investment maturity settlement',$entries,
            ['type'=>'investment-maturity','id'=>$id,'principal'=>$principal,'profit'=>$profit,'loss'=>$loss],
            function(PDO $db,int $journal) use($id,$returned,$profit,$loss,$user): void {
                $q=$db->prepare("UPDATE investments SET status='completed',settled_at=UTC_TIMESTAMP(6),settlement_amount=?,settlement_journal_id=? WHERE id=? AND status IN ('active','matured') AND matures_at<=UTC_TIMESTAMP(6)");
                $q->execute([bcadd($returned,$profit,18),$journal,$id]);
                if ($q->rowCount()!==1) throw new DomainException('Investment no longer eligible for settlement.');
                Accounts::notify($db,"maturity:$id",$user,"Investment settled\nReference: $id\nReturned principal: $returned USDT\nFunded earnings: $profit USDT\nRealized loss: $loss USDT");
            });
    }
    public function result(string $id,string $payout,string $evidence,int $admin):void
    {
        $payout=$payout==='0'?'0':Amount::positive($payout);
        if(trim($evidence)==='' || strlen($evidence)>500)throw new DomainException('Independent realized-result evidence is required.');
        $this->db->beginTransaction();
        try {
            $q=$this->db->prepare("SELECT * FROM investments WHERE id=? AND status IN ('active','matured') AND matures_at<=UTC_TIMESTAMP(6) FOR UPDATE");$q->execute([$id]);$i=$q->fetch();
            if(!$i || json_decode($i['terms'],true)['model']!=='performance')throw new DomainException('Only mature performance-based products accept realized results.');
            $this->db->prepare('INSERT INTO performance_results(investment_id,payout,evidence,approved_by) VALUES (?,?,?,?)')->execute([$id,$payout,$evidence,$admin]);
            $this->db->prepare("INSERT INTO admin_activity_logs(admin_id,action,reason) VALUES (?,'performance_result_approved',?)")->execute([$admin,$id.': '.$evidence]);
            $this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function commission(string $id): void
    {
        if (!$this->policy->value('referral_enabled',false)) return;
        $q=$this->db->prepare("SELECT * FROM investments WHERE id=? AND status='completed'"); $q->execute([$id]); $i=$q->fetch(); if(!$i)return;
        $parent=(int)$i['user_id']; $seen=[$parent];
        for($level=1;$level<=2;$level++) {
            $q=$this->db->prepare('SELECT referrer_id FROM users WHERE id=?'); $q->execute([$parent]); $parent=(int)$q->fetchColumn();
            if (!$parent || in_array($parent,$seen,true)) break; $seen[]=$parent;
            $q=$this->db->prepare('SELECT id FROM referral_commissions WHERE investment_id=? AND level=?'); $q->execute([$id,$level]); if($q->fetchColumn())continue;
            $rate=(string)$this->policy->value("referral_level_$level",$level===1?'3':'1');
            if (!preg_match('/^(?:[0-9]|[1-9][0-9]|100)(?:\.[0-9]{1,4})?$/D',$rate) || bccomp($rate,'100',4)>0) throw new DomainException('Invalid referral policy.');
            $amount=bcdiv(bcmul($i['principal'],$rate,22),'100',18); if(bccomp($amount,'0',18)<=0)continue;
            $this->policy->user($parent);
            $credit=$this->accounts->user($parent);
            (new Ledger($this->db))->postEvent("referral:$id:$level",$id,'Funded referral commission',[
                Accounts::entry($this->accounts->find('platform:reserve'),'debit',$amount),Accounts::entry($credit,'credit',$amount),
            ],['type'=>'referral','investment'=>$id,'level'=>$level,'beneficiary'=>$parent,'amount'=>$amount],
            function(PDO $db,int $journal) use($id,$level,$parent,$amount):void {
                $db->prepare('INSERT INTO referral_commissions(investment_id,beneficiary_id,level,amount,journal_id) VALUES (?,?,?,?,?)')->execute([$id,$parent,$level,$amount,$journal]);
                Accounts::notify($db,"referral:$id:$level",$parent,"Referral commission credited\nLevel: $level\nAmount: $amount USDT");
            });
        }
    }
}
