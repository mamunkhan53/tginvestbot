<?php
declare(strict_types=1);
namespace Novikov\Finance;
use PDO;
use DomainException;

final class Accounts
{
    public function __construct(private PDO $db) {}
    public function user(int $id, string $type='available'): int
    {
        if (!in_array($type,['available','invested','reserved'],true)) throw new DomainException('Invalid account type.');
        $code="user:$id:$type";
        $this->db->prepare("INSERT IGNORE INTO ledger_accounts(code,user_id,normal_side) VALUES (?,?,'credit')")->execute([$code,$id]);
        return $this->find($code);
    }
    public function find(string $code): int
    {
        $q=$this->db->prepare('SELECT id FROM ledger_accounts WHERE code=?');
        $q->execute([$code]); $id=$q->fetchColumn();
        if (!$id) throw new DomainException('Ledger account configuration missing.');
        return (int)$id;
    }
    public function balance(int $id): string
    {
        $q=$this->db->prepare("SELECT COALESCE(SUM(CASE WHEN e.side=a.normal_side THEN e.amount ELSE -e.amount END),0) FROM ledger_accounts a LEFT JOIN ledger_entries e ON e.account_id=a.id WHERE a.id=?");
        $q->execute([$id]); return bcadd((string)$q->fetchColumn(),'0',18);
    }
    public static function entry(int $id,string $side,string $amount): array
    {
        return ['account'=>$id,'side'=>$side,'amount'=>$amount];
    }
    public static function notify(PDO $db,string $key,int $user,string $text): void
    {
        $db->prepare('INSERT INTO financial_notifications(event_key,user_id,text) VALUES (?,?,?)')->execute([$key,$user,$text]);
    }
}
