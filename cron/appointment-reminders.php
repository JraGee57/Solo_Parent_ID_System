<?php
/**
 * Scheduled job: appointment reminders -- an SMS + in-app notification for
 * every booked appointment starting within the configured window
 * (system_settings appointment_reminder_hours, default 24), once per
 * scheduled time. Each run is logged in scheduled_job_runs.
 *
 * Schedule it hourly with Windows Task Scheduler:
 *   Program:   C:\xampp\php\php.exe
 *   Arguments: C:\xampp\htdocs\virac-soloparent\cron\appointment-reminders.php
 */
require __DIR__ . '/bootstrap.php';
run_scheduled_job($pdo, 'appointment_reminders');