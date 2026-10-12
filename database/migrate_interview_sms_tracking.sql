-- Link each appointment to its latest interview scheduling SMS attempt.
-- sms_logs already stores the final message, recipient, provider and result.
-- Repeatable on both a migrated database and a fresh schema.sql install.
SET @schema_upgrade_sql = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND COLUMN_NAME = 'interview_sms_log_id'),
  'DO 0',
  'ALTER TABLE appointments ADD COLUMN interview_sms_log_id INT NULL AFTER reminder_sent_at'
);
PREPARE schema_upgrade FROM @schema_upgrade_sql;
EXECUTE schema_upgrade;
DEALLOCATE PREPARE schema_upgrade;

SET @schema_upgrade_sql = IF(
  EXISTS(SELECT 1 FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND INDEX_NAME = 'idx_appt_sms_log'),
  'DO 0',
  'ALTER TABLE appointments ADD KEY idx_appt_sms_log (interview_sms_log_id)'
);
PREPARE schema_upgrade FROM @schema_upgrade_sql;
EXECUTE schema_upgrade;
DEALLOCATE PREPARE schema_upgrade;

SET @schema_upgrade_sql = IF(
  EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND CONSTRAINT_NAME = 'fk_appt_sms_log'),
  'DO 0',
  'ALTER TABLE appointments ADD CONSTRAINT fk_appt_sms_log FOREIGN KEY (interview_sms_log_id) REFERENCES sms_logs(id) ON DELETE SET NULL'
);
PREPARE schema_upgrade FROM @schema_upgrade_sql;
EXECUTE schema_upgrade;
DEALLOCATE PREPARE schema_upgrade;
SET @schema_upgrade_sql = NULL;
