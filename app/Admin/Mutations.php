<?php
declare(strict_types=1);
namespace Novikov\Admin;
use PDO;
use DomainException;
use Novikov\Finance\{Amount,Withdrawals};

final class Mutations
{
    public function __construct(private PDO $db,private array $admin) {}
    private function text(array $input,string $key,int $max=500): string
    {
        $value=$input[$key]??'';
        if(!is_string($value) || strlen($value)>$max || trim($value)==='')throw new DomainException("Invalid $key.");
        return trim($value);
    }
    private function integer(array $input,string $key,int $max=PHP_INT_MAX):int
    {
        $value=$this->text($input,$key,20);
        if(!ctype_digit($value) || (int)$value<1 || (int)$value>$max)throw new DomainException("Invalid $key.");
        return (int)$value;
    }
    public static function can(string $role,string $route):bool
    {
        return match($route) {
            'settings','staff'=>$role==='super_admin',
            'packages','users'=>in_array($role,['super_admin','administrator'],true),
            'withdrawals','deposits','investments'=>in_array($role,['super_admin','administrator','finance_manager'],true),
            default=>false,
        };
    }
    public function run(string $route,array $input):void
    {
        if(!self::can($this->admin['role'],$route))throw new DomainException('You do not have permission to perform this action.');
        $action=$this->text($input,'action',64);
        $reason=$this->text($input,'reason',400);
        $actor=(int)$this->admin['id'];
        if($route==='investments' && $action==='performance_result') {
            (new \Novikov\Investment\Service($this->db))->result($this->text($input,'id',32),$this->text($input,'payout',40),$reason,$actor);
            return;
        }
        if($route==='withdrawals') {
            $id=$this->text($input,'id',32);
            if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new DomainException('Invalid withdrawal reference.');
            $service=new Withdrawals($this->db);
            if($action==='withdrawal_approve')$service->approve($id,$actor,$reason);
            elseif($action==='withdrawal_reject')$service->reject($id,$reason,$actor);
            else throw new DomainException('Invalid withdrawal action.');
            return;
        }
        if($route==='deposits' && $action==='deposit_verify') {
            $id=$this->integer($input,'id');
            $q=$this->db->prepare('SELECT tx_hash,log_index FROM deposits WHERE id=?'); $q->execute([$id]); $row=$q->fetch();
            if(!$row)throw new DomainException('Deposit not found.');
            (new \Novikov\Blockchain\Deposits($this->db))->verifyAndCredit($row['tx_hash'],(int)$row['log_index']);
            $this->audit($action,"$id: $reason"); return;
        }
        $this->db->beginTransaction();
        try {
            if($route==='packages' && $action==='package_save') {
                $name=$this->text($input,'name',64); $description=$this->text($input,'description',5000);
                $min=Amount::positive($this->text($input,'minimum',40)); $max=Amount::positive($this->text($input,'maximum',40));
                $duration=$this->integer($input,'duration_hours',87600);
                $rate=$this->text($input,'rate',20);
                if(!preg_match('/^(?:[0-9]|[1-9][0-9]|100)(?:\.[0-9]{1,4})?$/D',$rate) || bccomp($rate,'100',4)>0 || bccomp($max,$min,18)<0)throw new DomainException('Invalid rate or package limits.');
                $model=$this->text($input,'model',20);
                if(!in_array($model,['fixed','performance'],true))throw new DomainException('Invalid product model.');
                $active=($input['active']??'0')==='1'?1:0;
                $this->db->prepare('INSERT INTO investment_packages(name,description,minimum,maximum,duration_hours,rate,model,active) VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE description=VALUES(description),minimum=VALUES(minimum),maximum=VALUES(maximum),duration_hours=VALUES(duration_hours),rate=VALUES(rate),model=VALUES(model),active=VALUES(active),terms_version=terms_version+1')
                    ->execute([$name,$description,$min,$max,$duration,$rate,$model,$active]);
            }elseif($route==='users' && $action==='user_review') {
                $id=$this->integer($input,'id');
                $status=$this->text($input,'status',20); $kyc=$this->text($input,'kyc_status',20); $risk=$this->text($input,'risk_status',20);
                $country=strtoupper($this->text($input,'country_code',2));
                if(!in_array($status,['active','suspended'],true) || !in_array($kyc,['pending','verified','rejected'],true) || !in_array($risk,['pending','clear','blocked'],true) || !preg_match('/^[A-Z]{2}$/D',$country))throw new DomainException('Invalid account review.');
                $q=$this->db->prepare('SELECT id FROM users WHERE id=? FOR UPDATE'); $q->execute([$id]); if(!$q->fetchColumn())throw new DomainException('User not found.');
                $this->db->prepare('UPDATE users SET status=? WHERE id=?')->execute([$status,$id]);
                $this->db->prepare('INSERT INTO user_compliance(user_id,kyc_status,risk_status,country_code,review_reason,reviewed_by) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE kyc_status=VALUES(kyc_status),risk_status=VALUES(risk_status),country_code=VALUES(country_code),review_reason=VALUES(review_reason),reviewed_by=VALUES(reviewed_by),updated_at=UTC_TIMESTAMP(6)')
                    ->execute([$id,$kyc,$risk,$country,$reason,$actor]);
            }elseif($route==='users' && $action==='wallet_review') {
                $id=$this->integer($input,'id');
                $verified=($input['verified']??'0')==='1'?1:0;
                $this->db->prepare('UPDATE withdrawal_addresses SET verified=? WHERE id=?')->execute([$verified,$id]);
            }elseif($route==='settings' && $action==='setting_save') {
                $key=$this->text($input,'key',100); $raw=$this->text($input,'value',1000); $value=null;
                $booleans=['testnet_deposits_enabled','testnet_investments_enabled','testnet_withdrawals_enabled','referral_enabled','maintenance_mode'];
                $decimals=['minimum_withdrawal','withdrawal_fee_percent','daily_withdrawal_limit','referral_level_1','referral_level_2'];
                if(in_array($key,$booleans,true)) {
                    if(!in_array($raw,['true','false'],true))throw new DomainException('Use true or false.'); $value=$raw==='true';
                }elseif(in_array($key,$decimals,true)){
                    $value=$raw==='0'?'0':Amount::positive($raw);
                    if((str_contains($key,'percent') || str_starts_with($key,'referral_')) && bccomp($value,'100',18)>0)throw new DomainException('Rate cannot exceed 100%.');
                }elseif(in_array($key,['required_confirmations','withdrawal_delay_hours','deposit_scan_cursor'],true)){
                    if(!ctype_digit($raw) || (int)$raw<1 || (int)$raw>2000000000)throw new DomainException('A positive integer is required.');
                    $value=(int)$raw;
                }elseif($key==='blocked_countries'){
                    $value=json_decode($raw,true);
                    if(!is_array($value) || !array_is_list($value) || count($value)>250)throw new DomainException('Use a JSON list of country codes.');
                    foreach($value as $country)if(!is_string($country) || !preg_match('/^[A-Z]{2}$/D',$country))throw new DomainException('Use uppercase two-letter country codes.');
                }else throw new DomainException('This setting cannot be changed through this form. Credentials and real-money activation are not accepted here.');
                $this->db->prepare('INSERT INTO system_settings(setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute([$key,json_encode($value,JSON_THROW_ON_ERROR)]);
            }elseif($route==='staff' && $action==='staff_create') {
                $email=strtolower($this->text($input,'email',254)); $password=$this->text($input,'password',1024); $role=$this->text($input,'role',30);
                if(!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($password)<16 || !in_array($role,['super_admin','administrator','finance_manager','support_manager','analyst'],true))throw new DomainException('Valid email, supported role and a 16-character password are required.');
                $this->db->prepare('INSERT INTO admin_users(email,password_hash,role) VALUES (?,?,?)')->execute([$email,password_hash($password,PASSWORD_ARGON2ID),$role]);
            }elseif($route==='staff' && $action==='staff_access') {
                $id=$this->integer($input,'id');
                if($id===$actor)throw new DomainException('You cannot change your own access here.');
                $role=$this->text($input,'role',30);
                if(!in_array($role,['super_admin','administrator','finance_manager','support_manager','analyst'],true))throw new DomainException('Unsupported role.');
                $this->db->prepare('UPDATE admin_users SET role=?,active=? WHERE id=?')->execute([$role,($input['active']??'0')==='1'?1:0,$id]);
            }else throw new DomainException('Unsupported action.');
            $this->audit($action,$reason.' | entity '.substr((string)($input['id']??$input['name']??$input['key']??$input['email']??''),0,80));
            $this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    private function audit(string $action,string $reason):void
    {
        $this->db->prepare('INSERT INTO admin_activity_logs(admin_id,action,reason) VALUES (?,?,?)')->execute([(int)$this->admin['id'],$action,mb_substr($reason,0,500)]);
    }
}
