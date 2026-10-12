<?php
require_once __DIR__ . '/Model.php';

/** Data access for applications and their status history, as seen by the owning applicant. */
class Application extends Model
{
    public const EDITABLE_STATUSES = ['Pending Review', 'Under Verification', 'For Revision'];
    public const STATUSES = ['Pending Review', 'Under Verification', 'For Revision', 'Interview Scheduled', 'Approved', 'ID Released', 'Rejected'];
    public const ELIGIBILITY_STATUSES = ['Not Assessed', 'Eligible', 'Not Eligible'];
    /** Still being processed by MSWD -- documents can be verified. After a final decision they are frozen. */
    public const IN_PROGRESS_STATUSES = ['Pending Review', 'Under Verification', 'For Revision', 'Interview Scheduled'];

    /**
     * The controlled processing workflow: which status MSWD staff may move an
     * application to from each status. "Interview Scheduled" is only ever set
     * by booking/scheduling an appointment; Rejected and ID Released are final.
     * (These are the spec's Pending / Under Review / For Revision / Interview
     * Scheduled / Approved / Rejected, plus ID Released after approval.)
     */
    public const TRANSITIONS = [
        'Pending Review'      => ['Under Verification', 'For Revision', 'Rejected'],
        'Under Verification'  => ['For Revision', 'Approved', 'Rejected'],
        'For Revision'        => ['Under Verification', 'Rejected'],
        'Interview Scheduled' => ['Under Verification', 'For Revision', 'Approved', 'Rejected'],
        'Approved'            => [],
        'ID Released'         => [],
        'Rejected'            => [],
    ];
    /** Decisions the applicant must be given a reason for. */
    public const REASON_REQUIRED = ['For Revision', 'Rejected'];
    /** Button wording for each target status on the staff decision panel. */
    public const ACTION_LABELS = [
        'Under Verification' => 'Start Review',
        'For Revision'       => 'Request Corrections',
        'Approved'           => 'Approve',
        'Rejected'           => 'Reject',
    ];

    // ---- Staff queue / search ----

    /** Staff queue search. $filters: status, type, barangay, q (each optional). Returns ['rows', 'total', 'page', 'totalPages']. */
    public function search(array $filters, int $page, int $perPage): array
    {
        $where = '1=1';
        $params = [];
        if (in_array($filters['status'] ?? '', self::STATUSES, true)) {
            $where .= ' AND a.status = ?';
            $params[] = $filters['status'];
        }
        if (in_array($filters['type'] ?? '', ['new', 'renewal'], true)) {
            $where .= ' AND a.type = ?';
            $params[] = $filters['type'];
        }
        if (($filters['barangay'] ?? '') !== '') {
            $where .= ' AND a.barangay LIKE ?';
            $params[] = '%' . $filters['barangay'] . '%';
        }
        if (($filters['q'] ?? '') !== '') {
            $where .= ' AND (a.reference_no LIKE ? OR a.full_name LIKE ?)';
            $params[] = '%' . $filters['q'] . '%';
            $params[] = '%' . $filters['q'] . '%';
        }

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM applications a WHERE $where");
        $countStmt->execute($params);
        $pager = paginate((int) $countStmt->fetchColumn(), $page, $perPage);

        $stmt = $this->pdo->prepare(
            "SELECT a.*, c.name AS category_name FROM applications a JOIN categories c ON c.id = a.category_id
             WHERE $where ORDER BY a.submitted_at DESC LIMIT {$pager['perPage']} OFFSET {$pager['offset']}"
        );
        $stmt->execute($params);
        return ['rows' => $stmt->fetchAll()] + $pager;
    }

    /** Admin read-only monitoring list: latest 200, by status/type/reference-or-name. */
    public function monitoringList(string $status, string $type, string $q): array
    {
        $sql = 'SELECT a.*, c.name AS category_name FROM applications a JOIN categories c ON c.id = a.category_id WHERE 1=1';
        $params = [];
        if (in_array($status, self::STATUSES, true)) {
            $sql .= ' AND a.status = ?';
            $params[] = $status;
        }
        if (in_array($type, ['new', 'renewal'], true)) {
            $sql .= ' AND a.type = ?';
            $params[] = $type;
        }
        if ($q !== '') {
            $sql .= ' AND (a.reference_no LIKE ? OR a.full_name LIKE ?)';
            array_push($params, "%$q%", "%$q%");
        }
        $stmt = $this->pdo->prepare($sql . ' ORDER BY a.submitted_at DESC LIMIT 200');
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** status => count across all applications. */
    public function countsByStatus(): array
    {
        return $this->pdo->query('SELECT status, COUNT(*) AS n FROM applications GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /** Every application with category and reviewer name, newest first, for the CSV export. */
    public function allForExport(): array
    {
        return $this->pdo->query(
            'SELECT a.reference_no, a.type, a.full_name, c.name AS category_name, a.status, a.mobile, a.email, r.full_name AS reviewer_name, a.submitted_at
             FROM applications a
             JOIN categories c ON c.id = a.category_id
             LEFT JOIN users r ON r.id = a.reviewed_by
             ORDER BY a.submitted_at DESC'
        )->fetchAll();
    }

    /** Count of applications matching a fixed, code-supplied WHERE clause (never user input). */
    public function countWhere(string $where): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM applications WHERE $where")->fetchColumn();
    }

    public function recentlyReviewedBy(int $staffId, int $limit = 5): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM applications WHERE reviewed_by = ? ORDER BY updated_at DESC LIMIT ' . (int) $limit);
        $stmt->execute([$staffId]);
        return $stmt->fetchAll();
    }

    /** Shared staff activity, including work done before a new staff account was created. */
    public function recentlyReviewed(int $limit = 5): array
    {
        return $this->pdo->query(
            'SELECT a.*, r.full_name AS reviewer_name FROM applications a
             LEFT JOIN users r ON r.id = a.reviewed_by
             WHERE a.reviewed_by IS NOT NULL
             ORDER BY a.updated_at DESC, a.id DESC LIMIT ' . max(1, (int) $limit)
        )->fetchAll();
    }

    // ---- Single application (staff review) ----

    public function findForReview(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.*, c.name AS category_name, r.full_name AS reviewer_name,
                    acct.mobile AS account_mobile, acct.mobile_verified_at AS account_mobile_verified_at
             FROM applications a
             JOIN categories c ON c.id = a.category_id
             JOIN users acct ON acct.id = a.user_id
             LEFT JOIN users r ON r.id = a.reviewed_by
             WHERE a.id = ?'
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function findIdByReference(string $ref): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM applications WHERE reference_no = ?');
        $stmt->execute([$ref]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    public function children(int $applicationId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM application_children WHERE application_id = ?');
        $stmt->execute([$applicationId]);
        return $stmt->fetchAll();
    }

    public function history(int $applicationId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM application_status_history WHERE application_id = ? ORDER BY changed_at DESC, id DESC');
        $stmt->execute([$applicationId]);
        return $stmt->fetchAll();
    }

    /** Sets status + reviewer; new and renewed IDs expire one year after release. */
    public function setReviewedStatus(int $applicationId, string $status, int $staffId): void
    {
        if ($status === 'ID Released') {
            $this->pdo->prepare('UPDATE applications SET status = ?, reviewed_by = ?, id_expires_at = DATE_ADD(CURDATE(), INTERVAL 1 YEAR) WHERE id = ?')
                ->execute([$status, $staffId, $applicationId]);
            return;
        }
        $this->pdo->prepare('UPDATE applications SET status = ?, reviewed_by = ? WHERE id = ?')->execute([$status, $staffId, $applicationId]);
    }

    public function setEligibility(int $applicationId, string $status, ?string $remarks): void
    {
        $this->pdo->prepare('UPDATE applications SET eligibility_status = ?, eligibility_remarks = ? WHERE id = ?')
            ->execute([$status, $remarks, $applicationId]);
    }

    /** Every application of one applicant, newest first, with category name and interview (if any). */
    public function forUserDetailed(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT a.*, c.name AS category_name, i.scheduled_at AS interview_at, i.location AS interview_location, i.status AS interview_status
             FROM applications a
             JOIN categories c ON c.id = a.category_id
             LEFT JOIN appointments i ON i.application_id = a.id AND i.status <> 'CANCELLED'
             WHERE a.user_id = ? ORDER BY a.submitted_at DESC"
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public function forUser(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM applications WHERE user_id = ? ORDER BY submitted_at DESC');
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    /** The applicant's newest application that isn't Rejected (being processed, approved, or ID released), or null. */
    public function blockingForNewApplication(int $userId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM applications WHERE user_id = ? AND status <> 'Rejected' ORDER BY submitted_at DESC, id DESC LIMIT 1");
        $stmt->execute([$userId]);
        return $stmt->fetch() ?: null;
    }

    /** A renewal of this applicant's that is still being processed (not yet released or rejected), or null. */
    public function activeRenewal(int $userId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM applications WHERE user_id = ? AND type = 'renewal' AND status NOT IN ('ID Released', 'Rejected') ORDER BY id DESC LIMIT 1");
        $stmt->execute([$userId]);
        return $stmt->fetch() ?: null;
    }

    /** The record a renewal renews (reference, status, details) -- for staff "what changed" views. */
    public function findWithChildren(int $applicationId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT a.*, c.name AS category_name FROM applications a JOIN categories c ON c.id = a.category_id WHERE a.id = ?');
        $stmt->execute([$applicationId]);
        $app = $stmt->fetch();
        if (!$app) {
            return null;
        }
        $app['children'] = $this->children($applicationId);
        return $app;
    }

    public function findOwned(int $applicationId, int $userId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, user_id, reference_no, full_name, status, category_id FROM applications WHERE id = ? AND user_id = ?');
        $stmt->execute([$applicationId, $userId]);
        return $stmt->fetch() ?: null;
    }

    public function findByReference(string $ref): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM applications WHERE reference_no = ?');
        $stmt->execute([$ref]);
        return $stmt->fetch() ?: null;
    }

    /** Latest renewal filed for this particular ID, including its final outcome. */
    public function latestRenewalFor(int $applicationId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT reference_no, status FROM applications WHERE renewal_of_id = ? ORDER BY submitted_at DESC, id DESC LIMIT 1');
        $stmt->execute([$applicationId]);
        return $stmt->fetch() ?: null;
    }

    public function findByReferenceWithCategory(string $ref): ?array
    {
        $stmt = $this->pdo->prepare('SELECT a.*, c.name AS category_name FROM applications a JOIN categories c ON c.id = a.category_id WHERE a.reference_no = ?');
        $stmt->execute([$ref]);
        return $stmt->fetch() ?: null;
    }

    /** The applicant's current Solo Parent ID: their most recently released application, new or renewal. */
    public function latestReleased(int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT a.*, c.name AS category_name FROM applications a
             JOIN categories c ON c.id = a.category_id
             WHERE a.user_id = ? AND a.status = 'ID Released'
             ORDER BY a.submitted_at DESC, a.id DESC LIMIT 1"
        );
        $stmt->execute([$userId]);
        return $stmt->fetch() ?: null;
    }

    /** Inserts the application row and returns its id. $type is 'new' or 'renewal' ($renewalOfId: the ID being renewed). */
    public function create(string $refNumber, int $userId, string $type, array $fields, string $now, ?int $renewalOfId = null): int
    {
        $this->pdo->prepare(
            "INSERT INTO applications
                (reference_no, user_id, type, renewal_of_id, full_name, barangay, address, mobile, email, birthdate, sex, civil_status, category_id, emergency_contact_name, emergency_contact_relation, emergency_contact_phone, emergency_contact_address, status, consent_at, submitted_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending Review', ?, ?)"
        )->execute([
            $refNumber, $userId, $type, $renewalOfId, $fields['full_name'], $fields['barangay'], $fields['address'],
            $fields['mobile'], $fields['email'], $fields['birthdate'], $fields['sex'],
            $fields['civil_status'], $fields['category_id'],
            $fields['emergency_contact_name'] ?? null, $fields['emergency_contact_relation'] ?? null,
            $fields['emergency_contact_phone'] ?? null, $fields['emergency_contact_address'] ?? null, $now, $now,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function addChild(int $applicationId, string $name, string $birthdate): void
    {
        $this->pdo->prepare('INSERT INTO application_children (application_id, full_name, birthdate) VALUES (?, ?, ?)')
            ->execute([$applicationId, $name, $birthdate]);
    }

    /**
     * The status the application had before its most recent "Interview
     * Scheduled" -- where it goes back to when that appointment is cancelled
     * or missed. Defaults to Under Verification if the history has none.
     */
    public function statusBeforeInterview(int $applicationId): string
    {
        $stmt = $this->pdo->prepare(
            "SELECT status FROM application_status_history
             WHERE application_id = ? AND status <> 'Interview Scheduled'
               AND id < COALESCE((SELECT MAX(id) FROM application_status_history WHERE application_id = ? AND status = 'Interview Scheduled'), 2147483647)
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$applicationId, $applicationId]);
        $status = $stmt->fetchColumn();
        return $status !== false ? (string) $status : 'Under Verification';
    }

    public function setStatus(int $applicationId, string $status): void
    {
        $this->pdo->prepare('UPDATE applications SET status = ? WHERE id = ?')->execute([$status, $applicationId]);
    }

    /** Moves the application to $to only if it is still $from (so two requests can't both move it). True if it moved. */
    public function moveStatus(int $applicationId, string $from, string $to): bool
    {
        $stmt = $this->pdo->prepare('UPDATE applications SET status = ? WHERE id = ? AND status = ?');
        $stmt->execute([$to, $applicationId, $from]);
        return $stmt->rowCount() === 1;
    }

    /** $changedBy is the display-name snapshot; $changedByUserId links the account that made the change. */
    public function addHistory(int $applicationId, string $status, ?string $remarks, string $changedBy, ?int $changedByUserId): void
    {
        $this->pdo->prepare('INSERT INTO application_status_history (application_id, status, remarks, changed_by, changed_by_user_id) VALUES (?, ?, ?, ?, ?)')
            ->execute([$applicationId, $status, $remarks, $changedBy, $changedByUserId]);
    }

    /** Every MSWD decision on the application, newest first, with the deciding staff member's name. */
    public function decisions(int $applicationId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT d.*, u.full_name AS decided_by_name FROM application_decisions d
             LEFT JOIN users u ON u.id = d.decided_by WHERE d.application_id = ? ORDER BY d.decided_at DESC, d.id DESC'
        );
        $stmt->execute([$applicationId]);
        return $stmt->fetchAll();
    }

    /** Internal MSWD case notes, newest first. */
    public function notes(int $applicationId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM application_notes WHERE application_id = ? ORDER BY created_at DESC, id DESC');
        $stmt->execute([$applicationId]);
        return $stmt->fetchAll();
    }

    /** $author: id, full_name, role. */
    public function addNote(int $applicationId, string $note, array $author): void
    {
        $this->pdo->prepare('INSERT INTO application_notes (application_id, note, author_id, author_name, author_role) VALUES (?, ?, ?, ?, ?)')
            ->execute([$applicationId, $note, $author['id'], $author['full_name'], $author['role']]);
    }

    /** $decision is APPROVED, REJECTED or NEEDS_CORRECTION. */
    public function recordDecision(int $applicationId, string $decision, ?string $reason, int $decidedBy): void
    {
        $this->pdo->prepare('INSERT INTO application_decisions (application_id, decision, reason, decided_by) VALUES (?, ?, ?, ?)')
            ->execute([$applicationId, $decision, $reason, $decidedBy]);
    }
}
