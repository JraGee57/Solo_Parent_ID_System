<?php
require_once __DIR__ . '/Model.php';

/**
 * appointment_slots + the appointments (interviews) booked against them.
 *
 * Slot status: AVAILABLE or CANCELLED. Appointment status: BOOKED ->
 * COMPLETED | MISSED | CANCELLED. Only BOOKED and COMPLETED appointments
 * hold a seat, so capacity is always counted over those two.
 */
class Appointment extends Model
{
    public const STATUSES = ['BOOKED', 'COMPLETED', 'CANCELLED', 'MISSED'];
    public const SEAT_HOLDING = "('BOOKED','COMPLETED')";

    /** New results start with an explicit outcome; earlier free-text results remain readable. */
    public static function interviewOutcome(?array $appointment): ?string
    {
        $result = trim((string) ($appointment['result'] ?? ''));
        foreach (['Successful', 'Unsuccessful'] as $outcome) {
            if ($result === $outcome || str_starts_with($result, $outcome . ': ')) {
                return $outcome;
            }
        }
        return null;
    }

    public static function interviewSuccessful(?array $appointment): bool
    {
        return $appointment && $appointment['status'] === 'COMPLETED'
            && trim((string) ($appointment['result'] ?? '')) !== '' && !empty($appointment['result_recorded_at'])
            && self::interviewOutcome($appointment) !== 'Unsuccessful';
    }

    /**
     * Slots an applicant can book: AVAILABLE, starting at least $minNoticeHours
     * from now, with seats left. Soonest first.
     */
    public function openSlots(int $minNoticeHours): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT s.*, (s.capacity - COALESCE(b.booked, 0)) AS remaining
             FROM appointment_slots s
             LEFT JOIN (SELECT slot_id, COUNT(*) AS booked FROM appointments
                        WHERE slot_id IS NOT NULL AND status IN " . self::SEAT_HOLDING . " GROUP BY slot_id) b
               ON b.slot_id = s.id
             WHERE s.status = 'AVAILABLE' AND TIMESTAMP(s.slot_date, s.start_time) >= DATE_ADD(NOW(), INTERVAL ? HOUR)
             HAVING remaining > 0
             ORDER BY s.slot_date, s.start_time"
        );
        $stmt->execute([$minNoticeHours]);
        return $stmt->fetchAll();
    }

    /** The slot row, locked until the surrounding transaction ends -- serialises bookings for the same slot. */
    public function lockSlot(int $slotId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM appointment_slots WHERE id = ? FOR UPDATE');
        $stmt->execute([$slotId]);
        return $stmt->fetch() ?: null;
    }

    public function findSlot(int $slotId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM appointment_slots WHERE id = ?');
        $stmt->execute([$slotId]);
        return $stmt->fetch() ?: null;
    }

    /** Seat-holding bookings on a slot, not counting the given application's own (so moving within a slot isn't blocked by itself). */
    public function bookedCountExcluding(int $slotId, int $applicationId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM appointments WHERE slot_id = ? AND application_id != ? AND status IN ' . self::SEAT_HOLDING);
        $stmt->execute([$slotId, $applicationId]);
        return (int) $stmt->fetchColumn();
    }

    /** Creates or reschedules the application's appointment into a slot; clears any old result/reminder/cancellation. */
    public function upsertSelfBooked(int $applicationId, int $slotId, string $scheduledAt, string $location): void
    {
        $this->pdo->prepare(
            'INSERT INTO appointments (application_id, slot_id, scheduled_at, location, scheduled_by, reminder_sent_at)
             VALUES (?, ?, ?, ?, NULL, NULL)
             ON DUPLICATE KEY UPDATE slot_id = VALUES(slot_id), scheduled_at = VALUES(scheduled_at),
                 location = VALUES(location), status = \'BOOKED\', scheduled_by = NULL, result = NULL, result_recorded_at = NULL,
                 reminder_sent_at = NULL, interview_sms_log_id = NULL, cancelled_at = NULL, cancelled_by = NULL, cancel_reason = NULL'
        )->execute([$applicationId, $slotId, $scheduledAt, $location]);
    }

    /** Staff (re)schedules an interview directly, not tied to a slot; clears any old result/reminder/cancellation. */
    public function upsertStaffScheduled(int $applicationId, string $scheduledAt, string $location, ?string $notes, int $staffId): void
    {
        $this->pdo->prepare(
            'INSERT INTO appointments (application_id, slot_id, scheduled_at, location, notes, scheduled_by, reminder_sent_at)
             VALUES (?, NULL, ?, ?, ?, ?, NULL)
             ON DUPLICATE KEY UPDATE slot_id = NULL, scheduled_at = VALUES(scheduled_at), location = VALUES(location), status = \'BOOKED\',
                 notes = VALUES(notes), scheduled_by = VALUES(scheduled_by), result = NULL, result_recorded_at = NULL,
                 reminder_sent_at = NULL, interview_sms_log_id = NULL, cancelled_at = NULL, cancelled_by = NULL, cancel_reason = NULL'
        )->execute([$applicationId, $scheduledAt, $location, $notes, $staffId]);
    }

    public function recordResult(int $applicationId, string $result): void
    {
        $this->pdo->prepare("UPDATE appointments SET result = ?, result_recorded_at = NOW(), status = 'COMPLETED' WHERE application_id = ?")
            ->execute([$result, $applicationId]);
    }

    /** Marks a BOOKED appointment CANCELLED (frees its seat). $cancelledBy is the user who cancelled. */
    public function cancel(int $appointmentId, int $cancelledBy, string $reason): void
    {
        $this->pdo->prepare("UPDATE appointments SET status = 'CANCELLED', cancelled_at = NOW(), cancelled_by = ?, cancel_reason = ? WHERE id = ? AND status = 'BOOKED'")
            ->execute([$cancelledBy, $reason, $appointmentId]);
    }

    public function markMissed(int $appointmentId): void
    {
        $this->pdo->prepare("UPDATE appointments SET status = 'MISSED' WHERE id = ? AND status = 'BOOKED'")->execute([$appointmentId]);
    }

    public function interviewFor(int $applicationId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM appointments WHERE application_id = ?');
        $stmt->execute([$applicationId]);
        return $stmt->fetch() ?: null;
    }

    public function linkSmsLog(int $applicationId, ?int $smsLogId): void
    {
        $this->pdo->prepare('UPDATE appointments SET interview_sms_log_id = ? WHERE application_id = ?')
            ->execute([$smsLogId, $applicationId]);
    }

    public function interviewSms(int $applicationId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT s.* FROM appointments i JOIN sms_logs s ON s.id = i.interview_sms_log_id WHERE i.application_id = ?');
        $stmt->execute([$applicationId]);
        return $stmt->fetch() ?: null;
    }

    /** One appointment with its application's reference, applicant and contact details. */
    public function findWithApplication(int $appointmentId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT i.*, a.reference_no, a.full_name, a.user_id, a.mobile, a.status AS application_status
             FROM appointments i JOIN applications a ON a.id = i.application_id WHERE i.id = ?'
        );
        $stmt->execute([$appointmentId]);
        return $stmt->fetch() ?: null;
    }

    /** Next $limit booked appointments from now, with applicant reference and name. */
    public function upcomingInterviews(int $limit): array
    {
        return $this->pdo->query(
            "SELECT i.*, a.reference_no, a.full_name FROM appointments i
             JOIN applications a ON a.id = i.application_id
             WHERE i.scheduled_at >= NOW() AND i.status = 'BOOKED' ORDER BY i.scheduled_at ASC LIMIT " . (int) $limit
        )->fetchAll();
    }

    /** Today's appointments in any status except CANCELLED (the staff day sheet). */
    public function todaysInterviews(): array
    {
        return $this->pdo->query(
            "SELECT i.*, a.reference_no, a.full_name, a.id AS application_id
             FROM appointments i JOIN applications a ON a.id = i.application_id
             WHERE DATE(i.scheduled_at) = CURDATE() AND i.status <> 'CANCELLED' ORDER BY i.scheduled_at"
        )->fetchAll();
    }

    /** Booked appointments after today (up to 100), grouped by Y-m-d date. */
    public function laterInterviewsByDate(): array
    {
        $rows = $this->pdo->query(
            "SELECT i.*, a.reference_no, a.full_name, a.id AS application_id
             FROM appointments i JOIN applications a ON a.id = i.application_id
             WHERE DATE(i.scheduled_at) > CURDATE() AND i.status = 'BOOKED'
             ORDER BY i.scheduled_at LIMIT 100"
        )->fetchAll();
        $byDate = [];
        foreach ($rows as $iv) {
            $byDate[date('Y-m-d', strtotime($iv['scheduled_at']))][] = $iv;
        }
        return $byDate;
    }

    /** Appointments whose time has passed but are still BOOKED: staff must record the result or mark them missed. Oldest first. */
    public function awaitingOutcome(): array
    {
        return $this->pdo->query(
            "SELECT i.*, a.reference_no, a.full_name, a.id AS application_id
             FROM appointments i JOIN applications a ON a.id = i.application_id
             WHERE i.status = 'BOOKED' AND i.scheduled_at < NOW() ORDER BY i.scheduled_at LIMIT 100"
        )->fetchAll();
    }

    /** Today-or-later slots (any status) with their seat-holding booked count and total appointments ever made in them, soonest first. */
    public function upcomingSlotsWithBookings(?int $limit = null): array
    {
        return $this->pdo->query(
            'SELECT s.*, (SELECT COUNT(*) FROM appointments i WHERE i.slot_id = s.id AND i.status IN ' . self::SEAT_HOLDING . ') AS booked,
                    (SELECT COUNT(*) FROM appointments i WHERE i.slot_id = s.id) AS ever_used
             FROM appointment_slots s
             WHERE s.slot_date >= CURDATE()
             ORDER BY s.slot_date, s.start_time' . ($limit ? ' LIMIT ' . (int) $limit : '')
        )->fetchAll();
    }

    /** The slot at exactly this date/start time (any status), if one exists. */
    public function slotAt(string $date, string $startTime): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM appointment_slots WHERE slot_date = ? AND start_time = ?');
        $stmt->execute([$date, $startTime]);
        return $stmt->fetch() ?: null;
    }

    /** An AVAILABLE slot on $date whose time range overlaps [$start, $end), other than $exceptId. */
    public function overlappingSlot(string $date, string $start, string $end, int $exceptId = 0): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM appointment_slots WHERE slot_date = ? AND status = 'AVAILABLE' AND start_time < ? AND end_time > ? AND id <> ? LIMIT 1"
        );
        $stmt->execute([$date, $end, $start, $exceptId]);
        return $stmt->fetch() ?: null;
    }

    public function createSlot(string $date, string $startTime, string $endTime, int $capacity, int $staffId): int
    {
        $this->pdo->prepare('INSERT INTO appointment_slots (slot_date, start_time, end_time, capacity, created_by) VALUES (?, ?, ?, ?, ?)')
            ->execute([$date, $startTime, $endTime, $capacity, $staffId]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Re-opens a cancelled slot at the same date/start time with new details. */
    public function reopenSlot(int $slotId, string $endTime, int $capacity): void
    {
        $this->pdo->prepare("UPDATE appointment_slots SET status = 'AVAILABLE', cancel_reason = NULL, end_time = ?, capacity = ? WHERE id = ?")
            ->execute([$endTime, $capacity, $slotId]);
    }

    public function cancelSlot(int $slotId, string $reason): void
    {
        $this->pdo->prepare("UPDATE appointment_slots SET status = 'CANCELLED', cancel_reason = ? WHERE id = ?")->execute([$reason, $slotId]);
    }

    /** BOOKED appointments in a slot, with the details needed to notify each applicant. */
    public function bookedInSlot(int $slotId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT i.*, a.reference_no, a.full_name, a.user_id, a.mobile, a.status AS application_status
             FROM appointments i JOIN applications a ON a.id = i.application_id
             WHERE i.slot_id = ? AND i.status = 'BOOKED'"
        );
        $stmt->execute([$slotId]);
        return $stmt->fetchAll();
    }

    /** Every appointment ever attached to the slot (any status) -- a slot with history is cancelled, not deleted. */
    public function anyAppointmentsInSlot(int $slotId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM appointments WHERE slot_id = ?');
        $stmt->execute([$slotId]);
        return (int) $stmt->fetchColumn();
    }

    public function deleteSlot(int $slotId): void
    {
        $this->pdo->prepare('DELETE FROM appointment_slots WHERE id = ?')->execute([$slotId]);
    }

    /** BOOKED appointments within the next $hours that haven't been reminded yet. */
    public function dueForReminder(int $hours): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT i.id AS interview_id, i.scheduled_at, i.location, a.id AS application_id, a.user_id, a.reference_no, a.mobile
             FROM appointments i JOIN applications a ON a.id = i.application_id
             WHERE i.status = 'BOOKED'
               AND i.scheduled_at > NOW()
               AND i.scheduled_at <= DATE_ADD(NOW(), INTERVAL ? HOUR)
               AND i.reminder_sent_at IS NULL"
        );
        $stmt->execute([$hours]);
        return $stmt->fetchAll();
    }

    public function markReminded(int $interviewId): void
    {
        $this->pdo->prepare('UPDATE appointments SET reminder_sent_at = NOW() WHERE id = ?')->execute([$interviewId]);
    }
}
