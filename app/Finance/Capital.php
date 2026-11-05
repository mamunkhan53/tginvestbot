<?php
declare(strict_types=1);
namespace Novikov\Finance;
use PDO;
use DomainException;
use Novikov\Blockchain\{Rpc,Verifier};

final class Capital
{
    public function __construct(private PDO $db) {}
    public function fund(string $hash,int $index,string $evidence):void
    {
        if(trim($evidence)==='' || strlen($evidence)>500)throw new DomainException('Funding evidence required.');
        $address=Rpc::address(getenv('BSC_RESERVE_ADDRESS')?:'');
        $policy=new Policy($this->db);
        $verified=(new Verifier())->verify($hash,$index,(int)$policy->value('required_confirmations',15));
        if(!$verified || !$verified['ready'] || $verified['to']!==$address)throw new DomainException('Reserve funding is not independently confirmed.');
        $q=$this->db->prepare('SELECT id FROM deposit_addresses WHERE address=?');$q->execute([$address]);
        if($q->fetchColumn())throw new DomainException('Reserve treasury cannot also be a customer deposit address.');
        $accounts=new Accounts($this->db);$amount=$verified['amount'];
        $key='transfer:'.$verified['hash'].':'.$index;
        (new Ledger($this->db))->postEvent($key,$verified['hash'],'Verified reserve capital contribution',[
            Accounts::entry($accounts->find('platform:custody'),'debit',$amount),Accounts::entry($accounts->find('platform:reserve'),'credit',$amount),
        ],['type'=>'reserve-funding','hash'=>$verified['hash'],'index'=>$index,'amount'=>$amount,'evidence'=>$evidence],
        function(PDO $db,int $journal)use($verified,$index,$amount,$evidence):void {
            $db->prepare('INSERT INTO reserve_funding(tx_hash,log_index,amount,journal_id,evidence) VALUES (?,?,?,?,?)')->execute([$verified['hash'],$index,$amount,$journal,$evidence]);
        });
    }
}
