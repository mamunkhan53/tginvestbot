<?php
declare(strict_types=1);
namespace Novikov\Finance;

use PDO;
use PDOException;
use DomainException;
use Throwable;

final class Ledger
{
    public function __construct(private PDO $db) {}

    /** Caller supplies balanced entries. Locks accounts in stable order. */
    public function post(string $key, string $reference, string $reason, array $entries): int
    {
        return $this->execute($key, $reference, $reason, $entries, null, null);
    }

    /**
     * Atomic database-only financial event. The callback runs once per committed
     * key, on this connection, before entries are inserted. It may run again after
     * a rollback. See docs/financial-events.md for the trusted callback contract.
     *
     * @param array $businessData Complete, deterministic business intent, including
     *                           event type/version and affected entity identifiers.
     * @param callable(PDO, int): void $changeBusinessState
     */
    public function postEvent(
        string $key,
        string $reference,
        string $reason,
        array $entries,
        array $businessData,
        callable $changeBusinessState,
    ): int {
        if ($businessData === []) throw new DomainException('Business event data is required.');
        return $this->execute($key, $reference, $reason, $entries, $businessData, $changeBusinessState);
    }

    private function execute(
        string $key,
        string $reference,
        string $reason,
        array $entries,
        ?array $businessData,
        ?callable $changeBusinessState,
    ): int {
        if ($key === '' || strlen($key) > 191 || $reference === '' || strlen($reference) > 191
            || trim($reason) === '' || strlen($reason) > 500 || count($entries) < 2) {
            throw new DomainException('A journal requires a key, reference, reason and balanced entries.');
        }
        $debit = $credit = '0';
        foreach ($entries as &$entry) {
            if (!isset($entry['account'], $entry['side'], $entry['amount'])
                || !is_int($entry['account']) || $entry['account'] < 1
                || !in_array($entry['side'], ['debit', 'credit'], true)
                || !is_string($entry['amount'])) {
                throw new DomainException('Invalid ledger entry.');
            }
            $entry['amount'] = Amount::positive($entry['amount']);
            if ($entry['side'] === 'debit') $debit = bcadd($debit, $entry['amount'], 18);
            else $credit = bcadd($credit, $entry['amount'], 18);
        }
        unset($entry);
        if (bccomp($debit, $credit, 18) !== 0) throw new DomainException('Unbalanced journal.');
        // Preserve existing standalone hashes. An event can never replay a
        // standalone posting (or vice versa), even with identical ledger data.
        $payload = [$reference, $reason, $entries];
        if ($businessData !== null) $payload = ['financial-event-v1', $payload, $businessData];
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        if ($this->db->inTransaction()) throw new DomainException('Ledger owns its transaction.');
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $this->db->beginTransaction();
        try {
            // Claim the key first. The unique index serializes independent
            // connections even when their account sets do not overlap.
            $insert = $this->db->prepare('INSERT INTO ledger_journals (idempotency_key,payload_hash,reference,reason) VALUES (?,?,?,?)');
            try {
                $insert->execute([$key, $hash, $reference, $reason]);
            } catch (PDOException $e) {
                if ((int)($e->errorInfo[1] ?? 0) !== 1062) throw $e;
                $this->db->rollBack();
                $existing = $this->db->prepare('SELECT id,payload_hash FROM ledger_journals WHERE idempotency_key = ?');
                $existing->execute([$key]);
                $journal = $existing->fetch();
                if (!$journal) throw $e;
                if (!hash_equals($journal['payload_hash'], $hash)) {
                    throw new DomainException('Idempotency key reused with different data.');
                }
                return (int)$journal['id'];
            }
            $journalId = (int)$this->db->lastInsertId();
            $ids = array_unique(array_column($entries, 'account'));
            sort($ids, SORT_NUMERIC);
            $accounts = [];
            $lock = $this->db->prepare('SELECT * FROM ledger_accounts WHERE id = ? FOR UPDATE');
            foreach ($ids as $id) {
                $lock->execute([$id]);
                $accounts[$id] = $lock->fetch();
                if (!$accounts[$id]) throw new DomainException('Account not found.');
            }
            $balance = $this->db->prepare("SELECT COALESCE(SUM(CASE WHEN side = ? THEN amount ELSE -amount END),0) FROM ledger_entries WHERE account_id = ?");
            foreach ($accounts as $id => $account) {
                $balance->execute([$account['normal_side'], $id]);
                $next = (string)$balance->fetchColumn();
                foreach ($entries as $entry) {
                    if ($entry['account'] === $id) {
                        $next = $entry['side'] === $account['normal_side']
                            ? bcadd($next, $entry['amount'], 18) : bcsub($next, $entry['amount'], 18);
                    }
                }
                if ($account['prevent_negative'] && bccomp($next, '0', 18) < 0) {
                    throw new DomainException('Insufficient available funds.');
                }
            }
            if ($changeBusinessState !== null) {
                $changeBusinessState($this->db, $journalId);
                if (!$this->db->inTransaction()) {
                    throw new DomainException('Business event callback ended the transaction.');
                }
            }
            $insert = $this->db->prepare('INSERT INTO ledger_entries (journal_id,account_id,side,amount) VALUES (?,?,?,?)');
            foreach ($entries as $entry) $insert->execute([$journalId, $entry['account'], $entry['side'], $entry['amount']]);
            $this->db->commit();
            return $journalId;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }
}
