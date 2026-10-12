-- Step 11: appointment slot status, cancellation details, booking notice setting. Safe to run once on an existing database.
USE virac_solo_parent;

-- A slot is AVAILABLE (bookable while in the future, not full, and outside
-- the minimum notice) or CANCELLED by staff (its bookings are cancelled too).
ALTER TABLE appointment_slots
  ADD COLUMN status ENUM('AVAILABLE','CANCELLED') NOT NULL DEFAULT 'AVAILABLE' AFTER capacity,
  ADD COLUMN cancel_reason VARCHAR(255) DEFAULT NULL AFTER status;

-- Appointment lifecycle: BOOKED -> COMPLETED (result recorded) | MISSED (no-show) | CANCELLED (by applicant or staff).
-- Only BOOKED and COMPLETED hold a seat in a slot.
ALTER TABLE appointments
  ADD COLUMN cancelled_at DATETIME DEFAULT NULL AFTER result_recorded_at,
  ADD COLUMN cancelled_by INT DEFAULT NULL AFTER cancelled_at,
  ADD COLUMN cancel_reason VARCHAR(255) DEFAULT NULL AFTER cancelled_by,
  ADD CONSTRAINT fk_appt_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES users(id) ON DELETE SET NULL,
  ADD KEY idx_appt_slot_status (slot_id, status);

-- Applicants can't book, reschedule or cancel within this many hours of an appointment (admin-configurable).
INSERT INTO system_settings (setting_key, setting_value) VALUES ('appointment_min_notice_hours', '2')
  ON DUPLICATE KEY UPDATE setting_value = setting_value;

INSERT INTO sms_templates (template_key, label, body) VALUES
('appointment_cancelled', 'Appointment Cancelled', 'MSWDO Virac: Your appointment for application {ref} on {when} was cancelled. Reason: {reason}. Please book a new appointment online.')
  ON DUPLICATE KEY UPDATE template_key = template_key;
