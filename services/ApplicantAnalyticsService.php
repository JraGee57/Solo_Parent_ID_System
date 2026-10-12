<?php
require_once __DIR__ . '/../models/Document.php';

/**
 * The applicant's own analytics (applicant/dashboard.php?section=analytics):
 * document counts, interview summary and a recent-activity timeline for one
 * of their applications. It works from an ApplicationTrackerService::track()
 * result, which has already loaded the documents, appointment and status
 * history, so the only extra query is the document status trail.
 *
 * The caller must pass an application taken from the signed-in applicant's
 * own list (Application::forUserDetailed) -- never one chosen by request input.
 */
class ApplicantAnalyticsService
{
    private const TIMELINE_LIMIT = 10;

    /** Applicant-facing wording for each application status reached. */
    private const STATUS_EVENTS = [
        'Pending Review'      => 'Application submitted',
        'Under Verification'  => 'Application under review',
        'For Revision'        => 'Corrections requested',
        'Interview Scheduled' => 'Interview scheduled',
        'Approved'            => 'Application approved',
        'ID Released'         => 'Solo Parent ID released',
        'Rejected'            => 'Application not approved',
    ];

    private Document $documents;

    public function __construct(PDO $pdo)
    {
        $this->documents = new Document($pdo);
    }

    /**
     * @return array{required: int, requiredUploaded: int, uploaded: int, verified: int, awaiting: int, correction: int, rejected: int, missing: int}
     *   Applicant checklist selections and independent staff verification results.
     */
    public static function documentCounts(array $docs): array
    {
        $c = ['required' => 0, 'requiredUploaded' => 0, 'uploaded' => 0, 'verified' => 0, 'awaiting' => 0, 'correction' => 0, 'rejected' => 0, 'missing' => 0];
        foreach ($docs as $d) {
            $checklist = array_key_exists('applicant_has', $d);
            $has = $checklist ? (bool) $d['applicant_has'] : $d['id'] !== null;
            if ($d['is_required']) {
                $c['required']++;
                $c[$has ? 'requiredUploaded' : 'missing']++;
            }
            if ($checklist) {
                if ($has) { $c['uploaded']++; }
                if ($d['is_required']) {
                    if ($d['verification_status'] === 'Verified') { $c['verified']++; }
                    elseif ($d['verification_status'] === 'Needs Correction') { $c['correction']++; }
                    else { $c['awaiting']++; }
                }
                continue;
            }
            if (!$has) {
                continue;
            }
            $c['uploaded']++;
            if ($d['verification_status'] === 'VERIFIED') {
                $c['verified']++;
            } elseif ($d['verification_status'] === 'NEEDS_CORRECTION') {
                $c['correction']++;
            } elseif ($d['verification_status'] === 'REJECTED') {
                $c['rejected']++;
            } else {
                $c['awaiting']++;
            }
        }
        return $c;
    }

    /**
     * Newest-first events from the application's status history, its
     * documents' status trail and its appointment. Only what the applicant
     * already sees elsewhere is used: the reasons given with corrections and
     * rejections, and staff remarks on flagged documents. Staff names,
     * internal case notes and interview result notes are never included.
     *
     * @return list<array{date: string, label: string, detail: string, tone: string}>
     */
    public function timeline(array $app, array $tracker): array
    {
        $events = [];
        $add = function (?string $date, string $label, string $detail = '', string $tone = 'info') use (&$events): void {
            if ($date) {
                $events[] = ['date' => $date, 'label' => $label, 'detail' => $detail, 'tone' => $tone];
            }
        };
        $tones = ['For Revision' => 'warn', 'Rejected' => 'bad', 'Approved' => 'good', 'ID Released' => 'good'];

        $scheduledInHistory = false;
        foreach ($tracker['history'] as $h) {
            if (!isset(self::STATUS_EVENTS[$h['status']])) {
                continue;
            }
            $scheduledInHistory = $scheduledInHistory || $h['status'] === 'Interview Scheduled';
            $reason = in_array($h['status'], Application::REASON_REQUIRED, true) ? (string) $h['remarks'] : '';
            $add($h['changed_at'], self::STATUS_EVENTS[$h['status']], $reason, $tones[$h['status']] ?? 'info');
        }
        if (!$tracker['history']) {
            $add($app['submitted_at'], self::STATUS_EVENTS['Pending Review']);
        }

        $labels = array_column($tracker['documents'], 'label', 'doc_key');
        foreach ($this->documents->statusHistoryFor((int) $app['id']) as $trail) {
            foreach ($trail as $h) {
                $doc = $labels[$h['doc_key']] ?? $h['doc_key'];
                switch ($h['to_status']) {
                    case 'FOR_VERIFICATION':
                        // A first upload or an applicant re-upload; a staff reset to "For Verification" is not an applicant event.
                        if ($h['from_status'] === null || $h['actor_role'] === 'applicant') {
                            $add($h['created_at'], ($h['from_status'] === null ? 'Document uploaded: ' : 'Document re-uploaded: ') . $doc);
                        }
                        break;
                    case 'VERIFIED':
                        $add($h['created_at'], 'Document verified: ' . $doc, '', 'good');
                        break;
                    case 'NEEDS_CORRECTION':
                        $add($h['created_at'], 'Document correction requested: ' . $doc, (string) $h['remarks'], 'warn');
                        break;
                    case 'REJECTED':
                        $add($h['created_at'], 'Document not accepted: ' . $doc, (string) $h['remarks'], 'bad');
                        break;
                }
            }
        }

        $appt = $tracker['appointment'];
        if ($appt) {
            if (!$scheduledInHistory) {
                $add($appt['created_at'], 'Interview scheduled', 'For ' . date('M j, Y g:i A', strtotime($appt['scheduled_at'])) . '.');
            }
            if ($appt['status'] === 'COMPLETED') {
                $add($appt['result_recorded_at'], 'Interview completed', '', 'good');
            } elseif ($appt['status'] === 'MISSED') {
                $add($appt['updated_at'], 'Interview missed', '', 'warn');
            } elseif ($appt['status'] === 'CANCELLED') {
                $add($appt['cancelled_at'], 'Interview cancelled');
            }
        }

        usort($events, fn($a, $b) => strcmp($b['date'], $a['date']));
        return array_slice($events, 0, self::TIMELINE_LIMIT);
    }
}
