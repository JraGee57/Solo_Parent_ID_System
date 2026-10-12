<?php
require_once __DIR__ . '/../models/Application.php';
require_once __DIR__ . '/../models/Document.php';
require_once __DIR__ . '/../models/Appointment.php';
require_once __DIR__ . '/../models/EligibilityAssessment.php';
require_once __DIR__ . '/IdReleaseService.php';

/**
 * Builds the applicant-facing progress tracker for one application
 * (Application Submitted -> Under Review -> Document Verification -> MSWD
 * Assessment -> For Approval -> Approved -> ID Released).
 *
 * Every stage is derived from recorded data -- application_status_history,
 * the documents' verification states, MSWD assessments and the appointment
 * -- never from the status alone. A stage is only "done" when the record
 * that proves it exists, and its date is that record's date; stages not
 * reached have no date.
 *
 * Stage states: done | active (in progress now) | attention (waiting on the
 * applicant, e.g. corrections) | pending (not reached) | stopped (the
 * application was rejected before reaching it).
 */
class ApplicationTrackerService
{
    private PDO $pdo;
    private Application $applications;
    private Document $documents;
    private Appointment $appointments;
    private EligibilityAssessment $assessments;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->applications = new Application($pdo);
        $this->documents = new Document($pdo);
        $this->appointments = new Appointment($pdo);
        $this->assessments = new EligibilityAssessment($pdo);
    }

    /**
     * @return array{stages: array, current: string, rejected: ?array, correction: ?array, history: array}
     *   stages: [key, label, state, date (Y-m-d H:i:s|null), detail]; history: status rows, oldest first;
     *   documents: Document::forReview() rows; appointment: the appointment row (any status) or null.
     */
    public function track(array $app): array
    {
        $id = (int) $app['id'];
        $idReleaseSchedule = (new IdReleaseService($this->pdo))->scheduleFor($id);
        $history = array_reverse($this->applications->history($id)); // oldest first
        $status = $app['status'];

        $firstAt = function (array $statuses) use ($history): ?string {
            foreach ($history as $h) {
                if (in_array($h['status'], $statuses, true)) {
                    return $h['changed_at'];
                }
            }
            return null;
        };
        $latest = function (string $status) use ($history): ?array {
            for ($i = count($history) - 1; $i >= 0; $i--) {
                if ($history[$i]['status'] === $status) {
                    return $history[$i];
                }
            }
            return null;
        };

        // --- 1. Submitted ---
        $stages = [['key' => 'submitted', 'label' => 'Application Submitted', 'state' => 'done', 'date' => $app['submitted_at'], 'detail' => '']];

        // --- 2. Under review: the first recorded move past "Pending Review". ---
        // (The current status is itself a record: when it proves a stage was reached but that stage's
        // own record is missing -- e.g. data from before it was kept -- the stage is done with no date.)
        $reviewAt = $firstAt(['Under Verification', 'For Revision', 'Interview Scheduled', 'Approved', 'ID Released', 'Rejected']);
        $reviewed = $reviewAt !== null || $status !== 'Pending Review';
        $stages[] = ['key' => 'review', 'label' => 'Under Review', 'state' => $reviewed ? 'done' : 'active', 'date' => $reviewAt,
            'detail' => $reviewed ? ($reviewAt ? '' : 'Date not recorded.') : 'Waiting for MSWDO to start reviewing your application.'];

        // --- 3. Document verification: the documents' actual states. ---
        $docs = $this->documents->forReview($id, $app['category_id'] !== null ? (int) $app['category_id'] : null);
        $checklist = $this->documents->checklistForReview($id, $app['category_id'] !== null ? (int) $app['category_id'] : null, $app['type'] === 'renewal');
        $required = array_filter($checklist, fn($d) => $d['is_required']);
        $missing = array_filter($required, fn($d) => !$d['applicant_has']);
        $flagged = array_filter($checklist, fn($d) => $d['verification_status'] === 'Needs Correction');
        $verified = array_filter($required, fn($d) => $d['verification_status'] === 'Verified');
        $docsDone = !$missing && count($verified) === count($required) && count($required) > 0;
        $reviewed = array_filter($required, fn($d) => $d['reviewed_at'] !== null);
        $docsDate = $docsDone ? max(array_map(fn($d) => (string) $d['reviewed_at'], $verified)) : null;
        if ($docsDone) {
            $docState = 'done';
            $docDetail = count($verified) . ' of ' . count($required) . ' required documents verified.';
        } elseif ($flagged || $missing) {
            $docState = 'attention';
            $docDetail = ($flagged ? count($flagged) . ' document(s) need correction' : '') . ($flagged && $missing ? '; ' : '')
                . ($missing ? count($missing) . ' required document(s) not checked' : '') . '. See Required Documents Checklist.';
        } else {
            $docState = $reviewed ? 'active' : ($status === 'Pending Review' ? 'pending' : 'active');
            $docDetail = count($verified) . ' of ' . count($required) . ' required documents verified so far.';
        }
        $stages[] = ['key' => 'documents', 'label' => 'Document Verification', 'state' => $docState, 'date' => $docsDate, 'detail' => $docDetail];

        // --- 4. MSWD assessment: recorded assessments + the appointment. ---
        $assessment = $this->assessments->mswdForApplication($id)[0] ?? null;
        $appt = $this->appointments->interviewFor($id);
        $apptPending = $appt && $appt['status'] === 'BOOKED';
        $interviewDone = Appointment::interviewSuccessful($appt);
        $eligible = $app['eligibility_status'] === 'Eligible' && $assessment && $assessment['preliminary_result'] === 'ELIGIBLE';
        if ($eligible && $interviewDone) {
            $assessDate = $assessment['created_at'];
            if ($appt && $appt['status'] === 'COMPLETED' && $appt['result_recorded_at'] > $assessDate) {
                $assessDate = $appt['result_recorded_at'];
            }
            $stages[] = ['key' => 'assessment', 'label' => 'MSWD Assessment', 'state' => 'done', 'date' => $assessDate,
                'detail' => $appt && $appt['status'] === 'COMPLETED' ? 'Interview completed; assessed eligible.' : 'Assessed eligible.'];
        } else {
            if ($apptPending) {
                $detail = 'Interview on ' . date('M j, Y g:i A', strtotime($appt['scheduled_at'])) . ' at ' . $appt['location'] . '.';
            } elseif ($appt && $appt['status'] === 'MISSED') {
                $detail = 'You missed your appointment. Please book a new one.';
            } elseif (Appointment::interviewOutcome($appt) === 'Unsuccessful') {
                $detail = 'The interview was unsuccessful. Please contact MSWDO for the next steps.';
            } elseif ($assessment && $assessment['preliminary_result'] === 'NOT_ELIGIBLE') {
                $detail = 'MSWDO assessed you as not eligible' . ($assessment['result_reason'] ? ': ' . $assessment['result_reason'] : '') . '.';
            } elseif ($eligible) {
                $detail = 'Eligibility confirmed. Waiting for a completed interview and recorded result.';
            } else {
                $detail = 'MSWDO will assess your eligibility' . ($appt ? '' : ' (an interview may be scheduled)') . '.';
            }
            $state = ($appt && $appt['status'] === 'MISSED') || Appointment::interviewOutcome($appt) === 'Unsuccessful' ? 'attention' : ($reviewed ? 'active' : 'pending');
            $stages[] = ['key' => 'assessment', 'label' => 'MSWD Assessment', 'state' => $state, 'date' => null, 'detail' => $detail];
        }

        // --- 5-7. Decision, approval, release: straight from the status history. ---
        $approvedAt = $firstAt(['Approved']);
        $releasedAt = $idReleaseSchedule['released_at'] ?? $firstAt(['ID Released']);
        $isReleased = $releasedAt !== null || $status === 'ID Released';
        $isApproved = $approvedAt !== null || $isReleased || $status === 'Approved';
        $releasePending = $status === 'Approved' && $idReleaseSchedule && !$isReleased;
        $noDate = fn(?string $at) => $at ? '' : 'Date not recorded.';
        if ($isApproved) {
            // Approval is only possible once documents and assessment passed (see the approval checklist),
            // so an approved application's earlier stages are complete even if their records predate this.
            foreach ($stages as &$s) {
                if ($s['key'] === 'assessment') {
                    continue;
                }
                if ($s['state'] !== 'done') {
                    $s['state'] = 'done';
                    $s['detail'] = $noDate($s['date']);
                }
            }
            unset($s);
        }
        $readyForDecision = $docsDone && $eligible && $interviewDone;
        $stages[] = ['key' => 'decision', 'label' => 'For Approval', 'state' => $isApproved ? 'done' : ($readyForDecision ? 'active' : 'pending'),
            'date' => $approvedAt, 'detail' => $isApproved ? $noDate($approvedAt) : ($readyForDecision ? 'All checks complete. Waiting for the MSWDO decision.' : '')];
        $stages[] = ['key' => 'approved', 'label' => 'Approved', 'state' => $isApproved ? 'done' : 'pending', 'date' => $approvedAt,
            'detail' => $isApproved ? trim($noDate($approvedAt) . ($isReleased || $releasePending ? '' : ' Your Solo Parent ID is being prepared.')) : ''];
        $stages[] = ['key' => 'released', 'label' => $releasePending ? 'ID Release Scheduled' : 'ID Released',
            'state' => $isReleased ? 'done' : ($releasePending ? 'active' : 'pending'), 'date' => $releasedAt,
            'detail' => $isReleased ? trim($noDate($releasedAt) . ($app['id_expires_at'] ? ' Valid until ' . date('F j, Y', strtotime($app['id_expires_at'])) . '.' : ''))
                : ($releasePending ? 'ID release on ' . date('F j, Y g:i A', strtotime($idReleaseSchedule['scheduled_at'])) . ' at ' . $idReleaseSchedule['location'] . '. Please bring your application slip and a valid ID.' : '')];

        // --- Rejected: stop every stage that wasn't reached. ---
        $rejected = null;
        if ($status === 'Rejected') {
            $row = $latest('Rejected');
            $rejected = ['date' => $row['changed_at'] ?? null, 'reason' => $row['remarks'] ?? null];
            foreach ($stages as &$s) {
                if ($s['state'] !== 'done') {
                    $s['state'] = 'stopped';
                    $s['detail'] = '';
                }
            }
            unset($s);
        }

        // --- Corrections requested (current status only). ---
        $correction = null;
        if ($status === 'For Revision') {
            $row = $latest('For Revision');
            $correction = ['date' => $row['changed_at'] ?? null, 'reason' => $row['remarks'] ?? null];
        }

        $current = 'Completed';
        foreach ($stages as $s) {
            if ($s['state'] !== 'done') {
                $current = $status === 'Rejected' ? 'Not approved' : $s['label'];
                break;
            }
        }

        return ['stages' => $stages, 'current' => $current, 'rejected' => $rejected, 'correction' => $correction, 'history' => $history,
            'documents' => $docs, 'checklist' => $checklist, 'appointment' => $appt, 'idReleaseSchedule' => $idReleaseSchedule];
    }
}
