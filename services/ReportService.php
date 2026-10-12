<?php
require_once __DIR__ . '/../models/Application.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/SmsLog.php';
require_once __DIR__ . '/../models/Report.php';
require_once __DIR__ . '/../models/AuditLog.php';

/** Numbers for the admin dashboard and Analytics page, plus CSV downloads. */
class ReportService
{
    private PDO $pdo;
    private Application $applications;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->applications = new Application($pdo);
    }

    public function dashboardStats(): array
    {
        $users = new User($this->pdo);
        $sms = new SmsLog($this->pdo);
        $count = fn(string $where) => $this->applications->countWhere($where);
        return [
            'totalApplicants'   => $users->countWhere("role = 'applicant'"),
            'activeUsers'       => $users->countWhere('is_active = 1'),
            'totalApplications' => $count('1=1'),
            'pendingNew'        => $count("type = 'new' AND status = 'Pending Review'"),
            'underVerification' => $count("status = 'Under Verification'"),
            'approvedNew'       => $count("type = 'new' AND status = 'Approved'"),
            'rejectedApps'      => $count("status = 'Rejected'"),
            'forRevision'       => $count("status = 'For Revision'"),
            'totalRenewals'     => $count("type = 'renewal'"),
            'pendingRenewals'   => $count("type = 'renewal' AND status = 'Pending Review'"),
            // Same definition as Analytics: released IDs expiring from today through the renewal reminder period.
            'expiringIds'       => $count("status = 'ID Released' AND id_expires_at BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL " . max(1, (int) setting('renewal_reminder_days')) . ' DAY)'),
            'expiringWindow'    => max(1, (int) setting('renewal_reminder_days')),
            'smsSent'           => $sms->countByStatus('sent'),
            'smsFailed'         => $sms->countByStatus('failed'),
            'smsLoggedOnly'     => $sms->countByStatus('test'),
            'smsPending'        => $sms->countByStatus('pending'),
        ];
    }

    /** Accounts by role and state, plus this month's new applicant registrations. */
    public function userStats(): array
    {
        $users = new User($this->pdo);
        return [
            'applicants' => $users->countWhere("role = 'applicant'"),
            'mswdStaff' => $users->countWhere("role = 'mswd_social_worker'"),
            'admins' => $users->countWhere("role = 'admin'"),
            'deactivated' => $users->countWhere('is_active = 0'),
            'locked' => $users->countWhere('locked_until > NOW()'),
            'newThisMonth' => $users->countWhere("role = 'applicant' AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"),
        ];
    }

    /** Security-relevant audit activity: logins today and denied/failed/reset events over the last 7 days. */
    public function auditStats(): array
    {
        $audit = new AuditLog($this->pdo);
        return [
            'eventsToday' => $audit->countWhere('created_at >= CURDATE()'),
            'loginsToday' => $audit->countWhere("action = 'Login' AND created_at >= CURDATE()"),
            'accessDenied7d' => $audit->countWhere("action = 'Access Denied' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"),
            'failedLogins7d' => $audit->countWhere("action IN ('Login Failed','Password Reset Failed') AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"),
            'passwordResets7d' => $audit->countWhere("action IN ('Password Reset Requested','Password Reset Completed','Password Reset') AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"),
        ];
    }

    /** Headline trend numbers: applications this month vs last month, and the approval rate of decided applications. */
    public function analyticsSummary(): array
    {
        $count = fn(string $where) => $this->applications->countWhere($where);
        $approved = $count("status IN ('Approved','ID Released')");
        $rejected = $count("status = 'Rejected'");
        return [
            'thisMonth' => $count("submitted_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"),
            'lastMonth' => $count("submitted_at >= DATE_FORMAT(CURDATE() - INTERVAL 1 MONTH, '%Y-%m-01') AND submitted_at < DATE_FORMAT(CURDATE(), '%Y-%m-01')"),
            'approvalRate' => $approved + $rejected > 0 ? (int) round($approved / ($approved + $rejected) * 100) : null,
            'decided' => $approved + $rejected,
        ];
    }

    /** export_csv.php types: 'applications' or 'users'. Returns [header row, data rows], or null for an unknown type. */
    public function fullExport(string $type): ?array
    {
        if ($type === 'applications') {
            return [
                ['Reference No', 'Type', 'Full Name', 'Category', 'Status', 'Mobile', 'Email', 'Reviewed By', 'Submitted At'],
                array_map(fn($r) => [
                    $r['reference_no'], $r['type'] === 'renewal' ? 'Renewal' : 'New Application', $r['full_name'], $r['category_name'],
                    $r['status'], $r['mobile'], $r['email'], $r['reviewer_name'] ?? '', $r['submitted_at'],
                ], $this->applications->allForExport()),
            ];
        }
        if ($type === 'users') {
            return [
                ['Username', 'Full Name', 'Email', 'Mobile', 'Role', 'Status', 'Created At'],
                array_map(fn($r) => [
                    $r['username'], $r['full_name'], $r['email'], $r['mobile'] ?? '', $r['role'], $r['is_active'] ? 'Active' : 'Deactivated', $r['created_at'],
                ], (new User($this->pdo))->allForExport()),
            ];
        }
        return null;
    }

    /** Sends a CSV download (UTF-8 with BOM so Excel renders accented characters correctly). */
    /**
     * Sends a CSV download (UTF-8 with BOM so Excel reads accents). Any cell
     * that a spreadsheet would run as a formula (starting with = + - @ or a
     * tab/CR) is prefixed with an apostrophe, so exported data can't execute.
     */
    public function streamCsv(string $filename, array $header, array $rows): void
    {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) . '"');
        header('X-Content-Type-Options: nosniff');
        $out = fopen('php://output', 'w');
        fprintf($out, "\xEF\xBB\xBF");
        fputcsv($out, array_map([self::class, 'csvSafe'], $header));
        foreach ($rows as $row) {
            fputcsv($out, array_map([self::class, 'csvSafe'], $row));
        }
        fclose($out);
    }

    public static function csvSafe(mixed $value): string
    {
        $value = (string) ($value ?? '');
        return $value !== '' && strpbrk($value[0], "=+-@\t\r") !== false && !preg_match('/^-?\d+(\.\d+)?$/', $value) ? "'" . $value : $value;
    }

    // ---- Reports page ----

    /**
     * The report filters from the query string, validated for $type:
     * [['from','to','q', and the report's own filters], error or null].
     * A wrong date range is dropped (with the error shown); unknown filter values are ignored.
     */
    public function reportFilters(string $type, array $get): array
    {
        $str = fn(string $k, int $max = 100) => is_string($get[$k] ?? null) ? mb_substr(trim($get[$k]), 0, $max) : '';
        $f = ['from' => $str('from', 10), 'to' => $str('to', 10), 'q' => $str('q'), 'status' => '', 'type' => '', 'account' => '', 'renewal' => ''];
        $error = null;
        foreach (['from', 'to'] as $k) {
            if ($f[$k] !== '' && !is_ymd($f[$k])) {
                $error = 'Enter dates as YYYY-MM-DD.';
                $f[$k] = '';
            }
        }
        if ($f['from'] !== '' && $f['to'] !== '' && $f['from'] > $f['to']) {
            $error = 'The "from" date must be on or before the "to" date.';
            [$f['from'], $f['to']] = ['', ''];
        }
        foreach ((new Report($this->pdo))->filterOptions($type) as $name => [, $options]) {
            $v = $str($name, 30);
            $f[$name] = isset($options[$v]) ? $v : '';
        }
        return [$f, $error];
    }

    /** A cell for screen/print: dates readable, numbers grouped, empty as a dash. */
    public static function formatCell(mixed $value, string $format): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        return match ($format) {
            'datetime' => date('M j, Y g:i A', strtotime((string) $value)),
            'date' => date('M j, Y', strtotime((string) $value)),
            'int' => number_format((int) $value),
            default => (string) $value,
        };
    }

    /** One line describing the active filters, e.g. 'Submitted Jan 1, 2026 – Mar 31, 2026 · Status: Approved · Search "Cruz"'. */
    public function describeFilters(string $type, array $f): string
    {
        $report = new Report($this->pdo);
        $parts = [];
        if ($f['from'] !== '' || $f['to'] !== '') {
            $parts[] = $report->dateLabel($type) . ' ' . ($f['from'] !== '' ? date('M j, Y', strtotime($f['from'])) : 'any time') . ' – ' . ($f['to'] !== '' ? date('M j, Y', strtotime($f['to'])) : 'today');
        }
        foreach ($report->filterOptions($type) as $name => [$label, $options]) {
            if ($f[$name] !== '') {
                $parts[] = $label . ': ' . $options[$f[$name]];
            }
        }
        if ($f['q'] !== '') {
            $parts[] = 'Search "' . $f['q'] . '"';
        }
        return $parts ? implode(' · ', $parts) : 'All records';
    }
}
