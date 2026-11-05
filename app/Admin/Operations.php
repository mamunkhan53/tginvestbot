<?php
declare(strict_types=1);
namespace Novikov\Admin;
use PDO;
use DomainException;

final class Operations
{
    public const ROUTES=['users','packages','investments','deposits','withdrawals','transactions','referrals','reports','settings','staff','health'];
    public function __construct(private PDO $db,private array $admin) {}
    public function data(string $route,string $search,int $page):array
    {
        if(in_array($route,['staff','settings'],true) && $this->admin['role']!=='super_admin')throw new DomainException('Only Super Admin may access this section.');
        $definitions=[
            'users'=>["u.id,u.telegram_id,u.username,u.status,c.kyc_status,c.risk_status,c.country_code,(SELECT wa.id FROM withdrawal_addresses wa WHERE wa.user_id=u.id ORDER BY wa.id DESC LIMIT 1) AS wallet_id,(SELECT wa.address FROM withdrawal_addresses wa WHERE wa.user_id=u.id ORDER BY wa.id DESC LIMIT 1) AS withdrawal_wallet,(SELECT wa.verified FROM withdrawal_addresses wa WHERE wa.user_id=u.id ORDER BY wa.id DESC LIMIT 1) AS wallet_verified,(SELECT COALESCE(SUM(CASE WHEN e.side='credit' THEN e.amount ELSE -e.amount END),0) FROM ledger_accounts a LEFT JOIN ledger_entries e ON e.account_id=a.id WHERE a.code=CONCAT('user:',u.id,':available')) AS balance,u.created_at",'users u LEFT JOIN user_compliance c ON c.user_id=u.id','u.id','u.username','u.telegram_id'],
            'packages'=>['id,name,minimum,maximum,duration_hours,rate,model,active,terms_version','investment_packages','id','name','id'],
            'investments'=>['i.id,i.user_id,p.name AS package,i.principal,i.status,i.created_at,i.matures_at,i.settlement_amount','investments i JOIN investment_packages p ON p.id=i.package_id','i.id','i.id','i.user_id'],
            'deposits'=>['id,user_id,amount,address,tx_hash,log_index,confirmations,status,created_at','deposits','id','tx_hash','user_id'],
            'withdrawals'=>['id,user_id,address,net_amount,fee,total,status,tx_hash,created_at','withdrawals','id','id','user_id'],
            'transactions'=>['e.id,a.user_id,j.reference,e.side,e.amount,j.reason,j.created_at','ledger_entries e JOIN ledger_accounts a ON a.id=e.account_id JOIN ledger_journals j ON j.id=e.journal_id','e.id','j.reference','a.user_id'],
            'referrals'=>['u.id,u.telegram_id,u.referrer_id,p.referrer_id AS second_level_referrer,(SELECT COALESCE(SUM(r.amount),0) FROM referral_commissions r WHERE r.beneficiary_id=u.id) AS commission_amount,u.created_at','users u LEFT JOIN users p ON p.id=u.referrer_id','u.id','u.username','u.telegram_id'],
            'reports'=>["a.id,a.code,a.user_id,a.normal_side,COALESCE(SUM(CASE WHEN e.side=a.normal_side THEN e.amount ELSE -e.amount END),0) AS balance",'ledger_accounts a LEFT JOIN ledger_entries e ON e.account_id=a.id','a.id','a.code','a.user_id'],
            'settings'=>['setting_key,setting_value','system_settings','setting_key','setting_key','setting_key'],
            'staff'=>['id,email,role,active,created_at','admin_users','id','email','id'],
            'health'=>['name,last_started_at,last_succeeded_at,status,summary','job_runs','name','name','name'],
        ];
        [$columns,$from,$order,$matchA,$matchB]=$definitions[$route];
        $sql="SELECT $columns FROM $from";
        $params=[];
        if($search!=='') { $sql.=" WHERE (CAST($matchA AS CHAR) LIKE ? OR CAST($matchB AS CHAR) LIKE ? OR CAST($order AS CHAR) LIKE ?)"; $params=['%'.$search.'%','%'.$search.'%','%'.$search.'%']; }
        if($route==='reports')$sql.=' GROUP BY a.id,a.code,a.user_id,a.normal_side';
        $sql.=" ORDER BY $order DESC LIMIT 26 OFFSET ".(($page-1)*25);
        $q=$this->db->prepare($sql);$q->execute($params);$rows=$q->fetchAll();
        $columnLabels=[];
        for($i=0;$i<$q->columnCount();$i++) { $key=$q->getColumnMeta($i)['name']; $columnLabels[$key]=ucwords(str_replace('_',' ',$key)); }
        $metrics=$this->metrics();
        return ['title'=>ucfirst($route),'description'=>$route==='reports'?'Ledger balances, not projected trading income. Each entry is an accounting movement; totals are not trading profit.':'Live database records. Financial operations remain testnet-only and subject to policy checks.',
            'columns'=>$columnLabels,'rows'=>array_slice($rows,0,25),'metrics'=>$metrics,'forms'=>$this->forms($route),'search'=>$search,'page'=>$page,'has_next'=>count($rows)>25];
    }
    private function metrics():array
    {
        return [
            'Registered users'=>(string)$this->db->query('SELECT COUNT(*) FROM users')->fetchColumn(),
            'Active investments'=>(string)$this->db->query("SELECT COUNT(*) FROM investments WHERE status='active'")->fetchColumn(),
            'Pending withdrawals'=>(string)$this->db->query("SELECT COUNT(*) FROM withdrawals WHERE status='pending'")->fetchColumn(),
            'Verified deposit volume (testnet)'=>(string)$this->db->query("SELECT COALESCE(SUM(amount),0) FROM deposits WHERE status='credited'")->fetchColumn().' USDT',
        ];
    }
    public function overview():array
    {
        $metrics=$this->metrics();
        $metrics['Credited referral commissions']=(string)$this->db->query('SELECT COALESCE(SUM(amount),0) FROM referral_commissions')->fetchColumn().' USDT';
        $metrics['Completed payouts']=(string)$this->db->query("SELECT COALESCE(SUM(net_amount),0) FROM withdrawals WHERE status='completed'")->fetchColumn().' USDT';
        $metrics['User liabilities']=(string)$this->db->query("SELECT COALESCE(SUM(CASE WHEN e.side='credit' THEN e.amount ELSE -e.amount END),0) FROM ledger_accounts a JOIN ledger_entries e ON e.account_id=a.id WHERE a.user_id IS NOT NULL")->fetchColumn().' USDT';
        $metrics['Realized fee revenue']=(string)$this->db->query("SELECT COALESCE(SUM(CASE WHEN e.side='credit' THEN e.amount ELSE -e.amount END),0) FROM ledger_accounts a JOIN ledger_entries e ON e.account_id=a.id WHERE a.code='platform:fees'")->fetchColumn().' USDT';
        $integrations=['Database'=>'Connected'];
        foreach(['Telegram bot'=>'TELEGRAM_BOT_TOKEN','BSC primary RPC'=>'BSC_RPC_URL','BSC independent verification'=>'BSC_RPC_VERIFY_URL','Authorized signer'=>'SIGNING_SERVICE_TOKEN','Custody test token'=>'BSC_TEST_TOKEN_CONTRACT'] as $label=>$variable)$integrations[$label]=getenv($variable)?'Configured; live operation not established by this status':'Missing configuration';
        return ['metrics'=>$metrics,'jobs'=>$this->db->query('SELECT * FROM job_runs ORDER BY name')->fetchAll(),'integrations'=>$integrations];
    }
    private function forms(string $route):array
    {
        if(!Mutations::can($this->admin['role'],$route))return [];
        $field=static fn(string $name,string $label,string $type='text',array $extra=[])=>['name'=>$name,'label'=>$label,'type'=>$type,'required'=>true]+$extra;
        $reason=$field('reason','Documented reason / evidence reference');
        $id=$field('id','Record ID');
        $form=static fn(string $title,string $action,array $fields,string $warning='')=>['title'=>$title,'action'=>$action,'fields'=>$fields,'submit'=>'Save and audit','warning'=>$warning];
        $roles=['super_admin'=>'Super Admin','administrator'=>'Administrator','finance_manager'=>'Finance Manager','support_manager'=>'Support Manager','analyst'=>'Read-only Analyst'];
        return match($route) {
            'investments'=>[$form('Approve realized performance result','performance_result',[$id,$field('payout','Actual total payout including returned principal (USDT)'),$reason],
                'For mature performance-based products only. Record independently verified trading/custody evidence. Losses reduce both returned principal and custody assets. Positive earnings must be funded from reserves. This is not a manual profit generator.')],
            'packages'=>[$form('Create or update package','package_save',[
                $field('name','Package name (same name updates existing package)'),$field('description','Legally reviewed product terms and risk disclosure','textarea'),
                $field('minimum','Minimum USDT'),$field('maximum','Maximum USDT'),$field('duration_hours','Duration in hours','number',['min'=>'1','max'=>'87600']),
                $field('rate','Return percentage (not trading performance)'),$field('model','Settlement model','select',['options'=>['fixed'=>'Funded fixed-term contractual','performance'=>'Approved realized-performance result required']]),
                $field('active','Enable package','checkbox',['required'=>false]),$reason,
            ],'Existing investment terms are snapshotted and will not change. Earnings require an actually funded reserve; no guaranteed trading claims are permitted.')],
            'users'=>[$form('Review account and compliance','user_review',[
                $id,$field('status','Account status','select',['options'=>['active'=>'Active','suspended'=>'Suspended']]),
                $field('kyc_status','Identity review','select',['options'=>['pending'=>'Pending','verified'=>'Verified','rejected'=>'Rejected']]),
                $field('risk_status','AML / sanctions review','select',['options'=>['pending'=>'Pending','clear'=>'Clear','blocked'=>'Blocked']]),
                $field('country_code','Country code (two letters)'),$reason,
            ],'Record the independent review evidence. This form does not perform identity or sanctions verification for you.'),
            $form('Review withdrawal wallet','wallet_review',[$field('id','Wallet record ID'),$field('verified','Ownership verified','checkbox',['required'=>false]),$reason])],
            'withdrawals'=>[$form('Approve pending withdrawal','withdrawal_approve',[$id,$reason],'Approval is not a completed payout. All security checks and blockchain verification still apply.'),
                $form('Reject unsubmitted withdrawal and refund reservation','withdrawal_reject',[$id,$reason],'Only pending or approved, unsubmitted requests can be refunded.')],
            'deposits'=>[$form('Independently verify deposit','deposit_verify',[$id,$reason],'No manual approval: both configured RPC providers must agree on the real token transfer.')],
            'settings'=>[$form('Update operational policy','setting_save',[
                $field('key','Setting key','select',['options'=>array_combine(
                    ['testnet_deposits_enabled','testnet_investments_enabled','testnet_withdrawals_enabled','referral_enabled','maintenance_mode','minimum_withdrawal','withdrawal_fee_percent','daily_withdrawal_limit','referral_level_1','referral_level_2','required_confirmations','withdrawal_delay_hours','deposit_scan_cursor','blocked_countries'],
                    ['Testnet deposits','Testnet investments','Testnet withdrawals','Referral commissions','Maintenance mode','Minimum withdrawal','Withdrawal fee %','Daily withdrawal limit','Level 1 %','Level 2 %','Confirmations','Wallet delay (hours)','Deposit scan start/cursor','Blocked countries'])]),
                $field('value','Value (booleans: true/false; countries: JSON array)'),$reason,
            ],'Real-money switches, signing keys and external credentials cannot be changed here.')],
            'staff'=>[$form('Create staff account','staff_create',[$field('email','Email'),$field('password','Initial password (16+ characters)','password'),$field('role','Role','select',['options'=>$roles]),$reason],'New staff must enroll an authenticator before access. Never share passwords through Telegram.'),
                $form('Change staff access','staff_access',[$id,$field('role','Role','select',['options'=>$roles]),$field('active','Active','checkbox',['required'=>false]),$reason])],
            default=>[],
        };
    }
}
