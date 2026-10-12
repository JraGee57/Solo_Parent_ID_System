<?php
require_once __DIR__ . '/../models/Application.php';
require_once __DIR__ . '/../models/Applicant.php';
require_once __DIR__ . '/../models/ApplicationDraft.php';
require_once __DIR__ . '/QrSlipService.php';
require_once __DIR__ . '/NotificationService.php';
require_once __DIR__ . '/../models/Document.php';
require_once __DIR__ . '/DocumentValidationService.php';
require_once __DIR__ . '/SMSService.php';
require_once __DIR__ . '/EmergencyContactService.php';

/**
 * The 4-step Apply Online wizard. Progress is saved as the applicant's
 * draft (application_drafts, one per applicant; uploads in
 * storage/uploads/drafts/<user_id>/) after every action, so it survives
 * logout and can be resumed from any device. It only becomes a real
 * application -- with a reference number -- on final submission.
 * An applicant may have only one active application at a time.
 */
class ApplicationService
{
    public const STEP_META = [
        1 => ['Personal Info', 'Tell us about yourself. Fields marked * are required.'],
        2 => ['Children', 'List each child in your sole care.'],
        3 => ['Documents', 'Review the documents required for your application.'],
        4 => ['Review', 'Check your details before submitting.'],
    ];

    private PDO $pdo;
    private Application $applications;
    private Document $documents;
    private DocumentValidationService $files;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->applications = new Application($pdo);
        $this->documents = new Document($pdo);
        $this->files = new DocumentValidationService($pdo);
    }

    public function files(): DocumentValidationService
    {
        return $this->files;
    }

    // ---- Draft ----

    /** A new draft, pre-filled from the applicant's saved profile so they don't retype it. */
    public function freshDraft(array $user): array
    {
        $profile = (new Applicant($this->pdo))->profile((int) $user['id']);
        return [
            'step' => 1,
            'highestStep' => 1,
            'fullName' => $user['full_name'] ?? '',
            'barangay' => (string) ($profile['barangay'] ?? ''),
            'address' => (string) ($profile['address'] ?? ''),
            'contact' => (string) ($user['mobile'] ?? ''),
            'email' => $user['email'] ?? '',
            'birthdate' => (string) ($profile['birthdate'] ?? ''),
            'sex' => (string) ($profile['sex'] ?? ''),
            'civilStatus' => (string) ($profile['civil_status'] ?? ''),
            'categoryId' => null,
            'children' => [['name' => '', 'birthdate' => '']],
            'documents' => [],
            'checkedDocuments' => [],
            'docErrors' => [],
            'consent' => false,
        ] + EmergencyContactService::draftFields();
    }

    /** The applicant's saved draft, or a fresh one pre-filled from their profile. */
    public function getDraft(array $user): array
    {
        $saved = (new ApplicationDraft($this->pdo))->find((int) $user['id']);
        if (!$saved) {
            return $this->freshDraft($user);
        }
        $draft = $saved['data'] + $this->freshDraft($user);
        // File paths are rebuilt from the stored name, never trusted from storage.
        foreach ($draft['documents'] as $key => $meta) {
            $draft['documents'][$key]['path'] = $this->draftDir((int) $user['id']) . '/' . basename((string) ($meta['stored_name'] ?? ''));
        }
        $draft['step'] = min(4, max(1, (int) $draft['step']));
        $draft['highestStep'] = self::furthestStep($draft);
        $draft['contact'] = (string) ($user['mobile'] ?? ''); // always the confirmed account number, see collectStepInput()
        return $draft;
    }

    /** Saves the draft; the first save is when the application is created (audit-logged). */
    public function saveDraft(array $user, array $draft, bool $syncProfile = false): void
    {
        $draft['highestStep'] = self::furthestStep($draft);
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            if ((new ApplicationDraft($this->pdo))->save((int) $user['id'], $draft)) {
                audit_log($this->pdo, (int) $user['id'], $user['full_name'], $user['role'], 'Application Created', 'user', (int) $user['id'], 'New application draft started');
            }
            if ($syncProfile) {
                $this->syncProfileFromDraft($user, $draft);
            }
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Save valid, nonblank shared fields; incomplete drafts must not erase existing details. */
    private function syncProfileFromDraft(array $user, array $draft): void
    {
        if ($user['role'] !== 'applicant') {
            return;
        }
        $errors = $this->validateStep(1, $draft);
        $fields = [];
        foreach (['fullName' => 'full_name', 'email' => 'email', 'birthdate' => 'birthdate', 'sex' => 'sex', 'civilStatus' => 'civil_status', 'barangay' => 'barangay', 'address' => 'address'] as $source => $target) {
            $value = is_string($draft[$source] ?? null) ? trim($draft[$source]) : '';
            if ($value !== '' && !isset($errors[$source]) && ($source !== 'birthdate' || $value >= '1900-01-01')) {
                $fields[$target] = $value;
            }
        }
        if (!$fields) {
            return;
        }
        $applicants = new Applicant($this->pdo);
        $account = $applicants->findApplicant((int) $user['id']);
        if (!$account) {
            return;
        }
        $before = $applicants->profile((int) $user['id']) + $account;
        $changed = array_filter($fields, static fn($value, $key) => (string) ($before[$key] ?? '') !== $value, ARRAY_FILTER_USE_BOTH);
        if (!$changed) {
            return;
        }
        $applicants->updateApplicationDetails((int) $user['id'], $changed);
        audit_log($this->pdo, (int) $user['id'], $fields['full_name'] ?? $account['full_name'], $user['role'], 'Profile Updated', 'user', (int) $user['id'], 'From application form: ' . implode(', ', array_keys($changed)));
    }

    /** Remember unlocked steps even when the applicant returns to an earlier one. */
    public static function furthestStep(array $draft): int
    {
        return min(4, max(1, (int) ($draft['step'] ?? 1), (int) ($draft['highestStep'] ?? 1)));
    }

    /** A step click saves the displayed form and revisits an already unlocked step. */
    public function navigateToStep(array $draft, int $target, array $post): array
    {
        $draft['highestStep'] = self::furthestStep($draft);
        if ($target < 1 || $target > $draft['highestStep']) {
            return $draft;
        }
        // Legacy standalone Edit forms have no current-step inputs to collect.
        if ((int) ($post['wizard_step'] ?? 0) === (int) $draft['step']) {
            $draft = $this->collectStepInput((int) $draft['step'], $draft, $post);
        }
        $draft['step'] = $target;
        return $draft;
    }

    /** Deletes the draft and its uploaded files (after submission, or when the applicant discards it). */
    public function clearDraft(array $user): void
    {
        (new ApplicationDraft($this->pdo))->delete((int) $user['id']);
        $this->files->deleteDir($this->draftDir((int) $user['id']));
    }

    /** ['step', 'stepLabel', 'updatedAt'] for the applicant's saved draft, or null if they have none. */
    public function draftSummary(array $user): ?array
    {
        $saved = (new ApplicationDraft($this->pdo))->find((int) $user['id']);
        if (!$saved) {
            return null;
        }
        $step = min(4, max(1, (int) ($saved['data']['step'] ?? 1)));
        return ['step' => $step, 'stepLabel' => self::STEP_META[$step][0], 'updatedAt' => $saved['updated_at']];
    }

    public function draftDir(int $userId): string
    {
        return STORAGE_PATH . '/drafts/' . $userId;
    }

    /**
     * The application that stops this applicant starting a new one: any
     * application that isn't Rejected (still being processed, approved, or
     * an ID already released -- which is renewed, not re-applied for).
     */
    public function blockingApplication(array $user): ?array
    {
        return $this->applications->blockingForNewApplication((int) $user['id']);
    }

    /**
     * Merges the fields belonging to $step out of $post into the draft (no
     * validation). The mobile number ('contact') is never taken from the
     * form: it is the account's confirmed number, where every SMS about the
     * application goes, and is changed (and re-confirmed) on My Profile.
     */
    public function collectStepInput(int $step, array $draft, array $post): array
    {
        // Form values are plain strings; anything else (e.g. an injected array) is treated as blank.
        $str = fn($v) => is_string($v) ? mb_substr(trim($v), 0, 500) : ''; // capped: drafts are saved unvalidated
        if ($step === 1) {
            foreach (['fullName', 'barangay', 'address', 'email', 'birthdate', 'sex', 'civilStatus'] as $f) {
                $draft[$f] = $str($post[$f] ?? '');
            }
            foreach (EmergencyContactService::FIELD_MAP as $f => $_) {
                $draft[$f] = $str($post[$f] ?? '');
            }
            $draft['categoryId'] = ctype_digit($str($post['categoryId'] ?? '')) ? (int) $post['categoryId'] : null;
        }
        if ($step === 2) {
            $children = [];
            $names = is_array($post['child_name'] ?? null) ? array_values($post['child_name']) : [];
            $dates = is_array($post['child_birthdate'] ?? null) ? array_values($post['child_birthdate']) : [];
            $count = min(20, max(count($names), count($dates)));
            for ($i = 0; $i < $count; $i++) {
                $children[] = [
                    'name' => $str($names[$i] ?? ''),
                    'birthdate' => $str($dates[$i] ?? ''),
                ];
            }
            $draft['children'] = $children ?: [['name' => '', 'birthdate' => '']];
        }
        if ($step === 3) {
            $allowed = array_column($this->documents->requirementsFor($draft['categoryId']), 'doc_key');
            $selected = is_array($post['checklist'] ?? null) ? array_values(array_filter($post['checklist'], 'is_string')) : [];
            $draft['checkedDocuments'] = array_fill_keys(array_values(array_intersect($allowed, $selected)), true);
        }
        if ($step === 4) {
            $draft['consent'] = !empty($post['consent']);
        }
        return $draft;
    }

    public function validateStep(int $step, array $draft): array
    {
        $errors = [];
        $today = today_iso();

        if ($step === 1) {
            $fullName = trim($draft['fullName']);
            if ($fullName === '') {
                $errors['fullName'] = 'Enter your full name.';
            } elseif (count(preg_split('/\s+/', $fullName)) < 2) {
                $errors['fullName'] = 'Enter your first and last name.';
            } elseif (mb_strlen($fullName) > 150) {
                $errors['fullName'] = 'Full name is too long (up to 150 characters).';
            }
            if (trim($draft['barangay']) === '') {
                $errors['barangay'] = 'Enter your barangay.';
            } elseif (mb_strlen($draft['barangay']) > 100) {
                $errors['barangay'] = 'Barangay is too long (up to 100 characters).';
            }
            if (trim($draft['address']) === '') {
                $errors['address'] = 'Enter your complete address.';
            } elseif (mb_strlen($draft['address']) > 255) {
                $errors['address'] = 'Address is too long (up to 255 characters).';
            }
            $phone = normalize_mobile($draft['contact']);
            if ($phone === '') {
                $errors['contact'] = 'Enter your mobile number.';
            } elseif (!is_valid_ph_mobile($phone)) {
                $errors['contact'] = 'Use a valid PH mobile number, e.g. 09171234567.';
            }
            if (trim($draft['email']) !== '' && (!filter_var(trim($draft['email']), FILTER_VALIDATE_EMAIL) || strlen($draft['email']) > 150)) {
                $errors['email'] = 'Enter a valid email address.';
            }
            if ($draft['birthdate'] === '') {
                $errors['birthdate'] = 'Select your birthdate.';
            } elseif ($draft['birthdate'] > $today) {
                $errors['birthdate'] = 'Birthdate cannot be in the future.';
            } elseif (!is_past_date($draft['birthdate'])) {
                $errors['birthdate'] = 'Enter a valid birthdate.';
            }
            if (!in_array($draft['sex'], ['Male', 'Female'], true)) {
                $errors['sex'] = 'Select an option.';
            }
            if (!in_array($draft['civilStatus'], CIVIL_STATUS_OPTIONS, true)) {
                $errors['civilStatus'] = 'Select your civil status.';
            }
            if (!$draft['categoryId'] || !$this->categoryExists((int) $draft['categoryId'])) {
                $errors['circumstance'] = 'Select your solo parent category.';
            }
            $errors += EmergencyContactService::validate($draft);
        }

        if ($step === 2) {
            foreach ($draft['children'] as $i => $c) {
                if (trim($c['name']) === '') {
                    $errors['cn' . $i] = "Enter the child's full name.";
                } elseif (mb_strlen($c['name']) > 150) {
                    $errors['cn' . $i] = 'Name is too long (up to 150 characters).';
                }
                if ($c['birthdate'] === '') {
                    $errors['cd' . $i] = 'Select a birthdate.';
                } elseif ($c['birthdate'] > $today) {
                    $errors['cd' . $i] = 'Birthdate cannot be in the future.';
                } elseif (!is_past_date($c['birthdate'])) {
                    $errors['cd' . $i] = 'Enter a valid birthdate.';
                }
            }
        }

        if ($step === 4 && empty($draft['consent'])) {
            $errors['consent'] = 'Please confirm to submit.';
        }

        return $errors;
    }

    /** First step (1-4) that fails validation, with its errors, or null if the whole draft is valid. */
    public function firstInvalidStep(array $draft): ?array
    {
        for ($s = 1; $s <= 4; $s++) {
            $errors = $this->validateStep($s, $draft);
            if ($errors) {
                return ['step' => $s, 'errors' => $errors];
            }
        }
        return null;
    }

    private function categoryExists(int $categoryId): bool
    {
        $stmt = $this->pdo->prepare('SELECT id FROM categories WHERE id = ? AND is_active = 1');
        $stmt->execute([$categoryId]);
        return (bool) $stmt->fetch();
    }

    // ---- Submission ----

    /**
     * Creates the application + children + document rows from a valid
     * draft and deletes the draft. Returns the reference number. Throws
     * DomainException if the applicant meanwhile got another active
     * application (e.g. submitted from a second tab).
     */
    public function submit(array $user, array $draft): string
    {
        if ($this->blockingApplication($user)) {
            throw new DomainException('You already have an active application.');
        }
        $draft['contact'] = (string) $user['mobile'];
        // Only documents required for the chosen category go in (the category may have changed after uploading).
        $allowed = array_column($this->documents->requirementsFor($draft['categoryId']), 'doc_key');
        $draft['documents'] = array_intersect_key($draft['documents'], array_flip($allowed));
        for ($attempt = 1; ; $attempt++) {
            try {
                [$applicationId, $refNumber] = $this->insertApplication($user, $draft);
                break;
            } catch (PDOException $e) {
                // Two submissions at the same moment can compute the same next
                // reference number; the UNIQUE key rejects the second, so retry.
                if ($attempt >= 3 || $e->getCode() !== '23000' || strpos($e->getMessage(), 'reference_no') === false) {
                    throw $e;
                }
            }
        }

        $this->clearDraft($user);
        (new QrSlipService($this->pdo))->issue(['id' => $applicationId, 'status' => 'Pending Review', 'id_expires_at' => null], (int) $user['id']);

        audit_log($this->pdo, (int) $user['id'], $user['full_name'], $user['role'], 'Application Submission', 'application', $applicationId, $refNumber);
        $this->auditDocuments($user, $applicationId, $draft['documents']);
        (new SMSService($this->pdo))->notifySubmitted($applicationId, $draft['contact'], $refNumber);
        create_notification($this->pdo, (int) $user['id'], 'submission', 'Your application ' . $refNumber . ' has been received and is Pending Review.', $applicationId);
        (new NotificationService($this->pdo))->notifyStaff('new_application', 'New application ' . $refNumber . ' from ' . $draft['fullName'] . ' is waiting for review.', $applicationId);

        return $refNumber;
    }

    /**
     * One all-or-nothing attempt at writing the application.
     * @return array{0: int, 1: string} [application id, reference number]
     */
    private function insertApplication(array $user, array $draft): array
    {
        $this->pdo->beginTransaction();
        try {
            $refNumber = generate_reference_no($this->pdo);
            $applicationId = $this->applications->create($refNumber, (int) $user['id'], 'new', [
                'full_name' => $draft['fullName'],
                'barangay' => $draft['barangay'],
                'address' => $draft['address'],
                'mobile' => normalize_mobile($draft['contact']),
                'email' => $draft['email'],
                'birthdate' => $draft['birthdate'],
                'sex' => $draft['sex'],
                'civil_status' => $draft['civilStatus'],
                'category_id' => $draft['categoryId'],
            ] + EmergencyContactService::applicationFields($draft), date('Y-m-d H:i:s'));

            foreach ($draft['children'] as $c) {
                $this->applications->addChild($applicationId, $c['name'], $c['birthdate']);
            }
            $this->saveChecklist($applicationId, $draft['checkedDocuments'] ?? [], $draft['categoryId']);
            $this->syncProfileFromDraft($user, $draft);
            $this->storeDraftDocuments($applicationId, $draft['documents'], $user);
            $this->applications->addHistory($applicationId, 'Pending Review', 'Application submitted online.', $draft['fullName'], (int) $user['id']);

            $this->pdo->commit();
            return [$applicationId, $refNumber];
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** Store applicant declarations independently from staff verification. */
    public function saveChecklist(int $applicationId, array $selected, ?int $categoryId, bool $standardOnly = false): void
    {
        $requirements = $standardOnly ? $this->documents->standardRequirements() : $this->documents->requirementsFor($categoryId);
        $allowed = array_column($requirements, 'doc_key');
        $selectedKeys = array_flip(array_intersect($allowed, array_map('strval', array_keys($selected))));
        $stmt = $this->pdo->prepare("INSERT INTO application_document_checklists (application_id, doc_key, applicant_has, verification_status) VALUES (?, ?, ?, 'Pending')");
        foreach ($allowed as $key) {
            $stmt->execute([$applicationId, $key, isset($selectedKeys[$key]) ? 1 : 0]);
        }
    }

    /** Moves a draft's tmp files into the application's permanent folder and records them. Call inside a transaction. */
    public function storeDraftDocuments(int $applicationId, array $documents, array $actor): void
    {
        if (!$documents) {
            return;
        }
        $destDir = STORAGE_PATH . '/' . $applicationId;
        if (!is_dir($destDir) && !mkdir($destDir, 0750, true) && !is_dir($destDir)) {
            throw new RuntimeException('Could not create storage folder for uploaded documents.');
        }
        $moved = []; // final path => draft path, to put files back if anything fails (the caller rolls back the DB)
        try {
            foreach ($documents as $docKey => $meta) {
                // The file must still be exactly what was validated at upload.
                $hash = is_file($meta['path']) ? hash_file('sha256', $meta['path']) : null;
                if ($hash === null || (!empty($meta['sha256']) && !hash_equals($meta['sha256'], $hash))) {
                    throw new RuntimeException('An uploaded document is missing or was altered. Please upload it again.');
                }
                $meta['sha256'] = $hash;
                $final = $destDir . '/' . $meta['stored_name'];
                if (!@rename($meta['path'], $final)) {
                    throw new RuntimeException('Could not store an uploaded document. Please try again.');
                }
                $moved[$final] = $meta['path'];
                $this->documents->create($applicationId, $docKey, $meta, $applicationId . '/' . $meta['stored_name'], $actor);
            }
        } catch (Throwable $e) {
            foreach ($moved as $final => $original) {
                @rename($final, $original);
            }
            @rmdir($destDir); // only succeeds if empty, i.e. nothing else was stored there
            throw $e;
        }
    }

    public function auditDocuments(array $user, int $applicationId, array $documents): void
    {
        foreach ($documents as $docKey => $meta) {
            audit_log($this->pdo, (int) $user['id'], $user['full_name'], $user['role'], 'Document Upload', 'application', $applicationId, $docKey . ' — ' . $meta['original_name']);
        }
    }
}
