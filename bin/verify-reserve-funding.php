<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
try{
    if(!isset($argv[1],$argv[2],$argv[3]) || !ctype_digit($argv[2]))throw new RuntimeException('Usage error.');
    (new \Novikov\Finance\Capital(\Novikov\Database::connect()))->fund($argv[1],(int)$argv[2],$argv[3]);
    echo "Independently verified testnet reserve capital recorded.\n";
}catch(Throwable){fwrite(STDERR,"Reserve funding not credited. Check transfer, network, custody address, confirmations and evidence.\n");exit(1);}
