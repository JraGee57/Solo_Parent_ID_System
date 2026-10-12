-- Step 12: MSWD processing -- assessment history and internal case notes. Safe to run once on an existing database.
USE virac_solo_parent;

-- MSWD assessment notes can be as long as other staff remarks.
ALTER TABLE eligibility_assessments MODIFY result_reason VARCHAR(500) DEFAULT NULL;

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

-- Every MSWD eligibility assessment is now kept as its own eligibility_assessments row
-- (applications.eligibility_status stays as the current snapshot). Start the history
-- with each application's existing assessment.
INSERT INTO eligibility_assessments (user_id, application_id, assessment_type, answers, preliminary_result, result_reason, assessed_by, created_at)
SELECT a.user_id, a.id, 'MSWD', '{}', IF(a.eligibility_status = 'Eligible', 'ELIGIBLE', 'NOT_ELIGIBLE'), a.eligibility_remarks, a.reviewed_by, a.updated_at
FROM applications a
WHERE a.eligibility_status <> 'Not Assessed'
  AND NOT EXISTS (SELECT 1 FROM eligibility_assessments e WHERE e.application_id = a.id AND e.assessment_type = 'MSWD');
