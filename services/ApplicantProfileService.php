<?php
require_once __DIR__ . '/../models/Applicant.php';
require_once __DIR__ . '/DocumentValidationService.php';

/**
 * The applicant's self-service profile. Only these fields are editable by
 * the applicant: full name, email, mobile, birthdate, sex, civil status,
 * barangay, address, and profile photo. Username, role, account status and
 * everything else are system-controlled and never read from the request.
 * Every method acts on the given user's own record only and returns
 * [error, success] (one of them '').
 */
class ApplicantProfileService
{
    private const PERSONAL_FIELDS = ['birthdate', 'sex', 'civil_status', 'barangay', 'address'];

    private PDO $pdo;
    private Applicant $applicants;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->applicants = new Applicant($pdo);
    }

    public function profile(int $userId): array
    {
        return $this->applicants->profile($userId);
    }

    /**
     * Validates and saves the whole profile form. Personal/address fields
     * may be left blank until the applicant applies; email is optional. Name and a
     * mobile number unique to this account are required.
     *
     * @return array{0: string, 1: string}
     */
    public function update(array $user, array $input): array
    {
        $f = [];
        foreach (['full_name', 'email', 'mobile', 'birthdate', 'sex', 'civil_status', 'barangay', 'address'] as $key) {
            $f[$key] = is_string($input[$key] ?? null) ? trim($input[$key]) : '';
        }
        $userId = (int) $user['id'];

        $error = $this->validate($f, $userId);
        if ($error) {
            return [$error, ''];
        }

        $before = $this->applicants->profile($userId) + ['full_name' => $user['full_name'], 'email' => $user['email'], 'mobile' => $user['mobile']];
        $nullIfBlank = fn(string $v) => $v === '' ? null : $v;
        $this->applicants->updateContact($userId, $f['full_name'], $f['email'], $f['mobile']);
        $this->applicants->updatePersonal($userId, $nullIfBlank($f['birthdate']), $nullIfBlank($f['sex']), $nullIfBlank($f['civil_status']), $nullIfBlank($f['barangay']), $nullIfBlank($f['address']));

        $f['mobile'] = normalize_mobile($f['mobile']);
        $changed = array_keys(array_filter(
            ['full_name' => 1, 'email' => 1, 'mobile' => 1] + array_fill_keys(self::PERSONAL_FIELDS, 1),
            fn($_, $key) => (string) ($before[$key] ?? '') !== $f[$key],
            ARRAY_FILTER_USE_BOTH
        ));
        if (!$changed) {
            return ['', 'No changes to save.'];
        }
        // Field names only -- the audit trail records what changed, not the personal values themselves.
        audit_log($this->pdo, $userId, $f['full_name'], $user['role'], 'Profile Updated', 'user', $userId, 'Changed: ' . implode(', ', $changed));
        if (!in_array($f['mobile'], mobile_variants((string) $before['mobile']), true)) {
            require_once __DIR__ . '/AuthService.php';
            $sendError = (new AuthService($this->pdo))->sendMobileCode(['mobile' => $f['mobile'], 'full_name' => $f['full_name']] + $user);
            return ['', 'Your profile has been updated. ' . ($sendError ?: 'We texted a 6-digit code to ' . $f['mobile'] . ' -- enter it to confirm your new number.')];
        }
        return ['', 'Your profile has been updated.'];
    }

    private function validate(array $f, int $userId): string
    {
        if ($f['full_name'] === '' || mb_strlen($f['full_name']) > 150) {
            return 'Enter your full name (up to 150 characters).';
        }
        if ($f['email'] !== '' && (!filter_var($f['email'], FILTER_VALIDATE_EMAIL) || strlen($f['email']) > 150)) {
            return 'Enter a valid email address.';
        }
        if (!is_valid_ph_mobile($f['mobile'])) {
            return 'Enter a valid Philippine mobile number (e.g. 09171234567).';
        }
        if ($this->applicants->mobileTakenByOther($f['mobile'], $userId)) {
            return 'That mobile number is already registered to another account.';
        }
        if ($f['birthdate'] !== '') {
            $dt = DateTime::createFromFormat('!Y-m-d', $f['birthdate']);
            if (!$dt || $dt->format('Y-m-d') !== $f['birthdate'] || $f['birthdate'] > today_iso() || $f['birthdate'] < '1900-01-01') {
                return 'Enter a valid birthdate (not in the future).';
            }
        }
        if ($f['sex'] !== '' && !in_array($f['sex'], ['Male', 'Female'], true)) {
            return 'Select a valid sex.';
        }
        if ($f['civil_status'] !== '' && !in_array($f['civil_status'], CIVIL_STATUS_OPTIONS, true)) {
            return 'Select a valid civil status.';
        }
        if (mb_strlen($f['barangay']) > 100) {
            return 'Barangay is too long (up to 100 characters).';
        }
        if (mb_strlen($f['address']) > 255) {
            return 'Address is too long (up to 255 characters).';
        }
        return '';
    }

    /** @return array{0: string, 1: string} */
    public function uploadPhoto(array $user, array $file): array
    {
        $result = (new DocumentValidationService($this->pdo))->storeProfilePhoto($file, (int) $user['id']);
        if (!$result['ok']) {
            return [$result['error'], ''];
        }
        $this->applicants->setProfilePhoto((int) $user['id'], $result['relativePath']);
        $this->deletePhotoFile($user['profile_photo']);
        audit_log($this->pdo, (int) $user['id'], $user['full_name'], $user['role'], 'Profile Photo Updated', 'user', (int) $user['id'], 'New profile photo uploaded');
        return ['', 'Your profile photo has been updated.'];
    }

    /** @return array{0: string, 1: string} */
    public function removePhoto(array $user): array
    {
        if (!$user['profile_photo']) {
            return ['', 'You have no profile photo.'];
        }
        $this->applicants->setProfilePhoto((int) $user['id'], null);
        $this->deletePhotoFile($user['profile_photo']);
        audit_log($this->pdo, (int) $user['id'], $user['full_name'], $user['role'], 'Profile Photo Removed', 'user', (int) $user['id'], 'Profile photo deleted');
        return ['', 'Your profile photo has been removed.'];
    }

    private function deletePhotoFile(?string $relativePath): void
    {
        // basename(): the stored name is ours, but never let a path escape the avatar folder.
        if ($relativePath && is_file(AVATAR_STORAGE_PATH . '/' . basename($relativePath))) {
            @unlink(AVATAR_STORAGE_PATH . '/' . basename($relativePath));
        }
    }
}
