# Atomic financial events

Deposit, investment and withdrawal services remain disabled. Before implementing
them, use `Ledger::postEvent()` rather than updating business state separately
from `Ledger::post()`. No schema migration or seed balances are needed.

## Transaction and retry contract

`postEvent(key, reference, reason, entries, businessData, changeBusinessState)`
owns a single READ COMMITTED transaction on its PDO connection:

1. Validate and normalize balanced entries, then claim the unique journal key.
2. Lock ledger accounts in ascending ID order and validate available balances.
3. Invoke `changeBusinessState(PDO $db, int $journalId)` on that same connection.
4. Insert every ledger entry and commit once.

Any exception rolls back the journal claim, business writes and entries. Connection
loss before commit also rolls back InnoDB writes. After a lost commit response,
retry the **entire event with the same key and data** on a fresh connection.
The unique journal index serializes concurrent retries; an already committed
event returns its journal ID without invoking the callback. Rolled-back events
may invoke the callback again. This is not exactly-once execution of PHP code.

`businessData` is a nonempty array containing the full deterministic intent:
event type, semantic version, entity/owner identifiers, expected state and every
business value used by the callback. Use scalar values/arrays, decimal strings
for money, stable field ordering and stable identifiers, not current timestamps,
random values, objects or floats. It is hashed together with the normalized
entries, reference and reason. Different data with the same key is rejected.
Standalone journals cannot be mistaken for completed business events.
The hash is an identity check, not stored recovery data; durable event input must
exist in the originating request/job source.

## Trusted service callback requirements

- Perform only transactional DML against InnoDB tables using the supplied PDO.
  Lock business rows with `SELECT ... FOR UPDATE` and enforce expected status,
  ownership and uniqueness inside the callback. Persist `journalId` on the
  affected business record when its schema supports it.
- Throw on a rejected transition or unexpected affected-row count. Do not catch
  a write failure and return success. The callback return value is ignored;
  `postEvent` returns the committed journal ID.
- Never commit, roll back, start transactions, execute DDL, change autocommit or
  isolation, acquire a second connection, or call the ledger recursively.
  Caller-owned transactions are rejected without changing them.
- Lock accounts first (the ledger does this), then business rows in a stable
  order. Do not lock business rows before entering the boundary. Retry deadlocks
  and lock timeouts at the whole-event level with bounded backoff.
- No HTTP, chain transfers, signing, email or Telegram sends in this callback.
  If an external effect is needed, persist a transactional outbox record here
  and deliver it separately with its own idempotency and reconciliation.

This is an internal trusted-code boundary, not a sandbox for arbitrary callbacks:
PDO cannot undo an explicit commit, implicit DDL commit, nontransactional table
write or external side effect. The transaction-presence check detects an ended
transaction but cannot repair a callback that violates these requirements.
Do not expose the callback or raw SQL to users. Future services require review
and service-specific recovery tests before enabling any financial operation.

`post()` remains compatible for standalone accounting-only postings, but must
not be combined with separately committed business changes.

## Verification

`php novikov/tests/database.php` includes financial-event tests in a random,
disposable persistent schema. They exercise success, identical/conflicting
retries, concurrent retries, callback failure, failure after a business write
and a partial ledger write, and abrupt process death before commit.
Only use a dedicated MySQL/MariaDB test server with CREATE/DROP DATABASE rights.
The application schema and real funds are never used for fixtures.
