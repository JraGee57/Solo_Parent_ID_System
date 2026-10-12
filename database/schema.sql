-- e-SoloParentID (MSWDO Virac) -- database STRUCTURE for a fresh install.
-- Reference data and the initial admin account are in seed.sql; run it
-- right after this file:
--
--   "C:\xampp\mysql\bin\mysql.exe" -u root < database\schema.sql
--   "C:\xampp\mysql\bin\mysql.exe" -u root virac_solo_parent < database\seed.sql
--
-- WARNING: this DROPS and recreates virac_solo_parent, erasing all data.
-- Never run it against a database with real data -- existing databases are
-- upgraded with the additive database/migrate_*.sql files instead.
-- Current canonical structure: 31 tables. To compare/upgrade an existing
-- configured database without rebuilding it, use:
--   php database/sync_schema.php --check
--   php database/sync_schema.php --apply
-- The upgrade adds missing definitions and backs up before making changes;
-- incompatible existing definitions require a reviewed migration.

DROP DATABASE IF EXISTS virac_solo_parent;
CREATE DATABASE virac_solo_parent
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE virac_solo_parent;

-- ---------------------------------------------------------------
-- Roles and accounts
-- ---------------------------------------------------------------
-- role_key is what users.role stores; code is the spec's role code.
CREATE TABLE roles (
  id           TINYINT UNSIGNED PRIMARY KEY,
  role_key     VARCHAR(30)  NOT NULL UNIQUE,
  code         VARCHAR(30)  NOT NULL UNIQUE,
  name         VARCHAR(60)  NOT NULL,
  description  VARCHAR(255) DEFAULT NULL
) ENGINE=InnoDB;

CREATE TABLE users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  full_name     VARCHAR(150) NOT NULL,
  profile_photo VARCHAR(255) DEFAULT NULL,
  email         VARCHAR(150) NOT NULL,
  username      VARCHAR(50)  NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  mobile        VARCHAR(20)  DEFAULT NULL,
  mobile_verified_at DATETIME DEFAULT NULL,      -- NULL = number not confirmed by SMS code (yet, or since it changed)
  mobile_code   VARCHAR(255) DEFAULT NULL,       -- bcrypt hash of the texted verification code, never the code itself
  mobile_code_expires_at DATETIME DEFAULT NULL,
  mobile_code_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  role          VARCHAR(30)  NOT NULL DEFAULT 'applicant',
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  failed_login_attempts INT NOT NULL DEFAULT 0,
  locked_until  DATETIME DEFAULT NULL,
  password_reset_code VARCHAR(255) DEFAULT NULL, -- bcrypt hash of the texted code, never the code itself
  password_reset_expires_at DATETIME DEFAULT NULL,
  auth_version INT NOT NULL DEFAULT 0,  -- +1 on every password change/reset; sessions from before it are signed out
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,  -- set when an admin resets access; cleared when the user picks a new password
  last_login_at DATETIME DEFAULT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_user_role FOREIGN KEY (role) REFERENCES roles(role_key) ON UPDATE CASCADE
) ENGINE=InnoDB;

-- One profile per applicant account. Name, email and mobile live on users;
-- each submitted application keeps its own snapshot as the filed record.
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

-- ---------------------------------------------------------------
-- Solo parent categories (RA 8972 / RA 11861, Section 3) and the
-- category-specific initial requirements; category_id NULL retains the
-- legacy/renewal checklist used by the existing renewal workflow.
-- ---------------------------------------------------------------
CREATE TABLE categories (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(150) NOT NULL UNIQUE,
  description VARCHAR(500) DEFAULT NULL,
  sort_order  INT NOT NULL DEFAULT 0,
  is_active   TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE document_requirements (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  category_id  INT NULL,
  doc_key      VARCHAR(50)  NOT NULL,
  label        VARCHAR(255) NOT NULL,
  is_required  TINYINT(1) NOT NULL DEFAULT 1,
  sort_order   INT NOT NULL DEFAULT 0,
  CONSTRAINT fk_docreq_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
  UNIQUE KEY uq_docreq (category_id, doc_key)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Applications and everything attached to them
-- ---------------------------------------------------------------
CREATE TABLE applications (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  reference_no  VARCHAR(30) NOT NULL UNIQUE,
  user_id       INT NOT NULL,
  type          ENUM('new','renewal') NOT NULL DEFAULT 'new',
  renewal_of_id INT DEFAULT NULL,   -- for a renewal: the released ID it renews (keeps the chain of records)
  full_name     VARCHAR(150) NOT NULL,
  barangay      VARCHAR(100) NOT NULL,
  address       VARCHAR(255) NOT NULL,
  mobile        VARCHAR(20)  NOT NULL,
  email         VARCHAR(150) NOT NULL,
  birthdate     DATE NOT NULL,
  sex           ENUM('Male','Female') NOT NULL,
  civil_status  VARCHAR(30) NOT NULL,
  emergency_contact_name VARCHAR(150) DEFAULT NULL,
  emergency_contact_relation VARCHAR(80) DEFAULT NULL,
  emergency_contact_phone VARCHAR(30) DEFAULT NULL,
  emergency_contact_address VARCHAR(255) DEFAULT NULL,
  category_id   INT NOT NULL,
  reviewed_by   INT NULL,
  status        ENUM('Pending Review','Under Verification','For Revision','Interview Scheduled','Approved','ID Released','Rejected') NOT NULL DEFAULT 'Pending Review',
  eligibility_status  ENUM('Not Assessed','Eligible','Not Eligible') NOT NULL DEFAULT 'Not Assessed',
  eligibility_remarks VARCHAR(500) DEFAULT NULL,
  id_expires_at DATE NULL,
  consent_at    DATETIME NOT NULL,
  submitted_at  DATETIME NOT NULL,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_app_status (status),
  KEY idx_app_submitted (submitted_at),
  CONSTRAINT fk_app_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_app_category FOREIGN KEY (category_id) REFERENCES categories(id),
  CONSTRAINT fk_app_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_app_renewal_of FOREIGN KEY (renewal_of_id) REFERENCES applications(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- In-progress (not yet submitted) work: one new-application draft and one
-- renewal draft per applicant, kept out of applications so staff never see
-- incomplete records. Draft uploads live in storage/uploads/drafts/<user_id>/
-- (application) or drafts/renewal_<user_id>/ (renewal) until submission.
CREATE TABLE application_drafts (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  user_id     INT NOT NULL,
  kind        ENUM('application','renewal') NOT NULL DEFAULT 'application',  -- one draft per applicant per kind
  data        JSON NOT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_draft_user_kind (user_id, kind),
  CONSTRAINT fk_draft_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE application_children (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  application_id INT NOT NULL,
  full_name      VARCHAR(150) NOT NULL,
  birthdate      DATE NOT NULL,
  CONSTRAINT fk_child_app FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- At most one file per requirement per application (re-uploads replace it).
CREATE TABLE application_documents (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  application_id      INT NOT NULL,
  doc_key             VARCHAR(50) NOT NULL,
  original_name       VARCHAR(255) NOT NULL,
  stored_path         VARCHAR(255) NOT NULL,
  mime                VARCHAR(100) NOT NULL,
  -- Lifecycle: UPLOADED -> VALIDATING -> VALID | INVALID (server checks at upload; invalid files are never stored),
  -- then FOR_VERIFICATION once attached to a submitted application -> VERIFIED | NEEDS_CORRECTION | REJECTED (MSWD).
  verification_status ENUM('UPLOADED','VALIDATING','VALID','INVALID','FOR_VERIFICATION','VERIFIED','NEEDS_CORRECTION','REJECTED') NOT NULL DEFAULT 'FOR_VERIFICATION',
  staff_remarks       VARCHAR(500) DEFAULT NULL,
  verified_by         INT DEFAULT NULL,
  verified_at         DATETIME DEFAULT NULL,
  size_bytes          INT NOT NULL,
  sha256              CHAR(64) DEFAULT NULL,  -- checksum of the stored file (integrity)
  uploaded_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_doc_app_key (application_id, doc_key),
  CONSTRAINT fk_doc_app FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
  CONSTRAINT fk_doc_verifier FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Applicant availability declarations are separate from MSWD verification.
CREATE TABLE application_document_checklists (
  id INT AUTO_INCREMENT PRIMARY KEY,
  application_id INT NOT NULL,
  doc_key VARCHAR(50) NOT NULL,
  applicant_has TINYINT(1) NOT NULL DEFAULT 0,
  verification_status ENUM('Pending','Verified','Needs Correction') NOT NULL DEFAULT 'Pending',
  staff_remarks VARCHAR(500) DEFAULT NULL,
  reviewed_by INT DEFAULT NULL,
  reviewed_at DATETIME DEFAULT NULL,
  UNIQUE KEY uq_checklist_app_key (application_id, doc_key),
  CONSTRAINT fk_checklist_app FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
  CONSTRAINT fk_checklist_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Every change to a document's status -- applicant (re)submissions and MSWD
-- verdicts -- with who did it and when. application_documents only holds
-- the latest state; this is the full trace. Actor name/role are snapshots
-- (like audit_logs) so the trail survives account changes.
CREATE TABLE document_status_history (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  document_id    INT NOT NULL,
  application_id INT NOT NULL,
  doc_key        VARCHAR(50) NOT NULL,
  from_status    ENUM('UPLOADED','VALIDATING','VALID','INVALID','FOR_VERIFICATION','VERIFIED','NEEDS_CORRECTION','REJECTED') DEFAULT NULL,
  to_status      ENUM('UPLOADED','VALIDATING','VALID','INVALID','FOR_VERIFICATION','VERIFIED','NEEDS_CORRECTION','REJECTED') NOT NULL,
  remarks        VARCHAR(500) DEFAULT NULL,
  actor_user_id  INT DEFAULT NULL,
  actor_name     VARCHAR(150) NOT NULL,
  actor_role     VARCHAR(50) NOT NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_dsh_document (document_id, created_at),
  KEY idx_dsh_application (application_id),
  CONSTRAINT fk_dsh_document FOREIGN KEY (document_id) REFERENCES application_documents(id) ON DELETE CASCADE,
  CONSTRAINT fk_dsh_application FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
  CONSTRAINT fk_dsh_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Public checker results are PRELIMINARY (stored before login under a hash
-- of the visitor's session, linked to user_id after login); MSWD rows are
-- the official assessment during processing.
CREATE TABLE eligibility_assessments (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  session_token       CHAR(64) DEFAULT NULL,
  user_id             INT DEFAULT NULL,
  application_id      INT DEFAULT NULL,
  assessment_type     ENUM('PRELIMINARY','MSWD') NOT NULL DEFAULT 'PRELIMINARY',
  answers             JSON NOT NULL,
  preliminary_result  ENUM('LIKELY_ELIGIBLE','NOT_ELIGIBLE','ELIGIBLE','NEEDS_GUIDANCE') NOT NULL,
  result_reason       VARCHAR(500) DEFAULT NULL,
  assessed_by         INT DEFAULT NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_elig_session (session_token),
  CONSTRAINT fk_elig_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_elig_app FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
  CONSTRAINT fk_elig_assessor FOREIGN KEY (assessed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Every successful result-page request; refreshes are separate view events.
CREATE TABLE eligibility_result_views (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  assessment_id INT NOT NULL,
  viewer_user_id INT DEFAULT NULL,
  visitor_token CHAR(64) NOT NULL,
  viewed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_elig_view_assessment (assessment_id, viewed_at),
  KEY idx_elig_view_visitor (visitor_token, viewed_at),
  KEY idx_elig_view_user (viewer_user_id, viewed_at),
  CONSTRAINT fk_elig_view_assessment FOREIGN KEY (assessment_id) REFERENCES eligibility_assessments(id) ON DELETE CASCADE,
  CONSTRAINT fk_elig_view_user FOREIGN KEY (viewer_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- changed_by is a name snapshot (stays accurate if the account is renamed
-- or removed); changed_by_user_id links the account while it exists.
CREATE TABLE application_status_history (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  application_id      INT NOT NULL,
  status              VARCHAR(30) NOT NULL,
  remarks             VARCHAR(500) DEFAULT NULL,
  changed_by          VARCHAR(150) DEFAULT NULL,
  changed_by_user_id  INT DEFAULT NULL,
  changed_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_hist_app FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
  CONSTRAINT fk_hist_user FOREIGN KEY (changed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Internal MSWD case notes on an application: never shown to the applicant.
-- Author name/role are snapshots (like audit_logs).
CREATE TABLE application_notes (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  application_id INT NOT NULL,
  note           VARCHAR(1000) NOT NULL,
  author_id      INT DEFAULT NULL,
  author_name    VARCHAR(150) NOT NULL,
  author_role    VARCHAR(50) NOT NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_note_app (application_id, created_at),
  CONSTRAINT fk_note_app FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
  CONSTRAINT fk_note_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Every MSWD approve / reject / correction decision with its reason.
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

-- QR application slips. Each slip carries a random 256-bit token (64 hex
-- chars) -- the only thing its QR code encodes, so no personal data is in
-- the QR. An application has one active slip (revoked_at IS NULL); a lost
-- slip is replaced by revoking it and issuing a new token. The token expires
-- with the ID once released and is revoked if the application is rejected.
CREATE TABLE qr_application_slips (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  application_id  INT NOT NULL,
  token           CHAR(64) NOT NULL UNIQUE,
  issued_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  issued_by       INT DEFAULT NULL,
  expires_at      DATETIME DEFAULT NULL,
  revoked_at      DATETIME DEFAULT NULL,
  revoke_reason   VARCHAR(100) DEFAULT NULL,
  KEY idx_qr_app (application_id, revoked_at),
  CONSTRAINT fk_qr_app FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
  CONSTRAINT fk_qr_issued_by FOREIGN KEY (issued_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Appointments: slots MSWD opens for self-booking, and the bookings.
-- A booking can also be made by staff with no slot (slot_id NULL).
-- AVAILABLE is a property of a slot with remaining capacity.
-- ---------------------------------------------------------------
CREATE TABLE appointment_slots (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  slot_date   DATE NOT NULL,
  start_time  TIME NOT NULL,
  end_time    TIME NOT NULL,
  capacity    INT NOT NULL DEFAULT 10,
  -- AVAILABLE (bookable while upcoming, not full, outside the minimum notice) or CANCELLED by staff.
  status      ENUM('AVAILABLE','CANCELLED') NOT NULL DEFAULT 'AVAILABLE',
  cancel_reason VARCHAR(255) DEFAULT NULL,
  created_by  INT NOT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_slot (slot_date, start_time),
  CONSTRAINT fk_slot_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- Constraint names keep their original "interview" prefix so a migrated
-- database and a fresh install are identical.
CREATE TABLE appointments (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  application_id      INT NOT NULL,
  slot_id             INT NULL,
  scheduled_at        DATETIME NOT NULL,
  location            VARCHAR(255) NOT NULL,
  -- BOOKED -> COMPLETED (result recorded) | MISSED (no-show) | CANCELLED (applicant or staff). BOOKED/COMPLETED hold a seat.
  status              ENUM('BOOKED','COMPLETED','CANCELLED','MISSED') NOT NULL DEFAULT 'BOOKED',
  notes               VARCHAR(500) DEFAULT NULL,
  result              VARCHAR(500) DEFAULT NULL,
  result_recorded_at  DATETIME DEFAULT NULL,
  cancelled_at        DATETIME DEFAULT NULL,
  cancelled_by        INT DEFAULT NULL,
  cancel_reason       VARCHAR(255) DEFAULT NULL,
  scheduled_by        INT NULL,
  reminder_sent_at    DATETIME DEFAULT NULL,
  interview_sms_log_id INT NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_interview_app (application_id),
  KEY idx_appt_scheduled (scheduled_at),
  CONSTRAINT fk_interview_app FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
  CONSTRAINT fk_interview_slot FOREIGN KEY (slot_id) REFERENCES appointment_slots(id) ON DELETE SET NULL,
  CONSTRAINT fk_interview_staff FOREIGN KEY (scheduled_by) REFERENCES users(id),
  CONSTRAINT fk_appt_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES users(id) ON DELETE SET NULL,
  KEY idx_appt_slot_status (slot_id, status)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Notifications, SMS, chat
-- ---------------------------------------------------------------
CREATE TABLE notifications (
  id                      INT AUTO_INCREMENT PRIMARY KEY,
  user_id                 INT NOT NULL,
  type                    VARCHAR(30) NOT NULL,
  message                 VARCHAR(500) NOT NULL,
  related_application_id  INT NULL,
  link                    VARCHAR(255) DEFAULT NULL,  -- in-app path it opens (never an external URL)
  is_read                 TINYINT(1) NOT NULL DEFAULT 0,
  read_at                 DATETIME DEFAULT NULL,
  created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_notif_user_read (user_id, is_read),
  KEY idx_notif_user_created (user_id, created_at),
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_notif_app FOREIGN KEY (related_application_id) REFERENCES applications(id) ON DELETE SET NULL
) ENGINE=InnoDB;

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

-- One row per released ID per expiry date once its renewal reminder is
-- claimed (cron/renewal-reminders.php), so no expiry is ever reminded twice.
-- A renewal is a new application row, so it gets its own reminder later.
CREATE TABLE renewal_reminders (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  application_id  INT NOT NULL,
  id_expires_at   DATE NOT NULL,             -- the expiry this reminder is for (one reminder per ID per expiry)
  user_id         INT DEFAULT NULL,
  days_before     INT DEFAULT NULL,          -- days left when sent (negative = sent after expiry, as a catch-up)
  status          ENUM('SENDING','SENT','FAILED') NOT NULL DEFAULT 'SENT',  -- claimed as SENDING before anything is sent
  attempts        INT NOT NULL DEFAULT 1,
  sms_status      VARCHAR(30) DEFAULT NULL,  -- provider result, as in sms_logs
  notification_id INT DEFAULT NULL,          -- the in-app notification created
  trigger_source  ENUM('cron','manual') NOT NULL DEFAULT 'cron',
  error           VARCHAR(255) DEFAULT NULL,
  sent_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_reminder_app_expiry (application_id, id_expires_at),
  CONSTRAINT fk_reminder_app FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
  CONSTRAINT fk_reminder_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_reminder_notification FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE SET NULL
) ENGINE=InnoDB;

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

-- Every SMS attempt. application_id is NULL for account-level messages (e.g. password reset).
-- Every SMS attempt (services/SMSService.php): logged PENDING before the gateway is called, then
-- SENT or FAILED with the provider's answer. test_mode = logged only, never sent (SMS_PROVIDER=test).
-- sent_at is when the attempt was first made.
CREATE TABLE sms_logs (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  application_id      INT NULL,
  mobile              VARCHAR(20) NOT NULL,
  message             VARCHAR(500) NOT NULL,
  status              ENUM('PENDING','SENT','FAILED') NOT NULL DEFAULT 'PENDING',
  test_mode           TINYINT(1) NOT NULL DEFAULT 0,
  template_key        VARCHAR(50) DEFAULT NULL,
  provider            VARCHAR(30) NOT NULL DEFAULT 'none',
  provider_status     VARCHAR(30) NOT NULL DEFAULT 'queued',  -- the provider's own status text
  provider_message_id VARCHAR(100) DEFAULT NULL,
  error               VARCHAR(255) DEFAULT NULL,
  attempts            INT NOT NULL DEFAULT 1,
  sent_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_sms_status (provider_status),
  KEY idx_sms_state (status, test_mode),
  KEY idx_sms_sent (sent_at),
  CONSTRAINT fk_sms_app FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE
) ENGINE=InnoDB;

ALTER TABLE appointments
  ADD KEY idx_appt_sms_log (interview_sms_log_id),
  ADD CONSTRAINT fk_appt_sms_log FOREIGN KEY (interview_sms_log_id) REFERENCES sms_logs(id) ON DELETE SET NULL;

-- ID release appointments are separate from actual ID handover. A single
-- current schedule is retained per application; released_at/by record the
-- one-time confirmation that the physical ID was handed to the applicant.
CREATE TABLE id_release_schedules (
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

-- Admin-editable SMS text. Placeholders: {name} {ref} {status} {remarks} {when} {location} {reason} {document} {code} {minutes}
CREATE TABLE sms_templates (
  template_key VARCHAR(50) PRIMARY KEY,
  label        VARCHAR(150) NOT NULL,
  body         VARCHAR(500) NOT NULL,
  is_enabled   TINYINT(1) NOT NULL DEFAULT 1,  -- 0 = this SMS is not sent (Notification Settings; password_reset and mobile_verification are always sent)
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Chat Support: one conversation per applicant (user_id) with the Help Assistant and, when the
-- applicant asks for it (handoff = 1), MSWDO staff. The assistant never decides eligibility/approval.
CREATE TABLE chat_messages (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  user_id        INT NOT NULL,
  sender         ENUM('APPLICANT','ASSISTANT','STAFF') NOT NULL,
  message        TEXT NOT NULL,
  topic          VARCHAR(30) DEFAULT NULL,
  meta           JSON DEFAULT NULL,
  handoff        TINYINT(1) NOT NULL DEFAULT 0,
  staff_user_id  INT DEFAULT NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_chat_user (user_id, created_at),
  KEY idx_chat_handoff (handoff, created_at),
  CONSTRAINT fk_chat_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_chat_staff FOREIGN KEY (staff_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Admin-managed self-service answers shown on Chat Support.
CREATE TABLE faqs (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  question    VARCHAR(255) NOT NULL,
  answer      TEXT NOT NULL,
  sort_order  INT NOT NULL DEFAULT 0,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Audit trail and settings
-- ---------------------------------------------------------------
-- actor_name/actor_role are snapshotted so the log stays accurate even if
-- the account's name or role changes later. Never edited or deleted by the app.
CREATE TABLE audit_logs (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  user_id      INT NULL,
  actor_name   VARCHAR(150) NOT NULL,
  actor_role   VARCHAR(30) NOT NULL,
  action       VARCHAR(100) NOT NULL,
  entity_type  VARCHAR(50) DEFAULT NULL,
  entity_id    INT DEFAULT NULL,
  details      VARCHAR(500) DEFAULT NULL,
  ip_address   VARCHAR(45) DEFAULT NULL,
  user_agent   VARCHAR(255) DEFAULT NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_created (created_at),
  KEY idx_audit_action (action),
  KEY idx_audit_user (user_id, created_at),
  KEY idx_audit_entity (entity_type, entity_id),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Append-only: the database refuses UPDATE/DELETE on audit_logs. A user's
-- deletion still nulls user_id (foreign-key actions never fire triggers).
-- Deliberate DBA maintenance opts in per session: SET @audit_log_maintenance = 1;
DELIMITER $$
CREATE TRIGGER audit_logs_no_update BEFORE UPDATE ON audit_logs
FOR EACH ROW
BEGIN
  IF COALESCE(@audit_log_maintenance, 0) <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit log records cannot be changed.';
  END IF;
END$$
CREATE TRIGGER audit_logs_no_delete BEFORE DELETE ON audit_logs
FOR EACH ROW
BEGIN
  IF COALESCE(@audit_log_maintenance, 0) <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit log records cannot be deleted.';
  END IF;
END$$
DELIMITER ;

-- Admin-configurable runtime settings (code falls back to built-in defaults for missing keys).
CREATE TABLE system_settings (
  setting_key   VARCHAR(50) PRIMARY KEY,
  setting_value VARCHAR(255) NOT NULL,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Rate limits for the public endpoints (services/RateLimiter.php): sign-in
-- per IP, registration, forgot-password SMS, public status lookup. Rows are
-- pruned after a day. Keys are hashed (no raw IPs or usernames stored here).
CREATE TABLE rate_limit_hits (
  id      BIGINT AUTO_INCREMENT PRIMARY KEY,
  bucket  VARCHAR(64) NOT NULL,
  hit_at  DATETIME NOT NULL,
  KEY idx_rate_bucket (bucket, hit_at),
  KEY idx_rate_hit_at (hit_at)
) ENGINE=InnoDB;

-- Admin-managed public news.
CREATE TABLE IF NOT EXISTS public_announcements (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(200) NOT NULL,
  short_desc VARCHAR(500) NOT NULL,
  category VARCHAR(80) NOT NULL,
  details TEXT NOT NULL,
  status ENUM('draft','published') NOT NULL DEFAULT 'draft',
  published_at DATETIME NOT NULL,
  expires_at DATETIME NULL,
  priority INT NOT NULL DEFAULT 0,
  image VARCHAR(255) NULL,
  attachment VARCHAR(255) NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_public_announcements_visibility (status, published_at, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
