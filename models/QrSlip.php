<?php
require_once __DIR__ . '/Model.php';

/** qr_application_slips: the token behind each application slip's QR code (one active slip per application). */
class QrSlip extends Model
{
    /** The application's current (not revoked) slip, or null. */
    public function activeFor(int $applicationId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM qr_application_slips WHERE application_id = ? AND revoked_at IS NULL ORDER BY id DESC LIMIT 1');
        $stmt->execute([$applicationId]);
        return $stmt->fetch() ?: null;
    }

    /** The application's most recent slip, active or not (to explain why an old one is invalid). */
    public function latestFor(int $applicationId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM qr_application_slips WHERE application_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$applicationId]);
        return $stmt->fetch() ?: null;
    }

    /** Slips whose token starts with $prefix (the printed slip code) -- at most 2, enough to tell if it's unique. $prefix must be hex. */
    public function findByTokenPrefix(string $prefix): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM qr_application_slips WHERE token LIKE ? LIMIT 2');
        $stmt->execute([$prefix . '%']);
        return $stmt->fetchAll();
    }

    public function findByToken(string $token): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM qr_application_slips WHERE token = ?');
        $stmt->execute([$token]);
        return $stmt->fetch() ?: null;
    }

    public function create(int $applicationId, string $token, ?int $issuedBy, ?string $expiresAt): int
    {
        $this->pdo->prepare('INSERT INTO qr_application_slips (application_id, token, issued_by, expires_at) VALUES (?, ?, ?, ?)')
            ->execute([$applicationId, $token, $issuedBy, $expiresAt]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Revokes every active slip of the application. */
    public function revokeAll(int $applicationId, string $reason): void
    {
        $this->pdo->prepare('UPDATE qr_application_slips SET revoked_at = NOW(), revoke_reason = ? WHERE application_id = ? AND revoked_at IS NULL')
            ->execute([$reason, $applicationId]);
    }

    /** Sets when the active slip stops being valid (e.g. the ID's expiry once released). */
    public function setExpiry(int $applicationId, ?string $expiresAt): void
    {
        $this->pdo->prepare('UPDATE qr_application_slips SET expires_at = ? WHERE application_id = ? AND revoked_at IS NULL')
            ->execute([$expiresAt, $applicationId]);
    }
}
