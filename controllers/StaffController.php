<?php
require_once __DIR__ . '/Controller.php';
require_once __DIR__ . '/../services/AuthService.php';
require_once __DIR__ . '/../services/ApplicationReviewService.php';
require_once __DIR__ . '/../services/AppointmentService.php';
require_once __DIR__ . '/../services/RenewalService.php';
require_once __DIR__ . '/../services/QrSlipService.php';
require_once __DIR__ . '/../services/QrVerificationService.php';
require_once __DIR__ . '/../services/IdReleaseService.php';
require_once __DIR__ . '/NotificationController.php';
require_once __DIR__ . '/ReportController.php';
require_once __DIR__ . '/../services/HelpAssistantService.php';
require_once __DIR__ . '/../services/RecordService.php';
require_once __DIR__ . '/../models/Applicant.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Application.php';
require_once __DIR__ . '/../models/Document.php';
require_once __DIR__ . '/../models/Appointment.php';

/**
 * MSWD pages under staff/ and shared management pages with admin/ entry points.
 * Entry points check the portal role; every action also checks its permission.
 */
class StaffController extends Controller
{
    private const PER_PAGE = 20;

    private array $staff;

    public function __construct(PDO $pdo, bool $adminPortal = false)
    {
        parent::__construct($pdo);
        $auth = new AuthService($pdo);
        if ($auth->currentUser()) {
            $this->redirectTo('applicant/dashboard.php');
        }
        $staff = $auth->currentStaff();
        if (!$staff) {
            // Someone scanned a slip's QR while signed out: remember it, verify right after staff login.
            if (str_ends_with((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/staff/qr_verify.php')
                && is_string($_GET['t'] ?? null) && preg_match('/^[0-9a-f]{64}$/i', $_GET['t'])) {
                $_SESSION['pending_qr_token'] = strtolower($_GET['t']);
            }
            $this->redirectTo('mswd-login.php');
        }
        if ($staff['role'] !== ($adminPortal ? 'admin' : 'mswd_social_worker')) {
            $this->redirectTo($staff['role'] === 'admin' ? 'admin/dashboard.php' : 'staff/dashboard.php');
        }
        $this->staff = $staff;
        $accountPage = role_portal_path($staff, 'account.php');
        $this->requireOwnPassword($staff, $accountPage, str_ends_with((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/' . $accountPage));
    }

    public function dashboard(): void
    {
        $this->authorize($this->staff, 'staff.dashboard', 'admin/dashboard.php');
        $applications = new Application($this->pdo);
        $documents = new Document($this->pdo);
        $appointments = new Appointment($this->pdo);
        $todays = $appointments->todaysInterviews();
        $this->page('MSWD Dashboard', 'views/staff/dashboard.php', [
            'stats' => [
                'pending'           => $applications->countWhere("type = 'new' AND status = 'Pending Review'"),
                'underVerification' => $applications->countWhere("status = 'Under Verification'"),
                'forRevision'       => $applications->countWhere("status = 'For Revision'"),
                'pendingRenewals'   => $applications->countWhere("type = 'renewal' AND status = 'Pending Review'"),
                'todaysAppointments' => count($todays),
                'approved'          => $applications->countWhere("status IN ('Approved','ID Released')"),
                'rejected'          => $applications->countWhere("status = 'Rejected'"),
            ],
            'docBacklog' => $documents->verificationBacklog(),
            'verificationQueue' => $documents->verificationQueue(5),
            'todaysAppointments' => $todays,
            'upcomingInterviews' => $appointments->upcomingInterviews(5),
            'recentlyProcessed' => $applications->recentlyReviewed(),
        ]);
    }

    /** staff/index.php: the application queue with filters. */
    public function applications(): void
    {
        $this->authorize($this->staff, 'applications.process', 'admin/applications.php');
        $filters = [
            'status' => (string) ($_GET['status'] ?? 'ALL'),
            'type' => (string) ($_GET['type'] ?? 'ALL'),
            'barangay' => trim((string) ($_GET['barangay'] ?? '')),
            'q' => trim((string) ($_GET['q'] ?? '')),
        ];
        $result = (new Application($this->pdo))->search($filters, (int) ($_GET['page'] ?? 1), self::PER_PAGE);
        $this->page('Applications', 'views/staff/applications/index.php', [
            'filters' => $filters,
            'statuses' => Application::STATUSES,
            'apps' => $result['rows'],
            'pager' => $result,
        ]);
    }

    /** staff/application.php?id=: one application, with every review action. */
    public function application(): void
    {
        $this->authorize($this->staff, 'applications.process', 'admin/application_view.php?id=' . (int) ($_GET['id'] ?? 0));
        $id = (int) ($_GET['id'] ?? 0);
        $applications = new Application($this->pdo);
        $app = $applications->findForReview($id);
        if (!$app) {
            http_response_code(404);
            die('Application not found.');
        }

        $review = new ApplicationReviewService($this->pdo);
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $review->startReviewOnOpen($this->staff, $app);
            $app = $applications->findForReview($id);
        }
        $errors = ['status' => '', 'interview' => '', 'assessment' => '', 'release' => '', 'note' => '', 'documents' => []];
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_require();
            $errors = $this->handleReviewAction($app) + $errors;
        }
        [$renewalOf, $renewalChanges] = $this->renewalContext($app);
        $appointmentModel = new Appointment($this->pdo);

        $this->page('Application ' . $app['reference_no'], 'views/staff/applications/show.php', [
            'app' => $app,
            'statusError' => $errors['status'],
            'assessmentError' => $errors['assessment'],
            'releaseError' => $errors['release'],
            'documentErrors' => $errors['documents'],
            'interviewError' => $errors['interview'],
            'noteError' => $errors['note'],
            'flashMessage' => take_flash(),
            'allowedTransitions' => Application::TRANSITIONS[$app['status']] ?? [],
            'approvalChecklist' => $review->approvalChecklist($app),
            'interview' => $appointmentModel->interviewFor($id),
            'interviewSms' => $appointmentModel->interviewSms($id),
            'idReleaseSchedule' => (new IdReleaseService($this->pdo))->scheduleFor($id),
            'defaultReleaseLocation' => get_setting($this->pdo, 'appointment_location', 'MSWDO Virac office'),
            'children' => $applications->children($id),
            'submittedChecklist' => (new Document($this->pdo))->checklistForReview($id, $app['category_id'] !== null ? (int) $app['category_id'] : null, ($app['type'] ?? '') === 'renewal'),
            'history' => $applications->history($id),
            'decisions' => $applications->decisions($id),
            'notes' => $applications->notes($id),
            'renewalOf' => $renewalOf,
            'renewalChanges' => $renewalChanges,
        ]);
    }

    /** Runs one review action; redirects back on success, else returns an action-specific error. */
    private function handleReviewAction(array $app): array
    {
        $review = new ApplicationReviewService($this->pdo);
        $id = (int) $app['id'];
        $post = fn(string $key) => is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : '';
        $back = 'staff/application.php?id=' . $id;

        switch ($post('form_action') ?: 'update_status') {
            case 'schedule_id_release':
                $error = (new IdReleaseService($this->pdo))->schedule($this->staff, $app, $post('release_date'), $post('release_time'), $post('release_location'), $post('sms_message_type') ?: 'automatic', $post('release_custom_message'));
                if ($error) {
                    return ['release' => $error];
                }
                $back .= '#id-release';
                break;
            case 'retry_id_release_sms':
                $releaseService = new IdReleaseService($this->pdo);
                $schedule = $releaseService->scheduleFor($id);
                $logId = (int) $post('sms_log_id');
                if ($app['status'] !== 'Approved' || !$schedule || (int) $schedule['sms_log_id'] !== $logId || $schedule['sms_status'] !== 'FAILED') {
                    return ['release' => 'That failed SMS is no longer the current release notification. Refresh and review the latest schedule.'];
                }
                [$retryError, $retryResult] = (new SMSService($this->pdo))->retry($logId);
                if ($retryError) {
                    return ['release' => $retryError];
                }
                if ($retryResult['status'] === 'SENT' && !empty($retryResult['test_mode'])) {
                    flash('SMS retry was logged in test mode and was not sent.');
                } elseif ($retryResult['status'] === 'SENT') {
                    flash('The SMS provider accepted the release notification; delivery is not confirmed here.');
                } else {
                    flash('SMS retry failed: ' . ($retryResult['error'] ?: 'provider error'));
                }
                $back .= '#id-release';
                break;
            case 'confirm_id_release':
                if ($post('confirm_handover') !== '1') {
                    return ['release' => 'Confirm that the physical ID was handed to the applicant before recording release.'];
                }
                $error = (new IdReleaseService($this->pdo))->confirmRelease($this->staff, $app);
                if ($error) {
                    return ['release' => $error];
                }
                $back .= '#id-release';
                break;
            case 'schedule_interview':
                // The form uses separate HTML date and time inputs. Combine
                // their native values into the datetime-local format expected
                // by ApplicationReviewService (Y-m-d\TH:i).
                $interviewDate = $post('interview_date');
                $interviewTime = $post('interview_time');
                $scheduledAt = $interviewDate !== '' && $interviewTime !== ''
                    ? $interviewDate . 'T' . $interviewTime
                    : '';
                $error = $review->scheduleInterview($this->staff, $app, $scheduledAt, $post('location'), $post('notes'), $post('sms_message_type') ?: 'automatic', $post('custom_message'));
                if ($error) {
                    return ['interview' => $error];
                }
                $back .= '#interview';
                break;
            case 'retry_interview_sms':
                $appointment = (new Appointment($this->pdo))->interviewFor($id);
                $sms = new SMSService($this->pdo);
                $latest = $appointment ? (new Appointment($this->pdo))->interviewSms($id) : null;
                $logId = (int) $post('sms_log_id');
                if (!$appointment || $appointment['status'] !== 'BOOKED' || !$latest || (int) $latest['id'] !== $logId || $latest['status'] !== 'FAILED') {
                    return ['interview' => 'That failed SMS is no longer the current interview notification. Refresh the page and review the latest schedule.'];
                }
                if (!$sms->templateEnabled('interview_scheduled')) {
                    return ['interview' => 'Interview SMS notifications are disabled. Enable them in Notification Settings before retrying.'];
                }
                [$retryError, $retryResult] = $sms->retry($logId);
                if ($retryError) {
                    return ['interview' => $retryError];
                }
                if ($retryResult['status'] === 'SENT') {
                    flash(!empty($retryResult['test_mode']) ? 'SMS retry was logged in test mode and was not sent.' : 'Semaphore accepted the interview SMS retry.');
                } else {
                    flash('SMS retry failed: ' . ($retryResult['error'] ?: 'provider error'));
                }
                $back .= '#interview';
                break;
            case 'record_result':
                $error = $review->recordInterviewOutcome($this->staff, $id, $post('interview_outcome'), $post('interview_notes'));
                if ($error) {
                    return ['interview' => $error];
                }
                $back .= '#interview';
                break;
            case 'assess_eligibility':
            case 'update_eligibility':
                $assessmentNotes = $post('assessment_remarks') ?: $post('eligibility_remarks');
                $error = $review->assessEligibility($this->staff, $app, $post('eligibility_status'), $assessmentNotes);
                if ($error) {
                    return ['assessment' => $error];
                }
                $back .= '#decision';
                break;
            case 'update_document_status':
                $docId = (int) $post('doc_id');
                $error = $review->verifyDocument($this->staff, $app, $docId, $post('verification_status'), $post('staff_remarks'), $post('expected_status'));
                if ($error) {
                    return ['documents' => [$docId => $error]];
                }
                $back .= '#doc-' . $docId;
                break;
            case 'verify_all_documents':
                $error = $review->verifyAllChecklistItems($this->staff, $app);
                if ($error) {
                    return ['documents' => ['checklist' => $error]];
                }
                flash('All documents verified successfully.');
                $back .= '#checklist';
                break;
            case 'save_checklist_reviews':
                $error = $review->reviewChecklistItems($this->staff, $app, $_POST['checklist_items'] ?? null);
                if ($error) {
                    return ['documents' => ['checklist' => $error]];
                }
                flash('Document verification saved successfully.');
                $back .= '#checklist';
                break;
            case 'add_note':
                $error = $review->addNote($this->staff, $app, $post('note'));
                if ($error) {
                    return ['note' => $error];
                }
                $back .= '#notes';
                break;
            default:
                $status = $post('status');
                $error = $review->updateStatus($this->staff, $app, $status, $post('remarks'), $post('expected_status'));
                if ($error) {
                    return ['status' => $error];
                }
                flash('Application is now ' . $status . '. The applicant was notified.');
                $back .= '#decision';
        }
        $this->redirectTo($back);
        return [];
    }
    /** staff/applicants.php: applicant account search. */
    public function applicants(): void
    {
        $this->authorize($this->staff, 'applicants.view');
        $records = new RecordService($this->pdo);
        $filters = $records->filters($_GET);
        $result = $records->search($filters, (int) ($_GET['page'] ?? 1), $this->staff['role'] === 'admin' ? 10 : RecordService::PER_PAGE);
        $this->page('Applicant Records', 'views/staff/applicants/index.php', [
            'filters' => $filters,
            'barangays' => $records->barangays(),
            'statuses' => Application::STATUSES,
            'applicants' => $result['rows'],
            'pager' => $result,
        ]);
    }

    /**
     * staff/applicant_view.php?id=[&app=][&hpage=]: everything on file for one
     * applicant -- profile, applications, renewals, documents, appointments,
     * decisions and status history (optionally for one application only).
     */
    public function applicant(): void
    {
        $this->authorize($this->staff, 'applicants.view');
        $record = (new RecordService($this->pdo))->record((int) ($_GET['id'] ?? 0), (int) ($_GET['app'] ?? 0), (int) ($_GET['hpage'] ?? 1));
        if (!$record) {
            http_response_code(404);
            die('Applicant not found.');
        }
        $this->page($record['applicant']['full_name'] . ' — Applicant Record', 'views/staff/applicants/show.php', $record);
    }

    /**
     * staff/appointments.php: the schedule (needs-outcome, today, upcoming,
     * slots). MSWD staff can also cancel an appointment or mark a no-show;
     * administrators see the schedule read-only.
     */
    public function appointments(): void
    {
        $this->authorize($this->staff, 'appointments.view');
        $canManage = can($this->staff, 'applications.process');
        $error = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_require();
            $this->authorize($this->staff, 'applications.process', 'staff/appointments.php');
            $post = fn(string $key) => is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : '';
            $outcome = ['cancel' => 'CANCELLED', 'missed' => 'MISSED'][$post('action')] ?? '';
            $error = (new AppointmentService($this->pdo))->staffSetOutcome($this->staff, (int) $post('appointment_id'), $outcome, $post('reason')) ?? '';
            if ($error === '') {
                flash($outcome === 'CANCELLED' ? 'Appointment cancelled and the applicant notified.' : 'Appointment marked as missed and the applicant notified.');
                $this->redirectTo('staff/appointments.php');
            }
        }

        $appointments = new Appointment($this->pdo);
        $slotsByDate = [];
        foreach ($appointments->upcomingSlotsWithBookings(100) as $s) {
            $slotsByDate[$s['slot_date']][] = $s;
        }
        $this->page('Appointment Management', 'views/staff/appointments/index.php', [
            'error' => $error,
            'flashMessage' => take_flash(),
            'canManage' => $canManage,
            'awaitingOutcome' => $appointments->awaitingOutcome(),
            // Past-but-still-booked ones are already listed under Needs Outcome.
            'todayAppointments' => array_values(array_filter($appointments->todaysInterviews(), fn($iv) => !($iv['status'] === 'BOOKED' && strtotime($iv['scheduled_at']) < time()))),
            'upcomingByDate' => $appointments->laterInterviewsByDate(),
            'slotsByDate' => $slotsByDate,
        ]);
    }

    public function appointmentSlots(): void
    {
        $this->authorize($this->staff, 'appointments.slots');
        $service = new AppointmentService($this->pdo);
        $error = '';
        $success = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_require();
            $post = fn(string $key) => is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : '';
            $action = $post('action');
            if ($action === 'create') {
                $error = $service->createSlot($this->staff, $post('slot_date'), $post('start_time'), $post('end_time'), (int) $post('capacity')) ?? '';
                $success = $error === '' ? 'Slot added.' : '';
            } elseif ($action === 'cancel') {
                [$cancelError, $cancelled] = $service->cancelSlot($this->staff, (int) $post('slot_id'), $post('reason'));
                $error = $cancelError ?? '';
                $success = $error === '' ? 'Slot cancelled' . ($cancelled ? " and $cancelled booking(s) cancelled — those applicants were notified." : '.') : '';
            } elseif ($action === 'delete') {
                $error = $service->deleteSlot($this->staff, (int) $post('slot_id')) ?? '';
                $success = $error === '' ? 'Slot removed.' : '';
            }
        }

        $this->page('Appointment Slots', 'views/staff/appointments/slots.php', [
            'error' => $error,
            'success' => $success,
            'slots' => (new Appointment($this->pdo))->upcomingSlotsWithBookings(),
            'defaultCapacity' => (int) get_setting($this->pdo, 'appointment_default_capacity', '10'),
        ]);
    }

    /** staff/qr_verify.php: jump to an application by scanned QR (a status URL) or typed reference number. */
    public function qrVerify(): void
    {
        $this->authorize($this->staff, 'qr.verify');
        // ?t= comes from a phone camera opening the slip's QR link; ?code= from the in-page scanner or the manual field.
        $get = fn(string $k) => is_string($_GET[$k] ?? null) ? trim($_GET[$k]) : '';
        $input = $get('t') !== '' ? $get('t') : ($get('code') !== '' ? $get('code') : $get('ref'));
        $service = new QrVerificationService($this->pdo);
        $outcome = null;
        $error = '';
        if ($input !== '') {
            if ($service->throttled()) {
                $error = 'Too many unrecognised codes in a short time. Please wait a few minutes before trying again.';
                audit_log($this->pdo, (int) $this->staff['id'], $this->staff['full_name'], $this->staff['role'], 'QR Verification Throttled', 'qr_slip', null, 'Blocked after too many unrecognised codes; tried: ' . mb_substr(preg_replace('/\s+/', ' ', $input), 0, 60));
            } else {
                $outcome = $service->verify($this->staff, $input);
            }
        }
        $verificationCard = null;
        if (!empty($outcome['app']) && can($this->staff, 'applicants.view')) {
            $applications = new Application($this->pdo);
            $verificationCard = $applications->findForReview((int) $outcome['app']['id']);
            if ($verificationCard) {
                $verificationCard['children'] = $applications->children((int) $outcome['app']['id']);
            }
        }
        $this->page('QR Verification', 'views/staff/qr/verify.php', [
            'error' => $error,
            'outcome' => $outcome,
            'input' => $input,
            'verificationCard' => $verificationCard,
        ]);
    }

    /** staff/notifications.php: the signed-in staff member's own notifications. */
    public function notifications(): void
    {
        $this->authorize($this->staff, 'staff.dashboard', 'admin/notifications.php');
        $data = (new NotificationController($this->pdo))->handle($this->staff, 'staff/notifications.php');
        $this->page('Notifications', 'views/staff/notifications.php', $data);
    }

    /** staff/account.php: the signed-in staff member's (or admin's) own account -- details and password change. */
    public function account(): void
    {
        [$error, $success] = ['', ''];
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_require();
            [$error, $success] = (new AuthService($this->pdo))->changeOwnPassword(
                $this->staff,
                (string) ($_POST['current_password'] ?? ''),
                (string) ($_POST['new_password'] ?? ''),
                (string) ($_POST['new_password_confirm'] ?? '')
            );
            if ($success) {
                flash($success);
                $this->redirectTo(role_portal_path($this->staff, 'account.php'));
            }
        }
        $this->page('My Account', 'views/staff/account.php', [
            'error' => $error,
            'success' => (string) take_flash(),
            'roleLabels' => User::ROLE_LABELS,
        ]);
    }

    /** staff/chat.php: Chat Support conversations in which applicants asked for MSWDO staff, and replying to them. */
    public function chat(): void
    {
        $this->authorize($this->staff, 'chat.respond');
        $assistant = new HelpAssistantService($this->pdo);
        $applicantId = (int) ($_GET['user'] ?? 0);
        if (!$applicantId) {
            $this->page('Chat Support', 'views/staff/chat/index.php', ['inbox' => $assistant->inbox(), 'waiting' => $assistant->waitingCount(), 'flashMessage' => take_flash()]);
            return;
        }
        $applicant = (new Applicant($this->pdo))->findApplicant($applicantId);
        $thread = $applicant ? $assistant->staffThread($applicantId) : null;
        if (!$applicant || $thread === null) {
            flash('That conversation was not found.');
            $this->redirectTo('staff/chat.php');
        }
        $error = null;
        $typed = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_require();
            $typed = is_string($_POST['reply'] ?? null) ? $_POST['reply'] : '';
            $error = $assistant->staffReply($this->staff, $applicant, $typed);
            if (!$error) {
                flash('Reply sent. The applicant has been notified.');
                $this->redirectTo('staff/chat.php?user=' . $applicantId . '#chat-latest');
            }
        }
        $this->page('Chat with ' . $applicant['full_name'], 'views/staff/chat/thread.php', [
            'applicant' => $applicant,
            'thread' => $thread,
            'applications' => (new Application($this->pdo))->forUser($applicantId),
            'error' => $error,
            'typed' => $error ? $typed : '',
            'flashMessage' => take_flash(),
        ]);
    }

    public function reports(): void
    {
        $this->authorize($this->staff, 'reports.view');
        if ($this->staff['role'] === 'admin') {
            // Administrators use the same reports on their own page.
            $this->redirectTo('admin/reports.php' . ($_GET ? '?' . http_build_query($_GET) : ''));
        }
        $data = (new ReportController($this->pdo))->handle($this->staff, 'staff/reports.php');
        $this->page(Report::TYPES[$data['type']], 'views/reports/index.php', $data);
    }

    private function page(string $title, string $view, array $data): void
    {
        $this->render('views/layouts/page.php', array_merge($data, [
            'pageTitle' => $title,
            'contentView' => $view,
            'staff' => $this->staff,
            'ownDashboard' => base_url($this->staff['role'] === 'admin' ? 'admin/dashboard.php' : 'staff/dashboard.php'),
        ]));
    }
    /** [the record a renewal renews (with children) or null, the changes since it]. */
    protected function renewalContext(array $app): array
    {
        if ($app['type'] !== 'renewal' || !$app['renewal_of_id']) {
            return [null, []];
        }
        $applications = new Application($this->pdo);
        $previous = $applications->findWithChildren((int) $app['renewal_of_id']);
        if (!$previous) {
            return [null, []];
        }
        return [$previous, RenewalService::diff($previous, $app + ['children' => $applications->children((int) $app['id'])])];
    }
}
