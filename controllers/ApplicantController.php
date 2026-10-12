<?php
require_once __DIR__ . '/Controller.php';
require_once __DIR__ . '/../services/AuthService.php';
require_once __DIR__ . '/../services/EligibilityService.php';
require_once __DIR__ . '/../services/DocumentValidationService.php';
require_once __DIR__ . '/../services/AppointmentService.php';
require_once __DIR__ . '/../services/ApplicantProfileService.php';
require_once __DIR__ . '/../models/Applicant.php';
require_once __DIR__ . '/../models/Application.php';
require_once __DIR__ . '/../models/Notification.php';
require_once __DIR__ . '/../services/QrSlipService.php';
require_once __DIR__ . '/ApplicationController.php';
require_once __DIR__ . '/../services/ApplicationTrackerService.php';
require_once __DIR__ . '/../services/ApplicantAnalyticsService.php';
require_once __DIR__ . '/DocumentController.php';
require_once __DIR__ . '/AppointmentController.php';
require_once __DIR__ . '/RenewalController.php';
require_once __DIR__ . '/NotificationController.php';
require_once __DIR__ . '/../services/HelpAssistantService.php';
require_once __DIR__ . '/../services/RequirementGuideService.php';

/**
 * The applicant dashboard (applicant/dashboard.php?section=...). Owns the
 * shared layout and the account-level sections; module sections are
 * delegated to their own controllers. Every section handler returns
 * ['view' => path under views/applicant/, 'data' => [...]] and may
 * redirect instead for a successful POST.
 */
class ApplicantController extends Controller
{
    private const SECTION_TITLES = [
        'dashboard'     => 'Dashboard',
        'analytics'     => 'Analytics',
        'apply'         => 'Apply for Solo Parent ID',
        'application'   => 'My Application',
        'slip'          => 'Application Slip',
        'renewal'       => 'ID Renewal',
        'myid'          => 'My Solo Parent ID',
        'appointment'   => 'Appointment',
        'news'          => 'News & Announcements',
        'notifications' => 'Notifications',
        'documents'     => 'Required Documents Checklist',
        'requirements'  => 'Requirements Guide',
        'chat'          => 'Chat Support',
        'profile'       => 'My Profile',
        'help'          => 'Help & Support',
    ];

    private array $user;
    private array $applications;

    public function handle(): void
    {
        $auth = new AuthService($this->pdo);
        if ($staff = $auth->currentStaff()) {
            $this->redirectTo($staff['role'] === 'admin' ? 'admin/dashboard.php' : 'staff/dashboard.php');
        }
        $user = $auth->currentUser();
        if (!$user) {
            $this->redirectTo('portal.php?tab=login');
        }
        $this->authorize($user, 'applicant.portal');
        $this->user = $user;
        $this->applications = (new Application($this->pdo))->forUserDetailed((int) $user['id']);

        $section = (string) ($_GET['section'] ?? 'dashboard');
        // Account settings (details, photo, password) all live on My Profile; old "Settings" links go there.
        if ($section === 'settings') {
            $this->redirectTo('applicant/dashboard.php?section=profile');
        }
        // The Eligibility Checker is a public, pre-application step (eligibility.php); old dashboard links land on the dashboard.
        if ($section === 'eligibility') {
            $this->redirectTo('applicant/dashboard.php');
        }
        if (!isset(self::SECTION_TITLES[$section])) {
            $section = 'dashboard';
        }
        $this->requireOwnPassword($user, 'applicant/dashboard.php?section=profile#password', $section === 'profile');
        // The account is usable once its mobile number is confirmed by SMS code (after registering, or after the
        // number changed). My Profile stays open, so a mistyped number can be corrected.
        if (empty($user['mobile_verified_at']) && $section !== 'profile') {
            $this->redirectTo('verify-mobile.php');
        }
        $title = self::SECTION_TITLES[$section];

        switch ($section) {
            case 'slip':
                $result = (new ApplicationController($this->pdo))->applicantSlipSection($this->user);
                break;
            case 'apply':
                $result = (new ApplicationController($this->pdo))->applySection($this->user);
                break;
            case 'application':
                $result = (new ApplicationController($this->pdo))->statusList($this->user, $this->applications);
                break;
            case 'documents':
                $result = (new DocumentController($this->pdo))->handle($this->user, $this->applications);
                break;
            case 'requirements':
                $guides = RequirementGuideService::catalog($this->pdo);
                $requested = is_string($_GET['doc'] ?? null) ? $_GET['doc'] : '';
                $result = ['view' => 'requirements/index.php', 'data' => [
                    'requirementGuides' => $guides,
                    'selectedGuideKey' => isset($guides[$requested]) ? $requested : null,
                    'guideNotFound' => $requested !== '' && !isset($guides[$requested]),
                ]];
                break;
            case 'appointment':
                $result = (new AppointmentController($this->pdo))->handle($this->user, $this->applications);
                break;
            case 'renewal':
                $result = (new RenewalController($this->pdo))->handle($this->user, $this->applications);
                $title = $result['title'];
                break;
            case 'analytics':
                $result = $this->sectionAnalytics();
                break;
            case 'news':
                $result = $this->sectionNews();
                break;
            case 'myid':
                $result = $this->sectionMyid();
                break;
            case 'notifications':
                $result = $this->sectionNotifications();
                break;
            case 'chat':
                $result = $this->sectionChat();
                break;
            case 'profile':
                $result = $this->sectionProfile();
                break;
            case 'help':
                $result = ['view' => 'help.php', 'data' => [
                    'office' => ['name' => setting('office_name'), 'address' => setting('office_address'), 'hours' => setting('office_hours'),
                                 'email' => setting('office_email'), 'phone' => setting('office_phone')],
                    'hasApplication' => (bool) $this->applications,
                ]];
                break;
            default:
                $result = $this->sectionDashboard();
        }

        $this->renderLayout($section === 'slip' ? 'application' : $section, $title, $result);
    }

    private function renderLayout(string $section, string $title, array $result): void
    {
        $announcements = announcements_list();
        $this->render('views/applicant/layout.php', array_merge($result['data'], [
            'user' => $this->user,
            'section' => $section,
            'sectionTitle' => $title,
            'pageTitle' => $title . ' — ' . setting('system_name'),
            'contentView' => 'views/applicant/' . $result['view'],
            'unreadAnnouncementCount' => count_unread_announcements($announcements),
            'unreadNotificationCount' => (new Notification($this->pdo))->unreadCount((int) $this->user['id']),
        ]));
    }

    /** The released ID if there is one, otherwise the approved-but-not-yet-released one. */
    private function idApplication(): ?array
    {
        foreach (['ID Released', 'Approved'] as $status) {
            foreach ($this->applications as $app) {
                if ($app['status'] === $status) {
                    return $app;
                }
            }
        }
        return null;
    }

    private function idExpiryInfo(?array $idApplication): ?array
    {
        if (!$idApplication || !$idApplication['id_expires_at']) {
            return null;
        }
        return [
            'date' => $idApplication['id_expires_at'],
            'expired' => strtotime($idApplication['id_expires_at']) < strtotime('today'),
        ];
    }

    // ---- Sections ----

    private function sectionDashboard(): array
    {
        $idApplication = $this->idApplication();
        $renewal = $this->renewalInfo($idApplication);
        $upcoming = $this->nextAppointment();
        $bookable = (new AppointmentService($this->pdo))->findBookableApplication($this->applications);
        $draft = (new ApplicationService($this->pdo))->draftSummary($this->user);

        return ['view' => 'dashboard.php', 'data' => [
            'isFirstLogin' => (bool) ($_SESSION['applicant_first_login'] ?? false),
            'latestApplication' => $this->applications[0] ?? null,
            'latestTracker' => isset($this->applications[0]) ? (new ApplicationTrackerService($this->pdo))->track($this->applications[0]) : null,
            'idApplication' => $idApplication,
            'idExpiryInfo' => $this->idExpiryInfo($idApplication),
            'renewal' => $renewal,
            'upcomingAppointment' => $upcoming,
            'canBookAppointment' => AppointmentService::needsBooking($bookable),
            'recentNotifications' => (new Notification($this->pdo))->forUser((int) $this->user['id'], 3),
            'eligibilityCheck' => (new EligibilityService($this->pdo))->latestForUser((int) $this->user['id']),
            'quickActions' => $this->quickActions($renewal, $bookable, $draft),
            'applicationDraft' => $draft,
        ]];
    }

    /**
     * A summary of one of the applicant's own applications (the newest by
     * default) with their documents, interview, currently held ID and recent
     * activity. ?application_id= only picks among $this->applications, which
     * were loaded by the session user's id; an id not in that list (another
     * applicant's, or made up) falls back to the newest application.
     */
    private function sectionAnalytics(): array
    {
        $selected = $this->applications[0] ?? null;
        $requested = is_string($_GET['application_id'] ?? null) ? (int) $_GET['application_id'] : 0;
        foreach ($this->applications as $app) {
            if ((int) $app['id'] === $requested) {
                $selected = $app;
                break;
            }
        }
        $tracker = $selected ? (new ApplicationTrackerService($this->pdo))->track($selected) : null;

        // The ID the applicant holds now, which may come from an older application than the one shown
        // (e.g. while a renewal is being processed). Its release date is the first "ID Released" in its
        // status history: taken from the tracker when it is the shown application, otherwise read once.
        $idApplication = $this->idApplication();
        $idReleasedAt = null;
        if ($idApplication && $idApplication['status'] === 'ID Released') {
            $history = $selected && (int) $idApplication['id'] === (int) $selected['id']
                ? $tracker['history']
                : array_reverse((new Application($this->pdo))->history((int) $idApplication['id']));
            foreach ($history as $h) {
                if ($h['status'] === 'ID Released') {
                    $idReleasedAt = $h['changed_at'];
                    break;
                }
            }
        }

        return ['view' => 'analytics.php', 'data' => [
            'application' => $selected,
            'applications' => $this->applications,
            'tracker' => $tracker,
            'documentCounts' => $tracker ? ApplicantAnalyticsService::documentCounts($tracker['checklist']) : null,
            'activity' => $tracker ? (new ApplicantAnalyticsService($this->pdo))->timeline($selected, $tracker) : [],
            'applicationDraft' => (new ApplicationService($this->pdo))->draftSummary($this->user),
            'idApplication' => $idApplication,
            'idReleasedAt' => $idReleasedAt,
            'idExpiryInfo' => $this->idExpiryInfo($idApplication),
            'renewal' => $this->renewalInfo($idApplication),
        ]];
    }

    /**
     * Renewal status of the applicant's released ID: state is none (no
     * released ID), in_progress (a renewal is already being processed),
     * expired, due (within the renewal window, admin-configurable, default
     * 30 days) or valid.
     */
    private function renewalInfo(?array $idApplication): array
    {
        foreach ($this->applications as $app) {
            if ($app['type'] === 'renewal' && !in_array($app['status'], ['ID Released', 'Rejected'], true)) {
                return ['state' => 'in_progress', 'application' => $app];
            }
        }
        if (!$idApplication || $idApplication['status'] !== 'ID Released' || !$idApplication['id_expires_at']) {
            return ['state' => 'none'];
        }
        $windowDays = (int) get_setting($this->pdo, 'renewal_reminder_days', '30');
        $daysLeft = (int) floor((strtotime($idApplication['id_expires_at']) - strtotime('today')) / 86400);
        $state = $daysLeft < 0 ? 'expired' : ($daysLeft <= $windowDays ? 'due' : 'valid');
        return ['state' => $state, 'daysLeft' => $daysLeft, 'expiresAt' => $idApplication['id_expires_at'], 'windowDays' => $windowDays];
    }

    /** Soonest appointment still ahead, across all of the applicant's applications. */
    private function nextAppointment(): ?array
    {
        $next = null;
        foreach ($this->applications as $app) {
            if ($app['interview_status'] === 'BOOKED' && strtotime($app['interview_at']) >= time()
                && (!$next || $app['interview_at'] < $next['interview_at'])) {
                $next = $app;
            }
        }
        return $next;
    }

    /** Shortcuts for what this applicant most likely needs to do next, most urgent first. */
    private function quickActions(array $renewal, ?array $bookable, ?array $draft): array
    {
        $latest = $this->applications[0] ?? null;
        $actions = [];
        if ($draft) {
            $actions[] = ['Continue My Application', base_url('applicant/dashboard.php?section=apply'), 'apply'];
        } elseif (!$latest || $latest['status'] === 'Rejected') {
            $actions[] = ['Apply for Solo Parent ID', base_url('applicant/dashboard.php?section=apply'), 'apply'];
        }
        if ($latest && $latest['status'] === 'For Revision') {
            $actions[] = ['Fix My Documents', base_url('applicant/dashboard.php?section=documents'), 'documents'];
        }
        if (AppointmentService::needsBooking($bookable)) {
            $actions[] = ['Book Appointment', base_url('applicant/dashboard.php?section=appointment'), 'calendar'];
        }
        if (in_array($renewal['state'], ['due', 'expired'], true)) {
            $actions[] = ['Renew My ID', base_url('applicant/dashboard.php?section=renewal&type=new'), 'renew'];
        }
        if ($latest) {
            $actions[] = ['Track My Application', base_url('applicant/dashboard.php?section=application'), 'apply'];
        }
        $actions[] = ['Notifications', base_url('applicant/dashboard.php?section=notifications'), 'bell'];
        $actions[] = ['Chat Support', base_url('applicant/dashboard.php?section=chat'), 'chat'];
        return $actions;
    }

    private function sectionNews(): array
    {
        $activeAnnouncement = null;
        if (isset($_GET['id'])) {
            $id = (int) $_GET['id'];
            foreach (announcements_list() as $a) {
                if ($a['id'] === $id) {
                    $activeAnnouncement = $a;
                    mark_announcement_read($a['id']);
                    break;
                }
            }
        }
        return ['view' => 'news/index.php', 'data' => [
            'announcements' => announcements_list(),
            'activeAnnouncement' => $activeAnnouncement,
        ]];
    }

    private function sectionMyid(): array
    {
        $idApplication = $this->idApplication();
        if ($idApplication && $idApplication['status'] !== 'ID Released') {
            $idApplication = null;
        }
        // The same secure slip token as the application slip -- no reference number or personal data in the QR.
        $slip = $idApplication ? (new QrSlipService($this->pdo))->slipFor($idApplication) : null;
        return ['view' => 'qr/my-id.php', 'data' => [
            'idApplication' => $idApplication,
            'idExpiryInfo' => $this->idExpiryInfo($idApplication),
            'idQrUri' => $slip ? QrSlipService::qrImage($slip['token']) : null,
            'idSlipCode' => $slip ? QrSlipService::slipCode($slip['token']) : null,
            'latestApplication' => $this->applications[0] ?? null,
        ]];
    }

    private function sectionNotifications(): array
    {
        return ['view' => 'notifications/index.php', 'data' => (new NotificationController($this->pdo))->handle($this->user, 'applicant/dashboard.php?section=notifications')];
    }

    /** Chat Support: the Help Assistant, and hand-off to MSWDO staff who reply in the same thread. */
    private function sectionChat(): array
    {
        $assistant = new HelpAssistantService($this->pdo);
        $error = null;
        $typed = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_require();
            $typed = is_string($_POST['message'] ?? null) ? $_POST['message'] : '';
            $error = ($_POST['action'] ?? '') === 'handoff'
                ? $assistant->requestStaff($this->user, $typed)
                : $assistant->ask($this->user, $this->applications, $typed);
            if (!$error) {
                $this->redirectTo('applicant/dashboard.php?section=chat#chat-latest');
            }
        }
        return ['view' => 'chat/index.php', 'data' => [
            'thread' => $assistant->thread($this->user),
            'awaitingStaff' => $assistant->awaitingStaff($this->user),
            'chatError' => $error,
            'typed' => $error ? $typed : '',
            'faqs' => get_faqs($this->pdo),
        ]];
    }

    private function sectionProfile(): array
    {
        $profileError = '';
        $profileSuccess = '';

        $profiles = new ApplicantProfileService($this->pdo);
        $formValues = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_require();
            $action = (string) ($_POST['action'] ?? '');
            if ($action === 'update_info') {
                [$profileError, $profileSuccess] = $profiles->update($this->user, $_POST);
                if ($profileError) {
                    $formValues = $_POST; // redisplay what they typed
                }
            } elseif ($action === 'change_password') {
                [$profileError, $profileSuccess] = $this->changePassword();
            } elseif ($action === 'upload_photo') {
                [$profileError, $profileSuccess] = $profiles->uploadPhoto($this->user, $_FILES['photo'] ?? []);
            } elseif ($action === 'remove_photo') {
                [$profileError, $profileSuccess] = $profiles->removePhoto($this->user);
            }
            $this->refreshUser();
        }

        $saved = $profiles->profile((int) $this->user['id']) + [
            'full_name' => $this->user['full_name'], 'email' => $this->user['email'], 'mobile' => $this->user['mobile'],
        ];
        $form = [];
        foreach (['full_name', 'email', 'mobile', 'birthdate', 'sex', 'civil_status', 'barangay', 'address'] as $key) {
            $form[$key] = $formValues !== null && is_string($formValues[$key] ?? null) ? $formValues[$key] : (string) ($saved[$key] ?? '');
        }

        return ['view' => 'profile.php', 'data' => [
            'profileError' => $profileError,
            'profileSuccess' => $profileSuccess,
            'form' => $form,
            'profileUpdatedAt' => $saved['updated_at'] ?? null,
            'civilStatusOptions' => CIVIL_STATUS_OPTIONS,
        ]];
    }

    /** @return array{0: string, 1: string} [error, success] */
    private function changePassword(): array
    {
        return (new AuthService($this->pdo))->changeOwnPassword(
            $this->user,
            (string) ($_POST['current_password'] ?? ''),
            (string) ($_POST['new_password'] ?? ''),
            (string) ($_POST['new_password_confirm'] ?? '')
        );
    }

    private function refreshUser(): void
    {
        $this->user = (new AuthService($this->pdo))->currentUser() ?? $this->user;
    }
}
