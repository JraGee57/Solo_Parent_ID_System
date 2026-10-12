<?php
require_once __DIR__ . '/Model.php';

/**
 * Data access for the users table. Wraps the queries that used to live
 * inline in includes/auth.php, includes/portal/login.php, and
 * admin/login.php -- same columns, same ON conditions, just named and
 * in one place.
 */
class User extends Model
{
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Applicant account for sign-in by username, or by mobile number once that number has been
     * confirmed by SMS code. A username match wins (usernames can't be all digits, so they never
     * look like a mobile number).
     */
    public function findApplicantForLogin(string $identifier): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM users WHERE role = 'applicant' AND (username = ? OR (mobile IN (?, ?) AND mobile_verified_at IS NOT NULL))
             ORDER BY username = ? DESC LIMIT 1"
        );
        $stmt->execute([$identifier, ...mobile_variants($identifier), $identifier]);
        return $stmt->fetch() ?: null;
    }

    /** Active staff account (MSWD or admin) by username, for login. */
    public function findStaffByUsername(string $username): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE username = ? AND role IN ('mswd_social_worker','admin')");
        $stmt->execute([$username]);
        return $stmt->fetch() ?: null;
    }

    /** By username or mobile, any role -- used by "forgot password" since the user might not remember which they signed up with. */
    public function findByUsernameOrMobile(string $usernameOrMobile): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE username = ? OR mobile IN (?, ?) ORDER BY username = ? DESC LIMIT 1');
        $stmt->execute([$usernameOrMobile, ...mobile_variants($usernameOrMobile), $usernameOrMobile]);
        return $stmt->fetch() ?: null;
    }

    public function usernameExists(string $username): bool
    {
        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE username = ?');
        $stmt->execute([$username]);
        return (bool) $stmt->fetch();
    }

    public function emailExists(string $email): bool
    {
        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        return (bool) $stmt->fetch();
    }

    public function createApplicant(string $fullName, string $email, string $username, string $passwordHash, string $mobile): int
    {
        return $this->createWithRole($fullName, $email, $username, $passwordHash, 'applicant', $mobile);
    }

    /** Mobile numbers identify an account for password reset and SMS, so each may belong to only one account. */
    public function mobileExists(string $mobile): bool
    {
        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE mobile IN (?, ?)');
        $stmt->execute(mobile_variants($mobile));
        return (bool) $stmt->fetch();
    }

    /** Every applicant account has exactly one applicants (profile) row; no-op if it already exists. */
    public function ensureApplicantProfile(int $userId): void
    {
        $this->pdo->prepare('INSERT IGNORE INTO applicants (user_id) VALUES (?)')->execute([$userId]);
    }

    /** The three account roles (users.role, roles.role_key). */
    public const ROLE_LABELS = ['admin' => 'Administrator', 'mswd_social_worker' => 'MSWD Staff', 'applicant' => 'Solo Parent'];

    /** Count of users matching a fixed, code-supplied WHERE clause (never user input). */
    public function countWhere(string $where): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM users WHERE $where")->fetchColumn();
    }

    /**
     * Admin account list, newest first, one page at a time. $role is a role
     * key or ''; $status is active|inactive|locked|temp_password or '';
     * $q searches name, username, email and mobile.
     */
    public function listForAdmin(string $role, string $status, string $q, int $page, int $perPage): array
    {
        $where = ['1=1'];
        $params = [];
        if (isset(self::ROLE_LABELS[$role])) {
            $where[] = 'role = ?';
            $params[] = $role;
        }
        $statusWhere = [
            'active' => 'is_active = 1',
            'inactive' => 'is_active = 0',
            'locked' => 'locked_until > NOW()',
            'temp_password' => 'must_change_password = 1',
        ];
        if (isset($statusWhere[$status])) {
            $where[] = $statusWhere[$status];
        }
        if ($q !== '') {
            // A full mobile number also matches its other stored form (09… / +639…), as the duplicate check does.
            $where[] = '(username LIKE ? OR full_name LIKE ? OR email LIKE ? OR mobile LIKE ? OR mobile IN (?, ?))';
            array_push($params, "%$q%", "%$q%", "%$q%", "%$q%", ...mobile_variants($q));
        }
        $sqlWhere = implode(' AND ', $where);
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM users WHERE $sqlWhere");
        $count->execute($params);
        $pager = paginate((int) $count->fetchColumn(), $page, $perPage);
        $stmt = $this->pdo->prepare(
            "SELECT id, full_name, email, username, mobile, role, is_active, locked_until, must_change_password, last_login_at, created_at
             FROM users WHERE $sqlWhere ORDER BY created_at DESC, id DESC LIMIT {$pager['perPage']} OFFSET {$pager['offset']}"
        );
        $stmt->execute($params);
        return ['rows' => $stmt->fetchAll()] + $pager;
    }

    /** True if another account already uses this mobile number. */
    public function mobileTakenByOther(string $mobile, int $userId): bool
    {
        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE mobile IN (?, ?) AND id <> ?');
        $stmt->execute([...mobile_variants($mobile), $userId]);
        return (bool) $stmt->fetch();
    }

    public function applicationCount(int $userId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM applications WHERE user_id = ?');
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    /** Every account for the CSV export, newest first. */
    public function allForExport(): array
    {
        return $this->pdo->query('SELECT username, full_name, email, mobile, role, is_active, created_at FROM users ORDER BY created_at DESC')->fetchAll();
    }

    public function createWithRole(string $fullName, string $email, string $username, string $passwordHash, string $role, ?string $mobile = null): int
    {
        $this->pdo->prepare('INSERT INTO users (full_name, email, username, password_hash, role, mobile) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$fullName, $email, $username, $passwordHash, $role, $mobile !== null ? normalize_mobile($mobile) : null]);
        $userId = (int) $this->pdo->lastInsertId();
        if ($role === 'applicant') {
            $this->ensureApplicantProfile($userId);
        }
        return $userId;
    }

    public function setActive(int $userId, bool $active): void
    {
        $this->pdo->prepare('UPDATE users SET is_active = ? WHERE id = ?')->execute([$active ? 1 : 0, $userId]);
    }

    public function delete(int $userId): void
    {
        $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
    }

    public function setRole(int $userId, string $role): void
    {
        $this->pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $userId]);
        if ($role === 'applicant') {
            $this->ensureApplicantProfile($userId);
        }
    }

    /** Admin edit of contact details; a blank mobile is stored as NULL. */
    public function updateAccount(int $userId, string $fullName, string $email, ?string $mobile): void
    {
        $mobile = $mobile !== null ? normalize_mobile($mobile) : null;
        // A changed number is no longer confirmed (mobile_verified_at is assigned before mobile, so it compares with
        // the old number; either stored form of the same number counts as unchanged).
        [$as0, $as63] = mobile_variants((string) $mobile);
        $this->pdo->prepare(
            'UPDATE users SET full_name = ?, email = ?, mobile_verified_at = IF(mobile IN (?, ?), mobile_verified_at, NULL),
                              mobile_code = IF(mobile IN (?, ?), mobile_code, NULL), mobile = ? WHERE id = ?'
        )->execute([$fullName, $email, $as0, $as63, $as0, $as63, $mobile, $userId]);
    }

    public function recordLogin(int $userId): void
    {
        $this->pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$userId]);
    }

    public function recordFailedLogin(int $userId, int $currentAttempts): void
    {
        $attempts = $currentAttempts + 1;
        $lockedUntil = $attempts >= LOGIN_LOCKOUT_THRESHOLD
            ? date('Y-m-d H:i:s', time() + LOGIN_LOCKOUT_MINUTES * 60)
            : null;
        $this->pdo->prepare('UPDATE users SET failed_login_attempts = ?, locked_until = ? WHERE id = ?')
            ->execute([$attempts, $lockedUntil, $userId]);
    }

    public function clearLockout(int $userId): void
    {
        $this->pdo->prepare('UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE id = ?')->execute([$userId]);
    }

    /** Stores a re-hash of the same password (no other change). */
    public function updatePasswordHash(int $userId, string $passwordHash): void
    {
        $this->pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$passwordHash, $userId]);
    }

    /** A password the user chose: no forced change any more, any pending reset code is void. */
    public function setPassword(int $userId, string $passwordHash): void
    {
        $this->pdo->prepare(
            'UPDATE users SET password_hash = ?, must_change_password = 0, password_reset_code = NULL, password_reset_expires_at = NULL,
                              auth_version = auth_version + 1 WHERE id = ?'
        )->execute([$passwordHash, $userId]);
    }

    /** An administrator's "reset access": temporary password that must be changed at next sign-in, lockout and reset codes cleared, other sessions ended. */
    public function setTemporaryPassword(int $userId, string $passwordHash): void
    {
        $this->pdo->prepare(
            'UPDATE users SET password_hash = ?, must_change_password = 1, failed_login_attempts = 0, locked_until = NULL,
                              password_reset_code = NULL, password_reset_expires_at = NULL, auth_version = auth_version + 1 WHERE id = ?'
        )->execute([$passwordHash, $userId]);
    }

    public function authVersion(int $userId): int
    {
        $stmt = $this->pdo->prepare('SELECT auth_version FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    public function setResetCode(int $userId, string $codeHash, string $expiresAt): void
    {
        $this->pdo->prepare('UPDATE users SET password_reset_code = ?, password_reset_expires_at = ? WHERE id = ?')
            ->execute([$codeHash, $expiresAt, $userId]);
    }

    /** A fresh mobile verification code (hash only); any earlier code and its wrong attempts are void. */
    public function setMobileCode(int $userId, string $codeHash, string $expiresAt): void
    {
        $this->pdo->prepare('UPDATE users SET mobile_code = ?, mobile_code_expires_at = ?, mobile_code_attempts = 0 WHERE id = ?')
            ->execute([$codeHash, $expiresAt, $userId]);
    }

    /** Counts a wrong code; the code is voided once MOBILE_CODE_MAX_ATTEMPTS is reached. */
    public function recordMobileCodeFailure(int $userId): void
    {
        $this->pdo->prepare(
            'UPDATE users SET mobile_code_attempts = mobile_code_attempts + 1,
                              mobile_code = IF(mobile_code_attempts >= ?, NULL, mobile_code) WHERE id = ?'
        )->execute([MOBILE_CODE_MAX_ATTEMPTS, $userId]);
    }

    public function markMobileVerified(int $userId): void
    {
        $this->pdo->prepare('UPDATE users SET mobile_verified_at = NOW(), mobile_code = NULL, mobile_code_expires_at = NULL, mobile_code_attempts = 0 WHERE id = ?')
            ->execute([$userId]);
    }

}
