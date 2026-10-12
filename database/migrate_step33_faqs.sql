-- Step 33: the Eligibility Checker is a public, pre-login step; the applicant dashboard no longer offers it.
-- Rewords the two Chat Support FAQs that pointed logged-in applicants to it. An answer an administrator has
-- already edited is left alone. Safe to run more than once.
-- Run once against an existing database:
--   C:\xampp\mysql\bin\mysql.exe -u root virac_solo_parent < database/migrate_step33_faqs.sql

UPDATE faqs
SET answer = 'Any solo parent residing in Virac, Catanduanes who solely provides parental care to a child (below 22, or of any age if the child has a disability) under one of the circumstances in RA 8972 / RA 11861 -- e.g. widowed, abandoned, separated, or an unwed parent. Not sure yet? Before you sign up, the Eligibility Checker on the public Home page gives a quick preliminary result. Once you are logged in you can apply directly -- there is no need to check again.'
WHERE question = 'Who can apply for a Solo Parent ID?'
  AND answer = 'Any solo parent residing in Virac, Catanduanes who solely provides parental care to a child (below 22, or of any age if the child has a disability) under one of the circumstances in RA 8972 / RA 11861 -- e.g. widowed, abandoned, separated, or an unwed parent. Use the Eligibility Checker if you are unsure.';

UPDATE faqs
SET answer = 'No. The assistant can explain the process and show where your application is, but only MSWD staff decide eligibility and approval after reviewing your documents. The Eligibility Checker on the public Home page (used before logging in) gives only a preliminary, non-binding result.'
WHERE question = 'Can the online assistant tell me if I am eligible or approved?'
  AND answer = 'No. The assistant can explain the process and show where your application is, but only MSWD staff decide eligibility and approval after reviewing your documents. The Eligibility Checker gives a preliminary, non-binding result.';
