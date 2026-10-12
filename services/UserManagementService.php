<?php
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/AuditLog.php';
require_once __DIR__ . '/AuthService.php';

/**
 * Administrator account management: create, edit, activate/deactivate,
 * assign a role, reset access and view an account's activity.
 *
 * Rules:
 * - Admins can't deactivate, re-role or reset their own account (they
 *   change their own password on My Account), so the system always keeps
 *   at least one active administrator.
 * - "Reset access" sets a temporary password shown once to the admin
 *   (never stored or logged), clears any lockout, and makes the user pick
 *   a new password at their next sign-in.
 * - An account with applications on file stays a Solo Parent account.
 *
 * Each action returns [error, success] messages (one is '').
 */
class UserManagementService
{
    public const PER_PAGE = 10;
    public const ACTIVITY_PER_PAGE = 20;
    public const STATUS_FILTERS = ['active' => 'Active', 'inactive' => 'Deactivated', 'locked' => 'Locked out', 'temp_password' => 'Temporary password'];
    private const USERNAME_PATTERN = '/^[A-Za-z0-9._-]{3,50}$/';

    private PDO $pdo;
    private User $users;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->users = new User($pdo);
    }

    public function find(int $userId): ?array
    {
        return $this->users->findById($userId);
    }

    public function list(string $role, string $status, string $q, int $page): array
    {
        return $this->users->listForAdmin(
            isset(User::ROLE_LABELS[$role]) ? $role : '',
            isset(self::STATUS_FILTERS[$status]) ? $status : '',
            mb_substr($q, 0, 100),
            $page,
            self::PER_PAGE
        );
    }

    /** Checks name, email and mobile (shared by create and edit). Returns an error or null. */
    private function contactError(string $fullName, string $email, string $mobile, int $exceptUserId = 0): ?string
    {
        if (mb_strlen($fullName) < 2 || mb_strlen($fullName) > 150) {
            return 'Enter a full name (2 to 150 characters).';
        }
        if (mb_strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'Enter a valid email address.';
        }
        if ($mobile !== '' && !is_valid_ph_mobile($mobile)) {
            return 'Enter a valid Philippine mobile number (e.g. 09171234567), or leave it blank.';
        }
        if ($mobile !== '' && $this->users->mobileTakenByOther($mobile, $exceptUserId)) {
            return 'That mobile number is already used by another account.';
        }
        return null;
    }

    /** @return array{0: string, 1: string, 2: ?int} [error, success, new user id] */
    public function create(array $admin, array $in): array
    {
        $fullName = trim((string) ($in['fullName'] ?? ''));
        $email = trim((string) ($in['email'] ?? ''));
        $username = trim((string) ($in['username'] ?? ''));
        $mobile = trim((string) ($in['mobile'] ?? ''));
        $password = (string) ($in['password'] ?? '');
        $role = (string) ($in['role'] ?? '');

        if ($error = $this->contactError($fullName, $email, $mobile)) {
            return [$error, '', null];
        }
        if (!preg_match(self::USERNAME_PATTERN, $username)) {
            return ['Username must be 3 to 50 characters: letters, numbers, dots, dashes or underscores.', '', null];
        }
        if ($this->users->usernameExists($username)) {
            return ['That username is already taken.', '', null];
        }
        if (!isset(User::ROLE_LABELS[$role])) {
            return ['Select a valid role.', '', null];
        }
        if ($error = (new AuthService($this->pdo))->passwordPolicyError($password)) {
            return [$error, '', null];
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $newId = $this->users->createWithRole($fullName, $email, $username, $hash, $role, $mobile !== '' ? $mobile : null);
        $mustChange = !empty($in['mustChange']);
        if ($mustChange) {
            $this->users->setTemporaryPassword($newId, $hash);
        }
        $this->audit($admin, 'User Creation', $newId, $username . ' (' . User::ROLE_LABELS[$role] . ')' . ($mustChange ? ', must change password at first sign-in' : ''));
        $success = 'Account created for ' . $username . '.';
        if ($role === 'mswd_social_worker') {
            $success .= ' They can access and continue updating all existing applicant records when they sign in. Previous staff history is preserved.';
        }
        return ['', $success, $newId];
    }

    /** @return array{0: string, 1: string} */
    public function updateAccount(array $admin, array $target, array $in): array
    {
        $fullName = trim((string) ($in['full_name'] ?? ''));
        $email = trim((string) ($in['email'] ?? ''));
        $mobile = trim((string) ($in['mobile'] ?? ''));
        if ($error = $this->contactError($fullName, $email, $mobile, (int) $target['id'])) {
            return [$error, ''];
        }
        $changes = [];
        foreach (['full_name' => $fullName, 'email' => $email, 'mobile' => $mobile !== '' ? normalize_mobile($mobile) : ''] as $field => $value) {
            if ((string) ($target[$field] ?? '') !== $value) {
                $changes[] = str_replace('_', ' ', $field);
            }
        }
        if (!$changes) {
            return ['', 'No changes to save.'];
        }
        $this->users->updateAccount((int) $target['id'], $fullName, $email, $mobile !== '' ? $mobile : null);
        $this->audit($admin, 'User Edited', (int) $target['id'], $target['username'] . ': ' . implode(', ', $changes));
        return ['', 'Account updated.'];
    }

    /** @return array{0: string, 1: string} */
    public function setActive(array $admin, array $target, bool $active): array
    {
        if ((int) $target['id'] === (int) $admin['id']) {
            return ['You cannot deactivate your own account.', ''];
        }
        if ((bool) $target['is_active'] === $active) {
            return ['', 'The account is already ' . ($active ? 'active' : 'deactivated') . '.'];
        }
        $this->users->setActive((int) $target['id'], $active);
        $this->audit($admin, $active ? 'Account Activated' : 'Account Deactivated', (int) $target['id'], $target['username']);
        return ['', $active ? 'Account activated. ' . $target['username'] . ' can sign in again.' : 'Account deactivated. ' . $target['username'] . ' is signed out and can no longer sign in.'];
    }

    /**
     * Permanently removes an account. Accounts with applications on file, or
     * still referenced by staff records (slots, interviews), must be
     * deactivated instead so the history stays intact.
     *
     * @return array{0: string, 1: string}
     */
    public function delete(array $admin, array $target): array
    {
        if ((int) $target['id'] === (int) $admin['id']) {
            return ['You cannot delete your own account.', ''];
        }
        if (($count = $this->users->applicationCount((int) $target['id'])) > 0) {
            return ['This account has ' . $count . ' application(s) on file and cannot be deleted. Deactivate it instead.', ''];
        }
        try {
            $this->users->delete((int) $target['id']);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                return ['This account is linked to records such as appointment slots or interviews and cannot be deleted. Deactivate it instead.', ''];
            }
            throw $e;
        }
        $this->audit($admin, 'User Deleted', (int) $target['id'], $target['username'] . ' (' . (User::ROLE_LABELS[$target['role']] ?? $target['role']) . ')');
        return ['', 'Account ' . $target['username'] . ' deleted.'];
    }

    /** @return array{0: string, 1: string} */
    public function changeRole(array $admin, array $target, string $newRole): array
    {
        if ((int) $target['id'] === (int) $admin['id']) {
            return ['You cannot change your own role.', ''];
        }
        if (!isset(User::ROLE_LABELS[$newRole])) {
            return ['Select a valid role.', ''];
        }
        if ($target['role'] === $newRole) {
            return ['', 'The account already has the ' . User::ROLE_LABELS[$newRole] . ' role.'];
        }
        if ($target['role'] === 'applicant' && ($count = $this->users->applicationCount((int) $target['id'])) > 0) {
            return ['This account has ' . $count . ' application(s) on file, so it must stay a Solo Parent account. Create a separate account for staff work.', ''];
        }
        $this->users->setRole((int) $target['id'], $newRole);
        $this->audit($admin, 'User Role Change', (int) $target['id'], $target['username'] . ': ' . (User::ROLE_LABELS[$target['role']] ?? $target['role']) . ' → ' . User::ROLE_LABELS[$newRole]);
        return ['', 'Role changed to ' . User::ROLE_LABELS[$newRole] . '. It takes effect on their next page load.'];
    }

    /**
     * New temporary password (shown once in the returned message, never
     * stored in plain text or logged), lockout cleared, change forced.
     *
     * @return array{0: string, 1: string}
     */
    public function resetAccess(array $admin, array $target): array
    {
        if ((int) $target['id'] === (int) $admin['id']) {
            return ['To change your own password, use My Account.', ''];
        }
        $temp = self::temporaryPassword();
        $this->users->setTemporaryPassword((int) $target['id'], password_hash($temp, PASSWORD_DEFAULT));
        $this->audit($admin, 'Access Reset', (int) $target['id'], $target['username'] . ': temporary password issued, lockout cleared');
        return ['', 'Temporary password for ' . $target['username'] . ': ' . $temp . ' — give it to them in person or by phone. It is shown only once; they must choose a new password when they sign in.'];
    }

    /** Clears a lockout without touching the password. @return array{0: string, 1: string} */
    public function unlock(array $admin, array $target): array
    {
        if ((int) $target['failed_login_attempts'] === 0 && empty($target['locked_until'])) {
            return ['', 'The account is not locked.'];
        }
        $this->users->clearLockout((int) $target['id']);
        $this->audit($admin, 'Account Unlocked', (int) $target['id'], $target['username']);
        return ['', 'Account unlocked. Failed sign-in attempts were reset.'];
    }

    /**
     * For an applicant whose verification text never arrives: MSWDO checks the number some other way
     * (a call, or in person) and an administrator marks it confirmed.
     *
     * @return array{0: string, 1: string}
     */
    public function confirmMobile(array $admin, array $target): array
    {
        if ($target['role'] !== 'applicant' || empty($target['mobile'])) {
            return ['Only an applicant account with a mobile number can be confirmed.', ''];
        }
        if (!empty($target['mobile_verified_at'])) {
            return ['', 'This mobile number is already confirmed.'];
        }
        $this->users->markMobileVerified((int) $target['id']);
        $this->audit($admin, 'Mobile Verified', (int) $target['id'], $target['username'] . ': number ending ' . substr(preg_replace('/\D/', '', $target['mobile']), -4) . ' confirmed by an administrator');
        return ['', 'Mobile number confirmed. ' . $target['username'] . ' can now use their account.'];
    }

    /** The account's activity (see AuditLog::forAccount). */
    public function activity(int $userId, string $scope, int $page): array
    {
        return (new AuditLog($this->pdo))->forAccount($userId, in_array($scope, ['by', 'on'], true) ? $scope : '', $page, self::ACTIVITY_PER_PAGE);
    }

    /** 12 characters from an unambiguous alphabet, always with letters and digits (meets the password policy). */
    public static function temporaryPassword(): string
    {
        $letters = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';
        $digits = '23456789';
        $all = $letters . $digits;
        $chars = [$letters[random_int(0, strlen($letters) - 1)], $digits[random_int(0, strlen($digits) - 1)]];
        while (count($chars) < 12) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }
        return implode('', $chars);
    }

    private function audit(array $admin, string $action, int $userId, string $details): void
    {
        audit_log($this->pdo, (int) $admin['id'], $admin['full_name'], $admin['role'], $action, 'user', $userId, $details);
    }
}
