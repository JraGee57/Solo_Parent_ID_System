-- Non-destructive migration for the Automatic Renewal Reminder feature.
-- Safe to run once against an existing virac_solo_parent database with
-- real data -- additive only (CREATE TABLE / INSERT a new template row).
--
--   "C:\xampp\mysql\bin\mysql.exe" -u root virac_solo_parent < database\migrate_renewal_reminders.sql

USE virac_solo_parent;

CREATE TABLE renewal_reminders (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  application_id  INT NOT NULL,
  sent_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_reminder_app (application_id),
  CONSTRAINT fk_reminder_app FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT INTO sms_templates (template_key, label, body) VALUES
('renewal_reminder', 'Renewal Reminder (auto)', 'MSWDO Virac: Your Solo Parent ID ({ref}) expires on {when}. Please visit MSWDO or apply for renewal online soon to avoid interruption.')
ON DUPLICATE KEY UPDATE template_key = template_key;
