-- Non-destructive migration for brute-force login lockout (Security Review,
-- Step 27). Safe to run once against an existing virac_solo_parent
-- database with real data -- additive only.
--
--   "C:\xampp\mysql\bin\mysql.exe" -u root virac_solo_parent < database\migrate_login_lockout.sql

USE virac_solo_parent;

ALTER TABLE users
  ADD COLUMN failed_login_attempts INT NOT NULL DEFAULT 0 AFTER is_active,
  ADD COLUMN locked_until DATETIME DEFAULT NULL AFTER failed_login_attempts;
