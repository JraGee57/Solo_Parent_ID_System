-- Non-destructive migration for the Admin/MSWD/Applicant dashboard build-out
-- (eligibility assessment, SMS templates, system settings). Safe to run once
-- against an existing virac_solo_parent database with real data -- everything
-- here is additive (ADD COLUMN / CREATE TABLE), nothing is dropped. Mirrors
-- the baseline already reflected in schema.sql for fresh installs.
--
--   "C:\xampp\mysql\bin\mysql.exe" -u root virac_solo_parent < database\migrate_admin_staff_dashboards.sql

USE virac_solo_parent;

ALTER TABLE applications
  ADD COLUMN eligibility_status ENUM('Not Assessed','Eligible','Not Eligible') NOT NULL DEFAULT 'Not Assessed' AFTER status,
  ADD COLUMN eligibility_remarks VARCHAR(500) DEFAULT NULL AFTER eligibility_status;

CREATE TABLE sms_templates (
  template_key VARCHAR(50) PRIMARY KEY,
  label        VARCHAR(150) NOT NULL,
  body         VARCHAR(500) NOT NULL,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO sms_templates (template_key, label, body) VALUES
('submitted', 'Application Submitted', 'MSWDO Virac: Your Solo Parent ID application {ref} has been received and is Pending Review. We will text you when its status changes.'),
('status_change', 'Status Change (approval/rejection/correction/etc.)', 'MSWDO Virac: Application {ref} is now {status}. Note: {remarks}'),
('interview_scheduled', 'Interview/Appointment Scheduled', 'MSWDO Virac: An interview for application {ref} has been scheduled on {when} at {location}. Please arrive on time and bring a valid ID.'),
('renewal_submitted', 'Renewal Submitted', 'MSWDO Virac: Your Solo Parent ID renewal request {ref} has been received and is Pending Review. We will text you when its status changes.');

CREATE TABLE system_settings (
  setting_key   VARCHAR(50) PRIMARY KEY,
  setting_value VARCHAR(255) NOT NULL,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
