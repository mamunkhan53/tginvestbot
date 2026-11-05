<?php
declare(strict_types=1);
namespace Novikov\Finance;
use PDO;
use DomainException;
use Novikov\Blockchain\Rpc;

final class Withdrawals
{
    private Accounts $accounts;
    private Policy $policy;
    public function __construct(private PDO $db)
    {
        $this->accounts=new Accounts($db); $this->policy=new Policy($db);
    }
    public function request(int $user,string $net,string $id): string
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new DomainException('Invalid withdrawal request.');
        $quote=Amount::withdrawal($net,(string)$this->policy->value('withdrawal_fee_percent','1'));
        $q=$this->db->prepare('SELECT * FROM withdrawals WHERE id=?'); $q->execute([$id]);
        if($existing=$q->fetch()) {
            if((int)$existing['user_id']!==$user || bccomp($existing['net_amount'],$quote['net'],18)!==0)throw new DomainException('Request ID reused.');
            return $id;
        }
        $this->policy->enabled('withdrawals'); $this->policy->user($user);
        if(bccomp($quote['net'],(string)$this->policy->value('minimum_withdrawal','1'),18)<0)throw new DomainException('Below minimum withdrawal.');
        $q=$this->db->prepare('SELECT * FROM withdrawal_addresses WHERE user_id=? ORDER BY id DESC LIMIT 1'); $q->execute([$user]); $wallet=$q->fetch();
        if(!$wallet || !$wallet['verified'] || strtotime($wallet['eligible_at'].' UTC')>time())throw new DomainException('A reviewed wallet and completed security delay are required.');
        Rpc::address($wallet['address']);
        $available=$this->accounts->user($user); $reserved=$this->accounts->user($user,'reserved');
        (new Ledger($this->db))->postEvent("withdrawal:$id",$id,'Withdrawal balance reservation',[
            Accounts::entry($available,'debit',$quote['total']),Accounts::entry($reserved,'credit',$quote['total']),
        ],['type'=>'withdrawal-request','id'=>$id,'user'=>$user,'wallet'=>(int)$wallet['id'],'quote'=>$quote],
        function(PDO $db,int $journal)use($user,$id,$wallet,$quote):void {
            $this->policy->enabled('withdrawals'); $this->policy->user($user);
            $q=$db->prepare('SELECT id FROM users WHERE id=? FOR UPDATE'); $q->execute([$user]);
            $q=$db->prepare('SELECT id FROM withdrawal_addresses WHERE user_id=? ORDER BY id DESC LIMIT 1 FOR UPDATE'); $q->execute([$user]);
            if((int)$q->fetchColumn()!==(int)$wallet['id'])throw new DomainException('Wallet changed. Review withdrawal again.');
            $q=$db->prepare("SELECT COALESCE(SUM(total),0) FROM withdrawals WHERE user_id=? AND created_at>=UTC_DATE() AND status NOT IN ('rejected','cancelled','failed')");
            $q->execute([$user]);
            if(bccomp(bcadd((string)$q->fetchColumn(),$quote['total'],18),(string)$this->policy->value('daily_withdrawal_limit','1000'),18)>0)throw new DomainException('Daily withdrawal limit exceeded.');
            $db->prepare('INSERT INTO withdrawals(id,user_id,address_id,address,net_amount,fee,total,journal_id) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$id,$user,$wallet['id'],$wallet['address'],$quote['net'],$quote['fee'],$quote['total'],$journal]);
            Accounts::notify($db,"withdrawal:$id",$user,"Withdrawal submitted\nReference: $id\nNet payout: ".$quote['net']." USDT\nFee: ".$quote['fee']." USDT\nTotal reserved: ".$quote['total']." USDT\nWallet: ".substr($wallet['address'],0,6).'…'.substr($wallet['address'],-4)."\nStatus: Pending");
        });
        return $id;
    }
    public function reject(string $id,string $reason,int $admin):void
    {
        $q=$this->db->prepare("SELECT * FROM withdrawals WHERE id=? AND status IN ('pending','approved')"); $q->execute([$id]); $w=$q->fetch();
        if(!$w)throw new DomainException('Only unsubmitted withdrawals can be rejected.');
        if(trim($reason)==='')throw new DomainException('A rejection reason is required.');
        $user=(int)$w['user_id'];
        (new Ledger($this->db))->postEvent("withdrawal-reject:$id",$id,'Withdrawal rejection refund',[
            Accounts::entry($this->accounts->user($user,'reserved'),'debit',$w['total']),
            Accounts::entry($this->accounts->user($user),'credit',$w['total']),
        ],['type'=>'withdrawal-reject','id'=>$id,'reason'=>$reason,'admin'=>$admin],function(PDO $db,int $journal)use($id,$reason,$admin,$user):void {
            $q=$db->prepare("UPDATE withdrawals SET status='rejected',reason=?,approved_by=?,settlement_journal_id=?,updated_at=UTC_TIMESTAMP(6) WHERE id=? AND status IN ('pending','approved')");
            $q->execute([$reason,$admin,$journal,$id]);
            if($q->rowCount()!==1)throw new DomainException('Withdrawal changed; refund cancelled.');
            $db->prepare("INSERT INTO admin_activity_logs(admin_id,action,reason) VALUES (?,'withdrawal_rejected',?)")->execute([$admin,$id.': '.$reason]);
            Accounts::notify($db,"withdrawal-reject:$id",$user,"Withdrawal rejected. Reserved funds returned.\nReference: $id\nReason: $reason");
        });
    }
    public function approve(string $id,int $admin,string $reason):void
    {
        $this->policy->enabled('withdrawals');
        $this->db->beginTransaction();
        try {
            $q=$this->db->prepare("SELECT * FROM withdrawals WHERE id=? AND status='pending' FOR UPDATE"); $q->execute([$id]); $w=$q->fetch();
            if(!$w)throw new DomainException('Withdrawal is not pending.');
            $this->policy->user((int)$w['user_id']);
            $q=$this->db->prepare('SELECT * FROM withdrawal_addresses WHERE user_id=? ORDER BY id DESC LIMIT 1'); $q->execute([$w['user_id']]); $wallet=$q->fetch();
            if(!$wallet || !$wallet['verified'] || (int)$wallet['id']!==(int)$w['address_id'] || strtotime($wallet['eligible_at'].' UTC')>time())throw new DomainException('Wallet security checks failed.');
            if(bccomp($this->accounts->balance($this->accounts->user((int)$w['user_id'],'reserved')),$w['total'],18)<0)throw new DomainException('Reservation is missing.');
            $this->db->prepare("UPDATE withdrawals SET status='approved',approved_by=?,reason=?,updated_at=UTC_TIMESTAMP(6) WHERE id=?")->execute([$admin,$reason,$id]);
            $this->db->prepare("INSERT INTO admin_activity_logs(admin_id,action,reason) VALUES (?,'withdrawal_approved',?)")->execute([$admin,$id.': '.$reason]);
            $this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function complete(string $id,array $verified):void
    {
        $q=$this->db->prepare("SELECT * FROM withdrawals WHERE id=? AND status IN ('approved','processing','uncertain')"); $q->execute([$id]); $w=$q->fetch(); if(!$w)return;
        if(!$verified['ready'] || $verified['hash']!==$w['tx_hash'] || strtolower($w['address'])!==$verified['to'] || bccomp($w['net_amount'],$verified['amount'],18)!==0)throw new DomainException('Payout does not match verified transfer.');
        $user=(int)$w['user_id'];
        $entries=[Accounts::entry($this->accounts->user($user,'reserved'),'debit',$w['total']),Accounts::entry($this->accounts->find('platform:custody'),'credit',$w['net_amount'])];
        if(bccomp($w['fee'],'0',18)>0)$entries[]=Accounts::entry($this->accounts->find('platform:fees'),'credit',$w['fee']);
        (new Ledger($this->db))->postEvent("withdrawal-complete:$id",$id,'Independently verified payout settlement',$entries,
            ['type'=>'withdrawal-complete','id'=>$id,'hash'=>$verified['hash']],function(PDO $db,int $journal)use($id,$user):void {
                $q=$db->prepare("UPDATE withdrawals SET status='completed',settlement_journal_id=?,updated_at=UTC_TIMESTAMP(6) WHERE id=? AND status IN ('approved','processing','uncertain')");
                $q->execute([$journal,$id]); if($q->rowCount()!==1)throw new DomainException('Payout settlement state changed.');
                Accounts::notify($db,"withdrawal-complete:$id",$user,"Withdrawal confirmed on BSC testnet.\nReference: $id");
            });
    }
}
