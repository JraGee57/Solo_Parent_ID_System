-- Step 10: document verification trail. Safe to run once on an existing database.
USE virac_solo_parent;

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

-- Start the trail for documents that already exist: their upload, then their current verdict (if any).
INSERT INTO document_status_history (document_id, application_id, doc_key, from_status, to_status, actor_user_id, actor_name, actor_role, created_at)
SELECT d.id, d.application_id, d.doc_key, NULL, 'FOR_VERIFICATION', a.user_id, a.full_name, 'applicant', d.uploaded_at
FROM application_documents d JOIN applications a ON a.id = d.application_id;

INSERT INTO document_status_history (document_id, application_id, doc_key, from_status, to_status, remarks, actor_user_id, actor_name, actor_role, created_at)
SELECT d.id, d.application_id, d.doc_key, 'FOR_VERIFICATION', d.verification_status, d.staff_remarks, d.verified_by,
       COALESCE(u.full_name, 'Unknown staff'), COALESCE(u.role, 'mswd_social_worker'), COALESCE(d.verified_at, d.uploaded_at)
FROM application_documents d LEFT JOIN users u ON u.id = d.verified_by
WHERE d.verification_status <> 'FOR_VERIFICATION';
