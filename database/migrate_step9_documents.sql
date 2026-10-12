-- Step 9: document status lifecycle + file checksum. Safe to run once on an existing database.
-- Old values map: Pending -> FOR_VERIFICATION, Verified -> VERIFIED, Needs Correction -> NEEDS_CORRECTION.
-- (Goes through VARCHAR: an ENUM can't hold 'Verified' and 'VERIFIED' side by side.)
USE virac_solo_parent;

ALTER TABLE application_documents MODIFY verification_status VARCHAR(30) NOT NULL DEFAULT 'FOR_VERIFICATION';

UPDATE application_documents SET verification_status = CASE verification_status
    WHEN 'Pending' THEN 'FOR_VERIFICATION'
    WHEN 'Verified' THEN 'VERIFIED'
    WHEN 'Needs Correction' THEN 'NEEDS_CORRECTION'
    ELSE verification_status END;

ALTER TABLE application_documents
  MODIFY verification_status ENUM('UPLOADED','VALIDATING','VALID','INVALID','FOR_VERIFICATION','VERIFIED','NEEDS_CORRECTION','REJECTED') NOT NULL DEFAULT 'FOR_VERIFICATION',
  ADD COLUMN sha256 CHAR(64) DEFAULT NULL AFTER size_bytes;
