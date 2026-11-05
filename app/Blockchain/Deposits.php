<?php
declare(strict_types=1);
namespace Novikov\Blockchain;
use PDO;
use DomainException;
use Novikov\Finance\{Accounts,Policy,Ledger};

final class Deposits
{
    public function __construct(private PDO $db) {}
    public function assign(int $user): ?string
    {
        $q=$this->db->prepare('SELECT address FROM deposit_addresses WHERE user_id=?'); $q->execute([$user]);
        if($address=$q->fetchColumn())return $address;
        $this->db->prepare('UPDATE deposit_addresses SET user_id=?,assigned_at=UTC_TIMESTAMP(6) WHERE user_id IS NULL ORDER BY id LIMIT 1')->execute([$user]);
        $q->execute([$user]); return $q->fetchColumn()?:null;
    }
    public function verifyAndCredit(string $hash,int $index): void
    {
        $policy=new Policy($this->db); $policy->enabled('deposits');
        $v=(new Verifier())->verify($hash,$index,(int)$policy->value('required_confirmations',15));
        if(!$v)return;
        $q=$this->db->prepare('SELECT user_id FROM deposit_addresses WHERE address=? AND user_id IS NOT NULL'); $q->execute([$v['to']]); $user=(int)$q->fetchColumn();
        if(!$user)throw new DomainException('Deposit address is not assigned.');
        $this->db->prepare('INSERT INTO deposits(user_id,tx_hash,log_index,address,amount,confirmations,block_number) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE confirmations=VALUES(confirmations)')
            ->execute([$user,$v['hash'],$index,$v['to'],$v['amount'],$v['confirmations'],$v['block']]);
        if(!$v['ready'])return;
        $q=$this->db->prepare('SELECT * FROM deposits WHERE tx_hash=? AND log_index=?'); $q->execute([$v['hash'],$index]); $deposit=$q->fetch();
        if($deposit['status']==='credited')return;
        $accounts=new Accounts($this->db); $available=$accounts->user($user);
        $key='transfer:'.$v['hash'].':'.$index;
        (new Ledger($this->db))->postEvent($key,$v['hash'],'Independently verified BSC testnet deposit',[
            Accounts::entry($accounts->find('platform:custody'),'debit',$v['amount']),Accounts::entry($available,'credit',$v['amount']),
        ],['type'=>'deposit','id'=>(int)$deposit['id'],'amount'=>$v['amount'],'user'=>$user],
        function(PDO $db,int $journal)use($deposit,$v,$policy,$user,$key):void {
            $policy->enabled('deposits');
            $q=$db->prepare("UPDATE deposits SET status='credited',journal_id=? WHERE id=? AND status='confirming' AND amount=? AND address=? AND user_id=?");
            $q->execute([$journal,$deposit['id'],$v['amount'],$v['to'],$user]);
            if($q->rowCount()!==1)throw new DomainException('Deposit state changed or does not match verification.');
            Accounts::notify($db,$key,$user,"Deposit confirmed\nAmount: ".$v['amount']." USDT\nNetwork: BSC testnet\nTransaction: ".$v['hash']);
        });
    }
    public function scan(): int
    {
        $policy=new Policy($this->db); $policy->enabled('deposits');
        $cursor=$policy->value('deposit_scan_cursor');
        if(!is_int($cursor) || $cursor<1)throw new DomainException('Configure the audited deposit scan starting block.');
        $rpc=new Rpc(getenv('BSC_RPC_URL')?:''); $rpc->chain();
        $head=Rpc::integer($rpc->call('eth_blockNumber'));
        $end=min($head,$cursor+499); if($end<$cursor)return 0;
        $contract=Rpc::address(getenv('BSC_TEST_TOKEN_CONTRACT')?:'');
        $logs=$rpc->call('eth_getLogs',[['address'=>$contract,'fromBlock'=>'0x'.dechex($cursor),'toBlock'=>'0x'.dechex($end),'topics'=>[Rpc::TRANSFER]]]);
        if(!is_array($logs))throw new DomainException('Invalid deposit scan response.');
        $count=0;
        foreach($logs as $log) {
            $to='0x'.substr((string)($log['topics'][2]??''),-40);
            $q=$this->db->prepare('SELECT user_id FROM deposit_addresses WHERE address=? AND user_id IS NOT NULL'); $q->execute([strtolower($to)]);
            if(!$q->fetchColumn())continue;
            $this->verifyAndCredit((string)$log['transactionHash'],Rpc::integer($log['logIndex'])); $count++;
        }
        // Re-scan confirmation window to detect recent reorgs and delayed logs.
        $next=max($cursor,$end-(int)$policy->value('required_confirmations',15));
        $this->db->prepare("UPDATE system_settings SET setting_value=? WHERE setting_key='deposit_scan_cursor'")->execute([json_encode($next)]);
        $q=$this->db->query("SELECT tx_hash,log_index FROM deposits WHERE status='confirming' ORDER BY id LIMIT 100");
        foreach($q->fetchAll() as $row)$this->verifyAndCredit($row['tx_hash'],(int)$row['log_index']);
        return $count;
    }
}
