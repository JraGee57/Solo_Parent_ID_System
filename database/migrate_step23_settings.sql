-- Step 23: Admin system settings -- each SMS message can be switched on or off (Notification Settings).
-- Every other new setting lives in system_settings with its default in config/settings.php, so nothing to seed.
USE virac_solo_parent;

ALTER TABLE sms_templates
  ADD COLUMN is_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER body;  -- 0 = this SMS is not sent (password_reset is always sent)
