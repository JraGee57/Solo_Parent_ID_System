-- Step 8 (Online Application): saved application drafts. Additive only.
--
--   "C:\xampp\mysql\bin\mysql.exe" -u root virac_solo_parent < database\migrate_step8_drafts.sql

USE virac_solo_parent;

-- One in-progress (not yet submitted) application per applicant. Kept in
-- its own table so staff queues, counts and reports never see incomplete
-- records, and applications keeps its NOT NULL guarantees. Uploaded draft
-- files live in storage/uploads/drafts/<user_id>/ until submission.
CREATE TABLE application_drafts (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  user_id     INT NOT NULL UNIQUE,
  data        JSON NOT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_draft_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
