-- Step 14: renewals linked to the record they renew; renewal drafts saved to the account. Safe to run once.
USE virac_solo_parent;

-- A renewal points at the released ID it renews, so the chain of records stays connected.
ALTER TABLE applications
  ADD COLUMN renewal_of_id INT DEFAULT NULL AFTER type,
  ADD CONSTRAINT fk_app_renewal_of FOREIGN KEY (renewal_of_id) REFERENCES applications(id) ON DELETE SET NULL;

-- Link existing renewals to the applicant's latest released ID submitted before them.
UPDATE applications r
SET r.renewal_of_id = (
  SELECT p.id FROM applications p
  WHERE p.user_id = r.user_id AND p.id <> r.id AND p.submitted_at < r.submitted_at
    AND (p.status = 'ID Released' OR p.id_expires_at IS NOT NULL)
  ORDER BY p.submitted_at DESC LIMIT 1)
WHERE r.type = 'renewal' AND r.renewal_of_id IS NULL;

-- One saved draft per applicant per kind (a new application or a renewal).
ALTER TABLE application_drafts
  ADD COLUMN kind ENUM('application','renewal') NOT NULL DEFAULT 'application' AFTER user_id,
  ADD UNIQUE KEY uq_draft_user_kind (user_id, kind);
ALTER TABLE application_drafts DROP INDEX user_id;
