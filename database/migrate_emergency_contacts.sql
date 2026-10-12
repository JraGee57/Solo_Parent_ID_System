-- Preserve existing applications; emergency contact details are optional.
ALTER TABLE applications
  ADD COLUMN IF NOT EXISTS emergency_contact_name VARCHAR(150) DEFAULT NULL AFTER civil_status,
  ADD COLUMN IF NOT EXISTS emergency_contact_relation VARCHAR(80) DEFAULT NULL AFTER emergency_contact_name,
  ADD COLUMN IF NOT EXISTS emergency_contact_phone VARCHAR(30) DEFAULT NULL AFTER emergency_contact_relation,
  ADD COLUMN IF NOT EXISTS emergency_contact_address VARCHAR(255) DEFAULT NULL AFTER emergency_contact_phone;
