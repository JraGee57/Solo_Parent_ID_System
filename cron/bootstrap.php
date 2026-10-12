<?php
/**
 * Shared start-up for the scheduled jobs in cron/. They run from the
 * command line only (Windows Task Scheduler or cron); a web request is
 * refused even if the web server's deny rule (cron/.htaccess) is missing.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Scheduled jobs can only be run from the command line.');
}
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/ScheduledJobService.php';

/** Runs $job as a cron run, prints a one-line summary, and exits non-zero if it failed. */
function run_scheduled_job(PDO $pdo, string $job): void
{
    $result = (new ScheduledJobService($pdo))->run($job, 'cron');
    echo date('Y-m-d H:i:s') . " [$job] {$result['status']}: checked {$result['checked']}, sent " . count($result['sent'])
        . ', failed ' . count($result['failed']) . ". {$result['message']}\n";
    exit($result['status'] === 'FAILED' ? 1 : 0);
}