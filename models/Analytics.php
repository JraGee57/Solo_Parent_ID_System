<?php
require_once __DIR__ . '/Model.php';

/**
 * Read-only aggregate queries behind Administrator Analytics. Every number
 * comes from the live tables; a period is [from, to) as 'Y-m-d H:i:s'.
 */
class Analytics extends Model
{
    /** Registered Solo Parent accounts, and how many of them have filed at least one application. */
    public function applicantCounts(): array
    {
        return $this->pdo->query(
            "SELECT COUNT(*) AS registered,
                    SUM(EXISTS (SELECT 1 FROM applications a WHERE a.user_id = u.id)) AS with_application
             FROM users u WHERE u.role = 'applicant'"
        )->fetch();
    }

    /** Applications submitted in the period, by type: ['new' => n, 'renewal' => n]. */
    public function submittedByType(string $from, string $to): array
    {
        $stmt = $this->pdo->prepare('SELECT type, COUNT(*) FROM applications WHERE submitted_at >= ? AND submitted_at < ? GROUP BY type');
        $stmt->execute([$from, $to]);
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR) + ['new' => 0, 'renewal' => 0];
    }

    /** Applications that reached $status during the period (from the status history), counted once each. */
    public function reachedStatus(string $status, string $from, string $to): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(DISTINCT application_id) FROM application_status_history WHERE status = ? AND changed_at >= ? AND changed_at < ?');
        $stmt->execute([$status, $from, $to]);
        return (int) $stmt->fetchColumn();
    }

    /** Current pipeline: status => count, for every status (zero included). */
    public function byStatusNow(): array
    {
        $counts = $this->pdo->query('SELECT status, COUNT(*) FROM applications GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
        $out = [];
        foreach (Application::STATUSES as $s) {
            $out[$s] = (int) ($counts[$s] ?? 0);
        }
        return $out;
    }

    /** Per calendar month in the period: ym => ['new' => n, 'renewal' => n], every month present (zero-filled). */
    public function monthly(string $from, string $to): array
    {
        $months = [];
        for ($m = new DateTime(substr($from, 0, 7) . '-01'), $end = new DateTime($to); $m < $end; $m->modify('+1 month')) {
            $months[$m->format('Y-m')] = ['new' => 0, 'renewal' => 0];
        }
        $stmt = $this->pdo->prepare(
            "SELECT DATE_FORMAT(submitted_at, '%Y-%m') AS ym, type, COUNT(*) AS n FROM applications
             WHERE submitted_at >= ? AND submitted_at < ? GROUP BY ym, type"
        );
        $stmt->execute([$from, $to]);
        foreach ($stmt->fetchAll() as $r) {
            if (isset($months[$r['ym']])) {
                $months[$r['ym']][$r['type']] = (int) $r['n'];
            }
        }
        return $months;
    }

    /**
     * Applications submitted in the period by barangay. Names are grouped
     * ignoring case and extra spaces; the display name is the most common spelling.
     */
    public function byBarangay(string $from, string $to): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT LOWER(TRIM(REGEXP_REPLACE(barangay, '[[:space:]]+', ' '))) AS k, TRIM(REGEXP_REPLACE(barangay, '[[:space:]]+', ' ')) AS name, COUNT(*) AS n
             FROM applications WHERE submitted_at >= ? AND submitted_at < ? AND TRIM(barangay) <> ''
             GROUP BY k, name ORDER BY n DESC"
        );
        $stmt->execute([$from, $to]);
        $groups = [];
        foreach ($stmt->fetchAll() as $r) {
            $groups[$r['k']] ??= ['name' => $r['name'], 'n' => 0];
            $groups[$r['k']]['n'] += (int) $r['n'];
        }
        usort($groups, fn($a, $b) => $b['n'] <=> $a['n'] ?: strcasecmp($a['name'], $b['name']));
        return $groups;
    }

    /** Applications submitted in the period per solo-parent category (every category, zero included). */
    public function byCategory(string $from, string $to): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.name, COUNT(a.id) AS n FROM categories c
             LEFT JOIN applications a ON a.category_id = c.id AND a.submitted_at >= ? AND a.submitted_at < ?
             GROUP BY c.id, c.name ORDER BY n DESC, c.sort_order, c.name'
        );
        $stmt->execute([$from, $to]);
        return $stmt->fetchAll();
    }

    /** Applications submitted in the period grouped by a fixed demographic column ('sex' or 'civil_status'). */
    public function byDemographic(string $column, string $from, string $to): array
    {
        if (!in_array($column, ['sex', 'civil_status'], true)) {
            throw new InvalidArgumentException('Unknown column');
        }
        $stmt = $this->pdo->prepare("SELECT COALESCE(NULLIF($column, ''), 'Not stated') AS name, COUNT(*) AS n FROM applications
                                     WHERE submitted_at >= ? AND submitted_at < ? GROUP BY name ORDER BY n DESC, name");
        $stmt->execute([$from, $to]);
        return $stmt->fetchAll();
    }

    /** Released IDs expiring from today through $days ahead, soonest first (with whether a renewal was filed). */
    public function expiringIds(int $days): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT a.id, a.reference_no, a.full_name, a.barangay, a.id_expires_at,
                    EXISTS (SELECT 1 FROM applications r WHERE r.renewal_of_id = a.id AND r.status <> 'Rejected') AS renewal_filed
             FROM applications a
             WHERE a.status = 'ID Released' AND a.id_expires_at BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
             ORDER BY a.id_expires_at, a.reference_no"
        );
        $stmt->execute([$days]);
        return $stmt->fetchAll();
    }

    /** Released IDs already past their expiry date with no renewal filed (or only a rejected one). */
    public function expiredNotRenewed(): int
    {
        return (int) $this->pdo->query(
            "SELECT COUNT(*) FROM applications a
             WHERE a.status = 'ID Released' AND a.id_expires_at < CURDATE()
               AND NOT EXISTS (SELECT 1 FROM applications r WHERE r.renewal_of_id = a.id AND r.status <> 'Rejected')"
        )->fetchColumn();
    }

    /** Appointments scheduled in the period, by status (every status, zero included). */
    public function appointmentsByStatus(string $from, string $to): array
    {
        $stmt = $this->pdo->prepare('SELECT status, COUNT(*) FROM appointments WHERE scheduled_at >= ? AND scheduled_at < ? GROUP BY status');
        $stmt->execute([$from, $to]);
        $counts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $out = [];
        foreach (Appointment::STATUSES as $s) {
            $out[$s] = (int) ($counts[$s] ?? 0);
        }
        return $out;
    }

    /** Appointments still to come (booked, not cancelled): today and later. */
    public function upcomingAppointments(): array
    {
        return $this->pdo->query(
            "SELECT SUM(DATE(scheduled_at) = CURDATE()) AS today, COUNT(*) AS upcoming
             FROM appointments WHERE status = 'BOOKED' AND scheduled_at >= CURDATE()"
        )->fetch();
    }

    /** Open (not cancelled) slots from now on: total seats and seats taken by booked appointments. */
    public function slotUtilization(): array
    {
        return $this->pdo->query(
            "SELECT COUNT(*) AS slots, COALESCE(SUM(s.capacity), 0) AS capacity,
                    COALESCE(SUM((SELECT COUNT(*) FROM appointments i WHERE i.slot_id = s.id AND i.status = 'BOOKED')), 0) AS booked
             FROM appointment_slots s
             WHERE s.status = 'AVAILABLE' AND TIMESTAMP(s.slot_date, s.start_time) >= NOW()"
        )->fetch();
    }

    /** Date of the first application ever submitted, or null. */
    public function firstSubmission(): ?string
    {
        $v = $this->pdo->query('SELECT MIN(submitted_at) FROM applications')->fetchColumn();
        return $v ?: null;
    }
}
