<?php
declare(strict_types=1);
namespace Novikov\Finance;
use PDO;
use DomainException;

final class Policy
{
    public function __construct(private PDO $db) {}
    public function value(string $key, mixed $default = null): mixed
    {
        $q=$this->db->prepare('SELECT setting_value FROM system_settings WHERE setting_key=?');
        $q->execute([$key]); $value=$q->fetchColumn();
        return $value===false ? $default : json_decode($value,true,32,JSON_THROW_ON_ERROR);
    }
    public function enabled(string $operation): void
    {
        if ((int)$this->value('chain_id',97)!==97
            || $this->value('real_money_'.$operation,false)!==false
            || $this->value('testnet_'.$operation.'_enabled',false)!==true) {
            throw new DomainException('This operation is disabled. Only explicitly enabled BSC testnet operations are supported.');
        }
    }
    public function user(int $id): void
    {
        $q=$this->db->prepare('SELECT u.status,c.kyc_status,c.risk_status,c.country_code,s.terms_accepted FROM users u LEFT JOIN user_compliance c ON c.user_id=u.id LEFT JOIN user_settings s ON s.user_id=u.id WHERE u.id=?');
        $q->execute([$id]); $u=$q->fetch();
        if (!$u || $u['status']!=='active' || $u['kyc_status']!=='verified' || $u['risk_status']!=='clear'
            || !$u['terms_accepted'] || !$u['country_code']
            || in_array($u['country_code'],$this->value('blocked_countries',[]),true)) {
            throw new DomainException('An active, reviewed account, approved location and accepted risk terms are required.');
        }
    }
}
