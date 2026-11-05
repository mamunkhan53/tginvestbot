-- @statement
CREATE TRIGGER IF NOT EXISTS users_no_self_referral_insert
AFTER INSERT ON users FOR EACH ROW
BEGIN
 IF NEW.referrer_id IS NOT NULL AND NEW.referrer_id = NEW.id THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Self-referrals are not allowed';
 END IF;
END
-- @statement
CREATE TRIGGER IF NOT EXISTS users_no_self_referral_update
BEFORE UPDATE ON users FOR EACH ROW
BEGIN
 IF NEW.referrer_id IS NOT NULL AND NEW.referrer_id = NEW.id THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Self-referrals are not allowed';
 END IF;
END
