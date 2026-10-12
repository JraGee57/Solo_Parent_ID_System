-- Step 19: SMS delivery tracking -- every attempt is logged as PENDING before the gateway is
-- called, then marked SENT or FAILED with the provider's answer. Safe to run once.
USE virac_solo_parent;

ALTER TABLE sms_logs
  ADD COLUMN status ENUM('PENDING','SENT','FAILED') NOT NULL DEFAULT 'PENDING' AFTER message,
  ADD COLUMN test_mode TINYINT(1) NOT NULL DEFAULT 0 AFTER status,       -- 1 = test mode: logged, never sent to a gateway
  ADD COLUMN template_key VARCHAR(50) DEFAULT NULL AFTER test_mode,      -- which sms_templates row produced it
  ADD COLUMN provider_message_id VARCHAR(100) DEFAULT NULL AFTER provider_status,
  ADD COLUMN error VARCHAR(255) DEFAULT NULL AFTER provider_message_id,
  ADD COLUMN attempts INT NOT NULL DEFAULT 1 AFTER error,
  ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER sent_at,
  ADD KEY idx_sms_state (status, test_mode);

-- Map the old free-text results.
UPDATE sms_logs SET status = 'SENT' WHERE provider_status = 'sent';
UPDATE sms_logs SET status = 'FAILED', error = 'Gateway rejected the message' WHERE provider_status = 'failed';
UPDATE sms_logs SET status = 'SENT', test_mode = 1 WHERE provider_status = 'skipped-no-api-key';
