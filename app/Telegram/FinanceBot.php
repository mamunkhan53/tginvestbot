<?php
declare(strict_types=1);
namespace Novikov\Telegram;
use PDO;
use DomainException;
use Novikov\Finance\{Accounts,Amount,Policy,Withdrawals};
use Novikov\Blockchain\{Deposits,Rpc};

final class FinanceBot
{
    public function __construct(private PDO $db) {}
    private function user(array $update):array
    {
        $q=$this->db->prepare("SELECT * FROM users WHERE telegram_id=? AND status='active'");$q->execute([$update['telegram_id']]);
        $u=$q->fetch();if(!$u)throw new DomainException('Send /start to register, or contact support if your account is restricted.');
        return $u;
    }
    public function handles(array $update):bool
    {
        if($update['callback_id']!==null)return str_starts_with($update['text'],'fin:');
        if(str_starts_with($update['text'],'/'))return false;
        $q=$this->db->prepare("SELECT s.user_id FROM telegram_sessions s JOIN users u ON u.id=s.user_id WHERE u.telegram_id=? AND s.stage IN ('investment_amount','withdrawal_amount','wallet_input') AND s.updated_at>TIMESTAMPADD(MINUTE,-30,UTC_TIMESTAMP(6))");
        $q->execute([$update['telegram_id']]);return (bool)$q->fetchColumn();
    }
    private function session(int $user):?array
    {
        $q=$this->db->prepare('SELECT * FROM telegram_sessions WHERE user_id=? AND updated_at>TIMESTAMPADD(MINUTE,-30,UTC_TIMESTAMP(6))');$q->execute([$user]);$s=$q->fetch();
        if(!$s)return null;$s['data']=json_decode($s['payload'],true,32,JSON_THROW_ON_ERROR);return $s;
    }
    private function save(int $user,string $stage,array $data=[]):string
    {
        $nonce=bin2hex(random_bytes(16));
        $this->db->prepare('INSERT INTO telegram_sessions(user_id,stage,nonce,payload) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE stage=VALUES(stage),nonce=VALUES(nonce),payload=VALUES(payload),updated_at=UTC_TIMESTAMP(6)')
            ->execute([$user,$stage,$nonce,json_encode($data,JSON_THROW_ON_ERROR)]);
        return $nonce;
    }
    private function screen(string $text,array $buttons=[]):array
    {
        $buttons[]=[['text'=>'Back to menu','callback_data'=>'home']];
        return ['text'=>$text,'reply_markup'=>['inline_keyboard'=>$buttons]];
    }
    private function button(string $text,string $data):array {return ['text'=>$text,'callback_data'=>'fin:'.$data];}
    public function respond(array $update):array
    {
        try{return $this->dispatch($update);}catch(DomainException|\InvalidArgumentException $e){return $this->screen($e->getMessage());}
        catch(\RuntimeException $e){return $this->screen('This operation cannot be completed. Check your account or contact support. Funds have not been declared successful.');}
    }
    private function dispatch(array $update):array
    {
        $user=$this->user($update);$id=(int)$user['id'];$action=$update['text'];$policy=new Policy($this->db);
        $this->db->prepare("DELETE FROM telegram_sessions WHERE user_id=? AND updated_at<=TIMESTAMPADD(MINUTE,-30,UTC_TIMESTAMP(6))")->execute([$id]);
        if($policy->value('maintenance_mode',false))return $this->screen('The platform is under maintenance. No new financial actions are accepted.');
        $settings=$this->db->prepare('INSERT IGNORE INTO user_settings(user_id) VALUES (?)');$settings->execute([$id]);
        if($update['callback_id']===null) {
            $session=$this->session($id);if(!$session)return $this->screen('Your session expired. Choose an action again.');
            if($session['stage']==='investment_amount'){
                $amount=Amount::positive(trim($action));$package=(int)$session['data']['package'];
                $q=$this->db->prepare('SELECT * FROM investment_packages WHERE id=? AND active=1');$q->execute([$package]);$p=$q->fetch();
                if(!$p || bccomp($amount,$p['minimum'],18)<0 || bccomp($amount,$p['maximum'],18)>0)throw new DomainException('Amount is outside package limits.');
                $nonce=$this->save($id,'investment_confirm',['package'=>$package,'amount'=>$amount]);
                return $this->screen("Confirm investment\nPackage: ".$p['name']."\nPrincipal: $amount USDT\nDuration: ".$p['duration_hours']." hours\nConfigured rate: ".$p['rate']."%\nModel: ".$p['model']."\n".$p['description']."\nThis is BSC testnet. Projected returns are not received earnings.",[[$this->button('Confirm investment',"invest_confirm:$nonce")],[$this->button('Cancel','cancel')]]);
            }
            if($session['stage']==='withdrawal_amount') {
                $quote=Amount::withdrawal(trim($action),(string)$policy->value('withdrawal_fee_percent','1'));
                $q=$this->db->prepare('SELECT * FROM withdrawal_addresses WHERE user_id=? ORDER BY id DESC LIMIT 1');$q->execute([$id]);$w=$q->fetch();
                if(!$w)throw new DomainException('Set a withdrawal wallet first.');
                $nonce=$this->save($id,'withdrawal_confirm',$quote+['wallet_id'=>(int)$w['id']]);
                return $this->screen("Confirm withdrawal\nNet payout: ".$quote['net']." USDT\nFee: ".$quote['fee']." USDT\nTotal deduction: ".$quote['total']." USDT\nWallet: ".substr($w['address'],0,6).'…'.substr($w['address'],-4),[[$this->button('Confirm withdrawal',"withdraw_confirm:$nonce")],[$this->button('Cancel','cancel')]]);
            }
            if($session['stage']==='wallet_input') {
                $address=Rpc::address(trim($action));$nonce=$this->save($id,'wallet_confirm',['address'=>$address]);
                return $this->screen("Confirm withdrawal wallet\n$address\nOwnership review and a security delay are required before withdrawals.",[[$this->button('Confirm wallet',"wallet_confirm:$nonce")],[$this->button('Cancel','cancel')]]);
            }
        }
        if($action==='fin:cancel'){
            $this->db->prepare('DELETE FROM telegram_sessions WHERE user_id=?')->execute([$id]);
            return $this->screen('Action cancelled. No new financial operation submitted.');
        }
        if($action==='fin:deposit'){
            $policy->enabled('deposits');
            $address=(new Deposits($this->db))->assign($id);
            if(!$address)return $this->screen('No custodian-controlled deposit address is available. Do not send funds. Contact support.');
            return $this->screen("Deposit USDT test token\nNetwork: BSC testnet (chain 97)\nAddress: $address\nDo not send mainnet funds. Only independently verified transfers with sufficient confirmations are credited.",[[$this->button('Refresh balance','balance')]]);
        }
        if($action==='fin:balance'){
            $accounts=new Accounts($this->db);
            return $this->screen('Available balance: '.$accounts->balance($accounts->user($id)).' USDT',[[$this->button('Deposit details','deposit')]]);
        }
        if($action==='fin:packages'){
            $rows=$this->db->query('SELECT id,name,minimum,maximum,duration_hours,rate FROM investment_packages WHERE active=1 ORDER BY id LIMIT 20')->fetchAll();$buttons=[];
            $text="Available packages\n";
            foreach($rows as $p){$text.="\n".$p['name'].": ".$p['minimum'].'–'.$p['maximum']." USDT, ".$p['duration_hours']." hours, ".$p['rate']."% configured return";$buttons[]=[ $this->button($p['name'],'package:'.$p['id']) ];}
            return $this->screen($rows?$text:"No investment packages are currently active.",$buttons);
        }
        if(preg_match('/^fin:package:([0-9]{1,10})$/D',$action,$match)){
            $policy->enabled('investments');$policy->user($id);$this->save($id,'investment_amount',['package'=>(int)$match[1]]);
            return $this->screen('Enter the investment amount in USDT as a decimal number.',[[$this->button('Cancel','cancel')]]);
        }
        if(preg_match('/^fin:invest_confirm:([a-f0-9]{32})$/D',$action,$match)){
            $session=$this->session($id);
            if(!$session || $session['stage']!=='investment_confirm' || !hash_equals($session['nonce'],$match[1]))throw new DomainException('Expired or already submitted confirmation.');
            (new \Novikov\Investment\Service($this->db))->open($id,(int)$session['data']['package'],$session['data']['amount'],$session['nonce']);
            $this->db->prepare('DELETE FROM telegram_sessions WHERE user_id=? AND nonce=?')->execute([$id,$session['nonce']]);
            return $this->screen('Investment created. Reference: '.$session['nonce'],[[$this->button('My packages','mine:0')]]);
        }
        if($action==='fin:withdraw'){
            $policy->enabled('withdrawals');$policy->user($id);$this->save($id,'withdrawal_amount');
            return $this->screen('Enter the net payout amount in USDT. The fee will be added to the total deduction.',[[$this->button('Cancel','cancel')]]);
        }
        if(preg_match('/^fin:withdraw_confirm:([a-f0-9]{32})$/D',$action,$match)){
            $s=$this->session($id);
            if(!$s || $s['stage']!=='withdrawal_confirm' || !hash_equals($s['nonce'],$match[1]))throw new DomainException('Expired or already submitted confirmation.');
            $q=$this->db->prepare('SELECT id FROM withdrawal_addresses WHERE user_id=? ORDER BY id DESC LIMIT 1');$q->execute([$id]);
            if((int)$q->fetchColumn()!==$s['data']['wallet_id'])throw new DomainException('Wallet changed. Start again.');
            $quote=Amount::withdrawal($s['data']['net'],(string)$policy->value('withdrawal_fee_percent','1'));
            if($quote['fee']!==$s['data']['fee'])throw new DomainException('Fee policy changed. Review a new quote.');
            (new Withdrawals($this->db))->request($id,$s['data']['net'],$s['nonce']);
            $this->db->prepare('DELETE FROM telegram_sessions WHERE user_id=? AND nonce=?')->execute([$id,$s['nonce']]);
            return $this->screen('Withdrawal submitted for review. Reference: '.$s['nonce']."\nThis is not a confirmed blockchain payout.");
        }
        if($action==='fin:settings') {
            $q=$this->db->prepare('SELECT * FROM user_settings WHERE user_id=?');$q->execute([$id]);$s=$q->fetch();
            return $this->screen("Settings\nAuto-withdraw: ".($s['auto_withdraw']?'On':'Off')."\nAuto-reinvest: ".($s['auto_reinvest']?'On':'Off')."\nRisk terms accepted: ".($s['terms_accepted']?'Yes':'No'),[
                [$this->button('Change withdrawal wallet','wallet')],[$this->button('Auto-withdraw On/Off','toggle:auto_withdraw')],
                [$this->button('Auto-reinvest On/Off','toggle:auto_reinvest')],[$this->button('Review risk terms','terms')],
            ]);
        }
        if($action==='fin:terms'){
            $nonce=$this->save($id,'terms_confirm');
            return $this->screen('Investment products carry financial risk. Rates are configured product terms, not proof of trading returns. BSC testnet tokens have no represented real-money value. Earnings and commissions must be funded. Identity, AML, sanctions and geographic checks apply. Your accepted package terms are snapshotted at confirmation.',[[$this->button('I acknowledge these risks',"terms_confirm:$nonce")]]);
        }
        if(preg_match('/^fin:terms_confirm:([a-f0-9]{32})$/D',$action,$match)){
            $s=$this->session($id);if(!$s || $s['stage']!=='terms_confirm' || !hash_equals($s['nonce'],$match[1]))throw new DomainException('Expired acknowledgement.');
            $this->db->prepare('UPDATE user_settings SET terms_accepted=1 WHERE user_id=?')->execute([$id]);
            $this->db->prepare('DELETE FROM telegram_sessions WHERE user_id=? AND nonce=?')->execute([$id,$s['nonce']]);
            return $this->screen('Risk acknowledgement recorded.');
        }
        if($action==='fin:wallet'){$this->save($id,'wallet_input');return $this->screen('Enter your BSC withdrawal address. Do not send private keys or seed phrases.');}
        if(preg_match('/^fin:wallet_confirm:([a-f0-9]{32})$/D',$action,$match)){
            $s=$this->session($id);if(!$s || $s['stage']!=='wallet_confirm' || !hash_equals($s['nonce'],$match[1]))throw new DomainException('Expired wallet confirmation.');
            $this->db->beginTransaction();
            try {
                $q=$this->db->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$q->execute([$id]);
                $this->db->prepare('INSERT INTO withdrawal_addresses(user_id,address,eligible_at) VALUES (?,?,TIMESTAMPADD(HOUR,?,UTC_TIMESTAMP(6)))')
                    ->execute([$id,$s['data']['address'],(int)$policy->value('withdrawal_delay_hours',24)]);
                Accounts::notify($this->db,'wallet:'.$s['nonce'],$id,'Withdrawal address changed. Ownership review and security delay apply. If you did not request this, contact support immediately.');
                $this->db->prepare('DELETE FROM telegram_sessions WHERE user_id=? AND nonce=?')->execute([$id,$s['nonce']]);
                $this->db->commit();
            }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
            return $this->screen('Wallet saved as unverified. Withdrawals remain blocked pending ownership review and security delay.');
        }
        if(preg_match('/^fin:toggle:(auto_withdraw|auto_reinvest)$/D',$action,$match)){
            $field=$match[1];
            $q=$this->db->prepare("UPDATE user_settings SET $field=NOT $field WHERE user_id=?");$q->execute([$id]);
            return $this->screen('Automation preference updated. All funding, account, wallet and withdrawal checks remain mandatory.',[[$this->button('Settings','settings')]]);
        }
        if(preg_match('/^fin:mine:([0-9]{1,5})$/D',$action,$match)){
            $page=(int)$match[1];$q=$this->db->prepare('SELECT * FROM investments WHERE user_id=? ORDER BY created_at DESC LIMIT 6 OFFSET '.($page*5));$q->execute([$id]);$rows=$q->fetchAll();$text="My investments\n";$buttons=[];
            foreach(array_slice($rows,0,5) as $i){$terms=json_decode($i['terms'],true);$text.="\n".$i['id']."\n".$terms['name']." · ".$i['principal']." USDT\nStatus: ".$i['status']."\nMaturity: ".$i['matures_at']." UTC\n";}
            if(!$rows)$text.="\nNo investments yet.";
            if($page>0)$buttons[]=[ $this->button('Previous','mine:'.($page-1)) ];if(count($rows)>5)$buttons[]=[ $this->button('Next','mine:'.($page+1)) ];
            return $this->screen($text,$buttons);
        }
        if($action==='fin:examples'){
            $text="Illustrative calculations only; not received or guaranteed trading results.\n";
            foreach($this->db->query('SELECT name,rate FROM investment_packages ORDER BY id')->fetchAll() as $p)$text.="\n".$p['name'].": 100 USDT → ".bcadd('100',bcdiv(bcmul('100',$p['rate'],22),'100',18),18).' USDT';
            return $this->screen($text);
        }
        if($action==='fin:analytics'){
            $q=$this->db->prepare("SELECT COUNT(*) AS total,SUM(status='active') AS active,COALESCE(SUM(principal),0) AS invested,COALESCE(SUM(CASE WHEN status='completed' THEN settlement_amount-principal ELSE 0 END),0) AS realized FROM investments WHERE user_id=?");$q->execute([$id]);$r=$q->fetch();
            return $this->screen("Portfolio analytics\nInvestments: ".$r['total']."\nActive: ".($r['active']??0)."\nCumulative invested principal: ".$r['invested']." USDT\nRealized investment earnings: ".$r['realized']." USDT\nInternal transfers are not profit.");
        }
        if($action==='fin:help')return $this->screen('Review risk terms and available packages. Complete identity and wallet ownership reviews. Use BSC testnet only. Deposits require actual transfer verification; withdrawals require review and network confirmation. Monitor your history and package maturity.');
        throw new DomainException('Unsupported or expired action. Return to the menu.');
    }
}
