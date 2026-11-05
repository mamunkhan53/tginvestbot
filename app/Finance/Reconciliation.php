<?php
declare(strict_types=1);
namespace Novikov\Finance;
use PDO;

final class Reconciliation
{
    public function __construct(private PDO $db) {}
    public function run():array
    {
        $queries=[
            'unbalanced_journals'=>"SELECT j.id FROM ledger_journals j LEFT JOIN ledger_entries e ON e.journal_id=j.id GROUP BY j.id HAVING COUNT(e.id)<2 OR COALESCE(SUM(CASE WHEN e.side='debit' THEN e.amount ELSE -e.amount END),0)<>0",
            'negative_protected_accounts'=>"SELECT a.id FROM ledger_accounts a LEFT JOIN ledger_entries e ON e.account_id=a.id WHERE a.prevent_negative=1 GROUP BY a.id,a.normal_side HAVING COALESCE(SUM(CASE WHEN e.side=a.normal_side THEN e.amount ELSE -e.amount END),0)<0",
            'deposit_posting_mismatch'=>"SELECT d.id FROM deposits d LEFT JOIN ledger_entries e ON e.journal_id=d.journal_id LEFT JOIN ledger_accounts a ON a.id=e.account_id AND a.code=CONCAT('user:',d.user_id,':available') WHERE d.status='credited' GROUP BY d.id,d.amount HAVING COALESCE(SUM(CASE WHEN a.id IS NOT NULL AND e.side='credit' THEN e.amount ELSE 0 END),0)<>d.amount",
            'investment_principal_mismatch'=>"SELECT i.id FROM investments i LEFT JOIN ledger_entries e ON e.journal_id=i.journal_id LEFT JOIN ledger_accounts a ON a.id=e.account_id AND a.code=CONCAT('user:',i.user_id,':invested') GROUP BY i.id,i.principal HAVING COALESCE(SUM(CASE WHEN a.id IS NOT NULL AND e.side='credit' THEN e.amount ELSE 0 END),0)<>i.principal",
            'withdrawal_reservation_mismatch'=>"SELECT w.id FROM withdrawals w LEFT JOIN ledger_entries e ON e.journal_id=w.journal_id LEFT JOIN ledger_accounts a ON a.id=e.account_id AND a.code=CONCAT('user:',w.user_id,':reserved') GROUP BY w.id,w.total HAVING COALESCE(SUM(CASE WHEN a.id IS NOT NULL AND e.side='credit' THEN e.amount ELSE 0 END),0)<>w.total",
            'completed_without_settlement'=>"SELECT id FROM withdrawals WHERE status='completed' AND (settlement_journal_id IS NULL OR tx_hash IS NULL)",
            'investment_without_settlement'=>"SELECT id FROM investments WHERE status='completed' AND (settlement_journal_id IS NULL OR settlement_amount IS NULL)",
        ];
        $findings=[];
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->db->beginTransaction();
        try {
            foreach($queries as $name=>$sql)$findings[$name]=$this->db->query($sql.' LIMIT 100')->fetchAll(PDO::FETCH_COLUMN);
            $this->db->prepare('INSERT INTO reconciliation_records(findings) VALUES (?)')->execute([json_encode($findings,JSON_THROW_ON_ERROR)]);
            $this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        return $findings;
    }
}
