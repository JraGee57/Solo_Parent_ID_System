<?php
require_once __DIR__ . '/Controller.php';
require_once __DIR__ . '/../services/AuthService.php';
require_once __DIR__ . '/../services/UserManagementService.php';
require_once __DIR__ . '/../services/SettingsService.php';
require_once __DIR__ . '/../services/BackupService.php';
require_once __DIR__ . '/../services/ContentService.php';
require_once __DIR__ . '/../services/ReportService.php';
require_once __DIR__ . '/../services/AuditLogService.php';
require_once __DIR__ . '/../services/AnalyticsService.php';
require_once __DIR__ . '/../services/RenewalService.php';
require_once __DIR__ . '/../services/ScheduledJobService.php';
require_once __DIR__ . '/../services/SMSService.php';
require_once __DIR__ . '/NotificationController.php';
require_once __DIR__ . '/ReportController.php';
require_once __DIR__ . '/../services/AppointmentService.php';
require_once __DIR__ . '/../models/Application.php';
require_once __DIR__ . '/../models/EligibilityAssessment.php';
require_once __DIR__ . '/../models/Appointment.php';
require_once __DIR__ . '/../models/AuditLog.php';
require_once __DIR__ . '/../models/Document.php';
require_once __DIR__ . '/../models/Faq.php';
require_once __DIR__ . '/../models/Report.php';
require_once __DIR__ . '/../models/SmsLog.php';
require_once __DIR__ . '/../models/User.php';

/**
 * System Administrator pages under admin/. No login goes to the staff
 * login; other roles return to their own dashboard. Each action also
 * requires its own permission (config/permissions.php). Application decisions stay with MSWD staff (staff/), so the
 * application pages here are read-only monitoring.
 */
class AdminController extends Controller
{
    private array $admin;

    public function __construct(PDO $pdo)
    {
        parent::__construct($pdo);
        $auth = new AuthService($pdo);
        if ($auth->currentUser()) {
            $this->redirectTo('applicant/dashboard.php');
        }
        $staff = $auth->currentStaff();
        if (!$staff) {
            $this->redirectTo('mswd-login.php');
        }
        if ($staff['role'] !== 'admin') {
            $this->redirectTo('staff/dashboard.php');
        }
        $this->admin = $staff;
        $this->requireOwnPassword($staff, 'admin/account.php', false);
    }

    public function announcements(): void
    {
        $this->authorize($this->admin, 'content.manage');
        require_once __DIR__ . '/../models/Announcement.php';
        $model = new Announcement($this->pdo);
        $error = '';
        $editing = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_require();
            $id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT);
            if ($id === false || $id < 0) {
                $error = 'Invalid announcement.';
            } else {
                $error = $model->save($this->admin, $id, $_POST);
                if ($error === '') {
                    flash('Announcement saved. Drafts, future publications and expired announcements are hidden from the public.');
                    $this->redirectTo('admin/announcements.php');
                }
            }
            $editing = $_POST;
        } elseif (isset($_GET['edit'])) {
            $editing = $model->find((int) $_GET['edit']);
            if (!$editing) { $error = 'Announcement not found.'; }
        }
        $result = $model->page((int) ($_GET['page'] ?? 1));
        $this->page('Public Announcements', 'announcements/index.php', [
            'error' => $error, 'notice' => take_flash(), 'editing' => $editing, 'announcements' => $result['rows'], 'pager' => $result,
        ]);
    }

    public function dashboard(): void
    {
        $this->authorize($this->admin, 'admin.dashboard', 'staff/dashboard.php');
        $reports = new ReportService($this->pdo);
        $backups = (new BackupService($this->pdo))->all();
        $this->page('Administrator Dashboard', 'dashboard.php', [
            'stats' => $reports->dashboardStats(),
            'userStats' => $reports->userStats(),
            'auditStats' => $reports->auditStats(),
            'summary' => $reports->analyticsSummary(),
            'lastBackupAt' => $backups[0]['created'] ?? null,
            'recentActivity' => (new AuditLog($this->pdo))->recent(8),
        ]);
    }

    // ---- Application monitoring (read-only) ----

    public function applications(): void
    {
        $this->authorize($this->admin, 'applications.monitor', 'staff/index.php');
        $statusFilter = (string) ($_GET['status'] ?? 'ALL');
        $typeFilter = (string) ($_GET['type'] ?? 'ALL');
        $q = trim((string) ($_GET['q'] ?? ''));
        $applications = new Application($this->pdo);
        $result = $applications->search(['status' => $statusFilter, 'type' => $typeFilter, 'q' => $q], (int) ($_GET['page'] ?? 1), 10);
        $this->page('Application Monitoring', 'applications/index.php', [
            'statusFilter' => $statusFilter,
            'typeFilter' => $typeFilter,
            'q' => $q,
            'validStatuses' => Application::STATUSES,
            'apps' => $result['rows'],
            'pager' => $result,
            'stageCounts' => $applications->countsByStatus(),
        ]);
    }

    public function application(): void
    {
        $this->authorize($this->admin, 'applications.monitor', 'staff/application.php?id=' . (int) ($_GET['id'] ?? 0));
        $id = (int) ($_GET['id'] ?? 0);
        $applications = new Application($this->pdo);
        $app = $applications->findForReview($id);
        if (!$app) {
            http_response_code(404);
            die('Application not found.');
        }
        $this->page('Application ' . $app['reference_no'] . ' (Monitoring)', 'applications/show.php', [
            'app' => $app,
            'interview' => (new Appointment($this->pdo))->interviewFor($id),
            'children' => $applications->children($id),
            'docs' => (new Document($this->pdo))->checklistForReview($id, $app['category_id'] !== null ? (int) $app['category_id'] : null, $app['type'] === 'renewal'),
            'docHistory' => (new Document($this->pdo))->statusHistoryFor($id),
            'decisions' => $applications->decisions($id),
            'renewalOf' => $this->renewalContext($app)[0],
            'renewalChanges' => $this->renewalContext($app)[1],
            'notes' => $applications->notes($id),
            'mswdAssessments' => (new EligibilityAssessment($this->pdo))->mswdForApplication($id),
            'history' => $applications->history($id),
        ]);
    }

    // ---- Users ----

    /** admin/users.php: every account (filters, pagination) and the create form. */
    public function users(): void
    {
        $this->authorize($this->admin, 'users.manage');
        $service = new UserManagementService($this->pdo);
        [$error, $success] = ['', ''];
        $form = [];
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_require();
            if (($_POST['action'] ?? '') === 'create') {
                [$error, $success, $newId] = $service->create($this->admin, $_POST);
                if ($newId) {
                    flash($success);
                    $this->redirectTo('admin/user_edit.php?id=' . $newId);
                }
                $form = array_intersect_key($_POST, array_flip(['fullName', 'email', 'username', 'mobile', 'role', 'mustChange']));
            } elseif (($_POST['action'] ?? '') === 'delete') {
                $target = $service->find((int) ($_POST['user_id'] ?? 0));
                [$error, $success] = $target ? $service->delete($this->admin, $target) : ['Account not found.', ''];
                if ($success) {
                    flash($success);
                    $this->redirectTo('admin/users.php' . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''));
                }
            }
        }
        $filters = [
            'role' => (string) ($_GET['role'] ?? ''),
            'status' => (string) ($_GET['status'] ?? ''),
            'q' => trim((string) ($_GET['q'] ?? '')),
        ];
        $result = $service->list($filters['role'], $filters['status'], $filters['q'], (int) ($_GET['page'] ?? 1));
        $this->page('Manage Users', 'users/index.php', [
            'error' => $error,
            'success' => $success ?: (string) take_flash(),
            'form' => $form,
            'filters' => $filters,
            'roleLabels' => User::ROLE_LABELS,
            'statusFilters' => UserManagementService::STATUS_FILTERS,
            'users' => $result['rows'],
            'pager' => $result,
        ]);
    }

    /** admin/user_edit.php?id=: one account -- details, role, status, access, activity. */
    public function userEdit(): void
    {
        $this->authorize($this->admin, 'users.manage');
        $service = new UserManagementService($this->pdo);
        $target = $service->find((int) ($_GET['id'] ?? 0));
        if (!$target) {
            http_response_code(404);
            die('Account not found.');
        }

        [$error, $success] = ['', ''];
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_require();
            [$error, $success] = match ((string) ($_POST['action'] ?? '')) {
                'update' => $service->updateAccount($this->admin, $target, $_POST),
                'change_role' => $service->changeRole($this->admin, $target, (string) ($_POST['role'] ?? '')),
                'activate' => $service->setActive($this->admin, $target, true),
                'deactivate' => $service->setActive($this->admin, $target, false),
                'reset_access' => $service->resetAccess($this->admin, $target),
                'unlock' => $service->unlock($this->admin, $target),
                'confirm_mobile' => $service->confirmMobile($this->admin, $target),
                default => ['Unknown action.', ''],
            };
            $target = $service->find((int) $target['id']);
        }

        $scope = (string) ($_GET['scope'] ?? '');
        $this->page('Manage ' . $target['username'], 'users/edit.php', [
            'error' => $error,
            'success' => $success ?: (string) take_flash(),
            'target' => $target,
            'isSelf' => (int) $target['id'] === (int) $this->admin['id'],
            'locked' => !empty($target['locked_until']) && strtotime($target['locked_until']) > time(),
            'applicationCount' => (new User($this->pdo))->applicationCount((int) $target['id']),
            'roleLabels' => User::ROLE_LABELS,
            'scope' => in_array($scope, ['by', 'on'], true) ? $scope : '',
            'activity' => $service->activity((int) $target['id'], $scope, (int) ($_GET['apage'] ?? 1)),
        ]);
    }
    // ---- Audit, analytics, reports ----

    public function auditLog(): void
    {
        $this->authorize($this->admin, 'audit.view');
        $service = new AuditLogService($this->pdo);
        [$filters, $filterError] = $service->filters($_GET);

        if (($_GET['output'] ?? '') === 'csv') {
            $export = $service->exportRows($filters);
            if ($export === null) {
                http_response_code(413);
                die('More than ' . number_format(AuditLog::EXPORT_LIMIT) . ' events match. Narrow the date range or filters, then export again.');
            }
            [$header, $rows] = $export;
            // Logged before streaming, so the export itself is never missing from the log it exported.
            $this->auditSystem('Audit Log Export', count($rows) . (count($rows) === 1 ? ' event — ' : ' events — ') . $service->describe($filters));
            (new ReportService($this->pdo))->streamCsv('audit-log-' . date('Y-m-d') . '.csv', $header, $rows);
            exit;
        }

        $this->page('Audit Log', 'audit/index.php', $service->page($filters, (int) ($_GET['page'] ?? 1)) + [
            'filters' => $filters,
            'filterError' => $filterError,
        ]);
    }

    public function analytics(): void
    {
        $this->authorize($this->admin, 'analytics.view');
        $analytics = new AnalyticsService($this->pdo);
        $period = $analytics->period($_GET);
        $this->page('Analytics', 'analytics/index.php', $analytics->build($period) + ['period' => $period]);
    }

    /** admin/reports.php: the eight reports (shared with MSWD staff on staff/reports.php). */
    public function reports(): void
    {
        $this->authorize($this->admin, 'reports.view');
        if ($this->admin['role'] !== 'admin') {
            // MSWD staff use the same reports on their own page.
            $this->redirectTo('staff/reports.php' . ($_GET ? '?' . http_build_query($_GET) : ''));
        }
        $data = (new ReportController($this->pdo))->handle($this->admin, 'admin/reports.php');
        $this->page(Report::TYPES[$data['type']], '../reports/index.php', $data);
    }
    public function exportCsv(): void
    {
        $this->authorize($this->admin, 'data.export');
        $type = (string) ($_GET['export'] ?? '');
        $service = new ReportService($this->pdo);
        $export = $service->fullExport($type);
        if (!$export) {
            http_response_code(400);
            die('Unknown export type.');
        }
        audit_log($this->pdo, (int) $this->admin['id'], $this->admin['full_name'], $this->admin['role'], 'CSV Export', 'report', null, ucfirst($type) . ' export');
        $service->streamCsv($type . '-' . date('Y-m-d') . '.csv', $export[0], $export[1]);
    }

    // ---- System ----

    public function monitoring(): void
    {
        $this->authorize($this->admin, 'system.monitor');
        $backups = new BackupService($this->pdo);
        [$error, $success] = ['', ''];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_require();
            $action = (string) ($_POST['action'] ?? '');
            if ($action === 'create_backup') {
                try {
                    $filename = $backups->create();
                    $this->auditSystem('Database Backup Created', $filename);
                    $success = 'Backup created: ' . $filename;
                } catch (Throwable $e) {
                    $error = 'Backup failed: ' . $e->getMessage();
                }
            } elseif ($action === 'delete_backup') {
                $name = basename((string) ($_POST['name'] ?? ''));
                if ($backups->delete($name)) {
                    $this->auditSystem('Database Backup Deleted', $name);
                    $success = 'Backup deleted.';
                }
            }
        }

        $recentActivity = (new AuditLog($this->pdo))->recent(10);
        $this->page('Database & System Monitoring', 'monitoring/index.php', [
            'error' => $error,
            'success' => $success,
            'tableCounts' => (new Report($this->pdo))->tableRowCounts(),
            'uploadsSize' => BackupService::folderSize(STORAGE_PATH),
            'backupsSize' => BackupService::folderSize($backups->directory()),
            'backups' => $backups->all(),
            'recentActivity' => $recentActivity,
            'lastActivityAt' => $recentActivity[0]['created_at'] ?? null,
        ]);
    }

    public function downloadBackup(): void
    {
        $this->authorize($this->admin, 'system.monitor');
        $backups = new BackupService($this->pdo);
        $name = basename((string) ($_GET['name'] ?? ''));
        if (!$backups->isValidName($name)) {
            http_response_code(400);
            die('Invalid backup filename.');
        }
        $path = $backups->pathFor($name);
        if (!$path) {
            http_response_code(404);
            die('Backup not found.');
        }
        $this->auditSystem('Database Backup Downloaded', $name);
        header('Content-Type: application/sql');
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
    }

    public function settings(): void
    {
        $this->authorize($this->admin, 'settings.manage');
        $settings = new SettingsService($this->pdo);
        [$error, $success] = ['', ''];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_require();
            $action = (string) ($_POST['action'] ?? '');
            $jobs = ['send_reminders_now' => 'renewal_reminders', 'send_appointment_reminders_now' => 'appointment_reminders'];
            if (isset($jobs[$action])) {
                $result = (new ScheduledJobService($this->pdo))->run($jobs[$action], 'manual', $this->admin);
                $summary = 'Checked ' . $result['checked'] . ', sent ' . count($result['sent']) . (count($result['failed']) ? ', failed ' . count($result['failed']) : '') . '. ' . $result['message'];
                if ($result['status'] !== 'FAILED') {
                    $success = $summary;
                } else {
                    $error = $summary;
                }
            } elseif ($action === 'save_section') {
                $section = (string) ($_POST['section'] ?? '');
                [$error, $success] = $settings->saveSection($this->admin, $section, $_POST);
                $errorSection = $error && isset(SETTINGS_REGISTRY[$section]) ? $section : null;
            } else {
                $error = 'Unknown action.';
            }
        }

        $this->page('System Settings', 'settings/index.php', [
            'error' => $error,
            'success' => $success,
            'errorSection' => $errorSection ?? null,
            'posted' => $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : [],
            'current' => $settings->current(),
            'smsTemplates' => $settings->smsTemplates(),
            'serverUploadLimitMb' => SettingsService::serverUploadLimitMb(),
            'recentReminders' => (new RenewalReminder($this->pdo))->recent(10),
            'renewalRuns' => (new ScheduledJobService($this->pdo))->recentRuns('renewal_reminders', 5),
            'appointmentRuns' => (new ScheduledJobService($this->pdo))->recentRuns('appointment_reminders', 5),
            'recentApptReminders' => $settings->recentAppointmentReminders(),
        ]);
    }

    public function requirements(): void
    {
        $this->authorize($this->admin, 'content.manage');
        [$error, $success] = ['', ''];
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_require();
            $content = new ContentService($this->pdo);
            $str = fn(string $k) => is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : '';
            $label = $str('label');
            $isRequired = !empty($_POST['is_required']);
            $sortOrder = (int) $str('sort_order');
            $reqId = (int) $str('req_id');
            switch ($str('action')) {
                case 'create':
                    $categoryId = $str('category_id') !== '' ? (int) $str('category_id') : null;
                    [$error, $success] = $content->createRequirement($this->admin, $categoryId, $str('doc_key'), $label, $isRequired, $sortOrder);
                    break;
                case 'update':
                    [$error, $success] = $content->updateRequirement($this->admin, $reqId, $label, $isRequired, $sortOrder);
                    break;
                case 'delete':
                    [$error, $success] = $content->deleteRequirement($this->admin, $reqId);
                    break;
            }
        }

        $categories = get_categories($this->pdo);
        $allCategories = $this->pdo->query('SELECT id, name FROM categories ORDER BY sort_order, id')->fetchAll();
        $this->page('Application Requirements', 'settings/requirements.php', [
            'error' => $error,
            'success' => $success,
            'categories' => $categories,
            'categoriesById' => array_column($allCategories, 'name', 'id'),
            'requirements' => (new Document($this->pdo))->allRequirements(),
        ]);
    }

    public function faq(): void
    {
        $this->authorize($this->admin, 'content.manage');
        [$error, $success] = ['', ''];
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_require();
            $content = new ContentService($this->pdo);
            $faqId = (int) ($_POST['faq_id'] ?? 0);
            $question = trim((string) ($_POST['question'] ?? ''));
            $answer = trim((string) ($_POST['answer'] ?? ''));
            $sortOrder = (int) ($_POST['sort_order'] ?? 0);
            switch ((string) ($_POST['action'] ?? '')) {
                case 'create':
                    [$error, $success] = $content->saveFaq($this->admin, null, $question, $answer, $sortOrder);
                    break;
                case 'update':
                    [$error, $success] = $content->saveFaq($this->admin, $faqId, $question, $answer, $sortOrder);
                    break;
                case 'toggle_active':
                    [$error, $success] = $content->toggleFaq($this->admin, $faqId);
                    break;
                case 'delete':
                    [$error, $success] = $content->deleteFaq($this->admin, $faqId);
                    break;
            }
        }

        $this->page('Manage FAQ (Chat Support)', 'settings/faq.php', [
            'error' => $error,
            'success' => $success,
            'faqs' => (new Faq($this->pdo))->all(),
        ]);
    }

    public function sms(): void
    {
        $this->authorize($this->admin, 'sms.manage');
        [$error, $success] = ['', ''];
        $post = fn(string $k) => is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_require();
            $action = $post('action');
            if ($action === 'update_template') {
                [$error, $success] = (new ContentService($this->pdo))->updateSmsTemplate($this->admin, $post('template_key'), $post('body'));
            } elseif ($action === 'retry') {
                $logId = (int) $post('log_id');
                [$error, $result] = (new SMSService($this->pdo))->retry($logId);
                if (!$error) {
                    $this->auditSystem('SMS Retried', 'sms_logs #' . $logId . ' -> ' . $result['status']);
                    $success = $result['status'] === 'SENT' ? 'The message was sent' . ($result['test_mode'] ? ' (test mode: logged only).' : '.') : 'The retry failed: ' . $result['error'];
                }
            } elseif ($action === 'send_test') {
                if (SMSService::normalizeNumber($post('mobile')) === null) {
                    $error = 'Enter a valid Philippine mobile number (e.g. 09171234567).';
                } else {
                    $result = (new SMSService($this->pdo))->send(null, $post('mobile'), 'MSWDO Virac: Test message from e-SoloParentID (' . date('M j, Y g:i A') . '). No action needed.', 'test_message');
                    $this->auditSystem('SMS Test Sent', SMSService::normalizeNumber($post('mobile')) . ' -> ' . $result['status']);
                    $success = $result['status'] === 'SENT'
                        ? ($result['test_mode'] ? 'Test message logged (test mode — nothing was sent).' : 'Test message sent. Check the phone.')
                        : '';
                    $error = $result['status'] === 'SENT' ? '' : 'The test message failed: ' . $result['error'];
                }
            }
        }

        $statusFilter = is_string($_GET['status'] ?? null) ? $_GET['status'] : 'ALL';
        $q = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
        $sms = new SmsLog($this->pdo);
        $result = $sms->page($statusFilter, $q, (int) ($_GET['page'] ?? 1));
        $this->page('SMS Notification Management', 'sms/index.php', [
            'error' => $error,
            'success' => $success,
            'statusFilter' => $statusFilter,
            'q' => $q,
            'templates' => $sms->templates(),
            'logs' => $result['rows'],
            'pager' => $result,
            'editedTemplate' => $error && ($_POST['action'] ?? '') === 'update_template' ? $post('template_key') : '',
            'totals' => [
                'sent' => $sms->countByStatus('sent'),
                'test' => $sms->countByStatus('test'),
                'failed' => $sms->countByStatus('failed'),
                'pending' => $sms->countByStatus('pending'),
            ],
        ]);
    }

    /** admin/notifications.php: the administrator's own notifications, and sending system announcements. */
    public function notifications(): void
    {
        $this->authorize($this->admin, 'admin.dashboard', 'staff/notifications.php');
        $service = new NotificationService($this->pdo);
        $broadcastError = '';
        $isBroadcast = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'broadcast';
        if ($isBroadcast) {
            csrf_require();
            $this->authorize($this->admin, 'content.manage', 'admin/notifications.php');
            [$broadcastError, $count] = $service->broadcast($this->admin, is_string($_POST['message'] ?? null) ? $_POST['message'] : '', is_string($_POST['audience'] ?? null) ? $_POST['audience'] : '');
            if (!$broadcastError) {
                flash("Announcement sent to $count " . ($count === 1 ? 'person' : 'people') . '.');
                $this->redirectTo('admin/notifications.php');
            }
        }
        $data = (new NotificationController($this->pdo))->handle($this->admin, 'admin/notifications.php', !$isBroadcast);
        $this->page('Notifications', 'notifications/index.php', $data + [
            'ownDashboard' => base_url('admin/dashboard.php'),
            'broadcastError' => $broadcastError,
            'broadcasts' => $service->recentBroadcasts(),
            'canBroadcast' => can($this->admin, 'content.manage'),
        ]);
    }

    public function eligibilityLogs(): void
    {
        $this->authorize($this->admin, 'eligibility.logs');
        require_once __DIR__ . '/../services/EligibilityService.php';
        $filters = [];
        foreach (['from', 'to', 'result', 'circ', 'audience'] as $key) {
            $filters[$key] = is_string($_GET[$key] ?? null) ? trim($_GET[$key]) : '';
        }
        $error = '';
        foreach (['from', 'to'] as $key) {
            if ($filters[$key] !== '' && (!is_ymd($filters[$key]) || !DateTime::createFromFormat('!Y-m-d', $filters[$key]) || DateTime::createFromFormat('!Y-m-d', $filters[$key])->format('Y-m-d') !== $filters[$key])) {
                $filters[$key] = ''; $error = 'Enter valid filter dates.';
            }
        }
        if ($filters['from'] !== '' && $filters['to'] !== '' && $filters['from'] > $filters['to']) {
            $error = 'The start date must be before the end date.';
            $filters['from'] = $filters['to'] = '';
        }
        $results = ['LIKELY_ELIGIBLE', 'NOT_ELIGIBLE', 'NEEDS_GUIDANCE'];
        $situations = (new EligibilityService())->circumstanceOptions();
        if (!in_array($filters['result'], $results, true)) { $filters['result'] = ''; }
        if (!in_array($filters['circ'], $situations, true)) { $filters['circ'] = ''; }
        if (!in_array($filters['audience'], ['guest', 'registered'], true)) { $filters['audience'] = ''; }
        $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1;
        $data = (new EligibilityAssessment($this->pdo))->logPage($filters, $page);
        $this->page('Eligibility Logs', 'eligibility/index.php', $data + compact('filters', 'results', 'situations', 'error'));
    }

    private function auditSystem(string $action, string $details): void
    {
        audit_log($this->pdo, (int) $this->admin['id'], $this->admin['full_name'], $this->admin['role'], $action, 'system', null, $details);
    }

    /** Renders views/admin/$view inside the standard page layout, with $staff set for the header nav. */
    private function page(string $title, string $view, array $data): void
    {
        $this->render('views/layouts/page.php', array_merge($data, [
            'pageTitle' => $title,
            'contentView' => 'views/admin/' . $view,
            'staff' => $this->admin,
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
