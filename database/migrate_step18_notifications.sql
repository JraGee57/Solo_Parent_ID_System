-- Step 18: notifications for every role -- where each one leads, when it was read, and
-- system announcements sent by administrators. Safe to run once.
USE virac_solo_parent;

ALTER TABLE notifications
  ADD COLUMN link VARCHAR(255) DEFAULT NULL AFTER related_application_id,   -- in-app path it opens (never an external URL)
  ADD COLUMN read_at DATETIME DEFAULT NULL AFTER is_read,
  ADD KEY idx_notif_user_created (user_id, created_at);

-- Notifications already read keep a read time.
UPDATE notifications SET read_at = created_at WHERE is_read = 1 AND read_at IS NULL;

-- System announcements an administrator sent as notifications (one notification per recipient).
CREATE TABLE system_broadcasts (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  message     VARCHAR(500) NOT NULL,
  audience    ENUM('applicants','staff','everyone') NOT NULL,
  recipients  INT NOT NULL DEFAULT 0,
  sent_by     INT DEFAULT NULL,
  sent_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_broadcast_sender FOREIGN KEY (sent_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
