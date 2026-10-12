-- Step 26: the audit log is append-only.
-- The database itself refuses UPDATE and DELETE on audit_logs, so no
-- application code path (or bug, or injected query) can alter history.
--
-- Deleting a user still works: the user_id foreign key's ON DELETE SET NULL
-- is a foreign-key action, and MariaDB/MySQL never fire triggers for those.
--
-- Deliberate maintenance by a database administrator (e.g. removing test
-- data) must opt in explicitly for its own session:
--   SET @audit_log_maintenance = 1;  DELETE FROM audit_logs WHERE ...;
--
-- Run once against the live database (mysql client or phpMyAdmin "SQL" tab).

DROP TRIGGER IF EXISTS audit_logs_no_update;
DROP TRIGGER IF EXISTS audit_logs_no_delete;

DELIMITER $$

CREATE TRIGGER audit_logs_no_update BEFORE UPDATE ON audit_logs
FOR EACH ROW
BEGIN
  IF COALESCE(@audit_log_maintenance, 0) <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit log records cannot be changed.';
  END IF;
END$$

CREATE TRIGGER audit_logs_no_delete BEFORE DELETE ON audit_logs
FOR EACH ROW
BEGIN
  IF COALESCE(@audit_log_maintenance, 0) <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit log records cannot be deleted.';
  END IF;
END$$

DELIMITER ;

-- Filtering the viewer by account, and resolving targets.
ALTER TABLE audit_logs
  ADD KEY idx_audit_user (user_id, created_at),
  ADD KEY idx_audit_entity (entity_type, entity_id);
