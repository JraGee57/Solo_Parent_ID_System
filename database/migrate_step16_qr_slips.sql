-- Step 16: QR application slips -- keep a history of tokens per application (a lost slip is
-- replaced by revoking the old token and issuing a new one). Safe to run once.
USE virac_solo_parent;

ALTER TABLE qr_application_slips
  ADD COLUMN issued_by INT DEFAULT NULL AFTER issued_at,
  ADD COLUMN revoke_reason VARCHAR(100) DEFAULT NULL AFTER revoked_at,
  ADD KEY idx_qr_app (application_id, revoked_at),
  ADD CONSTRAINT fk_qr_issued_by FOREIGN KEY (issued_by) REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE qr_application_slips DROP INDEX application_id;
