<?php
require_once __DIR__ . '/../models/AuditLog.php';
require_once __DIR__ . '/../models/User.php';

/**
 * The administrators' audit log viewer (admin/audit_log.php): filters,
 * event categories, readable targets, and the CSV export.
 */
class AuditLogService
{
    public const PER_PAGE = 10;

    /**
     * Event categories, checked in order: an action belongs to the first
     * category with a matching pattern (* at either end matches anything there).
     */
    public const CATEGORIES = [
        'jobs' => ['Scheduled jobs', ['* Run', '* Run (Manual)', 'Appointment Reminders Sent']],
        'auth' => ['Sign-in & security', ['Login', 'Login Failed', 'Account Locked', 'Logout', 'Registration', 'Password *', 'Access Denied']],
        'decisions' => ['Approvals & decisions', ['Application Approval', 'Application Rejection', 'Correction Request', 'Status Change']],
        'applications' => ['Applications & renewals', ['Application *', 'Renewal *', 'Eligibility Assessment', 'Case Note Added']],
        'documents' => ['Documents', ['Document Upload', 'Document Uploaded', 'Document Resubmission', 'Document Verification', 'Document Viewed']],
        'appointments' => ['Appointments & interviews', ['Appointment *', 'Interview *']],
        'qr' => ['QR slips & verification', ['QR *']],
        'users' => ['User accounts & profiles', ['User *', 'Account *', 'Access Reset', 'Profile *']],
        'settings' => ['Settings & content', ['System Settings Updated', 'SMS Template Updated', 'FAQ *', 'Document Requirement *', 'System Announcement Sent']],
        'system' => ['System, data & messaging', ['*']],
    ];

    private AuditLog $logs;
    private User $users;

    public function __construct(PDO $pdo)
    {
        $this->logs = new AuditLog($pdo);
        $this->users = new User($pdo);
    }

    public static function category(string $action): string
    {
        foreach (self::CATEGORIES as $key => [, $patterns]) {
            foreach ($patterns as $pattern) {
                if ($pattern === '*' || $pattern === $action
                    || (str_ends_with($pattern, '*') && str_starts_with($action, substr($pattern, 0, -1)))
                    || (str_starts_with($pattern, '*') && str_ends_with($action, substr($pattern, 1)))) {
                    return $key;
                }
            }
        }
        return 'system';
    }

    public static function roleLabel(string $role): string
    {
        return User::ROLE_LABELS[$role] ?? match ($role) {
            'system' => 'System',
            'guest' => 'Not signed in',
            default => $role,
        };
    }

    /**
     * Validated filters from the query string: [filters, error or null].
     * An unknown category is ignored; a bad date is dropped with an error.
     */
    public function filters(array $get): array
    {
        $str = fn(string $k, int $max = 100) => is_string($get[$k] ?? null) ? mb_substr(trim($get[$k]), 0, $max) : '';
        $f = ['q' => $str('q'), 'category' => $str('category', 20), 'action' => $str('action'), 'role' => $str('role', 30),
              'user' => ctype_digit($str('user', 10)) ? (int) $str('user', 10) : 0, 'from' => $str('from', 10), 'to' => $str('to', 10)];
        $error = null;
        foreach (['from', 'to'] as $k) {
            if ($f[$k] !== '' && !is_ymd($f[$k])) {
                $error = 'Enter dates as YYYY-MM-DD.';
                $f[$k] = '';
            }
        }
        if ($f['from'] !== '' && $f['to'] !== '' && $f['from'] > $f['to']) {
            $error = 'The "from" date is after the "to" date.';
            $f['from'] = $f['to'] = '';
        }
        if (!isset(self::CATEGORIES[$f['category']])) {
            $f['category'] = '';
        }

        return [$f, $error];
    }

    /** $f as AuditLog filters (category and action become an exact action list). */
    private function query(array $f): array
    {
        $q = ['q' => $f['q'], 'role' => $f['role'], 'user' => $f['user'], 'from' => $f['from'], 'to' => $f['to']];
        if ($f['action'] !== '') {
            $q['actions'] = $f['category'] === '' || self::category($f['action']) === $f['category'] ? [$f['action']] : [];
        } elseif ($f['category'] !== '') {
            $q['actions'] = array_values(array_filter($this->logs->distinctActions(), fn($a) => self::category($a) === $f['category']));
        }
        return $q;
    }

    /** Everything the viewer page needs for $f and $page. */
    public function page(array $f, int $page): array
    {
        $result = $this->logs->search($this->query($f), $page, self::PER_PAGE);
        $actions = $this->logs->distinctActions();
        if ($f['category'] !== '') {
            $actions = array_values(array_filter($actions, fn($a) => self::category($a) === $f['category']));
        }
        if ($f['action'] !== '' && !in_array($f['action'], $actions, true)) {
            array_unshift($actions, $f['action']);
        }
        $roles = $this->logs->distinctRoles();
        if ($f['role'] !== '' && !in_array($f['role'], $roles, true)) {
            array_unshift($roles, $f['role']);
        }
        return [
            'rows' => $result['rows'],
            'pager' => array_diff_key($result, ['rows' => true]),
            'targets' => $this->logs->targets($result['rows']),
            'actions' => $actions,
            'roles' => $roles,
            'userName' => $f['user'] ? ($this->users->findById($f['user'])['full_name'] ?? 'account #' . $f['user']) : null,
        ];
    }

    /** CSV header + rows for every matching event, or null when over the export limit. */
    public function exportRows(array $f): ?array
    {
        $rows = $this->logs->all($this->query($f));
        if ($rows === null) {
            return null;
        }
        $targets = $this->logs->targets($rows);
        return [
            ['Event ID', 'Timestamp', 'User', 'User ID', 'Role', 'Category', 'Action', 'Target type', 'Target ID', 'Target', 'Details', 'IP address', 'Browser'],
            array_map(fn($r) => [
                $r['id'], $r['created_at'], $r['actor_name'], $r['user_id'], self::roleLabel($r['actor_role']),
                self::CATEGORIES[self::category($r['action'])][0], $r['action'], $r['entity_type'], $r['entity_id'],
                $targets[$r['entity_type'] . ':' . $r['entity_id']]['label'] ?? '', $r['details'], $r['ip_address'], $r['user_agent'],
            ], $rows),
        ];
    }

    /** "Category: …; Action: …" summary of the active filters, for the export audit entry. */
    public function describe(array $f): string
    {
        $parts = [];
        foreach (['q' => 'Search', 'action' => 'Action', 'role' => 'Role', 'from' => 'From', 'to' => 'To'] as $k => $label) {
            if ($f[$k] !== '') {
                $parts[] = $label . ': ' . $f[$k];
            }
        }
        if ($f['category'] !== '') {
            $parts[] = 'Category: ' . self::CATEGORIES[$f['category']][0];
        }
        if ($f['user']) {
            $parts[] = 'Account #' . $f['user'];
        }
        return $parts ? implode('; ', $parts) : 'All events';
    }
}
