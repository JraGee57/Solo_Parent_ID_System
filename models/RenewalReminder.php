<?php
require_once __DIR__ . '/Model.php';

/**
 * renewal_reminders: one row per released ID per expiry date. A reminder is
 * *claimed* (status SENDING) before anything is sent -- the unique
 * (application_id, id_expires_at) key means only one run can ever claim a
 * given expiry, so overlapping runs can't text anyone twice. A FAILED
 * reminder may be re-claimed by a later run, up to MAX_ATTEMPTS in total.
 */
class RenewalReminder extends Model
{
    public const MAX_ATTEMPTS = 3;

    /** Claims the reminder for this ID's expiry. Returns the reminder id, or null if another run already has it. */
    public function claim(int $applicationId, string $expiresAt, int $userId, int $daysBefore, string $trigger): ?int
    {
        $stmt = $this->pdo->prepare(
            "INSERT IGNORE INTO renewal_reminders (application_id, id_expires_at, user_id, days_before, status, trigger_source)
             VALUES (?, ?, ?, ?, 'SENDING', ?)"
        );
        $stmt->execute([$applicationId, $expiresAt, $userId, $daysBefore, $trigger]);
        if ($stmt->rowCount() === 1) {
            return (int) $this->pdo->lastInsertId();
        }
        // Already there: take it over only if an earlier attempt failed and attempts remain.
        $retry = $this->pdo->prepare(
            "UPDATE renewal_reminders SET status = 'SENDING', attempts = attempts + 1, days_before = ?, trigger_source = ?, error = NULL, sent_at = NOW()
             WHERE application_id = ? AND id_expires_at = ? AND status = 'FAILED' AND attempts < ?"
        );
        $retry->execute([$daysBefore, $trigger, $applicationId, $expiresAt, self::MAX_ATTEMPTS]);
        if ($retry->rowCount() !== 1) {
            return null;
        }
        $id = $this->pdo->prepare('SELECT id FROM renewal_reminders WHERE application_id = ? AND id_expires_at = ?');
        $id->execute([$applicationId, $expiresAt]);
        return (int) $id->fetchColumn();
    }

    public function markSent(int $reminderId, string $smsStatus, int $notificationId): void
    {
        $this->pdo->prepare("UPDATE renewal_reminders SET status = 'SENT', sms_status = ?, notification_id = ?, sent_at = NOW() WHERE id = ?")
            ->execute([$smsStatus, $notificationId, $reminderId]);
    }

    public function markFailed(int $reminderId, string $error): void
    {
        $this->pdo->prepare("UPDATE renewal_reminders SET status = 'FAILED', error = ? WHERE id = ?")
            ->execute([mb_substr($error, 0, 255), $reminderId]);
    }

    /** Latest reminders with the ID's reference and holder, newest first. */
    public function recent(int $limit): array
    {
        return $this->pdo->query(
            'SELECT r.*, a.reference_no, a.full_name FROM renewal_reminders r
             JOIN applications a ON a.id = r.application_id
             ORDER BY r.sent_at DESC, r.id DESC LIMIT ' . (int) $limit
        )->fetchAll();
    }
}