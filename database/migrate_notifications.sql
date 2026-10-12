-- Non-destructive migration for the internal Notification System (Step 18)
-- and the Appointment Reminder feature. Safe to run once against an
-- existing virac_solo_parent database with real data -- additive only.
--
--   "C:\xampp\mysql\bin\mysql.exe" -u root virac_solo_parent < database\migrate_notifications.sql

USE virac_solo_parent;

ALTER TABLE interviews
  ADD COLUMN reminder_sent_at DATETIME DEFAULT NULL AFTER scheduled_by;

CREATE TABLE notifications (
  id                      INT AUTO_INCREMENT PRIMARY KEY,
  user_id                 INT NOT NULL,
  type                    VARCHAR(30) NOT NULL,
  message                 VARCHAR(500) NOT NULL,
  related_application_id  INT NULL,
  is_read                 TINYINT(1) NOT NULL DEFAULT 0,
  created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_notif_app FOREIGN KEY (related_application_id) REFERENCES applications(id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT INTO sms_templates (template_key, label, body) VALUES
('appointment_reminder', 'Appointment Reminder (auto)', 'MSWDO Virac: Reminder -- your appointment for application {ref} is on {when} at {location}. Please arrive on time and bring a valid ID.')
ON DUPLICATE KEY UPDATE template_key = template_key;
