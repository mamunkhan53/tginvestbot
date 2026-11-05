<?php
declare(strict_types=1);
namespace Novikov\Blockchain;
use RuntimeException;

final class Verifier
{
    public function verify(string $hash,int $index,int $confirmations): ?array
    {
        $first=getenv('BSC_RPC_URL')?:''; $second=getenv('BSC_RPC_VERIFY_URL')?:'';
        if(!$second || parse_url($first,PHP_URL_HOST)===parse_url($second,PHP_URL_HOST))throw new RuntimeException('Two independently hosted BSC RPC providers are required.');
        $contract=getenv('BSC_TEST_TOKEN_CONTRACT')?:'';
        $a=(new Rpc($first))->transfer($hash,$index,$contract,$confirmations);
        $b=(new Rpc($second))->transfer($hash,$index,$contract,$confirmations);
        if($a===null || $b===null)return null;
        foreach(['hash','log_index','from','to','amount','block','block_hash'] as $key) if($a[$key]!==$b[$key])throw new RuntimeException('Independent RPC verification disagreed.');
        $a['confirmations']=min($a['confirmations'],$b['confirmations']);
        $a['ready']=$a['confirmations']>=$confirmations;
        return $a;
    }
}
