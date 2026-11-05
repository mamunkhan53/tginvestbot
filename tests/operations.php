<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

use Novikov\Database;
use Novikov\Finance\{Accounts,Ledger,Withdrawals};
use Novikov\Investment\Service;

$checks=0;
function check(bool $ok,string $label):void {global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function rejects(callable $operation,string $label):void {try{$operation();}catch(DomainException){check(true,$label);return;}throw new RuntimeException($label);}
try {
    $db=Database::connect();
    $tables=['users','admin_users','admin_activity_logs','ledger_accounts','ledger_journals','ledger_entries','system_settings','investment_packages','user_compliance','user_settings','withdrawal_addresses','deposit_addresses','investments','referral_commissions','deposits','withdrawals','financial_notifications','job_runs','reconciliation_records','telegram_sessions','performance_results','reserve_funding'];
    foreach($tables as $table) {
        $row=$db->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM);
        $ddl=preg_replace('/^CREATE TABLE /','CREATE TEMPORARY TABLE ',$row[1]);
        $ddl=preg_replace('/^\s*CONSTRAINT .*FOREIGN KEY.*\n/m','',$ddl);
        $ddl=preg_replace('/,\s*\)/',"\n)",$ddl);
        $db->exec($ddl);
    }
    $db->exec("INSERT INTO admin_users(id,email,password_hash,role) VALUES(1,'test@example.invalid','not-a-login','super_admin')");
    $db->exec("INSERT INTO users(id,telegram_id,first_name,referral_code) VALUES(1,1001,'Isolated fixture','test-one')");
    $db->exec("INSERT INTO user_compliance(user_id,kyc_status,risk_status,country_code) VALUES(1,'verified','clear','GB')");
    $db->exec('INSERT INTO user_settings(user_id,terms_accepted) VALUES(1,1)');
    $db->exec("INSERT INTO ledger_accounts(code,normal_side) VALUES('platform:custody','debit'),('platform:reserve','credit'),('platform:fees','credit')");
    $db->exec("INSERT INTO system_settings VALUES('chain_id','97'),('testnet_investments_enabled','true'),('testnet_withdrawals_enabled','true')");
    $db->exec("INSERT INTO investment_packages(id,name,description,minimum,maximum,duration_hours,rate,active) VALUES(1,'Test product','Test-only risk terms',10,1000,1,10,1)");
    $accounts=new Accounts($db);$available=$accounts->user(1);
    $ledger=new Ledger($db);
    $ledger->post('fixture-user','isolated','Temporary fixture',[
        Accounts::entry($accounts->find('platform:custody'),'debit','100'),Accounts::entry($available,'credit','100')]);
    $ledger->post('fixture-reserve','isolated','Temporary fixture',[
        Accounts::entry($accounts->find('platform:custody'),'debit','1000'),Accounts::entry($accounts->find('platform:reserve'),'credit','1000')]);
    $service=new Service($db);$id=str_repeat('a',32);$service->open(1,1,'50',$id);
    check(bccomp($accounts->balance($available),'50',18)===0,'investment debits available funds');
    check(bccomp($accounts->balance($accounts->user(1,'invested')),'50',18)===0,'investment reserves principal');
    check($service->open(1,1,'50',$id)===$id,'investment replay');
    rejects(fn()=>$service->open(1,1,'51',$id),'mismatched investment intent');
    rejects(fn()=>$service->open(1,1,'200',str_repeat('b',32)),'insufficient balance');
    $db->exec("UPDATE investment_packages SET rate=99,terms_version=2 WHERE id=1");
    $snapshot=json_decode($db->query("SELECT terms FROM investments WHERE id='$id'")->fetchColumn(),true);
    check(bccomp($snapshot['rate'],'10',4)===0,'accepted terms immutable snapshot');
    $service->settle($id);
    check($db->query("SELECT status FROM investments WHERE id='$id'")->fetchColumn()==='active','early maturity blocked');
    $db->exec("UPDATE investments SET matures_at=TIMESTAMPADD(HOUR,-1,UTC_TIMESTAMP(6)) WHERE id='$id'");
    $service->settle($id);
    check(bccomp($accounts->balance($available),'105',18)===0,'principal and funded profit settled');
    $count=(int)$db->query('SELECT COUNT(*) FROM ledger_journals')->fetchColumn();
    $service->settle($id);
    check((int)$db->query('SELECT COUNT(*) FROM ledger_journals')->fetchColumn()===$count,'maturity replay no duplicate');
    $db->exec("INSERT INTO withdrawal_addresses(user_id,address,verified,eligible_at) VALUES(1,'0x1111111111111111111111111111111111111111',1,TIMESTAMPADD(DAY,-1,UTC_TIMESTAMP(6)))");
    $withdrawals=new Withdrawals($db);$wid=str_repeat('c',32);$withdrawals->request(1,'5',$wid);
    check(bccomp($accounts->balance($available),'99.95',18)===0,'net plus fee reserved');
    check(bccomp($accounts->balance($accounts->user(1,'reserved')),'5.05',18)===0,'withdrawal reserved account');
    check($withdrawals->request(1,'5',$wid)===$wid,'withdrawal replay');
    $withdrawals->approve($wid,1,'Test-only authorization');
    check($db->query("SELECT status FROM withdrawals WHERE id='$wid'")->fetchColumn()==='approved','approval does not mark paid');
    $withdrawals->reject($wid,'Test-only rejection',1);
    check(bccomp($accounts->balance($available),'105',18)===0,'rejection refunds exact total');
    rejects(fn()=>$withdrawals->reject($wid,'Duplicate refund',1),'double refund blocked');
    $db->exec("UPDATE system_settings SET setting_value='false' WHERE setting_key='testnet_investments_enabled'");
    rejects(fn()=>$service->open(1,1,'10',str_repeat('d',32)),'policy disable enforced');
    check(!\Novikov\Admin\Mutations::can('support_manager','withdrawals'),'support cannot change finances');
    check(!\Novikov\Admin\Mutations::can('finance_manager','settings'),'finance cannot change global policy');
    foreach(\Novikov\Admin\Operations::ROUTES as $route) {
        $data=(new \Novikov\Admin\Operations($db,['id'=>1,'role'=>'super_admin']))->data($route,'',1);
        check(is_array($data['rows']) && is_array($data['forms']),"admin $route route data");
    }
    $result=(new \Novikov\Finance\Reconciliation($db))->run();
    check(array_sum(array_map('count',$result))===0,'business records reconcile with ledger');
    $db->exec("UPDATE system_settings SET setting_value='true' WHERE setting_key='testnet_investments_enabled'");
    $db->exec("INSERT INTO investment_packages(id,name,description,minimum,maximum,duration_hours,rate,active,model) VALUES(2,'Performance fixture','Isolated realized-loss fixture',10,1000,1,0,1,'performance')");
    $pid=str_repeat('e',32);$service->open(1,2,'20',$pid);
    $db->exec("UPDATE investments SET matures_at=TIMESTAMPADD(HOUR,-1,UTC_TIMESTAMP(6)) WHERE id='$pid'");
    rejects(fn()=>$service->settle($pid),'performance requires evidence');
    $service->result($pid,'18','Isolated test result evidence',1);
    $service->settle($pid);
    check(bccomp($accounts->balance($available),'103',18)===0,'realized loss reduces returned principal');
    check(bccomp($db->query("SELECT settlement_amount FROM investments WHERE id='$pid'")->fetchColumn(),'18',18)===0,'performance payout recorded');
    $reserve=$accounts->balance($accounts->find('platform:reserve'));
    $ledger->post('drain-fixture-reserve','isolated','Temporary funding exhaustion fixture',[
        Accounts::entry($accounts->find('platform:reserve'),'debit',$reserve),Accounts::entry($accounts->find('platform:fees'),'credit',$reserve)]);
    $blockedId=str_repeat('f',32);$service->open(1,1,'10',$blockedId);
    $db->exec("UPDATE investments SET matures_at=TIMESTAMPADD(HOUR,-1,UTC_TIMESTAMP(6)) WHERE id='$blockedId'");
    rejects(fn()=>$service->settle($blockedId),'unfunded profit prohibited');
    check($db->query("SELECT status FROM investments WHERE id='$blockedId'")->fetchColumn()==='active','unfunded maturity rolls back business state');
    $adminBot=new \Novikov\Telegram\AdminBot($db);
    check($adminBot->respond(['text'=>'/stats','telegram_id'=>999999999999])['text']==='Administrative access denied.','unlisted Telegram administrator denied');
    $bot=new \Novikov\Telegram\FinanceBot($db);
    $screen=$bot->respond(['telegram_id'=>1001,'callback_id'=>'fixture','text'=>'fin:packages']);
    check(str_contains($screen['text'],'Test product'),'bot lists real active products');
    $screen=$bot->respond(['telegram_id'=>1001,'callback_id'=>'fixture','text'=>'fin:withdraw_confirm:'.str_repeat('0',32)]);
    check(str_contains($screen['text'],'Expired'),'forged financial confirmation denied');
    $screen=$bot->respond(['telegram_id'=>1001,'callback_id'=>'fixture','text'=>'fin:mine:0']);
    check(str_contains($screen['text'],$id),'bot shows user-owned investments');
    $result=(new \Novikov\Finance\Reconciliation($db))->run();
    check(array_sum(array_map('count',$result))===0,'performance loss reconciles with ledger');
    echo "$checks isolated operations checks passed. No application records or blockchain transactions were created.\n";
}catch(Throwable $e) {
    if(isset($db) && $db->inTransaction())$db->rollBack();
    $code=$e instanceof PDOException?(int)($e->errorInfo[1]??0):0;
    // Only test assertion messages are safe; never print raw PDO messages.
    $label=$e instanceof RuntimeException && !$e instanceof PDOException?$e->getMessage():'database failure';
    fwrite(STDERR,"Operations test failed: $label (".get_class($e).", driver $code, ".basename($e->getFile()).':'.$e->getLine().").\n");exit(1);
}
