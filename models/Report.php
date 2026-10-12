<?php
require_once __DIR__ . '/Model.php';
require_once __DIR__ . '/Application.php';

/**
 * The eight operational reports (Reports page) plus the system-monitoring
 * queries. Each report is a definition: base query, columns (with how each
 * is formatted), the date it filters on, search columns, optional status /
 * type filters, and for grouped reports the columns to total. Every filter
 * value is bound as a parameter; column and filter names come only from
 * these definitions.
 */
class Report extends Model
{
    public const TYPES = [
        'applicant'   => 'Applicant Report',
        'application' => 'Application Report',
        'approval'    => 'Approval Report',
        'rejection'   => 'Rejection Report',
        'renewal'     => 'Renewal Report',
        'barangay'    => 'Barangay Distribution',
        'appointment' => 'Appointment Report',
        'expiring'    => 'Expiring ID Report',
    ];
    public const DESCRIPTIONS = [
        'applicant'   => 'Solo Parent accounts with their barangay, applications and latest status. Dates filter on registration.',
        'application' => 'Every application (new and renewal) by submission date.',
        'approval'    => 'Each time an application was approved, with who approved it. Dates filter on the decision.',
        'rejection'   => 'Each time an application was rejected, with the reason given. Dates filter on the decision.',
        'renewal'     => 'Renewal requests by submission date, with the ID each one renews.',
        'barangay'    => 'Applications per barangay (spellings merged), by submission date.',
        'appointment' => 'Appointments by scheduled date, with their outcome.',
        'expiring'    => 'Released IDs by expiry date, with days left and whether a renewal was filed.',
    ];
    public const PER_PAGE = 50;
    public const EXPORT_LIMIT = 10000;

    /**
     * One page of a report. $f: from, to (Y-m-d or ''), q, status, type (already validated).
     * Returns ['rows', 'total', 'totals' (grouped reports), ...paginate() fields].
     */
    public function run(string $type, array $f, int $page): array
    {
        [$sql, $params] = $this->query($type, $f);
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM ($sql) t");
        $count->execute($params);
        $pager = paginate((int) $count->fetchColumn(), $page, self::PER_PAGE);
        $def = $this->definition($type);
        $stmt = $this->pdo->prepare($sql . " ORDER BY {$def['orderBy']} LIMIT {$pager['perPage']} OFFSET {$pager['offset']}");
        $stmt->execute($params);
        return ['rows' => $stmt->fetchAll(), 'totals' => $this->totals($type, $f)] + $pager;
    }

    /** Every matching row for CSV/print, or null when there are more than EXPORT_LIMIT. */
    public function all(string $type, array $f): ?array
    {
        [$sql, $params] = $this->query($type, $f);
        $def = $this->definition($type);
        $stmt = $this->pdo->prepare($sql . " ORDER BY {$def['orderBy']} LIMIT " . (self::EXPORT_LIMIT + 1));
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        return count($rows) > self::EXPORT_LIMIT ? null : $rows;
    }

    public function totalsFor(string $type, array $f): array
    {
        return $this->totals($type, $f);
    }

    /** The report's columns: key => [label, format]; formats: text, int, datetime, date. */
    public function columns(string $type): array
    {
        return $this->definition($type)['columns'];
    }

    /** What the date range filters on, e.g. "Submitted". */
    public function dateLabel(string $type): string
    {
        return $this->definition($type)['dateLabel'];
    }

    /** The extra filters a report offers: name => [label, options value => label]. */
    public function filterOptions(string $type): array
    {
        return array_map(fn($f) => [$f[0], $f[2]], $this->definition($type)['filters'] ?? []);
    }

    /** [sql without ORDER BY, params]. $grouped = false drops GROUP BY (one aggregate row over everything matched). */
    private function query(string $type, array $f, bool $grouped = true): array
    {
        $def = $this->definition($type);
        $where = $def['where'] ?? [];
        $params = $def['params'] ?? [];
        $dateOnly = $def['dateOnly'] ?? false;
        if ($f['from'] !== '') {
            $where[] = "{$def['dateColumn']} >= ?";
            $params[] = $dateOnly ? $f['from'] : $f['from'] . ' 00:00:00';
        }
        if ($f['to'] !== '') {
            $where[] = "{$def['dateColumn']} <= ?";
            $params[] = $dateOnly ? $f['to'] : $f['to'] . ' 23:59:59';
        }
        if ($f['q'] !== '') {
            $where[] = '(' . implode(' OR ', array_map(fn($c) => "$c LIKE ?", $def['search'])) . ')';
            foreach ($def['search'] as $_) {
                $params[] = '%' . $f['q'] . '%';
            }
        }
        foreach ($def['filters'] ?? [] as $name => [, $column, $options]) {
            if (($f[$name] ?? '') !== '' && isset($options[$f[$name]])) {
                $where[] = "$column = ?";
                $params[] = $f[$name];
            }
        }
        $sql = $def['select'] . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ($grouped ? ($def['groupBy'] ?? '') : '');
        return [$sql, $params];
    }

    /**
     * Totals for grouped reports (barangay distribution), else []: the same
     * aggregates over everything matched, so an applicant with applications
     * in two barangays is still counted once.
     */
    private function totals(string $type, array $f): array
    {
        $keys = $this->definition($type)['totals'] ?? [];
        if (!$keys) {
            return [];
        }
        [$sql, $params] = $this->query($type, $f, false);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch() ?: [];
        return array_map(fn($k) => (int) ($row[$k] ?? 0), array_combine($keys, $keys));
    }

    private function definition(string $type): array
    {
        $statuses = array_combine(Application::STATUSES, Application::STATUSES);
        $barangayKey = "LOWER(TRIM(REGEXP_REPLACE(a.barangay, '[[:space:]]+', ' ')))";
        switch ($type) {
            case 'applicant':
                return [
                    'select' => "SELECT u.full_name, u.username, u.email, u.mobile, p.barangay,
                                   CASE WHEN u.is_active = 1 THEN 'Active' ELSE 'Deactivated' END AS account_status,
                                   (SELECT COUNT(*) FROM applications a WHERE a.user_id = u.id) AS app_count,
                                   (SELECT a.status FROM applications a WHERE a.user_id = u.id ORDER BY a.submitted_at DESC, a.id DESC LIMIT 1) AS latest_status,
                                   u.created_at
                                 FROM users u LEFT JOIN applicants p ON p.user_id = u.id",
                    'where' => ["u.role = 'applicant'"],
                    'dateColumn' => 'u.created_at', 'dateLabel' => 'Registered',
                    'search' => ['u.full_name', 'u.username', 'u.email', 'u.mobile', 'p.barangay'],
                    'filters' => ['account' => ['Account', "CASE WHEN u.is_active = 1 THEN 'active' ELSE 'inactive' END", ['active' => 'Active', 'inactive' => 'Deactivated']]],
                    'orderBy' => 'u.created_at DESC, u.id DESC',
                    'columns' => ['full_name' => ['Full Name', 'text'], 'username' => ['Username', 'text'], 'email' => ['Email', 'text'], 'mobile' => ['Mobile', 'text'],
                        'barangay' => ['Barangay', 'text'], 'account_status' => ['Account', 'text'], 'app_count' => ['Applications', 'int'],
                        'latest_status' => ['Latest Status', 'text'], 'created_at' => ['Registered', 'datetime']],
                ];
            case 'approval':
            case 'rejection':
                return [
                    'select' => "SELECT a.reference_no, a.full_name, CASE a.type WHEN 'renewal' THEN 'Renewal' ELSE 'New' END AS type_label,
                                   a.barangay, c.name AS category_name, h.changed_at AS decided_at, h.changed_by, h.remarks, a.status AS current_status
                                 FROM application_status_history h
                                 JOIN applications a ON a.id = h.application_id
                                 JOIN categories c ON c.id = a.category_id",
                    'where' => ['h.status = ?'],
                    'params' => [$type === 'approval' ? 'Approved' : 'Rejected'],
                    'dateColumn' => 'h.changed_at', 'dateLabel' => 'Decided',
                    'search' => ['a.reference_no', 'a.full_name', 'a.barangay', 'h.changed_by'],
                    'filters' => ['type' => ['Type', 'a.type', ['new' => 'New', 'renewal' => 'Renewal']]],
                    'orderBy' => 'h.changed_at DESC, h.id DESC',
                    'columns' => ['reference_no' => ['Reference No', 'text'], 'full_name' => ['Full Name', 'text'], 'type_label' => ['Type', 'text'], 'barangay' => ['Barangay', 'text'],
                        'category_name' => ['Category', 'text'], 'decided_at' => [$type === 'approval' ? 'Approved' : 'Rejected', 'datetime'], 'changed_by' => ['Decided By', 'text'],
                        'remarks' => [$type === 'approval' ? 'Remarks' : 'Reason', 'text'], 'current_status' => ['Current Status', 'text']],
                ];
            case 'renewal':
                return [
                    'select' => 'SELECT a.reference_no, a.full_name, a.barangay, c.name AS category_name, src.reference_no AS renews, src.id_expires_at AS old_expiry, a.status, a.submitted_at
                                 FROM applications a JOIN categories c ON c.id = a.category_id LEFT JOIN applications src ON src.id = a.renewal_of_id',
                    'where' => ["a.type = 'renewal'"],
                    'dateColumn' => 'a.submitted_at', 'dateLabel' => 'Submitted',
                    'search' => ['a.reference_no', 'a.full_name', 'a.barangay', 'src.reference_no'],
                    'filters' => ['status' => ['Status', 'a.status', $statuses]],
                    'orderBy' => 'a.submitted_at DESC, a.id DESC',
                    'columns' => ['reference_no' => ['Reference No', 'text'], 'full_name' => ['Full Name', 'text'], 'barangay' => ['Barangay', 'text'], 'category_name' => ['Category', 'text'],
                        'renews' => ['Renews ID', 'text'], 'old_expiry' => ['Old ID Expiry', 'date'], 'status' => ['Status', 'text'], 'submitted_at' => ['Submitted', 'datetime']],
                ];
            case 'barangay':
                return [
                    'select' => "SELECT MIN(TRIM(REGEXP_REPLACE(a.barangay, '[[:space:]]+', ' '))) AS barangay,
                                   COUNT(DISTINCT a.user_id) AS applicants,
                                   COUNT(*) AS total,
                                   SUM(a.type = 'new') AS new_count,
                                   SUM(a.type = 'renewal') AS renewal_count,
                                   SUM(a.status IN ('Pending Review','Under Verification','For Revision','Interview Scheduled')) AS pending_count,
                                   SUM(a.status IN ('Approved','ID Released')) AS approved_count,
                                   SUM(a.status = 'Rejected') AS rejected_count
                                 FROM applications a",
                    'dateColumn' => 'a.submitted_at', 'dateLabel' => 'Submitted',
                    'search' => ['a.barangay'],
                    'groupBy' => " GROUP BY $barangayKey",
                    'orderBy' => 'total DESC, barangay',
                    'totals' => ['applicants', 'total', 'new_count', 'renewal_count', 'pending_count', 'approved_count', 'rejected_count'],
                    'columns' => ['barangay' => ['Barangay', 'text'], 'applicants' => ['Applicants', 'int'], 'total' => ['Applications', 'int'], 'new_count' => ['New', 'int'],
                        'renewal_count' => ['Renewal', 'int'], 'pending_count' => ['Pending', 'int'], 'approved_count' => ['Approved / Released', 'int'], 'rejected_count' => ['Rejected', 'int']],
                ];
            case 'appointment':
                return [
                    'select' => "SELECT a.reference_no, a.full_name, a.mobile, i.scheduled_at, i.location,
                                   CASE i.status WHEN 'BOOKED' THEN 'Booked' WHEN 'COMPLETED' THEN 'Completed' WHEN 'MISSED' THEN 'Missed' ELSE 'Cancelled' END AS status_label,
                                   CASE WHEN i.scheduled_by IS NULL THEN 'Applicant' ELSE 'Staff' END AS booked_by,
                                   COALESCE(i.result, i.cancel_reason, '') AS outcome
                                 FROM appointments i JOIN applications a ON a.id = i.application_id",
                    'dateColumn' => 'i.scheduled_at', 'dateLabel' => 'Scheduled',
                    'search' => ['a.reference_no', 'a.full_name', 'i.location'],
                    'filters' => ['status' => ['Outcome', 'i.status', ['BOOKED' => 'Booked', 'COMPLETED' => 'Completed', 'MISSED' => 'Missed', 'CANCELLED' => 'Cancelled']]],
                    'orderBy' => 'i.scheduled_at DESC, i.id DESC',
                    'columns' => ['reference_no' => ['Reference No', 'text'], 'full_name' => ['Full Name', 'text'], 'mobile' => ['Mobile', 'text'], 'scheduled_at' => ['Scheduled', 'datetime'],
                        'location' => ['Location', 'text'], 'status_label' => ['Outcome', 'text'], 'booked_by' => ['Booked By', 'text'], 'outcome' => ['Result / Cancel Reason', 'text']],
                ];
            case 'expiring':
                return [
                    'select' => "SELECT a.reference_no, a.full_name, a.mobile, a.barangay, a.id_expires_at,
                                   DATEDIFF(a.id_expires_at, CURDATE()) AS days_left,
                                   CASE WHEN a.id_expires_at < CURDATE() THEN 'Expired' ELSE 'Expiring' END AS expiry_state,
                                   CASE WHEN EXISTS (SELECT 1 FROM applications r WHERE r.renewal_of_id = a.id AND r.status <> 'Rejected') THEN 'Filed' ELSE 'Not yet' END AS renewal
                                 FROM applications a",
                    'where' => ["a.status = 'ID Released'", 'a.id_expires_at IS NOT NULL'],
                    'dateColumn' => 'a.id_expires_at', 'dateOnly' => true, 'dateLabel' => 'Expires',
                    'search' => ['a.reference_no', 'a.full_name', 'a.barangay', 'a.mobile'],
                    'filters' => ['renewal' => ['Renewal', "CASE WHEN EXISTS (SELECT 1 FROM applications r WHERE r.renewal_of_id = a.id AND r.status <> 'Rejected') THEN 'filed' ELSE 'none' END", ['filed' => 'Filed', 'none' => 'Not yet']]],
                    'orderBy' => 'a.id_expires_at, a.reference_no',
                    'columns' => ['reference_no' => ['Reference No', 'text'], 'full_name' => ['Full Name', 'text'], 'mobile' => ['Mobile', 'text'], 'barangay' => ['Barangay', 'text'],
                        'id_expires_at' => ['Expires', 'date'], 'days_left' => ['Days Left', 'int'], 'expiry_state' => ['State', 'text'], 'renewal' => ['Renewal', 'text']],
                ];
            default: // application
                return [
                    'select' => "SELECT a.reference_no, a.full_name, CASE a.type WHEN 'renewal' THEN 'Renewal' ELSE 'New' END AS type_label, a.status,
                                   a.barangay, c.name AS category_name, a.mobile, a.submitted_at
                                 FROM applications a JOIN categories c ON c.id = a.category_id",
                    'dateColumn' => 'a.submitted_at', 'dateLabel' => 'Submitted',
                    'search' => ['a.reference_no', 'a.full_name', 'a.barangay', 'a.mobile'],
                    'filters' => ['status' => ['Status', 'a.status', $statuses], 'type' => ['Type', 'a.type', ['new' => 'New', 'renewal' => 'Renewal']]],
                    'orderBy' => 'a.submitted_at DESC, a.id DESC',
                    'columns' => ['reference_no' => ['Reference No', 'text'], 'full_name' => ['Full Name', 'text'], 'type_label' => ['Type', 'text'], 'status' => ['Status', 'text'],
                        'barangay' => ['Barangay', 'text'], 'category_name' => ['Category', 'text'], 'mobile' => ['Mobile', 'text'], 'submitted_at' => ['Submitted', 'datetime']],
                ];
        }
    }

    // ---- System monitoring ----

    /** table name => row count, for every table in the database. */
    public function tableRowCounts(): array
    {
        $counts = [];
        foreach ($this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $counts[$table] = (int) $this->pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
        }
        return $counts;
    }
}
