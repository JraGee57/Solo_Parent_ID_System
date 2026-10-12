-- Step 15: renewal reminder logging, per-expiry duplicate prevention, scheduled job run log. Safe to run once.
USE virac_solo_parent;

-- One reminder per ID *per expiry date* (a re-released ID with a new expiry gets a new reminder),
-- claimed before sending so overlapping runs can never text the same person twice.
ALTER TABLE renewal_reminders
  ADD COLUMN id_expires_at DATE DEFAULT NULL AFTER application_id,
  ADD COLUMN user_id INT DEFAULT NULL AFTER id_expires_at,
  ADD COLUMN days_before INT DEFAULT NULL AFTER user_id,
  ADD COLUMN status ENUM('SENDING','SENT','FAILED') NOT NULL DEFAULT 'SENT' AFTER days_before,
  ADD COLUMN attempts INT NOT NULL DEFAULT 1 AFTER status,
  ADD COLUMN sms_status VARCHAR(30) DEFAULT NULL AFTER attempts,
  ADD COLUMN notification_id INT DEFAULT NULL AFTER sms_status,
  ADD COLUMN trigger_source ENUM('cron','manual') NOT NULL DEFAULT 'cron' AFTER notification_id,
  ADD COLUMN error VARCHAR(255) DEFAULT NULL AFTER trigger_source;

UPDATE renewal_reminders r JOIN applications a ON a.id = r.application_id
SET r.id_expires_at = a.id_expires_at, r.user_id = a.user_id
WHERE r.id_expires_at IS NULL;
UPDATE renewal_reminders SET id_expires_at = DATE(sent_at) WHERE id_expires_at IS NULL;

ALTER TABLE renewal_reminders
  MODIFY id_expires_at DATE NOT NULL,
  ADD UNIQUE KEY uq_reminder_app_expiry (application_id, id_expires_at),
  ADD CONSTRAINT fk_reminder_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_reminder_notification FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE SET NULL;
ALTER TABLE renewal_reminders DROP INDEX uq_reminder_app;

-- Every run of a scheduled job (cron or triggered by an admin): when, what it did, whether it failed.
CREATE TABLE scheduled_job_runs (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  job            VARCHAR(50) NOT NULL,
  trigger_source ENUM('cron','manual') NOT NULL,
  triggered_by   INT DEFAULT NULL,
  status         ENUM('RUNNING','SUCCESS','FAILED','SKIPPED') NOT NULL DEFAULT 'RUNNING',  -- SKIPPED: another run of the job was still going
  checked        INT NOT NULL DEFAULT 0,
  sent           INT NOT NULL DEFAULT 0,
  failed         INT NOT NULL DEFAULT 0,
  message        VARCHAR(500) DEFAULT NULL,
  started_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at    DATETIME DEFAULT NULL,
  KEY idx_job_started (job, started_at),
  CONSTRAINT fk_jobrun_user FOREIGN KEY (triggered_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- SMS text for the catch-up reminder sent after an ID has already expired (admin-editable).
INSERT INTO sms_templates (template_key, label, body) VALUES
('renewal_expired', 'Renewal Reminder after Expiry (auto)', 'MSWDO Virac: Your Solo Parent ID ({ref}) expired on {when}. Please renew online or visit MSWDO to keep your benefits.')
  ON DUPLICATE KEY UPDATE template_key = template_key;
