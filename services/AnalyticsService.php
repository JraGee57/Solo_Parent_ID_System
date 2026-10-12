<?php
require_once __DIR__ . '/../models/Analytics.php';
require_once __DIR__ . '/../models/Application.php';
require_once __DIR__ . '/../models/Appointment.php';

/**
 * Administrator Analytics: summary figures and chart data for a chosen
 * period, all computed from the database (nothing is hard-coded or cached).
 *
 * Period figures count what happened inside the period: applications by
 * submission date, approvals and rejections by the date the status was set,
 * appointments by their scheduled date. "Now" figures (pending, expiring
 * IDs, upcoming appointments, slot use) describe the current state.
 */
class AnalyticsService
{
    public const PERIODS = ['12m' => 'Last 12 months', 'ytd' => 'This year', 'last_year' => 'Last year', 'all' => 'All time', 'custom' => 'Custom range'];
    public const MAX_MONTHS = 36;     // the monthly chart shows at most this many months
    public const BARANGAY_TOP = 12;   // the rest are grouped as "Other"

    private Analytics $analytics;

    public function __construct(private PDO $pdo)
    {
        $this->analytics = new Analytics($pdo);
    }

    /**
     * The period from the query string: ['key', 'label', 'from' (Y-m-d), 'to' (Y-m-d, inclusive), 'error' => ?string].
     * Invalid custom dates fall back to the last 12 months with an error to show.
     */
    public function period(array $get): array
    {
        $key = is_string($get['period'] ?? null) && isset(self::PERIODS[$get['period']]) ? $get['period'] : '12m';
        $today = date('Y-m-d');
        $error = null;
        switch ($key) {
            case 'ytd':
                [$from, $to] = [date('Y-01-01'), $today];
                break;
            case 'last_year':
                [$from, $to] = [(date('Y') - 1) . '-01-01', (date('Y') - 1) . '-12-31'];
                break;
            case 'all':
                $first = $this->analytics->firstSubmission();
                [$from, $to] = [$first ? substr($first, 0, 10) : $today, $today];
                break;
            case 'custom':
                $from = is_string($get['from'] ?? null) ? $get['from'] : '';
                $to = is_string($get['to'] ?? null) ? $get['to'] : '';
                if (!is_ymd($from) || !is_ymd($to)) {
                    $error = 'Enter both dates of the custom range.';
                } elseif ($from > $to) {
                    $error = 'The start date must be on or before the end date.';
                } elseif ($from < '2000-01-01' || $to > date('Y-m-d', strtotime('+1 year'))) {
                    $error = 'Choose dates between 2000 and next year.';
                }
                if ($error) {
                    $key = '12m';
                }
                break;
        }
        if ($key === '12m') {
            [$from, $to] = [date('Y-m-01', strtotime('first day of -11 months')), $today];
        }
        $label = $key === 'custom' || $key === 'all'
            ? date('M j, Y', strtotime($from)) . ' – ' . date('M j, Y', strtotime($to))
            : self::PERIODS[$key] . ' (' . date('M j, Y', strtotime($from)) . ' – ' . date('M j, Y', strtotime($to)) . ')';
        return ['key' => $key, 'label' => $label, 'from' => $from, 'to' => $to, 'error' => $error];
    }

    /** Everything the Analytics page shows, for one period. */
    public function build(array $period): array
    {
        $a = $this->analytics;
        [$from, $to] = [$period['from'] . ' 00:00:00', date('Y-m-d', strtotime($period['to'] . ' +1 day')) . ' 00:00:00'];

        $applicants = $a->applicantCounts();
        $submitted = $a->submittedByType($from, $to);
        $statusNow = $a->byStatusNow();
        $pending = array_sum(array_intersect_key($statusNow, array_flip(Application::IN_PROGRESS_STATUSES)));
        $windowDays = max(1, (int) setting('renewal_reminder_days'));
        $expiring = $a->expiringIds($windowDays);

        // Monthly chart: the last MAX_MONTHS months of the period.
        [$fy, $fm] = array_map('intval', explode('-', substr($period['from'], 0, 7)));
        [$ty, $tm] = array_map('intval', explode('-', substr($period['to'], 0, 7)));
        $chartFrom = ($ty - $fy) * 12 + ($tm - $fm) + 1 > self::MAX_MONTHS
            ? date('Y-m-01 00:00:00', strtotime(sprintf('%04d-%02d-01', $ty, $tm) . ' -' . (self::MAX_MONTHS - 1) . ' months'))
            : $from;

        $barangays = $a->byBarangay($from, $to);
        $top = array_slice($barangays, 0, self::BARANGAY_TOP);
        $rest = array_slice($barangays, self::BARANGAY_TOP);
        if ($rest) {
            $top[] = ['name' => 'Other (' . count($rest) . ' barangays)', 'n' => array_sum(array_column($rest, 'n')), 'other' => true];
        }

        $slots = $a->slotUtilization();
        $upcoming = $a->upcomingAppointments();
        $apptStatus = $a->appointmentsByStatus($from, $to);

        return [
            'cards' => [
                'applicants' => (int) $applicants['registered'],
                'applicantsWithApplication' => (int) $applicants['with_application'],
                'new' => (int) $submitted['new'],
                'renewals' => (int) $submitted['renewal'],
                'approved' => $a->reachedStatus('Approved', $from, $to),
                'rejected' => $a->reachedStatus('Rejected', $from, $to),
                'pending' => $pending,
                'expiring' => count($expiring),
                'expiringWindow' => $windowDays,
                'expired' => $a->expiredNotRenewed(),
                'appointments' => array_sum($apptStatus),
            ],
            'monthly' => $a->monthly($chartFrom, $to),
            'monthlyTruncated' => $chartFrom !== $from,
            'byBarangay' => $top,
            'barangayCount' => count($barangays),
            'byStatus' => $statusNow,
            'byCategory' => $a->byCategory($from, $to),
            'bySex' => $a->byDemographic('sex', $from, $to),
            'byCivilStatus' => $a->byDemographic('civil_status', $from, $to),
            'expiringIds' => $expiring,
            'appointments' => [
                'byStatus' => $apptStatus,
                'today' => (int) $upcoming['today'],
                'upcoming' => (int) $upcoming['upcoming'],
                'slots' => (int) $slots['slots'],
                'capacity' => (int) $slots['capacity'],
                'booked' => (int) $slots['booked'],
                'utilization' => (int) $slots['capacity'] > 0 ? (int) round($slots['booked'] / $slots['capacity'] * 100) : null,
            ],
        ];
    }
}
