-- Non-destructive migration for SMS-based forgot/reset password.
-- Safe to run once against an existing virac_solo_parent database with
-- real data -- additive only (new nullable columns, relax one NOT NULL,
-- INSERT a new template row).
--
--   "C:\xampp\mysql\bin\mysql.exe" -u root virac_solo_parent < database\migrate_mvc_auth.sql

USE virac_solo_parent;

ALTER TABLE users
  ADD COLUMN password_reset_code VARCHAR(10) DEFAULT NULL AFTER locked_until,
  ADD COLUMN password_reset_expires_at DATETIME DEFAULT NULL AFTER password_reset_code;

-- Password-reset texts aren't tied to any one application.
ALTER TABLE sms_logs MODIFY application_id INT NULL;

INSERT INTO sms_templates (template_key, label, body) VALUES
('password_reset', 'Password Reset Code', 'MSWDO Virac: Your password reset code is {code}. It expires in {minutes} minutes. If you didn''t request this, ignore this message.')
ON DUPLICATE KEY UPDATE template_key = template_key;
