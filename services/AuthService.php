<?php
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/ApplicationDraft.php';
require_once __DIR__ . '/DocumentValidationService.php';

/**
 * Login, sessions, lockout, password policy and password reset. This is
 * what includes/auth.php delegates to, so every existing caller shares one
 * implementation.
 *
 * Session rules: applicant, admin and staff portals each have their own cookie.
 * Each portal stores one identity; logging out affects only that portal. Its session data ends
 * on logout, after SESSION_IDLE_MINUTES without activity, or
 * SESSION_ABSOLUTE_HOURS after login. An account's sessions also end when
 * its password is changed, reset or replaced by an administrator
 * (users.auth_version moves on), except the session that changed it.
 * Ending a session wipes all its data,
 * so nothing carries over to the next person on that browser. (Application
 * and renewal drafts belong to the account, not the session -- see
 * ApplicationDraft.)
 */
class AuthService
{
    /** bcrypt hash of a random string, verified against when the username doesn't exist so both cases take the same time. */
    private const DUMMY_HASH = '$2y$10$31MBL39gLWJWynXE5ypA9uuebMudqMX1tX6U8zoSNoSWMKFlwjlka';

    private PDO $pdo;
    private User $userModel;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->userModel = new User($pdo);
    }

    public function userModel(): User
    {
        return $this->userModel;
    }

    // ---- Current session ----

    /** The signed-in applicant, re-checked against the DB on every call so deactivation or a role change takes effect immediately. */
    public function currentUser(): ?array
    {
        if (AUTH_PORTAL !== 'applicant') {
            return null;
        }
        if (empty($_SESSION['user_id']) || $this->sessionExpired()) {
            return null;
        }
        $stmt = $this->pdo->prepare("SELECT id, full_name, profile_photo, email, username, mobile, mobile_verified_at, role, must_change_password, auth_version FROM users WHERE id = ? AND role = 'applicant' AND is_active = 1");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
        if (!$user) {
            unset($_SESSION['user_id']);
            return null;
        }
        if ($this->signedOutElsewhere('user', $user)) {
            return null;
        }
        $_SESSION['last_activity'] = time();
        return $user;
    }

    public function currentStaff(): ?array
    {
        if (!in_array(AUTH_PORTAL, ['admin', 'staff'], true)) {
            return null;
        }
        if (!empty($_SESSION['user_id'])) {
            unset($_SESSION['staff_id'], $_SESSION['staff_auth_version']);
            return null;
        }
        if (empty($_SESSION['staff_id']) || $this->sessionExpired()) {
            return null;
        }
        $stmt = $this->pdo->prepare("SELECT id, full_name, email, username, mobile, role, must_change_password, last_login_at, auth_version FROM users WHERE id = ? AND role IN ('mswd_social_worker','admin') AND is_active = 1");
        $stmt->execute([$_SESSION['staff_id']]);
        $staff = $stmt->fetch();
        if (!$staff || $staff['role'] !== (AUTH_PORTAL === 'admin' ? 'admin' : 'mswd_social_worker')) {
            unset($_SESSION['staff_id']);
            return null;
        }
        if ($this->signedOutElsewhere('staff', $staff)) {
            return null;
        }
        $_SESSION['last_activity'] = time();
        return $staff;
    }

    /**
     * True (and the session is ended) if the account's password changed
     * since this session signed in -- e.g. the owner reset it after losing
     * their phone, or an administrator reset their access.
     */
    private function signedOutElsewhere(string $kind, array $account): bool
    {
        if ((int) ($_SESSION[$kind . '_auth_version'] ?? 0) === (int) $account['auth_version']) {
            return false;
        }
        $this->endSession();
        $_SESSION['auth_notice'] = 'Your password was changed, so you were signed out. Please log in with the new password.';
        return true;
    }

    /** Ends the session (with a notice for the login page) once it has been idle or open too long. */
    private function sessionExpired(): bool
    {
        $now = time();
        $idle = $now - (int) ($_SESSION['last_activity'] ?? $now);
        $age = $now - (int) ($_SESSION['auth_time'] ?? $now);
        if ($idle <= SESSION_IDLE_MINUTES * 60 && $age <= SESSION_ABSOLUTE_HOURS * 3600) {
            return false;
        }
        $this->endSession();
        $_SESSION['auth_notice'] = 'Your session expired for your security. Please log in again.';
        return true;
    }

    /** One-time message for the login page (e.g. "session expired"), or null. */
    public function takeAuthNotice(): ?string
    {
        $notice = $_SESSION['auth_notice'] ?? null;
        unset($_SESSION['auth_notice']);
        return $notice;
    }

    // ---- Login / logout ----

    /**
     * True if $password matches $account. Runs a full password check even
     * when the account doesn't exist, so response time doesn't reveal which
     * usernames are registered. Upgrades the stored hash if PHP's default
     * algorithm or cost has changed since it was created.
     */
    public function verifyCredentials(?array $account, string $password): bool
    {
        if (!$account) {
            password_verify($password, self::DUMMY_HASH);
            return false;
        }
        if (!password_verify($password, $account['password_hash'])) {
            return false;
        }
        if (password_needs_rehash($account['password_hash'], PASSWORD_DEFAULT)) {
            $this->userModel->updatePasswordHash((int) $account['id'], password_hash($password, PASSWORD_DEFAULT));
        }
        return true;
    }

    public function loginUser(array $user): void
    {
        // Capture the previous sign-in before recordLogin replaces its date.
        // Registration passes only the new user's ID, so load it when needed.
        $previousLogin = array_key_exists('last_login_at', $user)
            ? $user['last_login_at']
            : ($this->userModel->findById((int) $user['id'])['last_login_at'] ?? null);
        $this->startSession('user', (int) $user['id']);
        $_SESSION['applicant_first_login'] = empty($previousLogin);
        $this->userModel->recordLogin((int) $user['id']);
    }

    public function loginStaff(array $user): void
    {
        $this->startSession('staff', (int) $user['id']);
        $this->userModel->recordLogin((int) $user['id']);
    }

    public function logoutUser(): void
    {
        $this->endSession();
    }

    public function logoutStaff(): void
    {
        $this->endSession();
    }

    /** New session ID on every login (prevents session fixation). $kind: 'user' (applicant) or 'staff'. */
    private function startSession(string $kind, int $userId): void
    {
        $this->purgeAbandonedDrafts();
        // Preserve only pre-login context, never another account's identity or data.
        $context = array_intersect_key($_SESSION, array_flip(['eligibility_token', 'pending_qr_token', 'applicant_return']));
        if ($kind !== 'user') {
            unset($context['applicant_return']);
        }
        $_SESSION = $context;
        session_regenerate_id(true);
        $_SESSION[$kind . '_id'] = $userId;
        $_SESSION[$kind . '_auth_version'] = $this->userModel->authVersion($userId);
        $_SESSION['auth_time'] = time();
        $_SESSION['last_activity'] = time();
    }

    private function endSession(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
    }

    /**
     * Housekeeping on each login: temp upload folders older than the
     * longest possible session (left by sessions that simply expired, and
     * by the old session-based renewal drafts) are deleted, as are draft
     * folders whose saved draft no longer exists.
     */
    private function purgeAbandonedDrafts(): void
    {
        $cutoff = time() - SESSION_ABSOLUTE_HOURS * 3600;
        $files = new DocumentValidationService($this->pdo);
        foreach (glob(STORAGE_PATH . '/tmp/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (filemtime($dir) < $cutoff) {
                $files->deleteDir($dir);
            }
        }

        // Draft folders whose draft row is gone (e.g. the account was deleted).
        // The hour's grace covers an upload whose draft row is written a moment later.
        $saved = (new ApplicationDraft($this->pdo))->folderNames();
        foreach (glob(STORAGE_PATH . '/drafts/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (!isset($saved[basename($dir)]) && filemtime($dir) < time() - 3600) {
                $files->deleteDir($dir);
            }
        }
    }

    // ---- Password policy ----

    /** Null if acceptable, otherwise the message to show. Pass $confirmation to also require the two entries to match. */
    public function passwordPolicyError(string $password, ?string $confirmation = null): ?string
    {
        if (strlen($password) < PASSWORD_MIN_LENGTH || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            return 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters and include at least one letter and one number.';
        }
        if (strlen($password) > PASSWORD_MAX_BYTES) {
            return 'Password is too long (at most ' . PASSWORD_MAX_BYTES . ' characters).';
        }
        if ($confirmation !== null && !hash_equals($password, $confirmation)) {
            return 'The two passwords do not match.';
        }
        return null;
    }

    /**
     * The signed-in user changes their own password (applicants on My
     * Profile, staff and admins on My Account). Clears a forced change.
     *
     * @return array{0: string, 1: string} [error, success]
     */
    public function changeOwnPassword(array $account, string $current, string $new, string $confirm): array
    {
        $stored = $this->userModel->findById((int) $account['id']);
        if (!$stored || !password_verify($current, $stored['password_hash'])) {
            return ['Current password is incorrect.', ''];
        }
        if ($error = $this->passwordPolicyError($new, $confirm)) {
            return [$error, ''];
        }
        if (password_verify($new, $stored['password_hash'])) {
            return ['Choose a password different from your current one.', ''];
        }
        $this->userModel->setPassword((int) $account['id'], password_hash($new, PASSWORD_DEFAULT));
        // Every other session of this account is now signed out; this one carries on, under a fresh session ID.
        $kind = $account['role'] === 'applicant' ? 'user' : 'staff';
        if ((int) ($_SESSION[$kind . '_id'] ?? 0) === (int) $account['id']) {
            session_regenerate_id(true);
            $_SESSION[$kind . '_auth_version'] = $this->userModel->authVersion((int) $account['id']);
        }
        audit_log($this->pdo, (int) $account['id'], $account['full_name'], $account['role'], 'Password Changed', 'user', (int) $account['id'],
            empty($stored['must_change_password']) ? 'Changed own password' : 'Replaced the temporary password issued by an administrator');
        return ['', 'Your password has been updated. Any other device signed in to this account has been signed out.'];
    }

    // ---- Lockout ----

    /** Null if not locked, otherwise the user-facing message. */
    public function lockoutMessage(array $account): ?string
    {
        if (!empty($account['locked_until']) && strtotime($account['locked_until']) > time()) {
            return 'Too many failed attempts. Please try again after ' . date('g:i A', strtotime($account['locked_until'])) . '.';
        }
        return null;
    }

    /** Counts a failed login; true if this attempt locked the account (which also alerts the administrators). */
    public function recordFailedLogin(array $account): bool
    {
        $attempts = (int) $account['failed_login_attempts'];
        $this->userModel->recordFailedLogin((int) $account['id'], $attempts);
        if ($attempts + 1 !== LOGIN_LOCKOUT_THRESHOLD) {
            return false;
        }
        require_once __DIR__ . '/NotificationService.php';
        (new NotificationService($this->pdo))->notifyAdmins('security_alert',
            'Account "' . $account['username'] . '" (' . ($account['role'] ?? 'user') . ') was locked for ' . LOGIN_LOCKOUT_MINUTES . ' minutes after ' . LOGIN_LOCKOUT_THRESHOLD . ' failed sign-in attempts.', 'admin/users.php');
        return true;
    }

    public function clearLockout(int $userId): void
    {
        $this->userModel->clearLockout($userId);
    }

    // ---- Password reset ----

    /** Generates a fresh 6-digit reset code and stores only its hash, returning the code so the caller can text it. */
    public function requestPasswordReset(array $account): string
    {
        $code = (string) random_int(100000, 999999);
        $expiresAt = date('Y-m-d H:i:s', time() + PASSWORD_RESET_CODE_MINUTES * 60);
        $this->userModel->setResetCode((int) $account['id'], password_hash($code, PASSWORD_DEFAULT), $expiresAt);
        return $code;
    }

    public function verifyResetCode(array $account, string $code): bool
    {
        if (empty($account['password_reset_code']) || empty($account['password_reset_expires_at'])) {
            return false;
        }
        if (strtotime($account['password_reset_expires_at']) < time()) {
            return false;
        }
        // Stored as a bcrypt hash: a database or backup leak doesn't reveal live codes.
        return password_verify(trim($code), (string) $account['password_reset_code']);
    }

    public function completePasswordReset(int $userId, string $newPassword): void
    {
        $this->userModel->setPassword($userId, password_hash($newPassword, PASSWORD_DEFAULT));
    }

    // ---- Mobile number verification ----

    /**
     * Texts a fresh 6-digit code to the account's mobile number (only its hash is stored).
     * Returns '' when sent, otherwise why not -- at most a few texts per account per
     * 15 minutes, so the page can't be used to flood a phone.
     */
    public function sendMobileCode(array $account): string
    {
        require_once __DIR__ . '/RateLimiter.php';
        require_once __DIR__ . '/SMSService.php';
        if (empty($account['mobile'])) {
            return 'There is no mobile number on your account.';
        }
        $limiter = new RateLimiter($this->pdo);
        if ($limiter->tooMany('mobile_code', (string) $account['id'])) {
            return 'Too many codes were sent to this number. Please wait 15 minutes, then request a new one.';
        }
        $limiter->hit('mobile_code', (string) $account['id']);
        $code = (string) random_int(100000, 999999);
        $this->userModel->setMobileCode((int) $account['id'], password_hash($code, PASSWORD_DEFAULT), date('Y-m-d H:i:s', time() + MOBILE_CODE_MINUTES * 60));
        $result = (new SMSService($this->pdo))->notifyMobileVerification($account['mobile'], $code);
        audit_log($this->pdo, (int) $account['id'], $account['full_name'], $account['role'], 'Mobile Code Sent', 'user', (int) $account['id'],
            'Verification code to the number ending ' . substr(preg_replace('/\D/', '', $account['mobile']), -4) . ' (' . $result['status'] . ')');
        return $result['status'] === 'FAILED' ? 'The text could not be sent right now. Please try again in a few minutes.' : '';
    }

    /**
     * Checks a typed code against the account's current one. Returns '' and marks the number
     * confirmed when it matches; otherwise the message to show. MOBILE_CODE_MAX_ATTEMPTS wrong
     * entries void the code.
     */
    public function confirmMobileCode(array $account, string $code): string
    {
        $stored = $this->userModel->findById((int) $account['id']);
        if (!$stored || empty($stored['mobile_code'])) {
            return 'There is no active code. Tap "Send a new code" to get one.';
        }
        if (strtotime((string) $stored['mobile_code_expires_at']) < time()) {
            return 'That code has expired. Tap "Send a new code" to get a new one.';
        }
        if (!preg_match('/^\d{6}$/', trim($code)) || !password_verify(trim($code), (string) $stored['mobile_code'])) {
            $this->userModel->recordMobileCodeFailure((int) $account['id']);
            $left = MOBILE_CODE_MAX_ATTEMPTS - (int) $stored['mobile_code_attempts'] - 1;
            audit_log($this->pdo, (int) $account['id'], $account['full_name'], $account['role'], 'Mobile Verification Failed', 'user', (int) $account['id'],
                'Wrong code (attempt ' . ((int) $stored['mobile_code_attempts'] + 1) . ' of ' . MOBILE_CODE_MAX_ATTEMPTS . ')');
            return $left > 0
                ? 'That code is not correct. ' . $left . ($left === 1 ? ' try' : ' tries') . ' left.'
                : 'That code is not correct, and it can no longer be used. Tap "Send a new code" to get a new one.';
        }
        $this->userModel->markMobileVerified((int) $account['id']);
        audit_log($this->pdo, (int) $account['id'], $account['full_name'], $account['role'], 'Mobile Verified', 'user', (int) $account['id'],
            'Number ending ' . substr(preg_replace('/\D/', '', (string) $stored['mobile']), -4) . ' confirmed by SMS code');
        return '';
    }
}
