<?php
require_once __DIR__ . '/Controller.php';
require_once __DIR__ . '/../services/AuthService.php';
require_once __DIR__ . '/../services/SMSService.php';
require_once __DIR__ . '/../services/EligibilityService.php';
require_once __DIR__ . '/../services/RateLimiter.php';

class AuthController extends Controller
{
    private AuthService $auth;

    public function __construct(PDO $pdo)
    {
        parent::__construct($pdo);
        $this->auth = new AuthService($pdo);
    }

    /**
     * Handles the combined login/signup page (both applicant-facing).
     * GET just shows the form; POST processes whichever action was
     * submitted. Returns the data the view needs to render.
     */
    public function handleApplicantAuth(string $defaultMode = 'login'): array
    {
        if (array_key_exists('next', $_GET)) {
            unset($_SESSION['applicant_return']);
            if (applicant_return_path($_GET['next'])) {
                $_SESSION['applicant_return'] = $_GET['next'];
            }
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' && ($staff = $this->auth->currentStaff())) {
            $this->redirectTo($staff['role'] === 'admin' ? 'admin/dashboard.php' : 'staff/dashboard.php');
        }
        if ($existingUser = $this->auth->currentUser()) {
            $this->redirectTo(!empty($existingUser['mobile_verified_at']) ? take_applicant_return_path() : 'verify-mobile.php');
        }
        $authMode = ($_GET['mode'] ?? $defaultMode) === 'signup' ? 'signup' : 'login';
        $authError = '';
        $oldSignup = ['fullName' => '', 'email' => '', 'mobile' => '', 'username' => ''];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_require();
            $action = $_POST['action'] ?? '';

            if ($action === 'login') {
                $authMode = 'login';
                $username = trim((string) ($_POST['username'] ?? ''));
                $account = $this->auth->userModel()->findApplicantForLogin($username);
                $authError = $this->attemptLogin($account, $username, (string) ($_POST['password'] ?? ''), 'Applicant sign-in', 'Please contact MSWDO Virac for assistance.');
                if ($authError === '') {
                    $this->auth->loginUser($account);
                    (new EligibilityService($this->pdo))->claimForUser((int) $account['id']);
                    audit_log($this->pdo, (int) $account['id'], $account['full_name'], $account['role'], 'Login', 'user', (int) $account['id'],
                        ctype_digit(ltrim($username, '+')) ? 'Applicant sign-in with mobile number' : 'Applicant sign-in');
                    $this->redirectTo($account['mobile_verified_at'] ? take_applicant_return_path() : 'verify-mobile.php');
                }
            } elseif ($action === 'signup') {
                $authMode = 'signup';
                $oldSignup = [
                    'fullName' => trim((string) ($_POST['fullName'] ?? '')),
                    'email' => '', // Applicant registration uses mobile for contact and recovery.
                    'mobile' => trim((string) ($_POST['mobile'] ?? '')),
                    'username' => trim((string) ($_POST['username'] ?? '')),
                ];
                $password = (string) ($_POST['password'] ?? '');
                $authError = $this->registrationError($oldSignup, $password, (string) ($_POST['password_confirm'] ?? ''));
                $limiter = new RateLimiter($this->pdo);
                if ($authError === '' && $limiter->tooMany('register_ip', RateLimiter::clientIp())) {
                    $authError = 'Too many new accounts were created from this network recently. Please try again later.';
                }
                if ($authError === '') {
                    $limiter->hit('register_ip', RateLimiter::clientIp());
                    ['fullName' => $fullName, 'email' => $email, 'mobile' => $mobile, 'username' => $username] = $oldSignup;
                    $newUserId = $this->auth->userModel()->createApplicant($fullName, $email, $username, password_hash($password, PASSWORD_DEFAULT), $mobile);
                    // Mobile verification is temporarily skipped for self-registration. Mark the
                    // supplied number usable so the normal applicant login and SMS paths continue.
                    $this->auth->userModel()->markMobileVerified($newUserId);
                    $this->auth->loginUser(['id' => $newUserId]);
                    (new EligibilityService($this->pdo))->claimForUser($newUserId);
                    audit_log($this->pdo, $newUserId, $fullName, 'applicant', 'Registration', 'user', $newUserId, 'New applicant account @' . $username);
                    create_notification($this->pdo, $newUserId, 'registration', 'Welcome to e-SoloParentID! Your account has been created.');
                    flash('Your account has been created. You can now use your dashboard and apply online.');
                    $this->redirectTo(take_applicant_return_path());
                }
            }
        }

        $authNotice = $this->auth->takeAuthNotice();
        return compact('authMode', 'authError', 'oldSignup', 'authNotice');
    }

    /**
     * Shared login checks for both portals: '' if the credentials are good,
     * otherwise the message to show. The same "Incorrect username or
     * password" is given whether the username (or mobile number) or the
     * password was wrong.
     * Every refused attempt is audit-logged ($portal says which sign-in
     * page); an attempt on an unknown username is logged against no account,
     * with the username as typed (never the password). Too many failures
     * from one IP (across any accounts -- password spraying) stops all
     * sign-in attempts from it for a while, without checking the password.
     */
    private function attemptLogin(?array $account, string $username, string $password, string $portal, string $deactivatedHelp): string
    {
        $limiter = new RateLimiter($this->pdo);
        if ($limiter->tooMany('login_ip', RateLimiter::clientIp())) {
            return 'Too many failed sign-in attempts from this network. Please try again in 15 minutes.';
        }
        $lockoutMsg = $account ? $this->auth->lockoutMessage($account) : null;
        if ($lockoutMsg) {
            $this->auditFailedLogin($account, $username, $portal, 'account is locked until ' . date('g:i A', strtotime($account['locked_until'])));
            return $lockoutMsg;
        }
        if (!$this->auth->verifyCredentials($account, $password)) {
            if ($account) {
                $locked = $this->auth->recordFailedLogin($account);
                $this->auditFailedLogin($account, $username, $portal, 'wrong password (attempt ' . ((int) $account['failed_login_attempts'] + 1) . ' of ' . LOGIN_LOCKOUT_THRESHOLD . ')');
                if ($locked) {
                    $this->auditLocked($account);
                }
            } else {
                $this->auditFailedLogin(null, $username, $portal, 'no matching account');
            }
            // Logged once, when the limit is reached; further attempts are refused without a log entry each.
            if ($limiter->hit('login_ip', RateLimiter::clientIp()) === RateLimiter::RULES['login_ip'][0]) {
                audit_log($this->pdo, null, 'Unknown user', 'guest', 'Sign-in Blocked', null, null,
                    "$portal: " . RateLimiter::RULES['login_ip'][0] . ' failed sign-ins from IP ' . RateLimiter::clientIp() . ' within 15 minutes; further attempts refused for 15 minutes');
            }
            return $portal === 'Applicant sign-in' ? 'Incorrect username, mobile number or password.' : 'Incorrect username or password.';
        }
        if (!$account['is_active']) {
            $this->auditFailedLogin($account, $username, $portal, 'account is deactivated');
            return 'This account has been deactivated. ' . $deactivatedHelp;
        }
        $this->auth->clearLockout((int) $account['id']);
        return '';
    }

    private function auditLocked(array $account): void
    {
        audit_log($this->pdo, (int) $account['id'], $account['full_name'], $account['role'], 'Account Locked', 'user', (int) $account['id'],
            $account['username'] . ': ' . LOGIN_LOCKOUT_THRESHOLD . ' failed attempts, locked for ' . LOGIN_LOCKOUT_MINUTES . ' minutes');
    }

    private function auditFailedLogin(?array $account, string $username, string $portal, string $reason): void
    {
        if ($account) {
            audit_log($this->pdo, (int) $account['id'], $account['full_name'], $account['role'], 'Login Failed', 'user', (int) $account['id'], "$portal: $reason");
        } else {
            $typed = mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', $username) ?? '', 0, 50);
            audit_log($this->pdo, null, 'Unknown user', 'guest', 'Login Failed', null, null, "$portal: $reason, username \"$typed\"");
        }
    }

    /** '' if the sign-up form is valid, otherwise the first problem found. */
    private function registrationError(array $form, string $password, string $confirmation): string
    {
        if ($form['fullName'] === '' || $form['username'] === '' || $form['mobile'] === '' || $password === '') {
            return 'Please fill in full name, mobile number, username, and password.';
        }
        if (mb_strlen($form['fullName']) > 150) {
            return 'Full name is too long.';
        }
        if (!is_valid_ph_mobile($form['mobile'])) {
            return 'Enter a valid Philippine mobile number (e.g. 09171234567).';
        }
        if (!preg_match('/^[A-Za-z0-9._]{4,30}$/', $form['username'])) {
            return 'Username must be 4 to 30 characters: letters, numbers, dots, or underscores only.';
        }
        if (ctype_digit($form['username'])) {
            return 'Username must include at least one letter (a username of only numbers could be mistaken for a mobile number).';
        }
        $policyError = $this->auth->passwordPolicyError($password, $confirmation);
        if ($policyError) {
            return $policyError;
        }
        if ($this->auth->userModel()->usernameExists($form['username'])) {
            return 'That username is already taken.';
        }
        if ($this->auth->userModel()->mobileExists($form['mobile'])) {
            return 'That mobile number is already registered. Log in, or use Forgot password if you can\'t remember it.';
        }
        return '';
    }

    /**
     * verify-mobile.php: the signed-in applicant confirms their mobile number with the texted
     * code (after registering, or after the number changed). POST action=verify checks a code;
     * action=resend texts a new one.
     */
    public function verifyMobile(): array
    {
        if ($staff = $this->auth->currentStaff()) {
            $this->redirectTo($staff['role'] === 'admin' ? 'admin/dashboard.php' : 'staff/dashboard.php');
        }
        $user = $this->auth->currentUser();
        if (!$user) {
            $this->redirectTo('login.php');
        }
        if (!empty($user['mobile_verified_at'])) {
            $this->redirectTo(take_applicant_return_path());
        }
        $error = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_require();
            if (($_POST['action'] ?? '') === 'resend') {
                $error = $this->auth->sendMobileCode($user);
                if ($error === '') {
                    flash('A new code was texted to ' . $user['mobile'] . '.');
                    $this->redirectTo('verify-mobile.php');
                }
            } else {
                $error = $this->auth->confirmMobileCode($user, is_string($_POST['code'] ?? null) ? $_POST['code'] : '');
                if ($error === '') {
                    create_notification($this->pdo, (int) $user['id'], 'registration', 'Your mobile number ' . $user['mobile'] . ' is confirmed. SMS updates and password-reset codes will be sent there.');
                    flash('Your mobile number is confirmed. You can now use your dashboard and apply online.');
                    $this->redirectTo(take_applicant_return_path());
                }
            }
        }
        $pending = $this->auth->userModel()->findById((int) $user['id']);
        return [
            'user' => $user,
            'error' => $error,
            'notice' => (string) take_flash(),
            'hasCode' => !empty($pending['mobile_code']) && strtotime((string) $pending['mobile_code_expires_at']) >= time(),
        ];
    }

    /** One-time notice for the login page (e.g. session expired). */
    public function takeNotice(): ?string
    {
        return $this->auth->takeAuthNotice();
    }

    /** MSWD staff + admin login (mswd-login.php). Returns the error message to show, if any. */
    public function handleStaffLogin(): string
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $this->auth->currentUser()) {
            $this->redirectTo('applicant/dashboard.php');
        }
        if ($existing = $this->auth->currentStaff()) {
            $this->redirectTo($existing['role'] === 'admin' ? 'admin/dashboard.php' : 'staff/dashboard.php');
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return '';
        }

        csrf_require();
        $username = trim((string) ($_POST['username'] ?? ''));
        $account = $this->auth->userModel()->findStaffByUsername($username);
        $expectedRole = AUTH_PORTAL === 'admin' ? 'admin' : 'mswd_social_worker';
        if ($account && $account['role'] !== $expectedRole) {
            $account = null;
        }
        $error = $this->attemptLogin($account, $username, (string) ($_POST['password'] ?? ''), 'Staff sign-in', 'Please contact your system administrator.');
        if ($error !== '') {
            return $error;
        }

        $this->auth->loginStaff($account);
        audit_log($this->pdo, (int) $account['id'], $account['full_name'], $account['role'], 'Login', 'user', (int) $account['id'], 'Staff sign-in');
        // A QR slip scanned before signing in: go back to its verification (only that page, only a well-formed token).
        $pendingToken = $_SESSION['pending_qr_token'] ?? null;
        unset($_SESSION['pending_qr_token']);
        if (is_string($pendingToken) && preg_match('/^[0-9a-f]{64}$/', $pendingToken) && can($account, 'qr.verify')) {
            $this->redirectTo('staff/qr_verify.php?t=' . $pendingToken);
        }
        $this->redirectTo($account['role'] === 'admin' ? 'admin/dashboard.php' : 'staff/dashboard.php');
        return '';
    }

    public function logoutApplicant(): void
    {
        if ($staff = $this->auth->currentStaff()) {
            $this->redirectTo($staff['role'] === 'admin' ? 'admin/dashboard.php' : 'staff/dashboard.php');
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_require();
            if ($user = $this->auth->currentUser()) {
                audit_log($this->pdo, (int) $user['id'], $user['full_name'], $user['role'], 'Logout', 'user', (int) $user['id'], 'Applicant sign-out');
            }
            $this->auth->logoutUser();
        }
        $this->redirectTo('login.php');
    }

    public function logoutStaff(): void
    {
        if ($this->auth->currentUser()) {
            $this->redirectTo('applicant/dashboard.php');
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_require();
            if ($staff = $this->auth->currentStaff()) {
                audit_log($this->pdo, (int) $staff['id'], $staff['full_name'], $staff['role'], 'Logout', 'user', (int) $staff['id'], 'Staff sign-out');
            }
            $this->auth->logoutStaff();
        }
        $this->redirectTo('mswd-login.php');
    }

    public function forgotPassword(): array
    {
        $error = '';
        $success = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_require();
            $identifier = trim((string) ($_POST['identifier'] ?? ''));
            $account = $identifier !== '' ? $this->auth->userModel()->findByUsernameOrMobile($identifier) : null;

            $limiter = new RateLimiter($this->pdo);
            if ($limiter->tooMany('reset_ip', RateLimiter::clientIp())) {
                return ['error' => 'Too many reset requests from this network. Please try again in an hour.', 'success' => ''];
            }
            $limiter->hit('reset_ip', RateLimiter::clientIp());

            // Same message whether or not the account exists, so this
            // can't be used to enumerate valid usernames/mobiles.
            $success = 'If that account exists and has a mobile number on file, a reset code has been sent by SMS.';

            // At most a few texts per account per code lifetime (no SMS bombing); past that, the last code
            // sent still works, and the response is the same so it doesn't reveal the account exists.
            if ($account && !empty($account['mobile']) && $account['is_active'] && !$limiter->tooMany('reset_account', (string) $account['id'])) {
                $limiter->hit('reset_account', (string) $account['id']);
                $code = $this->auth->requestPasswordReset($account);
                (new SMSService($this->pdo))->notifyPasswordReset($account['mobile'], $code);
                audit_log($this->pdo, (int) $account['id'], $account['full_name'], $account['role'], 'Password Reset Requested', 'user', (int) $account['id'],
                    'Reset code sent by SMS to the number ending ' . substr(preg_replace('/\D/', '', $account['mobile']), -4));
            }
        }

        return compact('error', 'success');
    }

    public function resetPassword(): array
    {
        $error = '';
        $success = '';
        $loginUrl = 'login.php';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_require();
            $identifier = trim((string) ($_POST['identifier'] ?? ''));
            $code = trim((string) ($_POST['code'] ?? ''));
            $newPassword = (string) ($_POST['new_password'] ?? '');
            $policyError = $this->auth->passwordPolicyError($newPassword, (string) ($_POST['new_password_confirm'] ?? ''));
            $account = $identifier !== '' ? $this->auth->userModel()->findByUsernameOrMobile($identifier) : null;

            // Wrong codes count toward the same lockout as wrong passwords,
            // so the 6-digit code can't be brute-forced.
            $lockoutMsg = $account ? $this->auth->lockoutMessage($account) : null;

            if ($lockoutMsg) {
                $error = $lockoutMsg;
            } elseif (!$account || !$this->auth->verifyResetCode($account, $code)) {
                if ($account) {
                    $locked = $this->auth->recordFailedLogin($account);
                    audit_log($this->pdo, (int) $account['id'], $account['full_name'], $account['role'], 'Password Reset Failed', 'user', (int) $account['id'],
                        'Invalid or expired reset code (attempt ' . ((int) $account['failed_login_attempts'] + 1) . ' of ' . LOGIN_LOCKOUT_THRESHOLD . ')');
                    if ($locked) {
                        $this->auditLocked($account);
                    }
                }
                $error = 'That code is invalid or has expired. Please request a new one.';
            } elseif ($policyError) {
                $error = $policyError;
            } else {
                $this->auth->completePasswordReset((int) $account['id'], $newPassword);
                $this->auth->clearLockout((int) $account['id']);
                audit_log($this->pdo, (int) $account['id'], $account['full_name'], $account['role'], 'Password Reset Completed', 'user', (int) $account['id'], 'New password set with an SMS reset code; lockout cleared');
                $success = 'Your password has been reset. You can now log in with your new password.';
                $loginUrl = $account['role'] === 'applicant' ? 'login.php'
                    : 'mswd-login.php?portal=' . ($account['role'] === 'admin' ? 'admin' : 'staff');
            }
        }

        return compact('error', 'success', 'loginUrl');
    }
}
