-- Step 22: Admin user management -- forced password change after an admin resets access, last sign-in time.
USE virac_solo_parent;

ALTER TABLE users
  ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER password_reset_expires_at,  -- set by an admin "reset access"; cleared when the user picks a new password
  ADD COLUMN last_login_at DATETIME DEFAULT NULL AFTER must_change_password;

-- Backfill the last sign-in from the audit log.
UPDATE users u JOIN (SELECT user_id, MAX(created_at) AS at FROM audit_logs WHERE action = 'Login' AND user_id IS NOT NULL GROUP BY user_id) l ON l.user_id = u.id
SET u.last_login_at = l.at;
