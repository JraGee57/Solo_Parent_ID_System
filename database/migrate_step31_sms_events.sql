-- Step 31: SMS for flagged documents and missed appointments.
-- Run once against an existing database:
--   C:\xampp\mysql\bin\mysql.exe -u root virac_solo_parent < database/migrate_step31_sms_events.sql

INSERT IGNORE INTO sms_templates (template_key, label, body) VALUES
('appointment_missed', 'Appointment Missed', 'MSWDO Virac: You missed your appointment for application {ref} on {when}. Please log in and book a new appointment.'),
('document_correction', 'Document Needs Correction', 'MSWDO Virac: Your document "{document}" for application {ref} needs to be re-uploaded. Reason: {remarks}. Please log in and upload a new file under Upload Documents.');
