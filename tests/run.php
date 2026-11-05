<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
use Novikov\Finance\Amount;

$count = 0;
function check(bool $condition, string $name): void {
    global $count;
    if (!$condition) throw new RuntimeException("FAIL: $name");
    $count++;
}
$quote = Amount::withdrawal('5');
check($quote['net'] === '5.000000000000000000', 'net payout');
check($quote['fee'] === '0.050000000000000000', 'fee');
check($quote['total'] === '5.050000000000000000', 'deduction');
check(Amount::positive('0.000000000000000001') === '0.000000000000000001', 'smallest unit');
check(Amount::withdrawal('5','0')['fee'] === '0.000000000000000000', 'zero fee');
foreach (['-1','0','1e3','NaN','1.0000000000000000001',' 2','1000000000000000000'] as $input) {
    try { Amount::positive($input); throw new RuntimeException('Accepted invalid amount'); }
    catch (InvalidArgumentException) { $count++; }
}
foreach (['-1','101','100.01','NaN'] as $rate) {
    try { Amount::withdrawal('5',$rate); throw new RuntimeException('Accepted invalid fee'); }
    catch (InvalidArgumentException) { $count++; }
}
echo "$count amount tests passed. MySQL integration tests are not included in this result.\n";
