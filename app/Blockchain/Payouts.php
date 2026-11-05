<?php
declare(strict_types=1);
namespace Novikov\Blockchain;
use PDO;
use RuntimeException;
use Novikov\Finance\{Policy,Withdrawals};

final class Payouts
{
    public function __construct(private PDO $db) {}
    /** Signer must implement the documented durable-idempotency contract. */
    private function submit(array $withdrawal):string
    {
        $url=getenv('SIGNING_SERVICE_URL')?:''; $token=getenv('SIGNING_SERVICE_TOKEN')?:'';
        if(!filter_var($url,FILTER_VALIDATE_URL) || parse_url($url,PHP_URL_SCHEME)!=='https' || !$token
            || getenv('SIGNING_SERVICE_PROTOCOL')!=='novikov-v1')throw new RuntimeException('Authorized idempotent signing service configuration required.');
        $payload=['request_id'=>$withdrawal['id'],'chain_id'=>97,
            'contract'=>Rpc::address(getenv('BSC_TEST_TOKEN_CONTRACT')?:''),
            'from'=>Rpc::address(getenv('BSC_PAYOUT_ADDRESS')?:''),
            'to'=>$withdrawal['address'],'amount_units'=>bcmul($withdrawal['net_amount'],'1000000000000000000',0)];
        $curl=curl_init(rtrim($url,'/').'/withdrawals/'.$withdrawal['id']);
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload,JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$token,'Idempotency-Key: '.$withdrawal['id']],
            CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);
        $body=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
        if($body===false || $status!==200)throw new RuntimeException('Signing submission outcome unresolved. Reservation retained.');
        $response=json_decode($body,true,16,JSON_THROW_ON_ERROR);
        if(($response['request_id']??null)!==$withdrawal['id'] || !preg_match('/^0x[a-fA-F0-9]{64}$/D',$response['tx_hash']??''))throw new RuntimeException('Invalid signing service response. Reservation retained.');
        return strtolower($response['tx_hash']);
    }
    public function process():int
    {
        $policy=new Policy($this->db);$count=0;
        $rows=$this->db->query("SELECT * FROM withdrawals WHERE status IN ('approved','processing','uncertain') ORDER BY created_at LIMIT 25")->fetchAll();
        foreach($rows as $row) {
            if(!$row['tx_hash']) {
                $policy->enabled('withdrawals');
                $policy->user((int)$row['user_id']);
                $q=$this->db->prepare('SELECT * FROM withdrawal_addresses WHERE user_id=? ORDER BY id DESC LIMIT 1');
                $q->execute([$row['user_id']]);$wallet=$q->fetch();
                if(!$wallet || !$wallet['verified'] || (int)$wallet['id']!==(int)$row['address_id']
                    || strtotime($wallet['eligible_at'].' UTC')>time())throw new RuntimeException('Wallet security checks failed before signing.');
                $this->db->prepare("UPDATE withdrawals SET status='processing',updated_at=UTC_TIMESTAMP(6) WHERE id=? AND status IN ('approved','processing','uncertain')")->execute([$row['id']]);
                try {
                    $hash=$this->submit($row);
                    $this->db->prepare("UPDATE withdrawals SET tx_hash=?,updated_at=UTC_TIMESTAMP(6) WHERE id=? AND tx_hash IS NULL")->execute([$hash,$row['id']]);
                    $row['tx_hash']=$hash;
                }catch(\Throwable $e) {
                    $this->db->prepare("UPDATE withdrawals SET status='uncertain',updated_at=UTC_TIMESTAMP(6) WHERE id=? AND tx_hash IS NULL")->execute([$row['id']]);
                    throw $e;
                }
            }
            $rpc=new Rpc(getenv('BSC_RPC_URL')?:'');$rpc->chain();
            $receipt=$rpc->call('eth_getTransactionReceipt',[$row['tx_hash']]);if(!$receipt)continue;
            if(($receipt['status']??'')!=='0x1')throw new RuntimeException('Failed payout requires reviewed nonce and custody reconciliation; no automatic refund.');
            foreach($receipt['logs']??[] as $log) {
                if(strtolower($log['address']??'')!==strtolower(getenv('BSC_TEST_TOKEN_CONTRACT')?:'')
                    || strtolower($log['topics'][0]??'')!==Rpc::TRANSFER)continue;
                $verified=(new Verifier())->verify($row['tx_hash'],Rpc::integer($log['logIndex']),(int)$policy->value('required_confirmations',15));
                if(!$verified || !$verified['ready'] || $verified['to']!==strtolower($row['address'])
                    || $verified['from']!==Rpc::address(getenv('BSC_PAYOUT_ADDRESS')?:'')
                    || bccomp($verified['amount'],$row['net_amount'],18)!==0)continue;
                (new Withdrawals($this->db))->complete($row['id'],$verified);$count++;break;
            }
        }
        return $count;
    }
}
