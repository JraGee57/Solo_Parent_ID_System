<?php
require_once __DIR__ . '/Model.php';

/** scheduled_job_runs: one row per run of a scheduled job (from cron or triggered by an admin). */
class ScheduledJobRun extends Model
{
    public function start(string $job, string $trigger, ?int $triggeredBy): int
    {
        $this->pdo->prepare('INSERT INTO scheduled_job_runs (job, trigger_source, triggered_by) VALUES (?, ?, ?)')
            ->execute([$job, $trigger, $triggeredBy]);
        return (int) $this->pdo->lastInsertId();
    }

    public function finish(int $runId, string $status, int $checked, int $sent, int $failed, ?string $message): void
    {
        $this->pdo->prepare('UPDATE scheduled_job_runs SET status = ?, checked = ?, sent = ?, failed = ?, message = ?, finished_at = NOW() WHERE id = ?')
            ->execute([$status, $checked, $sent, $failed, $message !== null ? mb_substr($message, 0, 500) : null, $runId]);
    }

    /** The most recent runs of $job, newest first, with who triggered manual ones. */
    public function recent(string $job, int $limit): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.*, u.full_name AS triggered_by_name FROM scheduled_job_runs r
             LEFT JOIN users u ON u.id = r.triggered_by
             WHERE r.job = ? ORDER BY r.started_at DESC, r.id DESC LIMIT ' . (int) $limit
        );
        $stmt->execute([$job]);
        return $stmt->fetchAll();
    }
}