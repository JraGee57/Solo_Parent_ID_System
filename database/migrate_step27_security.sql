-- Step 27: security review.
-- Run once against the live database (mysql client or phpMyAdmin "SQL" tab).

-- Password reset codes are stored as bcrypt hashes (never in plain text),
-- so a database or backup leak doesn't reveal a live code. Any code issued
-- before this change was plain text: it is cleared (the person just asks
-- for a new one).
ALTER TABLE users MODIFY password_reset_code VARCHAR(255) DEFAULT NULL;
UPDATE users SET password_reset_code = NULL, password_reset_expires_at = NULL WHERE password_reset_code IS NOT NULL;

-- Goes up whenever the password is changed, reset or replaced by an
-- administrator. Each session remembers the value it signed in with, so a
-- password change signs the account out everywhere else.
ALTER TABLE users ADD COLUMN auth_version INT NOT NULL DEFAULT 0 AFTER password_reset_expires_at;

-- Rate limits for the public endpoints (services/RateLimiter.php): sign-in
-- per IP, registration, forgot-password SMS, public status lookup. Rows are
-- pruned after a day. Keys are hashed (no raw IPs or usernames stored here).
CREATE TABLE IF NOT EXISTS rate_limit_hits (
  id      BIGINT AUTO_INCREMENT PRIMARY KEY,
  bucket  VARCHAR(64) NOT NULL,
  hit_at  DATETIME NOT NULL,
  KEY idx_rate_bucket (bucket, hit_at),
  KEY idx_rate_hit_at (hit_at)
) ENGINE=InnoDB;
