-- @statement
CREATE TRIGGER IF NOT EXISTS ledger_journals_no_update
BEFORE UPDATE ON ledger_journals FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ledger journals are append-only'
-- @statement
CREATE TRIGGER IF NOT EXISTS ledger_journals_no_delete
BEFORE DELETE ON ledger_journals FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ledger journals are append-only'
-- @statement
CREATE TRIGGER IF NOT EXISTS ledger_entries_no_update
BEFORE UPDATE ON ledger_entries FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ledger entries are append-only'
-- @statement
CREATE TRIGGER IF NOT EXISTS ledger_accounts_no_update
BEFORE UPDATE ON ledger_accounts FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ledger account identity is immutable'
-- @statement
CREATE TRIGGER IF NOT EXISTS ledger_entries_no_delete
BEFORE DELETE ON ledger_entries FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ledger entries are append-only'
