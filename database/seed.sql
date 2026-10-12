-- e-SoloParentID reference data + initial administrator, for a fresh
-- install. Run right after schema.sql:
--
--   "C:\xampp\mysql\bin\mysql.exe" -u root virac_solo_parent < database\seed.sql
--
-- Initial login:  username "admin" / password "Admin@Virac2026".
-- CHANGE IT IMMEDIATELY after the first login (Manage Users -> Reset
-- Access issues a new random password, or use Forgot Password).

USE virac_solo_parent;

-- ---------------------------------------------------------------
-- The three system roles
-- ---------------------------------------------------------------
INSERT INTO roles (id, role_key, code, name, description) VALUES
(1, 'admin',              'ADMINISTRATOR', 'System Administrator',       'Manages users, settings, analytics, reports, SMS and audit logs.'),
(2, 'mswd_social_worker', 'MSWD_STAFF',    'MSWD Staff / Social Worker', 'Reviews and decides applications, verifies documents, manages appointments.'),
(3, 'applicant',          'SOLO_PARENT',   'Solo Parent Applicant',      'Applies for and renews their own Solo Parent ID.');

-- ---------------------------------------------------------------
-- Initial administrator (password: Admin@Virac2026 -- change it)
-- ---------------------------------------------------------------
INSERT INTO users (full_name, email, username, password_hash, role) VALUES
('MSWDO Virac Administrator', 'admin@mswdo-virac.local', 'admin', '$2y$10$31MBL39gLWJWynXE5ypA9uuebMudqMX1tX6U8zoSNoSWMKFlwjlka', 'admin');

-- ---------------------------------------------------------------
-- Solo parent categories (RA 8972 / RA 11861, Section 3)
-- ---------------------------------------------------------------
INSERT INTO categories (name, description, sort_order) VALUES
('Abandonment by the spouse', 'The spouse has abandoned the applicant and child/children.', 1),
('Declaration of nullity or annulment of marriage', 'A judicial declaration of nullity or annulment, including recognition of a foreign divorce.', 2),
('Legal guardian, adoptive parent, or foster parent', 'The applicant is the child’s legal guardian, adoptive parent, or foster parent.', 3),
('Unmarried father or mother who keeps and rears the child/children', 'An unmarried father or mother keeps and rears the child/children.', 4),
('Detention or imprisonment of the spouse', 'The spouse is detained or serving a sentence for at least three months.', 5),
('Child/children as a consequence of rape', 'The child/children were conceived as a consequence of rape.', 6),
('Death of the spouse', 'The applicant’s spouse has died.', 7),
('Legal or de facto separation of spouse for at least six months', 'The spouses have been legally or de facto separated for at least six months.', 8),
('Spouse or family member of an OFW', 'The applicant is the spouse or family member of an overseas Filipino worker.', 9);

-- ---------------------------------------------------------------
-- Standard document requirements (category_id NULL = applies to all)
-- ---------------------------------------------------------------
INSERT INTO document_requirements (category_id, doc_key, label, is_required, sort_order) VALUES
(NULL, 'brgyCert',  'Barangay Certificate of Residency and Solo Parent Status', 1, 1),
(NULL, 'birthCert', 'PSA Birth Certificate(s) of Children', 1, 2),
(NULL, 'validId',   'Valid Government ID', 1, 3),
(NULL, 'photo',     '2x2 ID Photo', 1, 4);

-- ---------------------------------------------------------------
-- Category-specific document requirements for new applications
-- ---------------------------------------------------------------
INSERT INTO document_requirements (category_id, doc_key, label, is_required, sort_order)
SELECT c.id, r.doc_key, r.label, 1, r.sort_order
FROM categories c
JOIN (
  SELECT 'Abandonment by the spouse' category_name, 'photo1x1' doc_key, 'Two 1x1 ID pictures' label, 1 sort_order UNION ALL
  SELECT 'Abandonment by the spouse', 'birthCert', 'Photocopy of the child''s/children''s birth certificate(s)', 2 UNION ALL
  SELECT 'Abandonment by the spouse', 'marriageOrAffidavit', 'Marriage certificate or affidavit of the applicant', 3 UNION ALL
  SELECT 'Abandonment by the spouse', 'witnessAffidavit', 'Affidavit of two disinterested persons attesting to abandonment', 4 UNION ALL
  SELECT 'Abandonment by the spouse', 'abandonmentRecord', 'Police or barangay record of abandonment', 5 UNION ALL
  SELECT 'Abandonment by the spouse', 'barangayCareAffidavit', 'Barangay official''s affidavit confirming residency and parental care/support', 6 UNION ALL
  SELECT 'Abandonment by the spouse', 'soleCareAffidavit', 'Sworn affidavit declaring sole parental care/support and non-cohabitation with a partner or co-parent', 7 UNION ALL
  SELECT 'Declaration of nullity or annulment of marriage', 'birthCert', 'Child''s/children''s birth certificate(s), photocopy', 1 UNION ALL
  SELECT 'Declaration of nullity or annulment of marriage', 'marriageCert', 'Marriage certificate, certified true copy', 2 UNION ALL
  SELECT 'Declaration of nullity or annulment of marriage', 'judicialDecree', 'Judicial decree of nullity/annulment or judicial recognition of foreign divorce', 3 UNION ALL
  SELECT 'Declaration of nullity or annulment of marriage', 'soleCareAffidavit', 'Sworn affidavit declaring sole parental care/support and non-cohabitation', 4 UNION ALL
  SELECT 'Legal guardian, adoptive parent, or foster parent', 'photo1x1', 'Two 1x1 ID pictures', 1 UNION ALL
  SELECT 'Legal guardian, adoptive parent, or foster parent', 'birthCert', 'Child''s/children''s birth certificate(s)', 2 UNION ALL
  SELECT 'Legal guardian, adoptive parent, or foster parent', 'guardianshipProof', 'Applicable proof of guardianship, adoption, or foster care', 3 UNION ALL
  SELECT 'Legal guardian, adoptive parent, or foster parent', 'soleCareAffidavit', 'Sworn affidavit declaring sole parental care/support and non-cohabitation', 4 UNION ALL
  SELECT 'Legal guardian, adoptive parent, or foster parent', 'barangayCareAffidavit', 'Barangay official''s affidavit confirming residency and parental care/support', 5 UNION ALL
  SELECT 'Unmarried father or mother who keeps and rears the child/children', 'photo1x1', 'Two 1x1 ID pictures', 1 UNION ALL
  SELECT 'Unmarried father or mother who keeps and rears the child/children', 'birthCert', 'Child''s/children''s birth certificate(s), photocopy', 2 UNION ALL
  SELECT 'Unmarried father or mother who keeps and rears the child/children', 'cenomar', 'CENOMAR, certified true copy', 3 UNION ALL
  SELECT 'Unmarried father or mother who keeps and rears the child/children', 'barangayCareAffidavit', 'Barangay official''s affidavit confirming residency and parental care/support', 4 UNION ALL
  SELECT 'Unmarried father or mother who keeps and rears the child/children', 'soleCareAffidavit', 'Sworn affidavit declaring sole parental care/support and non-cohabitation', 5 UNION ALL
  SELECT 'Detention or imprisonment of the spouse', 'photo1x1', 'Two 1x1 ID pictures', 1 UNION ALL
  SELECT 'Detention or imprisonment of the spouse', 'birthCert', 'Child''s/children''s birth certificate(s)', 2 UNION ALL
  SELECT 'Detention or imprisonment of the spouse', 'marriageOrCenomar', 'Marriage certificate or Certificate of No Marriage, as applicable', 3 UNION ALL
  SELECT 'Detention or imprisonment of the spouse', 'detentionCertificate', 'Certificate of detention or certification that the spouse is serving a sentence for at least three months', 4 UNION ALL
  SELECT 'Detention or imprisonment of the spouse', 'soleCareAffidavit', 'Sworn affidavit declaring sole parental care/support and non-cohabitation', 5 UNION ALL
  SELECT 'Detention or imprisonment of the spouse', 'barangayCareAffidavit', 'Barangay official''s affidavit confirming residency and parental care/support', 6 UNION ALL
  SELECT 'Child/children as a consequence of rape', 'birthCert', 'Child''s/children''s birth certificate(s), photocopy', 1 UNION ALL
  SELECT 'Child/children as a consequence of rape', 'complaintAffidavit', 'Complaint affidavit', 2 UNION ALL
  SELECT 'Child/children as a consequence of rape', 'incidentMedicalRecord', 'Medical record relating to the incident', 3 UNION ALL
  SELECT 'Child/children as a consequence of rape', 'soleCareAffidavit', 'Sworn affidavit declaring sole parental care/support at the time of execution', 4 UNION ALL
  SELECT 'Death of the spouse', 'photo1x1', 'Two 1x1 ID pictures', 1 UNION ALL
  SELECT 'Death of the spouse', 'birthCert', 'Child''s/children''s birth certificate(s)', 2 UNION ALL
  SELECT 'Death of the spouse', 'marriageCert', 'Marriage certificate, certified true copy', 3 UNION ALL
  SELECT 'Death of the spouse', 'deathCert', 'Spouse''s death certificate, certified true copy', 4 UNION ALL
  SELECT 'Death of the spouse', 'soleCareAffidavit', 'Sworn affidavit declaring sole parental care/support and non-cohabitation', 5 UNION ALL
  SELECT 'Death of the spouse', 'barangayCareAffidavit', 'Barangay official''s affidavit confirming residency and parental care/support', 6 UNION ALL
  SELECT 'Legal or de facto separation of spouse for at least six months', 'birthCert', 'Child''s/children''s birth certificate(s)', 1 UNION ALL
  SELECT 'Legal or de facto separation of spouse for at least six months', 'marriageCert', 'Marriage certificate, certified true copy', 2 UNION ALL
  SELECT 'Legal or de facto separation of spouse for at least six months', 'separationProof', 'Judicial decree of legal separation or affidavit of two disinterested persons attesting to de facto separation', 3 UNION ALL
  SELECT 'Legal or de facto separation of spouse for at least six months', 'soleCareAffidavit', 'Sworn affidavit declaring sole parental care/support and non-cohabitation', 4 UNION ALL
  SELECT 'Spouse or family member of an OFW', 'photo1x1', 'Two 1x1 ID pictures', 1 UNION ALL
  SELECT 'Spouse or family member of an OFW', 'birthCert', 'Dependents'' birth certificate(s), photocopy', 2 UNION ALL
  SELECT 'Spouse or family member of an OFW', 'ofwRelationshipProof', 'Marriage certificate for an OFW''s spouse, or birth certificate/competent proof of relationship for an OFW''s family member', 3 UNION ALL
  SELECT 'Spouse or family member of an OFW', 'ofwEmploymentContract', 'POEA Standard Employment Contract or equivalent', 4 UNION ALL
  SELECT 'Spouse or family member of an OFW', 'ofwPassportProof', 'Photocopy of the OFW''s passport showing continuous 12 months of overseas work, or Bureau of Immigration certification', 5 UNION ALL
  SELECT 'Spouse or family member of an OFW', 'ofwIncomeProof', 'Proof of income of the OFW''s spouse or family member', 6 UNION ALL
  SELECT 'Spouse or family member of an OFW', 'barangayCareAffidavit', 'Barangay official''s affidavit confirming residency and parental care/support', 7 UNION ALL
  SELECT 'Spouse or family member of an OFW', 'soleCareAffidavit', 'Sworn affidavit declaring sole parental care/support and non-cohabitation', 8
) r ON r.category_name = c.name;

-- ---------------------------------------------------------------
-- Chat Support FAQs
-- ---------------------------------------------------------------
INSERT INTO faqs (question, answer, sort_order) VALUES
('Who can apply for a Solo Parent ID?', 'Any solo parent residing in Virac, Catanduanes who solely provides parental care to a child (below 22, or of any age if the child has a disability) under one of the circumstances in RA 8972 / RA 11861 -- e.g. widowed, abandoned, separated, or an unwed parent. Not sure yet? Before you sign up, the Eligibility Checker on the public Home page gives a quick preliminary result. Once you are logged in you can apply directly -- there is no need to check again.', 1),
('What documents do I need?', 'The Required Documents Checklist shows the documentary requirements for your selected category. Renewal applicants see the renewal checklist for their existing record.', 2),
('How long does processing take?', 'Once submitted, your application is Pending Review, then moves to Under Verification. If needed, MSWDO may schedule a short interview/assessment. Most applications are resolved within a few weeks, but this can vary depending on document completeness and MSWDO office workload.', 3),
('What if my application needs correction?', 'If information or a document requirement needs correction, your application status will show "For Revision" with remarks from MSWDO staff. Open My Applications or Document Checklist to review the remarks, update your information or checklist as requested, and prepare the documents for MSWDO review.', 4),
('How do I renew my Solo Parent ID?', 'Your Solo Parent ID is valid for one year. Once it is close to expiring (or already expired), go to ID Renewal in the menu -- your previous information is pre-filled, and you only need to reconfirm your standard documents and submit.', 5),
('How will I be notified of updates?', 'MSWDO sends an SMS to your registered mobile number whenever your application status changes, an interview is scheduled, or your ID is approved/released. You can also see the full history under Notifications.', 6),
('Where is the MSWDO office?', 'MSWDO Office, Municipal Building, Salvacion, Virac, Catanduanes. Office hours are Monday to Friday, 8:00 AM to 5:00 PM.', 7),
('How do I book, reschedule or cancel an appointment?', 'Open Appointment in your dashboard and pick an open slot. You can reschedule or cancel online until a few hours before your appointment (the exact cut-off is shown on the page); after that, please contact the MSWDO office.', 8),
('I lost my application slip. What do I do?', 'Open your slip from My Application and choose "Lost it? Get a new QR". The old slip stops working immediately and you can print or save the new one.', 9),
('I forgot my password.', 'On the login page choose "Forgot password" and enter your username or mobile number. A reset code will be sent to your registered mobile number.', 10),
('Can the online assistant tell me if I am eligible or approved?', 'No. The assistant can explain the process and show where your application is, but only MSWD staff decide eligibility and approval after reviewing your documents. The Eligibility Checker on the public Home page (used before logging in) gives only a preliminary, non-binding result.', 11);

-- ---------------------------------------------------------------
-- SMS templates (SmsService falls back to built-in text if one is missing)
-- ---------------------------------------------------------------
INSERT INTO sms_templates (template_key, label, body) VALUES
('submitted', 'Application Submitted', 'MSWDO Virac: Your Solo Parent ID application {ref} has been received and is Pending Review. We will text you when its status changes.'),
('status_change', 'Status Change (approval/rejection/correction/etc.)', 'MSWDO Virac: Application {ref} is now {status}. Note: {remarks}'),
('interview_scheduled', 'Interview/Appointment Scheduled', 'Hello {name}, your Solo Parent ID application interview is scheduled on {when}. Location: {location}. Please arrive on time. For inquiries, contact the MSWDO Virac office.'),
('renewal_submitted', 'Renewal Submitted', 'MSWDO Virac: Your Solo Parent ID renewal request {ref} has been received and is Pending Review. We will text you when its status changes.'),
('renewal_reminder', 'Renewal Reminder (auto)', 'MSWDO Virac: Your Solo Parent ID ({ref}) expires on {when}. Please visit MSWDO or apply for renewal online soon to avoid interruption.'),
('renewal_expired', 'Renewal Reminder after Expiry (auto)', 'MSWDO Virac: Your Solo Parent ID ({ref}) expired on {when}. Please renew online or visit MSWDO to keep your benefits.'),
('appointment_reminder', 'Appointment Reminder (auto)', 'MSWDO Virac: Reminder -- your appointment for application {ref} is on {when} at {location}. Please arrive on time and bring a valid ID.'),
('appointment_cancelled', 'Appointment Cancelled', 'MSWDO Virac: Your appointment for application {ref} on {when} was cancelled. Reason: {reason}. Please book a new appointment online.'),
('appointment_missed', 'Appointment Missed', 'MSWDO Virac: You missed your appointment for application {ref} on {when}. Please log in and book a new appointment.'),
('document_correction', 'Document Checklist Needs Correction', 'MSWDO Virac: The requirement "{document}" for application {ref} needs correction. Reason: {remarks}. Please log in and update your Required Documents Checklist.'),
('password_reset', 'Password Reset Code', 'MSWDO Virac: Your password reset code is {code}. It expires in {minutes} minutes. If you didn''t request this, ignore this message.'),
('mobile_verification', 'Mobile Number Verification Code', 'MSWDO Virac: Your e-SoloParentID verification code is {code}. It expires in {minutes} minutes. Do not share this code with anyone.');
