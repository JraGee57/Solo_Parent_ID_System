-- Step 30: mobile-linked authentication.
-- An applicant's mobile number is confirmed with a 6-digit SMS code before the account can be used
-- (and again whenever the number changes); a confirmed number can be used to sign in.
-- Run once against an existing database:
--   C:\xampp\mysql\bin\mysql.exe -u root virac_solo_parent < database/migrate_step30_mobile.sql

ALTER TABLE users
  ADD COLUMN mobile_verified_at DATETIME DEFAULT NULL AFTER mobile,             -- NULL = number not confirmed (yet, or since it changed)
  ADD COLUMN mobile_code VARCHAR(255) DEFAULT NULL AFTER mobile_verified_at,   -- bcrypt hash of the texted code, never the code itself
  ADD COLUMN mobile_code_expires_at DATETIME DEFAULT NULL AFTER mobile_code,
  ADD COLUMN mobile_code_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER mobile_code_expires_at;

-- Accounts that already exist keep working: their numbers have been receiving SMS all along.
UPDATE users SET mobile_verified_at = created_at WHERE mobile IS NOT NULL AND mobile <> '' AND mobile_verified_at IS NULL;

INSERT IGNORE INTO sms_templates (template_key, label, body) VALUES
('mobile_verification', 'Mobile Number Verification Code', 'MSWDO Virac: Your e-SoloParentID verification code is {code}. It expires in {minutes} minutes. Do not share this code with anyone.');
