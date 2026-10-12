<?php
require_once __DIR__ . '/../models/Application.php';
require_once __DIR__ . '/../models/Appointment.php';
require_once __DIR__ . '/SMSService.php';
require_once __DIR__ . '/NotificationService.php';

/**
 * Appointment slots and bookings.
 *
 * Rules: an application holds at most one appointment (UNIQUE
 * application_id). Only BOOKED and COMPLETED appointments take a seat, and a
 * booking locks its slot row so two people can never take the last seat.
 * Applicants book, reschedule and cancel only up to the admin-configured
 * minimum notice before the appointment; staff can cancel (with a reason)
 * or mark a no-show at any time. Cancelling or missing an appointment puts
 * the application back to the status it had before it was scheduled.
 */
class AppointmentService
{
    public const DEFAULT_LOCATION = 'MSWDO Office, Municipal Building, Salvacion, Virac, Catanduanes';
    private const BOOKABLE_STATUSES = ['Pending Review', 'Under Verification', 'For Revision', 'Interview Scheduled'];
    /** Slots can be created at most this far ahead. */
    private PDO $pdo;
    private Appointment $appointments;
    private Application $applications;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->appointments = new Appointment($pdo);
        $this->applications = new Application($pdo);
    }

    /** Applicants can't book, reschedule or cancel within this many hours of the appointment. */
    public function minNoticeHours(): int
    {
        return max(0, min(168, (int) get_setting($this->pdo, 'appointment_min_notice_hours', '2')));
    }

    public function openSlots(): array
    {
        return $this->appointments->openSlots($this->minNoticeHours());
    }

    /** The first still-in-progress application an appointment applies to, if any. */
    public function findBookableApplication(array $applications): ?array
    {
        foreach ($applications as $app) {
            if (in_array($app['status'], self::BOOKABLE_STATUSES, true)) {
                return $app;
            }
        }
        return null;
    }

    /** An in-progress application (row with interview_status) that has no booked or completed appointment -- none yet, or it was missed. */
    public static function needsBooking(?array $application): bool
    {
        return $application !== null && in_array($application['interview_status'] ?? null, [null, 'MISSED'], true);
    }

    public function location(): string
    {
        return get_setting($this->pdo, 'appointment_location', self::DEFAULT_LOCATION);
    }

    /** True if an appointment at $scheduledAt is still outside the applicant's notice window (can be changed/cancelled online). */
    public function changeableByApplicant(string $scheduledAt): bool
    {
        return strtotime($scheduledAt) >= time() + $this->minNoticeHours() * 3600;
    }

    // ---- Applicant ----

    /**
     * Books (or reschedules) the application into a slot. The slot row is
     * locked while its seats are counted and the booking is written, so
     * concurrent bookings for the last seat are serialised.
     * Returns an error message, or null on success.
     */
    public function book(array $user, array $application, int $slotId): ?string
    {
        if (!in_array($application['status'], self::BOOKABLE_STATUSES, true)) {
            return 'This application does not need an appointment right now.';
        }
        $applicationId = (int) $application['id'];
        $current = $this->appointments->interviewFor($applicationId);
        if ($current && $current['status'] === 'COMPLETED') {
            return 'Your interview for this application has already been completed.';
        }
        if ($current && $current['status'] === 'BOOKED') {
            if ((int) $current['slot_id'] === $slotId) {
                return 'You are already booked in that slot.';
            }
            if (!$this->changeableByApplicant($current['scheduled_at'])) {
                return 'Your appointment is less than ' . $this->minNoticeHours() . ' hour(s) away and can no longer be changed online. Please contact the MSWDO office.';
            }
        }

        $location = $this->location();
        $this->pdo->beginTransaction();
        try {
            $slot = $this->appointments->lockSlot($slotId);
            if (!$slot || $slot['status'] !== 'AVAILABLE' || !$this->changeableByApplicant($slot['slot_date'] . ' ' . $slot['start_time'])) {
                $this->pdo->rollBack();
                return 'That appointment slot is no longer available.';
            }
            if ($this->appointments->bookedCountExcluding($slotId, $applicationId) >= (int) $slot['capacity']) {
                $this->pdo->rollBack();
                return 'That appointment slot just filled up. Please pick another.';
            }
            $scheduledAt = $slot['slot_date'] . ' ' . $slot['start_time'];
            $when = date('M j, Y g:i A', strtotime($scheduledAt));
            $this->appointments->upsertSelfBooked($applicationId, $slotId, $scheduledAt, $location);
            $this->applications->setStatus($applicationId, 'Interview Scheduled');
            $this->applications->addHistory($applicationId, 'Interview Scheduled', ($current && $current['status'] === 'BOOKED' ? 'Appointment rescheduled to ' : 'Appointment self-booked for ') . $when, $user['full_name'], (int) $user['id']);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $smsResult = (new SMSService($this->pdo))->notifyInterviewScheduled($applicationId, $application['mobile'], $application['reference_no'], $scheduledAt, $location, $application['full_name']);
        if (!empty($smsResult['id'])) {
            $this->appointments->linkSmsLog($applicationId, (int) $smsResult['id']);
        }
        create_notification(
            $this->pdo, (int) $user['id'], 'appointment_confirmation',
            'Your appointment for ' . $application['reference_no'] . ' is confirmed: ' . $when . ' at ' . $location . '.',
            $applicationId
        );
        audit_log($this->pdo, (int) $user['id'], $user['full_name'], $user['role'], $current && $current['status'] === 'BOOKED' ? 'Appointment Rescheduled' : 'Appointment Booked', 'application', $applicationId, $when . ' (slot ' . $slotId . ')');
        (new NotificationService($this->pdo))->notifyStaff('appointment_update', $application['reference_no'] . ' (' . $user['full_name'] . ') ' . ($current && $current['status'] === 'BOOKED' ? 'rescheduled their appointment to ' : 'booked an appointment for ') . $when . '.', $applicationId);
        return null;
    }

    /** The applicant cancels their own BOOKED appointment (outside the notice window). Returns an error message, or null. */
    public function cancelByApplicant(array $user, array $application): ?string
    {
        $current = $this->appointments->interviewFor((int) $application['id']);
        if (!$current || $current['status'] !== 'BOOKED') {
            return 'You have no booked appointment to cancel.';
        }
        if (!$this->changeableByApplicant($current['scheduled_at'])) {
            return 'Your appointment is less than ' . $this->minNoticeHours() . ' hour(s) away and can no longer be cancelled online. Please contact the MSWDO office.';
        }
        $current += ['reference_no' => $application['reference_no'], 'user_id' => $user['id'], 'mobile' => $application['mobile'], 'application_status' => $application['status']];
        $this->closeAppointment($user, $current, 'CANCELLED', 'Cancelled by the applicant', false);
        (new NotificationService($this->pdo))->notifyStaff('appointment_update', $application['reference_no'] . ' (' . $user['full_name'] . ') cancelled their appointment on ' . date('M j, Y g:i A', strtotime($current['scheduled_at'])) . '.', (int) $application['id']);
        return null;
    }

    // ---- Staff ----

    /**
     * Staff mark a BOOKED appointment CANCELLED (reason required; the
     * applicant is told by SMS and in-app) or MISSED (only once its time has
     * passed; also told by SMS and in-app). Returns an error message, or null on success.
     */
    public function staffSetOutcome(array $staff, int $appointmentId, string $outcome, string $reason): ?string
    {
        $appt = $this->appointments->findWithApplication($appointmentId);
        if (!$appt) {
            return 'Appointment not found.';
        }
        if ($appt['status'] !== 'BOOKED') {
            return 'Only a booked appointment can be changed (this one is ' . strtolower($appt['status']) . ').';
        }
        $reason = trim($reason);
        if (mb_strlen($reason) > 255) {
            return 'Reason is too long (up to 255 characters).';
        }
        if ($outcome === 'CANCELLED') {
            if ($reason === '') {
                return 'Give a reason for cancelling -- it is sent to the applicant.';
            }
        } elseif ($outcome === 'MISSED') {
            if (strtotime($appt['scheduled_at']) > time()) {
                return 'An appointment can only be marked missed after its scheduled time.';
            }
        } else {
            return 'Choose a valid action.';
        }
        $this->closeAppointment($staff, $appt, $outcome, $reason !== '' ? $reason : null, true);
        return null;
    }

    /** Staff: adds a bookable slot (or re-opens a cancelled one at the same time). Times are HH:MM. Returns an error message, or null. */
    public function createSlot(array $staff, string $date, string $startTime, string $endTime, int $capacity): ?string
    {
        $d = DateTime::createFromFormat('!Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date || $date < date('Y-m-d')) {
            return 'Select a valid date (today or later).';
        }
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $startTime) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $endTime) || $endTime <= $startTime) {
            return 'Select a valid start and end time (end must be after start).';
        }
        if (strtotime("$date $startTime") <= time()) {
            return 'That start time has already passed.';
        }
        if ($capacity < 1 || $capacity > 100) {
            return 'Capacity must be between 1 and 100.';
        }
        // The appointment schedule set by an administrator (System Settings).
        $daysAhead = (int) setting('appointment_max_days_ahead');
        if ($date > date('Y-m-d', strtotime('+' . $daysAhead . ' days'))) {
            return 'Slots can be opened up to ' . $daysAhead . ' days ahead.';
        }
        $days = setting_list('appointment_days');
        if (!in_array($d->format('N'), $days, true)) {
            $names = SETTINGS_REGISTRY['appointments']['keys']['appointment_days']['options'];
            return 'Appointments are scheduled on ' . implode(', ', array_map(fn($n) => $names[$n], $days)) . ' only (System Settings).';
        }
        [$open, $close] = [setting('appointment_day_start'), setting('appointment_day_end')];
        if ($startTime < $open || $endTime > $close) {
            return 'Slots must fall between ' . date('g:i A', strtotime($open)) . ' and ' . date('g:i A', strtotime($close)) . ' (System Settings).';
        }
        $existing = $this->appointments->slotAt($date, $startTime . ':00');
        if ($existing && $existing['status'] === 'AVAILABLE') {
            return 'A slot already exists for that date and start time.';
        }
        $overlap = $this->appointments->overlappingSlot($date, $startTime . ':00', $endTime . ':00', $existing ? (int) $existing['id'] : 0);
        if ($overlap) {
            return 'That time overlaps the ' . date('g:i A', strtotime($overlap['start_time'])) . '–' . date('g:i A', strtotime($overlap['end_time'])) . ' slot on the same day.';
        }
        if ($existing) {
            $this->appointments->reopenSlot((int) $existing['id'], $endTime, $capacity);
            audit_log($this->pdo, (int) $staff['id'], $staff['full_name'], $staff['role'], 'Appointment Slot Reopened', 'appointment_slot', (int) $existing['id'], "$date $startTime-$endTime x$capacity");
            return null;
        }
        $slotId = $this->appointments->createSlot($date, $startTime, $endTime, $capacity, (int) $staff['id']);
        audit_log($this->pdo, (int) $staff['id'], $staff['full_name'], $staff['role'], 'Appointment Slot Created', 'appointment_slot', $slotId, "$date $startTime-$endTime x$capacity");
        return null;
    }

    /**
     * Staff cancel a slot (e.g. office closed): it stops being bookable and
     * every BOOKED appointment in it is cancelled with the reason, and those
     * applicants are notified. Returns [error, cancelledCount].
     */
    public function cancelSlot(array $staff, int $slotId, string $reason): array
    {
        $slot = $this->appointments->findSlot($slotId);
        if (!$slot || $slot['status'] !== 'AVAILABLE') {
            return ['That slot is not open.', 0];
        }
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 255) {
            return ['Give a reason (up to 255 characters) -- it is sent to anyone booked in the slot.', 0];
        }
        $booked = $this->appointments->bookedInSlot($slotId);
        $this->appointments->cancelSlot($slotId, $reason);
        foreach ($booked as $appt) {
            $this->closeAppointment($staff, $appt, 'CANCELLED', $reason, true);
        }
        audit_log($this->pdo, (int) $staff['id'], $staff['full_name'], $staff['role'], 'Appointment Slot Cancelled', 'appointment_slot', $slotId,
            $slot['slot_date'] . ' ' . substr($slot['start_time'], 0, 5) . ' — ' . $reason . ' (' . count($booked) . ' booking(s) cancelled)');
        return [null, count($booked)];
    }

    /** Staff: removes a slot that has never had any appointment (otherwise it must be cancelled, keeping its history). */
    public function deleteSlot(array $staff, int $slotId): ?string
    {
        $slot = $this->appointments->findSlot($slotId);
        if (!$slot) {
            return 'That slot no longer exists.';
        }
        if ($this->appointments->anyAppointmentsInSlot($slotId) > 0) {
            return 'This slot has appointment history and cannot be deleted. Cancel it instead.';
        }
        $this->appointments->deleteSlot($slotId);
        audit_log($this->pdo, (int) $staff['id'], $staff['full_name'], $staff['role'], 'Appointment Slot Deleted', 'appointment_slot', $slotId, $slot['slot_date'] . ' ' . substr($slot['start_time'], 0, 5));
        return null;
    }

    /**
     * Sends a reminder SMS + in-app notification for every BOOKED appointment
     * inside the configured window (default 24h) not yet reminded.
     * Rescheduling resets reminder_sent_at, so a moved appointment gets a
     * fresh reminder.
     */
    public function sendDueReminders(): array
    {
        $hours = (int) get_setting($this->pdo, 'appointment_reminder_hours', '24');
        $hours = $hours > 0 ? $hours : 24;
        $due = $this->appointments->dueForReminder($hours);

        $sms = new SMSService($this->pdo);
        $sentRefs = [];
        foreach ($due as $iv) {
            $sms->notifyAppointmentReminder((int) $iv['application_id'], $iv['mobile'], $iv['reference_no'], $iv['scheduled_at'], $iv['location']);
            create_notification(
                $this->pdo, (int) $iv['user_id'], 'appointment_reminder',
                'Reminder: your appointment for ' . $iv['reference_no'] . ' is on ' . date('M j, Y g:i A', strtotime($iv['scheduled_at'])) . ' at ' . $iv['location'] . '.',
                (int) $iv['application_id']
            );
            $this->appointments->markReminded((int) $iv['interview_id']);
            $sentRefs[] = $iv['reference_no'];
        }

        return ['checked' => count($due), 'sent' => $sentRefs];
    }

    /**
     * Ends a BOOKED appointment as CANCELLED or MISSED: records it, puts an
     * application that was "Interview Scheduled" back to its earlier status
     * (so it can be booked again), and tells the applicant. $appt carries the
     * appointment row plus reference_no, user_id, mobile, application_status.
     */
    private function closeAppointment(array $actor, array $appt, string $outcome, ?string $reason, bool $byStaff): void
    {
        $applicationId = (int) $appt['application_id'];
        $when = date('M j, Y g:i A', strtotime($appt['scheduled_at']));

        $this->pdo->beginTransaction();
        try {
            if ($outcome === 'CANCELLED') {
                $this->appointments->cancel((int) $appt['id'], (int) $actor['id'], (string) $reason);
            } else {
                $this->appointments->markMissed((int) $appt['id']);
            }
            if ($appt['application_status'] === 'Interview Scheduled') {
                $back = $this->applications->statusBeforeInterview($applicationId);
                $this->applications->setStatus($applicationId, $back);
                $note = ($outcome === 'CANCELLED' ? 'Appointment on ' . $when . ' cancelled' : 'Appointment on ' . $when . ' missed') . ($reason ? ': ' . $reason : '.');
                $this->applications->addHistory($applicationId, $back, $note, $actor['full_name'], (int) $actor['id']);
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        if ($byStaff) {
            $message = $outcome === 'CANCELLED'
                ? 'Your appointment for ' . $appt['reference_no'] . ' on ' . $when . ' was cancelled by MSWDO: ' . $reason . '. Please book a new appointment.'
                : 'You missed your appointment for ' . $appt['reference_no'] . ' on ' . $when . '. Please book a new appointment.';
            create_notification($this->pdo, (int) $appt['user_id'], 'appointment_confirmation', $message, $applicationId);
            if ($outcome === 'CANCELLED') {
                (new SMSService($this->pdo))->notifyAppointmentCancelled($applicationId, $appt['mobile'], $appt['reference_no'], $appt['scheduled_at'], (string) $reason);
            } else {
                (new SMSService($this->pdo))->notifyAppointmentMissed($applicationId, $appt['mobile'], $appt['reference_no'], $appt['scheduled_at']);
            }
        }
        audit_log($this->pdo, (int) $actor['id'], $actor['full_name'], $actor['role'],
            $outcome === 'CANCELLED' ? 'Appointment Cancelled' : 'Appointment Missed', 'application', $applicationId,
            $appt['reference_no'] . ' · ' . $when . ($reason ? ' — ' . $reason : ''));
    }
}
