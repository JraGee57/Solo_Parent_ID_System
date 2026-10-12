-- Applicant document declarations are stored separately from staff review results.
-- Repeatable: existing declarations and staff review results are preserved.
CREATE TABLE IF NOT EXISTS application_document_checklists (
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

UPDATE sms_templates SET label = 'Document Checklist Needs Correction',
  body = 'MSWDO Virac: The requirement "{document}" for application {ref} needs correction. Reason: {remarks}. Please log in and update your Required Documents Checklist.'
WHERE template_key = 'document_correction';

-- Backfill the configured requirements for existing records. A legacy upload
-- records applicant availability; its old verification result is not copied.
INSERT INTO application_document_checklists (application_id, doc_key, applicant_has, verification_status)
SELECT DISTINCT a.id, r.doc_key, EXISTS(SELECT 1 FROM application_documents d WHERE d.application_id = a.id AND d.doc_key = r.doc_key), 'Pending'
FROM applications a
JOIN categories c ON c.id = a.category_id
JOIN document_requirements r ON
  (a.type = 'new' AND c.is_active = 1 AND r.category_id = a.category_id)
  OR ((a.type = 'renewal' OR c.is_active = 0)
      AND (r.category_id IS NULL OR (a.type = 'new' AND r.category_id = a.category_id)))
WHERE NOT EXISTS (
  SELECT 1 FROM application_document_checklists existing
  WHERE existing.application_id = a.id AND existing.doc_key = r.doc_key
);
