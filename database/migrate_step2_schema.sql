-- Step 2 (Database Architecture): brings an existing virac_solo_parent
-- database up to the full 16-table e-SoloParentID structure. Safe to run
-- once against a database with real data: renames and additive changes
-- only, with backfills. Never drops data.
--
--   "C:\xampp\mysql\bin\mysql.exe" -u root virac_solo_parent < database\migrate_step2_schema.sql

USE virac_solo_parent;

-- ---------------------------------------------------------------
-- roles: users.role (previously an ENUM) now references this table.
-- role_key stays the value stored on users, so existing code and data
-- are unchanged; code is the spec's role code.
-- ---------------------------------------------------------------
CREATE TABLE roles (
  id           TINYINT UNSIGNED PRIMARY KEY,
  role_key     VARCHAR(30)  NOT NULL UNIQUE,
  code         VARCHAR(30)  NOT NULL UNIQUE,
  name         VARCHAR(60)  NOT NULL,
  description  VARCHAR(255) DEFAULT NULL
) ENGINE=InnoDB;

INSERT INTO roles (id, role_key, code, name, description) VALUES
(1, 'admin',              'ADMINISTRATOR', 'System Administrator',       'Manages users, settings, analytics, reports, SMS and audit logs.'),
(2, 'mswd_social_worker', 'MSWD_STAFF',    'MSWD Staff / Social Worker', 'Reviews and decides applications, verifies documents, manages appointments.'),
(3, 'applicant',          'SOLO_PARENT',   'Solo Parent Applicant',      'Applies for and renews their own Solo Parent ID.');

ALTER TABLE users
  MODIFY role VARCHAR(30) NOT NULL DEFAULT 'applicant',
  ADD CONSTRAINT fk_user_role FOREIGN KEY (role) REFERENCES roles(role_key) ON UPDATE CASCADE;

-- ---------------------------------------------------------------
-- applicants: one profile row per applicant account. Name, email and
-- mobile stay on users (no duplication); each submitted application keeps
-- its own snapshot of these details as the legal record of what was filed.
-- Backfilled from each applicant's most recent application.
-- ---------------------------------------------------------------
CREATE TABLE applicants (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  user_id       INT NOT NULL UNIQUE,
  birthdate     DATE DEFAULT NULL,
  sex           ENUM('Male','Female') DEFAULT NULL,
  civil_status  VARCHAR(30) DEFAULT NULL,
  barangay      VARCHAR(100) DEFAULT NULL,
  address       VARCHAR(255) DEFAULT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_applicant_barangay (barangay),
  CONSTRAINT fk_applicant_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT INTO applicants (user_id, birthdate, sex, civil_status, barangay, address)
SELECT u.id, a.birthdate, a.sex, a.civil_status, a.barangay, a.address
FROM users u
LEFT JOIN applications a ON a.id = (
  SELECT a2.id FROM applications a2 WHERE a2.user_id = u.id ORDER BY a2.submitted_at DESC, a2.id DESC LIMIT 1
)
WHERE u.role = 'applicant';

-- ---------------------------------------------------------------
-- eligibility_assessments: the public checker's PRELIMINARY results
-- (stored before login, keyed by a hash of the visitor's session, linked
-- to the account after login) and MSWD's official assessments.
-- ---------------------------------------------------------------
CREATE TABLE eligibility_assessments (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  session_token       CHAR(64) DEFAULT NULL,
  user_id             INT DEFAULT NULL,
  application_id      INT DEFAULT NULL,
  assessment_type     ENUM('PRELIMINARY','MSWD') NOT NULL DEFAULT 'PRELIMINARY',
  answers             JSON NOT NULL,
  preliminary_result  ENUM('LIKELY_ELIGIBLE','NOT_ELIGIBLE','ELIGIBLE') NOT NULL,
  result_reason       VARCHAR(255) DEFAULT NULL,
  assessed_by         INT DEFAULT NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_elig_session (session_token),
  CONSTRAINT fk_elig_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_elig_app FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
  CONSTRAINT fk_elig_assessor FOREIGN KEY (assessed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- appointments (renamed from interviews) + a lifecycle status.
-- AVAILABLE is a property of appointment_slots, not of a booking.
-- ---------------------------------------------------------------
RENAME TABLE interviews TO appointments;
ALTER TABLE appointments
  ADD COLUMN status ENUM('BOOKED','COMPLETED','CANCELLED','MISSED') NOT NULL DEFAULT 'BOOKED' AFTER location,
  ADD KEY idx_appt_scheduled (scheduled_at);
UPDATE appointments SET status = 'COMPLETED' WHERE result IS NOT NULL;

-- ---------------------------------------------------------------
-- application_status_history (renamed from status_history). changed_by
-- stays as a name snapshot; changed_by_user_id links the account.
-- ---------------------------------------------------------------
RENAME TABLE status_history TO application_status_history;
ALTER TABLE application_status_history
  ADD COLUMN changed_by_user_id INT DEFAULT NULL AFTER changed_by,
  ADD CONSTRAINT fk_hist_user FOREIGN KEY (changed_by_user_id) REFERENCES users(id) ON DELETE SET NULL;

-- ---------------------------------------------------------------
-- application_decisions: every MSWD approve / reject / correction
-- decision, with its reason and the deciding staff member.
-- Backfilled from past status history (decider unknown for old rows).
-- ---------------------------------------------------------------
CREATE TABLE application_decisions (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  application_id  INT NOT NULL,
  decision        ENUM('APPROVED','REJECTED','NEEDS_CORRECTION') NOT NULL,
  reason          VARCHAR(500) DEFAULT NULL,
  decided_by      INT DEFAULT NULL,
  decided_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_decision_app (application_id, decided_at),
  CONSTRAINT fk_decision_app FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
  CONSTRAINT fk_decision_staff FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT INTO application_decisions (application_id, decision, reason, decided_by, decided_at)
SELECT h.application_id,
       CASE h.status WHEN 'Approved' THEN 'APPROVED' WHEN 'Rejected' THEN 'REJECTED' ELSE 'NEEDS_CORRECTION' END,
       h.remarks, NULL, h.changed_at
FROM application_status_history h
WHERE h.status IN ('Approved', 'Rejected', 'For Revision');

-- ---------------------------------------------------------------
-- qr_application_slips: an unguessable token per slip (replaces putting
-- the sequential reference number in the QR). Used from Step 16.
-- ---------------------------------------------------------------
CREATE TABLE qr_application_slips (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  application_id  INT NOT NULL UNIQUE,
  token           CHAR(64) NOT NULL UNIQUE,
  issued_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at      DATETIME DEFAULT NULL,
  revoked_at      DATETIME DEFAULT NULL,
  CONSTRAINT fk_qr_app FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- chat_messages: Chat Support conversation history. Used from Step 20.
-- ---------------------------------------------------------------
CREATE TABLE chat_messages (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  user_id     INT NOT NULL,
  sender      ENUM('APPLICANT','ASSISTANT','STAFF') NOT NULL,
  message     TEXT NOT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_chat_user (user_id, created_at),
  CONSTRAINT fk_chat_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- application_documents: who verified each document and when; and at
-- most one file per requirement per application (the code already
-- replaces rather than adds on re-upload).
-- ---------------------------------------------------------------
ALTER TABLE application_documents
  ADD COLUMN verified_by INT DEFAULT NULL AFTER staff_remarks,
  ADD COLUMN verified_at DATETIME DEFAULT NULL AFTER verified_by,
  ADD UNIQUE KEY uq_doc_app_key (application_id, doc_key),
  ADD CONSTRAINT fk_doc_verifier FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL;

-- ---------------------------------------------------------------
-- audit_logs: user agent, plus indexes for the filters the log page uses.
-- ---------------------------------------------------------------
ALTER TABLE audit_logs
  ADD COLUMN user_agent VARCHAR(255) DEFAULT NULL AFTER ip_address,
  ADD KEY idx_audit_created (created_at),
  ADD KEY idx_audit_action (action);

-- ---------------------------------------------------------------
-- Indexes for the queues, dashboards and filters that query these most.
-- ---------------------------------------------------------------
ALTER TABLE applications
  ADD KEY idx_app_status (status),
  ADD KEY idx_app_submitted (submitted_at);
ALTER TABLE notifications
  ADD KEY idx_notif_user_read (user_id, is_read);
ALTER TABLE sms_logs
  ADD KEY idx_sms_status (provider_status),
  ADD KEY idx_sms_sent (sent_at);
