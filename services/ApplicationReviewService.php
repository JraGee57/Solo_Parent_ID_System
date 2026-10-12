<?php
require_once __DIR__ . '/../models/Application.php';
require_once __DIR__ . '/../models/Document.php';
require_once __DIR__ . '/../models/Appointment.php';
require_once __DIR__ . '/../models/EligibilityAssessment.php';
require_once __DIR__ . '/QrSlipService.php';
require_once __DIR__ . '/SMSService.php';
require_once __DIR__ . '/NotificationService.php';

/**
 * MSWD staff decisions on one application: status changes, document
 * verification, eligibility assessment, and interviews. Each action writes
 * the audit log and, where the applicant should know, an SMS + in-app
 * notification. Actions that can fail return an error message, else null.
 */
class ApplicationReviewService
{
    private const NOTIFICATION_TYPES = ['Approved' => 'approval', 'Rejected' => 'rejection', 'For Revision' => 'correction'];
    private const AUDIT_ACTIONS = ['Approved' => 'Application Approval', 'Rejected' => 'Application Rejection', 'For Revision' => 'Correction Request'];
    /** Statuses that are MSWD decisions, recorded in application_decisions as well as the status history. */
    private const DECISIONS = ['Approved' => 'APPROVED', 'Rejected' => 'REJECTED', 'For Revision' => 'NEEDS_CORRECTION'];

    private PDO $pdo;
    private Application $applications;
    private Document $documents;
    private Appointment $appointments;
    private const AUTO_ELIGIBILITY_NOTES = 'Automatically assessed eligible after all required documents were verified.';

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->applications = new Application($pdo);
        $this->documents = new Document($pdo);
        $this->appointments = new Appointment($pdo);
    }

    /** Opening a pending application starts review once, even with concurrent staff views. */
    public function startReviewOnOpen(array $staff, array $app): void
    {
        if (!can($staff, 'applications.process') || $app['status'] !== 'Pending Review') {
            return;
        }
        $id = (int) $app['id'];
        $this->pdo->beginTransaction();
        try {
            $update = $this->pdo->prepare("UPDATE applications SET status = 'Under Verification', reviewed_by = ? WHERE id = ? AND status = 'Pending Review'");
            $update->execute([(int) $staff['id'], $id]);
            if ($update->rowCount() === 1) {
                $this->applications->addHistory($id, 'Under Verification', 'Review started when staff opened the application.', $staff['full_name'], (int) $staff['id']);
                $this->audit($staff, 'Status Change', 'application', $id, $app['reference_no'] . ' → Under Verification');
                create_notification($this->pdo, (int) $app['user_id'], 'status_change', 'MSWDO has started reviewing application ' . $app['reference_no'] . '.', $id);
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** Called within the checklist transaction so verification and assessment save together. */
    private function syncEligibilityFromChecklist(array $staff, array $app): void
    {
        $id = (int) $app['id'];
        $lock = $this->pdo->prepare('SELECT * FROM applications WHERE id = ? FOR UPDATE');
        $lock->execute([$id]);
        $current = $lock->fetch();
        if (!$current || !in_array($current['status'], Application::IN_PROGRESS_STATUSES, true)) {
            return;
        }
        $required = array_filter($this->documents->checklistForReview($id,
            $current['category_id'] !== null ? (int) $current['category_id'] : null, $current['type'] === 'renewal'),
            fn($row) => !empty($row['is_required']));
        $complete = count($required) > 0 && !array_filter($required,
            fn($row) => empty($row['applicant_has']) || $row['verification_status'] !== 'Verified');
        if ($complete && $current['eligibility_status'] !== 'Eligible') {
            (new EligibilityAssessment($this->pdo))->saveMswd($id, (int) $current['user_id'], 'ELIGIBLE', self::AUTO_ELIGIBILITY_NOTES, (int) $staff['id']);
            $this->applications->setEligibility($id, 'Eligible', self::AUTO_ELIGIBILITY_NOTES);
            $this->applications->addHistory($id, $current['status'], 'MSWD eligibility assessment: Eligible. ' . self::AUTO_ELIGIBILITY_NOTES, $staff['full_name'], (int) $staff['id']);
            $this->audit($staff, 'Eligibility Assessment', 'application', $id, $current['reference_no'] . ' → Eligible (required documents verified)');
        } elseif (!$complete && $current['eligibility_status'] === 'Eligible'
            && $current['eligibility_remarks'] === self::AUTO_ELIGIBILITY_NOTES) {
            $this->applications->setEligibility($id, 'Not Assessed', null);
            $this->applications->addHistory($id, $current['status'], 'Automatic eligibility assessment cleared: required documents need verification.', $staff['full_name'], (int) $staff['id']);
            $this->audit($staff, 'Eligibility Assessment', 'application', $id, $current['reference_no'] . ' → Not Assessed (verification incomplete)');
        }
    }

    /**
     * Moves the application through the controlled workflow
     * (Application::TRANSITIONS). A reason is required to request
     * corrections or reject; approval requires every item on
     * approvalChecklist() to pass. $expectedStatus is the status the staff
     * member was looking at -- if it changed meanwhile nothing is saved.
     * Every change is written to the status history; approve / reject /
     * correction are also stored as decisions. Returns an error, or null.
     */
    public function updateStatus(array $staff, array $app, string $newStatus, string $remarks, string $expectedStatus = ''): ?string
    {
        if ($newStatus === 'ID Released') {
            return 'Use the ID release section to confirm that the ID was handed to the applicant.';
        }
        if (!in_array($newStatus, Application::STATUSES, true)) {
            return 'Select a valid status.';
        }
        if ($expectedStatus !== '' && $expectedStatus !== $app['status']) {
            return 'This application was updated by someone else (now ' . $app['status'] . '). Review it again before deciding.';
        }
        if (!in_array($newStatus, Application::TRANSITIONS[$app['status']] ?? [], true)) {
            return $newStatus === $app['status']
                ? 'The application is already ' . $newStatus . '.'
                : 'An application that is ' . $app['status'] . ' cannot be moved to ' . $newStatus . '.';
        }
        $remarks = trim($remarks);
        if (mb_strlen($remarks) > 500) {
            return 'Remarks are too long (up to 500 characters).';
        }
        if ($remarks === '' && in_array($newStatus, Application::REASON_REQUIRED, true)) {
            return $newStatus === 'Rejected'
                ? 'Give the reason for rejecting -- it is sent to the applicant.'
                : 'Say what the applicant needs to correct -- it is sent to them.';
        }
        if ($newStatus === 'Approved') {
            $unmet = array_filter($this->approvalChecklist($app), fn($item) => !$item['ok']);
            if ($unmet) {
                return 'Cannot approve yet: ' . implode('; ', array_map(fn($item) => $item['label'] . ($item['detail'] ? ' (' . $item['detail'] . ')' : ''), $unmet)) . '.';
            }
        }
        $id = (int) $app['id'];

        $reason = $remarks !== '' ? $remarks : null;
        $this->pdo->beginTransaction();
        try {
            $this->applications->setReviewedStatus($id, $newStatus, (int) $staff['id']);
            $this->applications->addHistory($id, $newStatus, $reason, $staff['full_name'], (int) $staff['id']);
            if (isset(self::DECISIONS[$newStatus])) {
                $this->applications->recordDecision($id, self::DECISIONS[$newStatus], $reason, (int) $staff['id']);
            }
            // A rejected application no longer needs its booked appointment: free the seat.
            $appt = $this->appointments->interviewFor($id);
            if ($newStatus === 'Rejected' && $appt && $appt['status'] === 'BOOKED') {
                $this->appointments->cancel((int) $appt['id'], (int) $staff['id'], 'Application rejected');
            }
            // The QR slip follows the decision: it expires with the released ID, and a rejection revokes it.
            $expires = $this->pdo->prepare('SELECT id_expires_at FROM applications WHERE id = ?');
            $expires->execute([$id]);
            (new QrSlipService($this->pdo))->onStatusChange($app, $newStatus, $expires->fetchColumn() ?: null);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        (new SMSService($this->pdo))->notifyStatusChange($id, $app['mobile'], $app['reference_no'], $newStatus, $remarks);
        create_notification(
            $this->pdo, (int) $app['user_id'], self::NOTIFICATION_TYPES[$newStatus] ?? 'status_change',
            'Application ' . $app['reference_no'] . ' is now ' . $newStatus . '.' . ($remarks !== '' ? ' Note: ' . $remarks : ''),
            $id
        );
        $this->audit($staff, self::AUDIT_ACTIONS[$newStatus] ?? 'Status Change', 'application', $id, $app['reference_no'] . ' → ' . $newStatus);
        return null;
    }

    /** Verdicts the applicant must be told the reason for. */
    private const REMARKS_REQUIRED = ['NEEDS_CORRECTION', 'REJECTED'];

    /**
     * Sets one document's verification status. Every change is recorded in
     * the document's status trail (who, when, from -> to, remarks), stamps
     * the verifier on the document, writes the audit log, and tells the
     * applicant (in-app and by SMS) when a document needs correction or was
     * rejected.
     *
     * $expectedStatus is the status the staff member saw when they chose the
     * verdict; if someone else changed it meanwhile nothing is saved, so a
     * verdict is never made on stale information.
     *
     * Returns an error message, or null on success (including "no change").
     */
    public function verifyDocument(array $staff, array $app, int $documentId, string $status, string $remarks, string $expectedStatus): ?string
    {
        if (!in_array($status, Document::VERIFICATION_STATUSES, true)) {
            return 'Choose a valid document status.';
        }
        if (!in_array($app['status'], Application::IN_PROGRESS_STATUSES, true)) {
            return 'Documents can no longer be changed: this application is ' . $app['status'] . '.';
        }
        $doc = $this->documents->findInApplication($documentId, (int) $app['id']);
        if (!$doc) {
            return 'That document is not part of this application.';
        }
        $remarks = trim($remarks);
        if (mb_strlen($remarks) > 500) {
            return 'Remarks are too long (up to 500 characters).';
        }
        if ($remarks === '' && in_array($status, self::REMARKS_REQUIRED, true)) {
            return 'Add a remark telling the applicant what is wrong with the document.';
        }
        if ($expectedStatus !== '' && $expectedStatus !== $doc['verification_status']) {
            return 'This document was updated by someone else (now ' . document_status_label($doc['verification_status']) . '). Review it again before saving.';
        }
        $remarks = $remarks !== '' ? $remarks : null;
        if ($status === $doc['verification_status'] && $remarks === $doc['staff_remarks']) {
            return null;
        }

        $this->pdo->beginTransaction();
        try {
            $this->documents->setVerification($documentId, $status, $remarks, (int) $staff['id']);
            $this->documents->addStatusHistory($documentId, (int) $app['id'], $doc['doc_key'], $doc['verification_status'], $status, $remarks, $staff);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $label = $this->documents->requirementLabel($doc['doc_key']) ?? $doc['doc_key'];
        $this->audit($staff, 'Document Verification', 'application_document', $documentId,
            $app['reference_no'] . ' · ' . $doc['doc_key'] . ': ' . $doc['verification_status'] . ' → ' . $status . ($remarks !== null ? ' (' . $remarks . ')' : ''));
        if (in_array($status, self::REMARKS_REQUIRED, true)) {
            create_notification(
                $this->pdo, (int) $app['user_id'], 'correction',
                'Your document "' . $label . '" for ' . $app['reference_no'] . ' ' . ($status === 'REJECTED' ? 'was rejected' : 'needs correction') . ': '
                    . rtrim($remarks, " .") . '. Please upload a new file under Upload Documents.',
                (int) $app['id']
            );
            (new SMSService($this->pdo))->notifyDocumentCorrection((int) $app['id'], $app['mobile'], $app['reference_no'], $label, (string) $remarks);
        }
        return null;
    }

    /** Save an independent checklist verdict for one applicable requirement. */
    public function reviewChecklistItem(array $staff, array $app, string $docKey, string $status, string $remarks): ?string
    {
        if (!in_array($status, Document::CHECKLIST_STATUSES, true)) {
            return 'Choose a valid checklist status.';
        }
        if (!in_array($app['status'], Application::IN_PROGRESS_STATUSES, true)) {
            return 'Checklist review is closed for this application.';
        }
        $appId = (int) $app['id'];
        $stmt = $this->pdo->prepare('SELECT applicant_has, verification_status, staff_remarks FROM application_document_checklists WHERE application_id = ? AND doc_key = ?');
        $stmt->execute([$appId, $docKey]);
        $saved = $stmt->fetch();
        if (!$saved) {
            return 'That requirement is not part of this application.';
        }
        $remarks = trim($remarks);
        if (mb_strlen($remarks) > 500) {
            return 'Remarks are too long (up to 500 characters).';
        }
        if ($status === 'Needs Correction' && $remarks === '') {
            return 'Add a remark explaining what needs correction.';
        }
        $remarksValue = $remarks !== '' ? $remarks : null;
        if ($saved['verification_status'] === $status && $saved['staff_remarks'] === $remarksValue) {
            return null;
        }
        $label = $this->documents->requirementLabel($docKey) ?? $docKey;
        $this->pdo->beginTransaction();
        try {
            $this->documents->updateChecklistReview($appId, $docKey, $status, $remarksValue, (int) $staff['id']);
            $description = 'Document "' . $label . '": ' . $status . ($remarksValue ? ' — ' . mb_substr($remarksValue, 0, 400) : '.');
            $this->applications->addHistory($appId, $app['status'], mb_substr($description, 0, 500), $staff['full_name'], (int) $staff['id']);
            $this->syncEligibilityFromChecklist($staff, $app);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        audit_log($this->pdo, (int) $staff['id'], $staff['full_name'], $staff['role'], 'Document Checklist Review', 'application', $appId, $docKey . ': ' . $status);
        if ($status === 'Needs Correction') {
            create_notification($this->pdo, (int) $app['user_id'], 'correction', 'The checklist requirement "' . $label . '" for ' . $app['reference_no'] . ' needs correction: ' . rtrim($remarks, ' .'), $appId);
            (new SMSService($this->pdo))->notifyDocumentCorrection($appId, $app['mobile'], $app['reference_no'], $label, $remarks);
        }
        return null;
    }

    /** Verify every applicable checklist document using the same history and eligibility workflow. */
    public function verifyAllChecklistItems(array $staff, array $app): ?string
    {
        $rows = $this->documents->checklistForReview((int) $app['id'],
            $app['category_id'] !== null ? (int) $app['category_id'] : null, ($app['type'] ?? '') === 'renewal');
        if (!$rows) {
            return 'There are no documents to verify.';
        }
        $submitted = [];
        foreach ($rows as $row) {
            if (empty($row['id'])) {
                return 'A checklist record is missing. Reload the application and try again.';
            }
            $submitted[$row['id']] = ['status' => 'Verified', 'remarks' => ''];
        }
        return $this->reviewChecklistItems($staff, $app, $submitted, true);
    }

    /** Save checklist verdicts together; checklist rows are scoped to this application. */
    public function reviewChecklistItems(array $staff, array $app, $submitted, bool $includeOptional = false): ?string
    {
        if (!is_array($submitted) || !in_array($app['status'], Application::IN_PROGRESS_STATUSES, true)) {
            return 'Checklist review is closed or the submitted checklist is invalid.';
        }
        $appId = (int) $app['id'];
        $categoryId = $app['category_id'] !== null ? (int) $app['category_id'] : null;
        $required = array_values(array_filter(
            $this->documents->checklistForReview($appId, $categoryId, ($app['type'] ?? '') === 'renewal'),
            fn($row) => $includeOptional || !empty($row['is_required'])
        ));
        $expected = [];
        foreach ($required as $row) {
            if (empty($row['id'])) {
                return 'A required checklist record is missing. Reload the application and try again.';
            }
            $expected[(string) $row['id']] = $row;
        }
        if (count($submitted) !== count($expected)) {
            return 'The checklist changed or is incomplete. Reload the application and review every required document.';
        }

        $changes = [];
        foreach ($submitted as $id => $values) {
            $key = (string) $id;
            if (!ctype_digit($key) || !isset($expected[$key]) || !is_array($values)
                || !is_string($values['status'] ?? null) || !is_string($values['remarks'] ?? null)) {
                return 'The submitted checklist contains an invalid document.';
            }
            $row = $expected[$key];
            $status = $values['status'];
            if (!in_array($status, Document::CHECKLIST_STATUSES, true)) {
                return 'Choose a valid status for ' . $row['label'] . '.';
            }
            $remarks = trim($values['remarks']);
            if (mb_strlen($remarks) > 500) {
                return 'Remarks for ' . $row['label'] . ' are too long (up to 500 characters).';
            }
            if ($status === 'Needs Correction' && $remarks === '') {
                return 'Add a remark explaining what needs correction for ' . $row['label'] . '.';
            }
            $remarksValue = $remarks !== '' ? $remarks : null;
            if ($row['verification_status'] !== $status || $row['staff_remarks'] !== $remarksValue) {
                $changes[] = ['id' => (int) $key, 'key' => $row['doc_key'], 'label' => $row['label'],
                    'old_status' => $row['verification_status'], 'status' => $status,
                    'old_remarks' => $row['staff_remarks'], 'remarks' => $remarksValue];
            }
        }
        try {
            $this->pdo->beginTransaction();
            $update = $this->pdo->prepare('UPDATE application_document_checklists SET verification_status = ?, staff_remarks = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND application_id = ?');
            $confirm = $this->pdo->prepare('SELECT verification_status, staff_remarks FROM application_document_checklists WHERE id = ? AND application_id = ?');
            $applied = [];
            foreach ($changes as $change) {
                $update->execute([$change['status'], $change['remarks'], (int) $staff['id'], $change['id'], $appId]);
                if ($update->rowCount() !== 1) {
                    $confirm->execute([$change['id'], $appId]);
                    $current = $confirm->fetch();
                    // A repeated POST may arrive after the first identical save committed.
                    if ($current && $current['verification_status'] === $change['status'] && $current['staff_remarks'] === $change['remarks']) {
                        continue;
                    }
                    throw new RuntimeException('Checklist row no longer belongs to the application.');
                }
                $applied[] = $change;
                $description = 'Document "' . $change['label'] . '": ' . $change['status']
                    . ($change['remarks'] ? ' — ' . mb_substr($change['remarks'], 0, 400) : '.');
                $this->applications->addHistory($appId, $app['status'], mb_substr($description, 0, 500), $staff['full_name'], (int) $staff['id']);
            }
            $this->syncEligibilityFromChecklist($staff, $app);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log('Checklist verification save failed for application ' . $appId . ': ' . $e->getMessage());
            return 'Could not save document verification. No changes were saved; please try again.';
        }

        foreach ($applied as $change) {
            audit_log($this->pdo, (int) $staff['id'], $staff['full_name'], $staff['role'], 'Document Checklist Review', 'application', $appId, $change['key'] . ': ' . $change['old_status'] . ' → ' . $change['status']);
            if ($change['status'] === 'Needs Correction'
                && ($change['old_status'] !== 'Needs Correction' || $change['old_remarks'] !== $change['remarks'])) {
                create_notification($this->pdo, (int) $app['user_id'], 'correction', 'The checklist requirement "' . $change['label'] . '" for ' . $app['reference_no'] . ' needs correction: ' . rtrim((string) $change['remarks'], ' .'), $appId);
                (new SMSService($this->pdo))->notifyDocumentCorrection($appId, $app['mobile'], $app['reference_no'], $change['label'], (string) $change['remarks']);
            }
        }
        return null;
    }
    /**
     * Called after the applicant uploads a file. An application that is For
     * Revision goes back to Under Verification by itself once nothing is left
     * to fix -- no document still needs correction or is rejected, and no
     * required document is missing -- so staff don't have to resume the
     * review by hand. Returns true if it moved.
     */
    public function returnToReviewIfCorrected(array $applicant, array $app): bool
    {
        if ($app['status'] !== 'For Revision') {
            return false;
        }
        $id = (int) $app['id'];
        foreach ($this->documents->forReview($id, $app['category_id'] !== null ? (int) $app['category_id'] : null) as $d) {
            if (($d['id'] === null && $d['is_required']) || in_array($d['verification_status'] ?? '', self::REMARKS_REQUIRED, true)) {
                return false;
            }
        }

        $this->pdo->beginTransaction();
        try {
            if (!$this->applications->moveStatus($id, 'For Revision', 'Under Verification')) {
                $this->pdo->rollBack();
                return false;
            }
            $this->applications->addHistory($id, 'Under Verification', 'Corrections submitted by the applicant.', $applicant['full_name'], (int) $applicant['id']);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $this->audit($applicant, 'Corrections Submitted', 'application', $id, $app['reference_no'] . ' → Under Verification');
        create_notification($this->pdo, (int) $app['user_id'], 'status_change',
            'Your corrections for ' . $app['reference_no'] . ' were received. Your application is back with MSWD for review.', $id);
        (new NotificationService($this->pdo))->notifyStaff('corrections_submitted',
            $app['reference_no'] . ' (' . $app['full_name'] . ') submitted all requested corrections and is back in Under Verification.', $id);
        return true;
    }

    /**
     * What must be true before an application can be approved. Each item:
     * ['label' => ..., 'ok' => bool, 'detail' => what is missing, or ''].
     */
    public function approvalChecklist(array $app): array
    {
        $docs = $this->documents->checklistForReview((int) $app['id'], $app['category_id'] !== null ? (int) $app['category_id'] : null, $app['type'] === 'renewal');
        $missing = array_column(array_filter($docs, fn($d) => !$d['applicant_has'] && $d['is_required']), 'label');
        $unverified = array_column(array_filter($docs, fn($d) => $d['is_required'] && $d['verification_status'] !== 'Verified'), 'label');
        $appt = $this->appointments->interviewFor((int) $app['id']);
        $interviewDone = Appointment::interviewSuccessful($appt);
        $interviewDetail = $interviewDone ? '' : ($appt && $appt['status'] === 'BOOKED'
            ? 'record the interview result or cancel it'
            : ($appt && $appt['status'] === 'MISSED' ? 'the appointment was missed; schedule and complete an interview' : 'no completed interview outcome is recorded'));
        return [
            ['label' => 'All required documents marked available', 'ok' => !$missing, 'detail' => implode(', ', $missing)],
            ['label' => 'All required documents verified', 'ok' => !$unverified, 'detail' => implode(', ', $unverified)],
            ['label' => 'MSWD eligibility assessment: Eligible', 'ok' => $app['eligibility_status'] === 'Eligible', 'detail' => $app['eligibility_status'] === 'Eligible' ? '' : 'currently ' . $app['eligibility_status']],
            ['label' => 'Successful interview recorded', 'ok' => (bool) $interviewDone, 'detail' => Appointment::interviewOutcome($appt) === 'Unsuccessful' ? 'the interview was unsuccessful; review or schedule another interview' : $interviewDetail],
        ];
    }

    /**
     * Records MSWD's official eligibility assessment (Eligible / Not
     * Eligible) as a new eligibility_assessments row -- earlier assessments
     * are kept -- and updates the application's current snapshot. Notes are
     * required when not eligible. Returns an error message, or null.
     */
    public function assessEligibility(array $staff, array $app, string $status, string $remarks): ?string
    {
        if (!in_array($status, ['Eligible', 'Not Eligible'], true)) {
            return 'Choose Yes or No.';
        }
        if (!in_array($app['status'], Application::IN_PROGRESS_STATUSES, true)) {
            return 'The assessment can only change while the application is being processed (it is ' . $app['status'] . ').';
        }
        $remarks = trim($remarks);
        if (mb_strlen($remarks) > 500) {
            return 'Assessment notes are too long (up to 500 characters).';
        }
        if ($remarks === '' && $status === 'Not Eligible') {
            return 'Explain why the applicant is not eligible.';
        }
        $notes = $remarks !== '' ? $remarks : null;
        $latest = (new EligibilityAssessment($this->pdo))->mswdForApplication((int) $app['id'])[0] ?? null;
        $expectedResult = $status === 'Eligible' ? 'ELIGIBLE' : 'NOT_ELIGIBLE';
        if ($latest && $latest['preliminary_result'] === $expectedResult && $latest['result_reason'] === $notes
            && $app['eligibility_status'] === $status && ($app['eligibility_remarks'] ?? null) === $notes) {
            return null;
        }
        $this->pdo->beginTransaction();
        try {
            (new EligibilityAssessment($this->pdo))->saveMswd((int) $app['id'], (int) $app['user_id'], $status === 'Eligible' ? 'ELIGIBLE' : 'NOT_ELIGIBLE', $notes, (int) $staff['id']);
            $this->applications->setEligibility((int) $app['id'], $status, $notes);
            $this->applications->addHistory((int) $app['id'], $app['status'], mb_substr('MSWD eligibility assessment: ' . $status . ($notes ? '. ' . $notes : '.'), 0, 500), $staff['full_name'], (int) $staff['id']);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        $this->audit($staff, 'Eligibility Assessment', 'application', (int) $app['id'], $app['reference_no'] . ' → ' . $status . ($notes ? ' (' . $notes . ')' : ''));
        return null;
    }

    /** Adds an internal case note (never shown to the applicant). Returns an error message, or null. */
    public function addNote(array $staff, array $app, string $note): ?string
    {
        $note = trim($note);
        if ($note === '') {
            return 'Write the note first.';
        }
        if (mb_strlen($note) > 1000) {
            return 'The note is too long (up to 1000 characters).';
        }
        $this->applications->addNote((int) $app['id'], $note, $staff);
        $this->audit($staff, 'Case Note Added', 'application', (int) $app['id'], $app['reference_no']);
        return null;
    }

    /** $scheduledAt is the raw datetime-local value (Y-m-d\TH:i). */
    public function scheduleInterview(array $staff, array $app, string $scheduledAt, string $location, string $notes, string $messageType = 'automatic', string $customMessage = ''): ?string
    {
        if (!in_array($app['status'], Application::IN_PROGRESS_STATUSES, true)) {
            return 'An interview can only be scheduled while the application is being processed (it is ' . $app['status'] . ').';
        }
        $dt = DateTime::createFromFormat('!Y-m-d\TH:i', $scheduledAt);
        if (!$dt || $dt->format('Y-m-d\TH:i') !== $scheduledAt) {
            return 'Select a valid interview date and time.';
        }
        if ($dt->getTimestamp() <= time()) {
            return 'The interview must be scheduled in the future.';
        }
        if ($location === '') {
            return 'Enter an interview location.';
        }
        if (mb_strlen($location) > 255) {
            return 'Location is too long (up to 255 characters).';
        }
        if (mb_strlen($notes) > 500) {
            return 'Notes are too long (up to 500 characters).';
        }
        if (!in_array($messageType, ['automatic', 'custom'], true)) {
            return 'Choose Automatic Message or Custom Message.';
        }
        $customMessage = trim($customMessage);
        if ($messageType === 'custom' && $customMessage === '') {
            return 'Enter the custom SMS message before scheduling.';
        }
        if ($messageType === 'custom' && mb_strlen($customMessage) > 500) {
            return 'The custom SMS message is too long (up to 500 characters).';
        }
        $id = (int) $app['id'];
        $scheduledAtSql = $dt->format('Y-m-d H:i:s');
        $when = $dt->format('M j, Y g:i A');

        $sms = new SMSService($this->pdo);
        $message = $messageType === 'custom' ? $customMessage : null;
        $automaticMessage = $messageType === 'automatic' ? $sms->interviewScheduleMessage($app['full_name'], $app['reference_no'], $scheduledAtSql, $location) : null;
        if ($automaticMessage !== null && mb_strlen($automaticMessage) > 500) {
            return 'The automatic SMS exceeds 500 characters. Shorten the interview location and try again.';
        }
        $expectedMessage = $messageType === 'custom' ? $customMessage : $automaticMessage;
        $expectedKey = $messageType === 'custom' ? 'interview_custom' : 'interview_scheduled';
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT status FROM applications WHERE id = ? FOR UPDATE');
            $lock->execute([$id]);
            $currentStatus = $lock->fetchColumn();
            if ($currentStatus === false || !in_array($currentStatus, Application::IN_PROGRESS_STATUSES, true)) {
                $this->pdo->rollBack();
                return 'An interview can only be scheduled while the application is being processed. Refresh and review its current status.';
            }
            $existing = $this->appointments->interviewFor($id);
            if ($existing && $existing['status'] === 'COMPLETED') {
                $this->pdo->rollBack();
                return 'The interview for this application is already completed.';
            }
            $sameSchedule = $existing && $existing['status'] === 'BOOKED'
                && $existing['scheduled_at'] === $scheduledAtSql && $existing['location'] === $location
                && (string) ($existing['notes'] ?? '') === ($notes !== '' ? $notes : '');
            $latestSms = $this->appointments->interviewSms($id);
            // The application row lock makes repeated/concurrent clicks serialize here.
            if ($sameSchedule && $latestSms && $latestSms['message'] === $expectedMessage && $latestSms['template_key'] === $expectedKey) {
                $this->pdo->rollBack();
                if ($latestSms['provider_status'] === 'disabled') {
                    flash('The interview is saved, but its SMS was skipped because interview notifications are disabled. Enable notifications before using Retry SMS.');
                } elseif ($latestSms['status'] === 'FAILED') {
                    flash('The interview is saved, but its SMS failed. Use Retry SMS below to retry this same message.');
                } else {
                    flash(!empty($latestSms['test_mode']) ? 'Interview schedule saved. SMS was logged in test mode and was not sent.' : 'Interview schedule and SMS submission are already recorded; no duplicate text was sent.');
                }
                return null;
            }

            $this->appointments->upsertStaffScheduled($id, $scheduledAtSql, $location, $notes !== '' ? $notes : null, (int) $staff['id']);
            $this->applications->setReviewedStatus($id, 'Interview Scheduled', (int) $staff['id']);
            $this->applications->addHistory($id, 'Interview Scheduled', 'Interview at ' . $location . ' on ' . $when, $staff['full_name'], (int) $staff['id']);
            $smsResult = $sms->notifyInterviewScheduled($id, $app['mobile'], $app['reference_no'], $scheduledAtSql, $location, $app['full_name'], $message);
            $this->appointments->linkSmsLog($id, $smsResult['id'] !== null ? (int) $smsResult['id'] : null);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        if ($smsResult['status'] === 'SENT') {
            flash(!empty($smsResult['test_mode']) ? 'Interview schedule saved. SMS was logged in test mode and was not sent.' : 'Interview schedule saved and SMS accepted by the configured provider.');
        } elseif (($smsResult['provider_status'] ?? '') === 'disabled') {
            flash('Interview schedule saved. SMS was not sent because interview notifications are disabled in settings.');
        } elseif ($smsResult['status'] === 'FAILED') {
            flash('Interview schedule saved, but SMS submission failed: ' . ($smsResult['error'] ?: 'provider error') . ' Use Retry SMS below to retry the same message.');
        } else {
            flash('Interview schedule saved. SMS was not sent.');
        }
        create_notification(
            $this->pdo, (int) $app['user_id'], 'appointment_confirmation',
            'An appointment for ' . $app['reference_no'] . ' has been scheduled: ' . $when . ' at ' . $location . '.',
            $id
        );
        $this->audit($staff, 'Interview Scheduling', 'application', $id, $location . ' on ' . $when . ' (' . strtoupper($messageType) . ' SMS)');
        return null;
    }

    public function recordInterviewOutcome(array $staff, int $applicationId, string $outcome, string $notes): ?string
    {
        if (!in_array($outcome, ['Successful', 'Unsuccessful'], true)) {
            return 'Choose Successful or Unsuccessful.';
        }
        $notes = trim($notes);
        return $this->recordInterviewResult($staff, $applicationId, $outcome . ($notes !== '' ? ': ' . $notes : ''));
    }

    public function recordInterviewResult(array $staff, int $applicationId, string $result): ?string
    {
        if ($result === '') {
            return 'Enter the interview result/notes before saving.';
        }
        if (mb_strlen($result) > 500) {
            return 'The result is too long (up to 500 characters).';
        }
        $appt = $this->appointments->interviewFor($applicationId);
        if (!$appt || !in_array($appt['status'], ['BOOKED', 'COMPLETED'], true)) {
            return 'There is no booked appointment to record a result for.';
        }
        if ($appt['status'] === 'COMPLETED' && trim((string) ($appt['result'] ?? '')) === trim($result)) {
            return null;
        }
        if ($appt['status'] === 'BOOKED' && substr((string) $appt['scheduled_at'], 0, 10) > date('Y-m-d')) {
            return 'The interview is on ' . format_date_long(substr((string) $appt['scheduled_at'], 0, 10)) . '. Record its result on or after that day.';
        }
        $this->appointments->recordResult($applicationId, $result);
        $app = $this->applications->findForReview($applicationId);
        if ($app) {
            $this->applications->addHistory($applicationId, $app['status'], mb_substr('Interview result recorded: ' . trim($result), 0, 500), $staff['full_name'], (int) $staff['id']);
        }
        $this->audit($staff, 'Interview Result Recorded', 'application', $applicationId, $result);
        return null;
    }

    private function audit(array $staff, string $action, string $entityType, int $entityId, string $details): void
    {
        audit_log($this->pdo, (int) $staff['id'], $staff['full_name'], $staff['role'], $action, $entityType, $entityId, $details);
    }
}
