<?php
require_once __DIR__ . '/User.php';

/** The applicant's own account/profile data (a users row with role 'applicant'). */
class Applicant extends User
{
    public function findApplicant(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'applicant'");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** Contact details kept on the account (users): name, email, mobile. */
    public function updateContact(int $userId, string $fullName, string $email, string $mobile): void
    {
        // A changed number is no longer confirmed (mobile_verified_at is assigned before mobile, so it compares with
        // the old number; either stored form of the same number counts as unchanged).
        $mobile = normalize_mobile($mobile);
        [$as0, $as63] = mobile_variants($mobile);
        $this->pdo->prepare(
            'UPDATE users SET full_name = ?, email = ?, mobile_verified_at = IF(mobile IN (?, ?), mobile_verified_at, NULL),
                              mobile_code = IF(mobile IN (?, ?), mobile_code, NULL), mobile = ? WHERE id = ?'
        )->execute([$fullName, $email, $as0, $as63, $as0, $as63, $mobile, $userId]);
    }

    /** The applicant's personal/address profile (applicants row), created empty on first use. */
    public function profile(int $userId): array
    {
        $this->ensureApplicantProfile($userId);
        $stmt = $this->pdo->prepare('SELECT birthdate, sex, civil_status, barangay, address, updated_at FROM applicants WHERE user_id = ?');
        $stmt->execute([$userId]);
        return $stmt->fetch();
    }

    /** Blank values are stored as NULL ("not provided yet"). */
    public function updatePersonal(int $userId, ?string $birthdate, ?string $sex, ?string $civilStatus, ?string $barangay, ?string $address): void
    {
        $this->ensureApplicantProfile($userId);
        $this->pdo->prepare('UPDATE applicants SET birthdate = ?, sex = ?, civil_status = ?, barangay = ?, address = ? WHERE user_id = ?')
            ->execute([$birthdate, $sex, $civilStatus, $barangay, $address, $userId]);
    }

    /** Validated application fields only; omitted values preserve the profile. Mobile verification is untouched. */
    public function updateApplicationDetails(int $userId, array $fields): void
    {
        foreach (['users' => ['full_name', 'email'], 'applicants' => ['birthdate', 'sex', 'civil_status', 'barangay', 'address']] as $table => $allowed) {
            $updates = array_intersect_key($fields, array_flip($allowed));
            if (!$updates) {
                continue;
            }
            if ($table === 'applicants') {
                $this->ensureApplicantProfile($userId);
            }
            $assignments = implode(', ', array_map(static fn($column) => $column . ' = ?', array_keys($updates)));
            $where = $table === 'users' ? "id = ? AND role = 'applicant'" : 'user_id = ?';
            $this->pdo->prepare('UPDATE ' . $table . ' SET ' . $assignments . ' WHERE ' . $where)
                ->execute([...array_values($updates), $userId]);
        }
    }

    /** True if another account already uses this mobile number. */

    public function setProfilePhoto(int $userId, ?string $relativePath): void
    {
        $this->pdo->prepare('UPDATE users SET profile_photo = ? WHERE id = ?')->execute([$relativePath, $userId]);
    }
}
