<?php
require_once __DIR__ . '/../models/QrSlip.php';
require_once __DIR__ . '/../includes/QrService.php';

/**
 * QR application slips. Each application gets a slip with a random 256-bit
 * token (64 hex characters) when it is submitted. The QR code encodes only
 * a link to the MSWDO staff verification page carrying that token -- no
 * name, reference number or other personal data -- and staff must be signed
 * in to see anything behind it.
 *
 * The token is revoked when the application is rejected or when the
 * applicant replaces a lost slip, and expires with the Solo Parent ID once
 * the ID is released.
 */
class QrSlipService
{
    private PDO $pdo;
    private QrSlip $slips;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->slips = new QrSlip($pdo);
    }

    /** Issues a new slip for the application (revoking any active one). $issuedBy: the user issuing it, if any. */
    public function issue(array $app, ?int $issuedBy, string $replacedReason = 'Replaced by a new slip'): array
    {
        $this->slips->revokeAll((int) $app['id'], $replacedReason);
        $token = bin2hex(random_bytes(32));
        $expiresAt = $app['status'] === 'ID Released' && $app['id_expires_at'] ? $app['id_expires_at'] . ' 23:59:59' : null;
        $this->slips->create((int) $app['id'], $token, $issuedBy, $expiresAt);
        return $this->slips->findByToken($token);
    }

    /** The application's active slip; applications from before slips existed get one issued now. Null for a rejected application. */
    public function slipFor(array $app): ?array
    {
        $slip = $this->slips->activeFor((int) $app['id']);
        if (!$slip && $app['status'] !== 'Rejected') {
            $slip = $this->issue($app, null);
        }
        return $slip;
    }

    /** The applicant (or staff) replaces a lost slip: the old QR stops working. */
    public function replace(array $app, array $actor): array
    {
        $slip = $this->issue($app, (int) $actor['id'], 'Reported lost / replaced');
        audit_log($this->pdo, (int) $actor['id'], $actor['full_name'], $actor['role'], 'QR Slip Replaced', 'application', (int) $app['id'], $app['reference_no']);
        return $slip;
    }

    /** Called when the application's status changes: released -> expires with the ID; rejected -> revoked. */
    public function onStatusChange(array $app, string $newStatus, ?string $idExpiresAt): void
    {
        if ($newStatus === 'Rejected') {
            $this->slips->revokeAll((int) $app['id'], 'Application rejected');
        } elseif ($newStatus === 'ID Released') {
            $this->slips->setExpiry((int) $app['id'], $idExpiresAt ? $idExpiresAt . ' 23:59:59' : null);
        }
    }

    /** What the QR code contains: the staff verification link with the token (nothing personal). */
    public static function qrPayload(string $token): string
    {
        return base_url('staff/qr_verify.php?t=' . $token);
    }

    /** Short code printed under the QR for manual entry: the token's first 16 characters in groups of 4. */
    public static function slipCode(string $token): string
    {
        return strtoupper(implode('-', str_split(substr($token, 0, 16), 4)));
    }

    /** The QR image (data URI) for a slip, or null if the QR library isn't installed. */
    public static function qrImage(string $token): ?string
    {
        return qr_code_data_uri(self::qrPayload($token));
    }
}
