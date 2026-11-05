<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
try {
    $address=\Novikov\Blockchain\Rpc::address($argv[1]??'');$reference=$argv[2]??'';
    if($address===strtolower(getenv('BSC_RESERVE_ADDRESS')?:''))throw new RuntimeException('Treasury address cannot be assigned to a customer.');
    if(!$reference || strlen($reference)>191)throw new RuntimeException('Custody reference required.');
    \Novikov\Database::connect()->prepare('INSERT INTO deposit_addresses(address,custody_reference) VALUES (?,?)')->execute([$address,$reference]);
    echo "Custodian-controlled address added to assignment pool. No private key accepted.\n";
}catch(Throwable){fwrite(STDERR,"Address import failed. Use a verified custodian-controlled address and unique custody reference.\n");exit(1);}
