<?php
/**
 * Scheduled job: automatic renewal reminders.
 *
 * Every released Solo Parent ID expiring within the configured number of
 * days (system_settings renewal_reminder_days, default 30; editable in
 * admin Settings) gets one reminder per expiry date: an in-app notification
 * plus an SMS (logged in sms_logs with the provider result). IDs that
 * already have a renewal filed, and deactivated accounts, are skipped. An
 * ID whose window passed without a reminder (e.g. the job wasn't running)
 * gets a catch-up "expired" reminder for up to the same number of days.
 * Each reminder is recorded in renewal_reminders and each run in
 * scheduled_job_runs; it is safe to run as often as you like.
 *
 * Schedule it daily with Windows Task Scheduler:
 *   Program:   C:\xampp\php\php.exe
 *   Arguments: C:\xampp\htdocs\virac-soloparent\cron\renewal-reminders.php
 * or cron:  0 8 * * *  php /path/to/virac-soloparent/cron/renewal-reminders.php
 */
require __DIR__ . '/bootstrap.php';
run_scheduled_job($pdo, 'renewal_reminders');