<?php
require_once __DIR__ . '/Model.php';

/** application_documents rows plus the document_requirements definitions they satisfy. */
class Document extends Model
{
    public const CHECKLIST_STATUSES = ['Pending', 'Verified', 'Needs Correction'];

    /** Requirements with applicant declarations and independent staff review. */
    public function checklistForReview(int $applicationId, ?int $categoryId, bool $standardOnly = false): array
    {
        $requirements = $standardOnly ? $this->standardRequirements() : $this->requirementsFor($categoryId);
        $stmt = $this->pdo->prepare('SELECT c.*, u.full_name AS verifier_name FROM application_document_checklists c LEFT JOIN users u ON u.id = c.reviewed_by WHERE c.application_id = ?');
        $stmt->execute([$applicationId]);
        $saved = array_column($stmt->fetchAll(), null, 'doc_key');
        $rows = [];
        foreach ($requirements as $r) {
            $c = $saved[$r['doc_key']] ?? [];
            $rows[] = $c + ['doc_key' => $r['doc_key'], 'applicant_has' => null, 'verification_status' => 'Pending', 'staff_remarks' => null,
                'reviewed_by' => null, 'reviewed_at' => null, 'verifier_name' => null, 'label' => $r['label'],
                'is_required' => (int) $r['is_required'], 'category_specific' => $r['category_id'] !== null];
            unset($saved[$r['doc_key']]);
        }
        foreach ($saved as $c) {
            $rows[] = $c + ['label' => $c['doc_key'], 'is_required' => 0, 'category_specific' => false];
        }
        return $rows;
    }

    public function updateChecklistReview(int $applicationId, string $docKey, string $status, ?string $remarks, int $staffId): bool
    {
        $stmt = $this->pdo->prepare("UPDATE application_document_checklists SET verification_status = ?, staff_remarks = ?, reviewed_by = ?, reviewed_at = NOW() WHERE application_id = ? AND doc_key = ?");
        $stmt->execute([$status, $remarks, $staffId, $applicationId, $docKey]);
        return $stmt->rowCount() > 0;
    }

    public function saveApplicantChecklist(int $applicationId, array $requirements, array $selected): void
    {
        $allowed = array_column($requirements, 'doc_key');
        $selected = array_flip(array_intersect($allowed, array_map('strval', $selected)));
        $stmt = $this->pdo->prepare('UPDATE application_document_checklists SET applicant_has = ? WHERE application_id = ? AND doc_key = ?');
        foreach ($requirements as $r) {
            $stmt->execute([isset($selected[$r['doc_key']]) ? 1 : 0, $applicationId, $r['doc_key']]);
        }
    }

    /** Standard (category_id IS NULL) + category-specific requirements, in display order. */
    public function requirementsFor(?int $categoryId): array
    {
        return get_document_requirements($this->pdo, $categoryId);
    }

    public function categoryOnlyRequirements(?int $categoryId): array
    {
        return get_category_only_requirements($this->pdo, $categoryId);
    }

    /** Every requirement: standard (NULL category) first, then per category. */
    public function allRequirements(): array
    {
        return $this->pdo->query('SELECT * FROM document_requirements ORDER BY (category_id IS NULL) DESC, category_id, sort_order, id')->fetchAll();
    }

    /** The display label for a doc_key (standard or any category), or null if it isn't a requirement any more. */
    public function requirementLabel(string $docKey): ?string
    {
        $stmt = $this->pdo->prepare('SELECT label FROM document_requirements WHERE doc_key = ? ORDER BY category_id IS NULL DESC LIMIT 1');
        $stmt->execute([$docKey]);
        $label = $stmt->fetchColumn();
        return $label !== false ? (string) $label : null;
    }

    public function findRequirement(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM document_requirements WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * True if $docKey would appear twice on some applicant's form: a standard
     * key clashes with any requirement using it; a category key clashes with
     * the same key in that category or among the standard requirements.
     */
    public function requirementKeyConflicts(string $docKey, ?int $categoryId): bool
    {
        $stmt = $categoryId === null
            ? $this->pdo->prepare('SELECT 1 FROM document_requirements WHERE doc_key = ?')
            : $this->pdo->prepare('SELECT 1 FROM document_requirements WHERE doc_key = ? AND (category_id IS NULL OR category_id = ?)');
        $stmt->execute($categoryId === null ? [$docKey] : [$docKey, $categoryId]);
        return (bool) $stmt->fetch();
    }

    /** True if any submitted application already holds a document for this requirement. */
    public function requirementInUse(array $requirement): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM application_documents d JOIN applications a ON a.id = d.application_id
             WHERE d.doc_key = ? AND (? IS NULL OR a.category_id = ?) LIMIT 1'
        );
        $stmt->execute([$requirement['doc_key'], $requirement['category_id'], $requirement['category_id']]);
        return (bool) $stmt->fetch();
    }

    public function createRequirement(?int $categoryId, string $docKey, string $label, bool $isRequired, int $sortOrder): int
    {
        $this->pdo->prepare('INSERT INTO document_requirements (category_id, doc_key, label, is_required, sort_order) VALUES (?, ?, ?, ?, ?)')
            ->execute([$categoryId, $docKey, $label, (int) $isRequired, $sortOrder]);
        return (int) $this->pdo->lastInsertId();
    }

    public function updateRequirement(int $id, string $label, bool $isRequired, int $sortOrder): void
    {
        $this->pdo->prepare('UPDATE document_requirements SET label = ?, is_required = ?, sort_order = ? WHERE id = ?')
            ->execute([$label, (int) $isRequired, $sortOrder, $id]);
    }

    public function deleteRequirement(int $id): void
    {
        $this->pdo->prepare('DELETE FROM document_requirements WHERE id = ?')->execute([$id]);
    }

    public function standardRequirements(): array
    {
        return $this->pdo->query('SELECT * FROM document_requirements WHERE category_id IS NULL ORDER BY sort_order, id')->fetchAll();
    }

    /** All of one applicant's uploaded documents, grouped by application reference_no. */
    public function forUserByReference(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT d.*, a.reference_no, a.category_id, COALESCE(dr.label, d.doc_key) AS doc_label
             FROM application_documents d
             JOIN applications a ON a.id = d.application_id
             LEFT JOIN document_requirements dr ON dr.doc_key = d.doc_key AND (dr.category_id IS NULL OR dr.category_id = a.category_id)
             WHERE a.user_id = ?
             ORDER BY a.submitted_at DESC, d.uploaded_at'
        );
        $stmt->execute([$userId]);
        $byRef = [];
        foreach ($stmt->fetchAll() as $doc) {
            $byRef[$doc['reference_no']][] = $doc;
        }
        return $byRef;
    }

    /**
     * Document lifecycle (application_documents.verification_status):
     * UPLOADED -> VALIDATING -> VALID or INVALID happen during the upload
     * request (DocumentValidationService); an INVALID file is never stored.
     * A valid file attached to a submitted application waits as
     * FOR_VERIFICATION until MSWD marks it VERIFIED, NEEDS_CORRECTION or
     * REJECTED. Replacing a file puts it back to FOR_VERIFICATION.
     */
    public const STATUSES = ['UPLOADED', 'VALIDATING', 'VALID', 'INVALID', 'FOR_VERIFICATION', 'VERIFIED', 'NEEDS_CORRECTION', 'REJECTED'];

    /** What MSWD staff can set (FOR_VERIFICATION undoes an earlier verdict). */
    public const VERIFICATION_STATUSES = ['FOR_VERIFICATION', 'VERIFIED', 'NEEDS_CORRECTION', 'REJECTED'];

    /** Staff have accepted it -- the applicant can no longer replace it. */
    public const LOCKED_STATUSES = ['VERIFIED'];

    /** The document only if it belongs to that application (guards against a tampered doc_id). */
    public function findInApplication(int $documentId, int $applicationId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM application_documents WHERE id = ? AND application_id = ?');
        $stmt->execute([$documentId, $applicationId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Records the verdict and who gave it, when. Setting it back to
     * FOR_VERIFICATION clears the verifier (the trail keeps who reset it).
     */
    public function setVerification(int $documentId, string $status, ?string $remarks, int $verifiedBy): void
    {
        if ($status === 'FOR_VERIFICATION') {
            $this->pdo->prepare('UPDATE application_documents SET verification_status = ?, staff_remarks = ?, verified_by = NULL, verified_at = NULL WHERE id = ?')
                ->execute([$status, $remarks, $documentId]);
            return;
        }
        $this->pdo->prepare('UPDATE application_documents SET verification_status = ?, staff_remarks = ?, verified_by = ?, verified_at = NOW() WHERE id = ?')
            ->execute([$status, $remarks, $verifiedBy, $documentId]);
    }

    /**
     * The application's documents for review, in requirement order, each with
     * its requirement label/is_required and the verifier's name. Requirements
     * with no upload come back as rows with id NULL (missing documents).
     */
    public function forReview(int $applicationId, ?int $categoryId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT d.*, u.full_name AS verifier_name FROM application_documents d
             LEFT JOIN users u ON u.id = d.verified_by WHERE d.application_id = ?'
        );
        $stmt->execute([$applicationId]);
        $uploaded = array_column($stmt->fetchAll(), null, 'doc_key');

        $rows = [];
        foreach ($this->requirementsFor($categoryId) as $r) {
            $doc = $uploaded[$r['doc_key']] ?? ['id' => null, 'doc_key' => $r['doc_key']];
            unset($uploaded[$r['doc_key']]);
            $rows[] = $doc + ['label' => $r['label'], 'is_required' => (int) $r['is_required'], 'category_specific' => $r['category_id'] !== null];
        }
        // Documents whose requirement has since been removed are still shown.
        foreach ($uploaded as $doc) {
            $rows[] = $doc + ['label' => $doc['doc_key'], 'is_required' => 0, 'category_specific' => false];
        }
        return $rows;
    }

    /** One entry in the document's status trail. $actor: id, full_name, role. */
    public function addStatusHistory(int $documentId, int $applicationId, string $docKey, ?string $from, string $to, ?string $remarks, array $actor): void
    {
        $this->pdo->prepare(
            'INSERT INTO document_status_history (document_id, application_id, doc_key, from_status, to_status, remarks, actor_user_id, actor_name, actor_role)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$documentId, $applicationId, $docKey, $from, $to, $remarks, $actor['id'] ?? null, $actor['full_name'], $actor['role']]);
    }

    /** document_id => its status trail, oldest first. */
    public function statusHistoryFor(int $applicationId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM document_status_history WHERE application_id = ? ORDER BY created_at, id');
        $stmt->execute([$applicationId]);
        $byDoc = [];
        foreach ($stmt->fetchAll() as $h) {
            $byDoc[$h['document_id']][] = $h;
        }
        return $byDoc;
    }

    /**
     * In-progress applications with documents still awaiting staff
     * verification, oldest upload first (the verification queue). Each row:
     * id, reference_no, full_name, status, pending_docs, oldest_upload.
     */
    public function verificationQueue(int $limit): array
    {
        return $this->pdo->query(
            "SELECT a.id, a.reference_no, a.full_name, a.status, COUNT(d.id) AS pending_docs, MIN(d.uploaded_at) AS oldest_upload
             FROM applications a JOIN application_documents d ON d.application_id = a.id
             WHERE d.verification_status = 'FOR_VERIFICATION'
               AND a.status IN ('Pending Review','Under Verification','For Revision','Interview Scheduled')
             GROUP BY a.id, a.reference_no, a.full_name, a.status
             ORDER BY oldest_upload
             LIMIT " . (int) $limit
        )->fetchAll();
    }

    /** ['applications' => in-progress applications with unverified documents, 'documents' => those documents]. */
    public function verificationBacklog(): array
    {
        return $this->pdo->query(
            "SELECT COUNT(DISTINCT a.id) AS applications, COUNT(d.id) AS documents
             FROM applications a JOIN application_documents d ON d.application_id = a.id
             WHERE d.verification_status = 'FOR_VERIFICATION'
               AND a.status IN ('Pending Review','Under Verification','For Revision','Interview Scheduled')"
        )->fetch();
    }

    public function findForApplication(int $applicationId, string $docKey): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, doc_key, stored_path, verification_status FROM application_documents WHERE application_id = ? AND doc_key = ?');
        $stmt->execute([$applicationId, $docKey]);
        return $stmt->fetch() ?: null;
    }

    /** Stores a submitted document (FOR_VERIFICATION) and starts its status trail with $actor (id, full_name, role). */
    public function create(int $applicationId, string $docKey, array $meta, string $relativePath, array $actor): int
    {
        $this->pdo->prepare("INSERT INTO application_documents (application_id, doc_key, original_name, stored_path, mime, size_bytes, sha256, verification_status) VALUES (?, ?, ?, ?, ?, ?, ?, 'FOR_VERIFICATION')")
            ->execute([$applicationId, $docKey, $meta['original_name'], $relativePath, $meta['mime'], $meta['size'], $meta['sha256'] ?? null]);
        $id = (int) $this->pdo->lastInsertId();
        $this->addStatusHistory($id, $applicationId, $docKey, null, 'FOR_VERIFICATION', null, $actor);
        return $id;
    }

    /** Swaps in a new file and resets verification so staff re-review it (recorded in the trail as $actor's resubmission). */
    public function replace(array $existing, int $applicationId, array $meta, string $relativePath, array $actor): void
    {
        $documentId = (int) $existing['id'];
        $this->pdo->prepare("UPDATE application_documents SET original_name=?, stored_path=?, mime=?, size_bytes=?, sha256=?, verification_status='FOR_VERIFICATION', staff_remarks=NULL, verified_by=NULL, verified_at=NULL, uploaded_at=NOW() WHERE id=?")
            ->execute([$meta['original_name'], $relativePath, $meta['mime'], $meta['size'], $meta['sha256'] ?? null, $documentId]);
        $this->addStatusHistory($documentId, $applicationId, $existing['doc_key'], $existing['verification_status'], 'FOR_VERIFICATION', 'File replaced by applicant.', $actor);
    }
}
