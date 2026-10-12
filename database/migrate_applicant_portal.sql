-- Non-destructive migration for the applicant-portal feature set
-- (profile photos, document verification status, appointment
-- self-booking, ID expiration, Chat Support FAQ). Safe to run once
-- against an existing virac_solo_parent database with real data --
-- everything here is additive (ADD COLUMN / CREATE TABLE), nothing
-- is dropped. Mirrors the baseline already reflected in schema.sql
-- for fresh installs.
--
--   "C:\xampp\mysql\bin\mysql.exe" -u root virac_solo_parent < database\migrate_applicant_portal.sql

USE virac_solo_parent;

ALTER TABLE users
  ADD COLUMN profile_photo VARCHAR(255) DEFAULT NULL AFTER full_name;

ALTER TABLE applications
  ADD COLUMN id_expires_at DATE NULL AFTER status;

-- Backfill an expiry for IDs already released before this migration ran,
-- approximating the release date from the row's last update.
UPDATE applications
  SET id_expires_at = DATE_ADD(DATE(updated_at), INTERVAL 1 YEAR)
  WHERE status = 'ID Released' AND id_expires_at IS NULL;

ALTER TABLE application_documents
  ADD COLUMN verification_status ENUM('Pending','Verified','Needs Correction') NOT NULL DEFAULT 'Pending' AFTER mime,
  ADD COLUMN staff_remarks VARCHAR(500) DEFAULT NULL AFTER verification_status;

CREATE TABLE appointment_slots (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  slot_date   DATE NOT NULL,
  start_time  TIME NOT NULL,
  end_time    TIME NOT NULL,
  capacity    INT NOT NULL DEFAULT 10,
  created_by  INT NOT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_slot (slot_date, start_time),
  CONSTRAINT fk_slot_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

ALTER TABLE interviews
  ADD COLUMN slot_id INT NULL AFTER application_id,
  MODIFY scheduled_by INT NULL,
  ADD CONSTRAINT fk_interview_slot FOREIGN KEY (slot_id) REFERENCES appointment_slots(id) ON DELETE SET NULL;

CREATE TABLE faqs (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  question    VARCHAR(255) NOT NULL,
  answer      TEXT NOT NULL,
  sort_order  INT NOT NULL DEFAULT 0,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO faqs (question, answer, sort_order) VALUES
('Who can apply for a Solo Parent ID?', 'Any solo parent residing in Virac, Catanduanes who solely provides parental care to a child (below 22, or of any age if the child has a disability) under one of the circumstances in RA 8972 / RA 11861 -- e.g. widowed, abandoned, separated, or an unwed parent. Use the Eligibility Checker if you are unsure.', 1),
('What documents do I need?', 'Standard requirements for everyone: Barangay Certificate of Residency and Solo Parent Status, PSA Birth Certificate(s) of your children, a valid government ID, and a 2x2 ID photo. Depending on your specific circumstance (e.g. death of spouse, annulment), one or two additional documents are required -- these are listed for you automatically once you select your category in the application form.', 2),
('How long does processing take?', 'Once submitted, your application is Pending Review, then moves to Under Verification. If needed, MSWDO may schedule a short interview/assessment. Most applications are resolved within a few weeks, but this can vary depending on document completeness and MSWDO office workload.', 3),
('What if my application needs correction?', 'If a document is unclear or a requirement is missing, your application status will show "For Revision" with the specific remarks from MSWDO staff. Go to My Application or Upload Documents to see what needs fixing and to re-upload the corrected file.', 4),
('How do I renew my Solo Parent ID?', 'Your Solo Parent ID is valid for one year. Once it is close to expiring (or already expired), go to ID Renewal in the menu -- your previous information is pre-filled, and you only need to reconfirm your standard documents and submit.', 5),
('How will I be notified of updates?', 'MSWDO sends an SMS to your registered mobile number whenever your application status changes, an interview is scheduled, or your ID is approved/released. You can also see the full history under Notifications.', 6),
('Where is the MSWDO office?', 'MSWDO Office, Municipal Building, Salvacion, Virac, Catanduanes. Office hours are Monday to Friday, 8:00 AM to 5:00 PM.', 7);
