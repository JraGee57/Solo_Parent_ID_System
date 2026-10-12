<?php
require_once __DIR__ . '/../models/Application.php';
require_once __DIR__ . '/SMSService.php';
require_once __DIR__ . '/QrSlipService.php';

/** ID release appointment and handover workflow for one approved application. */
class IdReleaseService
{
    private PDO $pdo;
    private Application $applications;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->applications = new Application($pdo);
    }

    /** Save or reschedule the handover and send exactly the selected message. */
    public function schedule(array $staff, array $app, string $date, string $time, string $location, string $messageType, string $customMessage): ?string
    {
        $dateTime = DateTime::createFromFormat('!Y-m-d H:i', $date . ' ' . $time);
        if (!$dateTime || $dateTime->format('Y-m-d H:i') !== $date . ' ' . $time || $dateTime->getTimestamp() <= time()) {
            return 'Choose a valid future ID release date and time.';
        }
        $location = trim($location);
        if ($location === '' || mb_strlen($location) > 255) {
            return 'Enter a release location of up to 255 characters.';
        }
        if (!in_array($messageType, ['automatic', 'custom'], true)) {
            return 'Choose Automatic Message or Custom Message.';
        }
        $scheduledAt = $dateTime->format('Y-m-d H:i:s');
        $when = date('F j, Y', $dateTime->getTimestamp()) . ' at ' . date('g:i A', $dateTime->getTimestamp());
        $message = $messageType === 'custom'
            ? trim($customMessage)
            : 'Hello ' . $app['full_name'] . ', your Solo Parent ID is scheduled for release on ' . $when . '. Location: ' . $location . '. Please visit the MSWDO Virac office at your scheduled time. For inquiries, contact the MSWDO office.';
        if ($message === '') {
            return 'Enter the custom SMS message before scheduling.';
        }
        if (mb_strlen($message) > 500) {
            return 'The SMS message is too long (maximum 500 characters). Shorten the location or custom message.';
        }

        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT status FROM applications WHERE id = ? FOR UPDATE');
            $lock->execute([(int) $app['id']]);
            if ($lock->fetchColumn() !== 'Approved') {
                $this->pdo->rollBack();
                return 'ID release can only be scheduled for an approved application.';
            }
            $stmt = $this->pdo->prepare('SELECT * FROM id_release_schedules WHERE application_id = ? FOR UPDATE');
            $stmt->execute([(int) $app['id']]);
            $existing = $stmt->fetch() ?: null;
            if ($existing && $existing['released_at'] !== null) {
                $this->pdo->rollBack();
                return 'This ID has already been released.';
            }
            if ($existing && $existing['scheduled_at'] === $scheduledAt && $existing['location'] === $location && $existing['sms_message'] === $message) {
                $this->pdo->commit();
                flash('This ID release schedule and message are already saved; no duplicate SMS was sent.');
                return null;
            }

            $this->pdo->prepare(
                'INSERT INTO id_release_schedules (application_id, scheduled_at, location, scheduled_by, sms_message)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE scheduled_at = VALUES(scheduled_at), location = VALUES(location), scheduled_by = VALUES(scheduled_by), sms_message = VALUES(sms_message), sms_log_id = NULL'
            )->execute([(int) $app['id'], $scheduledAt, $location, (int) $staff['id'], $message]);
            $label = $existing ? 'rescheduled' : 'scheduled';
            $historyNote = 'ID release appointment ' . $label;
            if ($existing) {
                $historyNote .= ' from ' . date('M j, Y g:i A', strtotime($existing['scheduled_at'])) . ' at ' . $existing['location'];
            }
            $historyNote .= ' to ' . $when . ' at ' . $location . '.';
            $this->applications->addHistory((int) $app['id'], 'Approved', mb_substr($historyNote, 0, 500), $staff['full_name'], (int) $staff['id']);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        // Commit the appointment before calling the external SMS provider.
        $sms = (new SMSService($this->pdo))->notifyIdReleaseScheduled((int) $app['id'], $app['mobile'], $message, $messageType === 'custom');
        $this->pdo->prepare('UPDATE id_release_schedules SET sms_log_id = ? WHERE application_id = ? AND released_at IS NULL')
            ->execute([$sms['id'], (int) $app['id']]);
        create_notification($this->pdo, (int) $app['user_id'], 'appointment_confirmation', 'Your Solo Parent ID release is scheduled for ' . $when . ' at ' . $location . '.', (int) $app['id']);
        audit_log($this->pdo, (int) $staff['id'], $staff['full_name'], $staff['role'], 'ID Release Scheduled', 'application', (int) $app['id'], $when . ' at ' . $location . ' (' . strtoupper($messageType) . ' SMS)');
        $this->flashSmsResult($sms, 'ID release schedule saved');
        return null;
    }

    /** Confirm actual handover once; scheduling alone never changes application status. */
    public function confirmRelease(array $staff, array $app): ?string
    {
        $id = (int) $app['id'];
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT status FROM applications WHERE id = ? FOR UPDATE');
            $lock->execute([$id]);
            if ($lock->fetchColumn() !== 'Approved') {
                $this->pdo->rollBack();
                return 'Only an approved application can be confirmed as released.';
            }
            $stmt = $this->pdo->prepare('SELECT * FROM id_release_schedules WHERE application_id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $schedule = $stmt->fetch();
            if (!$schedule) {
                $this->pdo->rollBack();
                return 'Schedule the ID release appointment before confirming handover.';
            }
            if ($schedule['released_at'] !== null) {
                $this->pdo->rollBack();
                return 'ID release has already been confirmed.';
            }

            $this->pdo->prepare('UPDATE id_release_schedules SET released_at = NOW(), released_by = ? WHERE application_id = ? AND released_at IS NULL')
                ->execute([(int) $staff['id'], $id]);
            $this->applications->setReviewedStatus($id, 'ID Released', (int) $staff['id']);
            $actual = $this->pdo->prepare('SELECT released_at FROM id_release_schedules WHERE application_id = ?');
            $actual->execute([$id]);
            $releasedAt = (string) $actual->fetchColumn();
            $this->applications->addHistory($id, 'ID Released', 'Staff confirmed the Solo Parent ID was handed to the applicant on ' . date('F j, Y g:i A', strtotime($releasedAt)) . '.', $staff['full_name'], (int) $staff['id']);
            $expires = $this->pdo->prepare('SELECT id_expires_at FROM applications WHERE id = ?');
            $expires->execute([$id]);
            (new QrSlipService($this->pdo))->onStatusChange($app, 'ID Released', $expires->fetchColumn() ?: null);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        (new SMSService($this->pdo))->notifyStatusChange($id, $app['mobile'], $app['reference_no'], 'ID Released', 'Your ID handover was confirmed.');
        create_notification($this->pdo, (int) $app['user_id'], 'status_change', 'Application ' . $app['reference_no'] . ' is now ID Released.', $id);
        audit_log($this->pdo, (int) $staff['id'], $staff['full_name'], $staff['role'], 'ID Release Confirmed', 'application', $id, 'Handed to applicant at ' . date('M j, Y g:i A', strtotime($releasedAt)));
        flash('ID release confirmed. The application is now ID Released.');
        return null;
    }

    public function scheduleFor(int $applicationId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.*, s.status AS sms_status, s.provider_status AS sms_provider_status, s.template_key AS sms_template_key, s.test_mode AS sms_test_mode, s.error AS sms_error, s.mobile AS sms_mobile,
                    s.attempts AS sms_attempts, s.message AS logged_message,
                    u.full_name AS scheduled_by_name, ru.full_name AS released_by_name
             FROM id_release_schedules r
             LEFT JOIN sms_logs s ON s.id = r.sms_log_id
             LEFT JOIN users u ON u.id = r.scheduled_by
             LEFT JOIN users ru ON ru.id = r.released_by
             WHERE r.application_id = ?'
        );
        $stmt->execute([$applicationId]);
        return $stmt->fetch() ?: null;
    }

    private function flashSmsResult(array $sms, string $prefix): void
    {
        if ($sms['status'] === 'SENT' && !empty($sms['test_mode'])) {
            flash($prefix . '. SMS was logged in test mode and was not sent.');
        } elseif ($sms['status'] === 'SENT') {
            flash($prefix . '. The SMS provider accepted the message; delivery is not confirmed here.');
        } elseif (($sms['provider_status'] ?? '') === 'disabled') {
            flash($prefix . ', but SMS notifications are disabled in settings. Enable them and use Retry SMS.');
        } else {
            flash($prefix . ', but SMS submission failed: ' . ($sms['error'] ?: 'provider error') . '. Use Retry SMS to retry this message.');
        }
    }
}
