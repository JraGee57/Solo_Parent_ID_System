<?php
require_once __DIR__ . '/../models/ScheduledJobRun.php';
require_once __DIR__ . '/RenewalService.php';
require_once __DIR__ . '/AppointmentService.php';
require_once __DIR__ . '/NotificationService.php';

/**
 * Runs the scheduled jobs -- from cron/*.php or when an administrator clicks
 * "Send Reminders Now" -- and logs every run in scheduled_job_runs (when,
 * how it was triggered, how many were checked / sent / failed). A database
 * lock per job stops two runs of the same job overlapping.
 */
class ScheduledJobService
{
    public const JOBS = [
        'renewal_reminders' => 'Renewal reminders',
        'appointment_reminders' => 'Appointment reminders',
    ];

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Runs $job. $trigger is 'cron' or 'manual' ($actor = the admin for
     * manual runs). Returns ['status', 'checked', 'sent' => refs, 'failed' => refs, 'message'].
     */
    public function run(string $job, string $trigger, ?array $actor = null): array
    {
        if (!isset(self::JOBS[$job])) {
            throw new InvalidArgumentException("Unknown job $job");
        }
        $runs = new ScheduledJobRun($this->pdo);
        $runId = $runs->start($job, $trigger, $actor ? (int) $actor['id'] : null);

        $lockName = 'virac_solo_parent.' . $job;
        $lock = $this->pdo->prepare('SELECT GET_LOCK(?, 0)');
        $lock->execute([$lockName]);
        if ((int) $lock->fetchColumn() !== 1) {
            $message = 'Skipped: another run of this job is still in progress.';
            $runs->finish($runId, 'SKIPPED', 0, 0, 0, $message);
            return ['status' => 'SKIPPED', 'checked' => 0, 'sent' => [], 'failed' => [], 'message' => $message];
        }

        try {
            $result = $job === 'renewal_reminders'
                ? (new RenewalService($this->pdo))->sendDueReminders($trigger)
                : (new AppointmentService($this->pdo))->sendDueReminders() + ['failed' => []];
            $status = $result['failed'] && !$result['sent'] ? 'FAILED' : 'SUCCESS';
            $parts = [];
            if ($result['sent']) {
                $parts[] = 'Sent: ' . implode(', ', $result['sent']) . '.';
            }
            if ($result['failed']) {
                $parts[] = 'Failed (will retry): ' . implode(', ', $result['failed']) . '.';
            }
            $message = $parts ? implode(' ', $parts) : 'Nothing due.';
            $runs->finish($runId, $status, $result['checked'], count($result['sent']), count($result['failed']), $message);
        } catch (Throwable $e) {
            error_log("Scheduled job $job failed: " . $e->getMessage());
            $runs->finish($runId, 'FAILED', 0, 0, 0, 'Error: ' . $e->getMessage());
            $result = ['checked' => 0, 'sent' => [], 'failed' => []];
            $status = 'FAILED';
            $message = 'The job stopped with an error; see the server error log.';
        } finally {
            $this->pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
        }

        if ($status === 'FAILED') {
            (new NotificationService($this->pdo))->notifyAdmins('job_failed', self::JOBS[$job] . ' job ' . ($trigger === 'manual' ? '(run manually) ' : '') . 'had a problem: ' . $message, 'admin/settings.php');
        }
        if ($result['sent'] || $result['failed'] || $trigger === 'manual') {
            audit_log($this->pdo, $actor ? (int) $actor['id'] : null, $actor['full_name'] ?? 'System (Scheduled Job)', $actor['role'] ?? 'system',
                self::JOBS[$job] . ($trigger === 'manual' ? ' Run (Manual)' : ' Run'), 'system', $runId, $message);
        }
        return ['status' => $status, 'checked' => $result['checked'], 'sent' => $result['sent'], 'failed' => $result['failed'], 'message' => $message];
    }

    /** Latest runs of a job, newest first. */
    public function recentRuns(string $job, int $limit = 5): array
    {
        return (new ScheduledJobRun($this->pdo))->recent($job, $limit);
    }
}