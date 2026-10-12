-- Separate the planned ID handover appointment from actual ID release.
-- Safe to apply once to an existing database; it preserves applications and history.
CREATE TABLE IF NOT EXISTS id_release_schedules (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  application_id INT NOT NULL UNIQUE,
  scheduled_at   DATETIME NOT NULL,
  location       VARCHAR(255) NOT NULL,
  scheduled_by   INT DEFAULT NULL,
  sms_message    VARCHAR(500) NOT NULL,
  sms_log_id     INT DEFAULT NULL,
  released_at    DATETIME DEFAULT NULL,
  released_by    INT DEFAULT NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_release_app FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
  CONSTRAINT fk_release_scheduler FOREIGN KEY (scheduled_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_release_sms FOREIGN KEY (sms_log_id) REFERENCES sms_logs(id) ON DELETE SET NULL,
  CONSTRAINT fk_release_confirmer FOREIGN KEY (released_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
