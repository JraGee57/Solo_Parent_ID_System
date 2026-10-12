<?php
require_once __DIR__ . '/../models/QrSlip.php';
require_once __DIR__ . '/../models/Application.php';
require_once __DIR__ . '/../models/Appointment.php';

/**
 * MSWDO staff QR verification. Takes whatever was scanned or typed -- the
 * slip's QR link, a bare 64-character token, the 16-character slip code
 * printed under the QR, or an application reference number -- and returns
 * one of four results:
 *
 *   VALID    an active slip of an application that is not expired
 *   EXPIRED  a real slip, but it (or the Solo Parent ID it belongs to) has expired
 *   INVALID  a real slip that was revoked (replaced by a newer slip, or the application was rejected)
 *   UNKNOWN  not an MSWDO slip, or no slip matches
 *
 * Only what a counter check needs is returned (name, reference, type,
 * category, status, validity, appointment) -- never address, contacts,
 * children or documents; the full record is a separate, permission-checked
 * page. Every check is audit-logged, and a staff session that keeps
 * producing UNKNOWN results is briefly throttled.
 */
class QrVerificationService
{
    public const MAX_UNKNOWN = 10;          // unknown lookups allowed ...
    public const WINDOW_SECONDS = 300;      // ... per this many seconds, per staff session
    public const MAX_INPUT_LENGTH = 300;

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** True while this staff session is throttled for too many unknown codes. */
    public function throttled(): bool
    {
        $recent = array_filter($_SESSION['qr_unknown'] ?? [], fn($t) => $t > time() - self::WINDOW_SECONDS);
        $_SESSION['qr_unknown'] = array_values($recent);
        return count($recent) >= self::MAX_UNKNOWN;
    }

    /**
     * Verifies $input for $staff. Returns ['result' => VALID|EXPIRED|INVALID|UNKNOWN,
     * 'message' => string, 'app' => ?array (limited fields), 'slip' => ?array, 'via' => how it was matched].
     */
    public function verify(array $staff, string $input): array
    {
        $input = trim($input);
        [$slip, $via] = $this->findSlip($input);
        if (!$slip) {
            $_SESSION['qr_unknown'][] = time();
            $messages = [
                'reference' => 'No application with a slip matches that reference number.',
                'ambiguous code' => 'That slip code matches more than one slip. Scan the QR code instead.',
            ];
            $outcome = ['result' => 'UNKNOWN', 'message' => $messages[$via] ?? 'This is not a recognised MSWDO Virac QR slip.', 'app' => null, 'slip' => null, 'via' => $via];
            $this->log($staff, $outcome, null, $input);
            return $outcome;
        }

        $app = $this->counterView((int) $slip['application_id']);
        if ($slip['revoked_at']) {
            $reason = $slip['revoke_reason'] ?: 'revoked';
            $outcome = ['result' => 'INVALID', 'message' => 'This slip is no longer valid: ' . strtolower($reason) . ' on ' . date('M j, Y', strtotime($slip['revoked_at'])) . '.'
                . (str_contains(strtolower($reason), 'replaced') ? ' Ask the applicant for their current slip.' : ''), 'app' => $app, 'slip' => $slip, 'via' => $via];
        } elseif ($expiredOn = $this->expiredOn($slip, $app)) {
            $outcome = ['result' => 'EXPIRED', 'message' => 'This slip expired on ' . date('F j, Y', strtotime($expiredOn)) . '. The applicant needs to renew their Solo Parent ID.', 'app' => $app, 'slip' => $slip, 'via' => $via];
        } else {
            $outcome = ['result' => 'VALID', 'message' => $app['status'] === 'ID Released'
                ? 'Authentic Solo Parent ID slip, valid' . ($app['id_expires_at'] ? ' until ' . date('F j, Y', strtotime($app['id_expires_at'])) : '') . '.'
                : 'Authentic MSWDO application slip. The application is ' . strtolower($app['status']) . '.', 'app' => $app, 'slip' => $slip, 'via' => $via];
        }
        $this->log($staff, $outcome, $app, $input);
        return $outcome;
    }

    /**
     * The slip matching the input, and how it matched: 'token' (QR link or
     * bare token), 'code' (printed slip code) or 'reference' (the
     * application's current slip). [null, reason] if nothing matches.
     */
    private function findSlip(string $input): array
    {
        $slips = new QrSlip($this->pdo);
        if ($input === '' || mb_strlen($input) > self::MAX_INPUT_LENGTH) {
            return [null, 'input'];
        }
        // The slip's QR link (…/staff/qr_verify.php?t=<token>) or just the token. A link must point at this
        // system's own verification page -- a QR leading anywhere else is not an MSWDO slip, whatever it carries.
        if (preg_match('~^(https?://\S+?)[?&]t=([0-9a-f]{64})$~i', $input, $m)) {
            $ours = parse_url($m[1], PHP_URL_PATH) === parse_url(base_url('staff/qr_verify.php'), PHP_URL_PATH);
            return [$ours ? $slips->findByToken(strtolower($m[2])) : null, 'token'];
        }
        if (preg_match('/^[0-9a-f]{64}$/i', $input)) {
            return [$slips->findByToken(strtolower($input)), 'token'];
        }
        // The 16-character slip code printed under the QR (dashes/spaces optional).
        $code = strtolower(preg_replace('/[\s-]/', '', $input));
        if (preg_match('/^[0-9a-f]{16}$/', $code)) {
            $matches = $slips->findByTokenPrefix($code);
            return count($matches) === 1 ? [$matches[0], 'code'] : [null, count($matches) > 1 ? 'ambiguous code' : 'code'];
        }
        // An application reference number (typed, or an old status-link QR): its current slip.
        if (preg_match('/[?&]ref=([^&\s]+)/', $input, $m)) {
            $input = urldecode($m[1]);
        }
        if (preg_match('/^[A-Za-z0-9-]{3,30}$/', $input)) {
            $id = (new Application($this->pdo))->findIdByReference($input);
            return [$id ? ($slips->activeFor($id) ?? $slips->latestFor($id)) : null, 'reference'];
        }
        return [null, 'input'];
    }

    /** Limited application details for the counter check (no address, contacts, children or documents). */
    private function counterView(int $applicationId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.id, a.reference_no, a.type, a.full_name, a.status, a.id_expires_at, a.submitted_at, c.name AS category_name
             FROM applications a JOIN categories c ON c.id = a.category_id WHERE a.id = ?'
        );
        $stmt->execute([$applicationId]);
        $app = $stmt->fetch() ?: null;
        if ($app) {
            $appt = (new Appointment($this->pdo))->interviewFor($applicationId);
            $app['appointment'] = $appt && $appt['status'] === 'BOOKED' ? ['scheduled_at' => $appt['scheduled_at'], 'location' => $appt['location']] : null;
        }
        return $app;
    }

    /** The date the slip stopped being valid, or null if it hasn't: its own expiry, or the released ID's. */
    private function expiredOn(array $slip, ?array $app): ?string
    {
        if ($slip['expires_at'] && strtotime($slip['expires_at']) < time()) {
            return $slip['expires_at'];
        }
        if ($app && $app['status'] === 'ID Released' && $app['id_expires_at'] && $app['id_expires_at'] < date('Y-m-d')) {
            return $app['id_expires_at'];
        }
        return null;
    }

    private function log(array $staff, array $outcome, ?array $app, string $input): void
    {
        $what = $app ? $app['reference_no'] : 'input "' . mb_substr(preg_replace('/\s+/', ' ', $input), 0, 60) . '"';
        audit_log($this->pdo, (int) $staff['id'], $staff['full_name'], $staff['role'], 'QR Verification: ' . $outcome['result'],
            $app ? 'application' : 'qr_slip', $app ? (int) $app['id'] : null, $what . ' (by ' . $outcome['via'] . ')');
    }
}
