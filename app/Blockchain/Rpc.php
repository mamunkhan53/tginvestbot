<?php
declare(strict_types=1);
namespace Novikov\Blockchain;
use RuntimeException;

final class Rpc
{
    public const TRANSFER='0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';
    public function __construct(private string $url)
    {
        if (!filter_var($url,FILTER_VALIDATE_URL) || parse_url($url,PHP_URL_SCHEME)!=='https') throw new RuntimeException('HTTPS BSC RPC configuration required.');
    }
    public function call(string $method,array $params=[]): mixed
    {
        $curl=curl_init($this->url);
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode(['jsonrpc'=>'2.0','id'=>1,'method'=>$method,'params'=>$params],JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);
        $body=curl_exec($curl); $status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE); curl_close($curl);
        if($body===false || $status!==200)throw new RuntimeException('Blockchain RPC unavailable.');
        $result=json_decode($body,true,64,JSON_THROW_ON_ERROR);
        if (!is_array($result) || ($result['id']??null)!==1 || isset($result['error']) || !array_key_exists('result',$result)) throw new RuntimeException('Invalid RPC response.');
        return $result['result'];
    }
    public function chain(): int
    {
        $id=self::integer($this->call('eth_chainId'));
        if ($id!==97) throw new RuntimeException('Only BSC testnet chain 97 is permitted.');
        return $id;
    }
    public static function address(string $address): string
    {
        if(!preg_match('/^0x[a-fA-F0-9]{40}$/D',$address) || strtolower($address)==='0x'.str_repeat('0',40))throw new RuntimeException('Invalid BEP-20 address.');
        return strtolower($address);
    }
    public static function integer(mixed $hex): int
    {
        if(!is_string($hex) || !preg_match('/^0x[0-9a-fA-F]{1,64}$/D',$hex))throw new RuntimeException('Invalid blockchain integer.');
        $number=gmp_init(substr($hex,2),16);
        if(gmp_cmp($number,PHP_INT_MAX)>0)throw new RuntimeException('Blockchain integer overflow.');
        return (int)gmp_strval($number);
    }
    public static function tokenAmount(string $hex): string
    {
        if(!preg_match('/^0x[0-9a-fA-F]{64}$/D',$hex))throw new RuntimeException('Invalid token amount.');
        $amount=bcdiv(gmp_strval(gmp_init(substr($hex,2),16)),'1000000000000000000',18);
        return \Novikov\Finance\Amount::positive($amount);
    }
    public function transfer(string $hash,int $logIndex,string $contract,int $required): ?array
    {
        if(!preg_match('/^0x[a-fA-F0-9]{64}$/D',$hash))throw new RuntimeException('Invalid transaction hash.');
        $this->chain(); $contract=self::address($contract);
        if(self::integer($this->call('eth_call',[['to'=>$contract,'data'=>'0x313ce567'],'latest']))!==18) throw new RuntimeException('Token must have 18 decimal places.');
        $receipt=$this->call('eth_getTransactionReceipt',[$hash]); if($receipt===null)return null;
        if(!is_array($receipt) || strtolower($receipt['transactionHash']??'')!==strtolower($hash)
            || ($receipt['status']??'')!=='0x1')throw new RuntimeException('Transaction did not succeed.');
        $number=self::integer($receipt['blockNumber']??null);
        $block=$this->call('eth_getBlockByNumber',[$receipt['blockNumber'],false]);
        if(!is_array($block) || ($block['hash']??null)!==($receipt['blockHash']??null))throw new RuntimeException('Transaction block is not canonical.');
        $head=self::integer($this->call('eth_blockNumber'));
        $confirmations=max(0,$head-$number+1);
        foreach($receipt['logs']??[] as $log) {
            if(self::integer($log['logIndex']??null)!==$logIndex)continue;
            if(strtolower($log['address']??'')!==$contract || ($log['removed']??false)
                || strtolower($log['topics'][0]??'')!==self::TRANSFER || count($log['topics']??[])!==3
                || !preg_match('/^0x0{24}[a-fA-F0-9]{40}$/D',$log['topics'][2])
                || !preg_match('/^0x0{24}[a-fA-F0-9]{40}$/D',$log['topics'][1])) throw new RuntimeException('Invalid token transfer log.');
            return ['hash'=>strtolower($hash),'log_index'=>$logIndex,'to'=>self::address('0x'.substr($log['topics'][2],-40)),
                'from'=>self::address('0x'.substr($log['topics'][1],-40)),
                'amount'=>self::tokenAmount($log['data']),'block'=>$number,'block_hash'=>$block['hash'],
                'confirmations'=>$confirmations,'ready'=>$confirmations>=$required];
        }
        throw new RuntimeException('Token transfer log not found.');
    }
}
