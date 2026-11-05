<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
use Novikov\Telegram\Update;

$count = 0;
function expectReject(callable $f): void {
    global $count;
    try { $f(); } catch (InvalidArgumentException|RuntimeException) { $count++; return; }
    throw new Exception('Invalid webhook accepted.');
}
function check(bool $value): void {
    global $count;
    if (!$value) throw new Exception('Telegram assertion failed.');
    $count++;
}
$secret = str_repeat('a',32);
Update::authenticate($secret,$secret);
check(true);
expectReject(fn()=>Update::authenticate($secret,'wrong'));
expectReject(fn()=>Update::authenticate('',$secret));
expectReject(fn()=>Update::parse('{'));
expectReject(fn()=>Update::parse(str_repeat('x',65537)));
$base = ['update_id'=>12,'message'=>[
    'message_id'=>1,'from'=>['id'=>123,'is_bot'=>false,'first_name'=>'Test'],
    'chat'=>['id'=>123,'type'=>'private'],'text'=>'/start',
]];
$parsed = Update::parse(json_encode($base));
check($parsed['telegram_id'] === 123 && $parsed['callback_id'] === null);
foreach ([
    ['chat'=>['id'=>456,'type'=>'private']],
    ['chat'=>['id'=>123,'type'=>'group']],
    ['from'=>['id'=>123,'is_bot'=>true]],
    ['text'=>['invalid']],
] as $change) {
    $bad = $base;
    $bad['message'] = array_replace($bad['message'],$change);
    expectReject(fn()=>Update::parse(json_encode($bad)));
}
$callback = ['update_id'=>13,'callback_query'=>[
    'id'=>'callback-id','from'=>$base['message']['from'],'message'=>$base['message'],'data'=>'cabinet',
]];
check(Update::parse(json_encode($callback))['text'] === 'cabinet');
$callback['callback_query']['from']['id'] = 456;
expectReject(fn()=>Update::parse(json_encode($callback)));
$bad = $base; $bad['update_id'] = '12';
expectReject(fn()=>Update::parse(json_encode($bad)));
echo "$count Telegram validation checks passed. No live Telegram messages sent.\n";
