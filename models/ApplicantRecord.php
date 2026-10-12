<?php
require_once __DIR__ . '/Model.php';

/**
 * Read-only queries behind centralised record management: finding applicant
 * records by account or application details, and every record kept about
 * one applicant (documents, appointments, decisions, status history).
 */
class ApplicantRecord extends Model
{
    /** Every barangay that appears on an applicant profile or application, A-Z. */
    public function barangays(): array
    {
        return $this->pdo->query(
            "SELECT b FROM (
                SELECT TRIM(barangay) AS b FROM applications
                UNION SELECT TRIM(p.barangay) FROM applicants p JOIN users u ON u.id = p.user_id AND u.role = 'applicant'
             ) x WHERE b IS NOT NULL AND b <> '' ORDER BY b"
        )->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Applicant accounts matching $f (already validated by RecordService):
     * q (name/username/email/mobile, name on an application, or reference
     * number), barangay (profile or any application), status ('NONE' = no
     * application), type, account (active|inactive), sort (name|recent).
     * Status and type must hold for the same application.
     * Returns ['rows' => ..., ...paginate() fields].
     */
    public function search(array $f, int $page, int $perPage): array
    {
        [$where, $params] = $this->conditions($f);
        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM users u LEFT JOIN applicants p ON p.user_id = u.id WHERE $where");
        $countStmt->execute($params);
        $pager = paginate((int) $countStmt->fetchColumn(), $page, $perPage);

        $order = $f['sort'] === 'recent' ? 'last_activity IS NULL, last_activity DESC, u.full_name' : 'u.full_name, u.id';
        $stmt = $this->pdo->prepare(
            "SELECT u.id, u.full_name, u.username, u.email, u.mobile, u.mobile_verified_at, u.is_active, u.created_at, p.barangay AS profile_barangay,
                    (SELECT COUNT(*) FROM applications a WHERE a.user_id = u.id) AS app_count,
                    (SELECT MAX(a.submitted_at) FROM applications a WHERE a.user_id = u.id) AS last_activity
             FROM users u LEFT JOIN applicants p ON p.user_id = u.id
             WHERE $where ORDER BY $order LIMIT {$pager['perPage']} OFFSET {$pager['offset']}"
        );
        $stmt->execute($params);
        return ['rows' => $stmt->fetchAll()] + $pager;
    }

    /**
     * For each listed applicant, up to $limit of their applications, newest
     * first -- the ones matching the application filters when any are set,
     * and an application whose reference number was searched for first.
     */
    public function applicationsFor(array $userIds, array $f, int $limit = 3): array
    {
        if (!$userIds) {
            return [];
        }
        [$appWhere, $appParams] = $this->applicationConditions($f, 'a');
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT a.id, a.user_id, a.reference_no, a.type, a.status, a.barangay, a.submitted_at, a.id_expires_at
             FROM applications a WHERE a.user_id IN ($placeholders)" . ($appWhere ? " AND $appWhere" : '') . '
             ORDER BY a.reference_no LIKE ? DESC, a.submitted_at DESC, a.id DESC'
        );
        $stmt->execute(array_merge(array_values($userIds), $appParams, [$f['q'] !== '' ? '%' . $f['q'] . '%' : '']));
        $byUser = [];
        foreach ($stmt->fetchAll() as $app) {
            if (count($byUser[$app['user_id']] ?? []) < $limit) {
                $byUser[$app['user_id']][] = $app;
            }
        }
        return $byUser;
    }

    private function conditions(array $f): array
    {
        $where = ["u.role = 'applicant'"];
        $params = [];
        if ($f['q'] !== '') {
            $like = '%' . $f['q'] . '%';
            $where[] = '(u.full_name LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR u.mobile LIKE ?
                         OR EXISTS (SELECT 1 FROM applications aq WHERE aq.user_id = u.id AND (aq.reference_no LIKE ? OR aq.full_name LIKE ?)))';
            array_push($params, $like, $like, $like, $like, $like, $like);
        }
        if ($f['barangay'] !== '') {
            $where[] = '(TRIM(p.barangay) = ? OR EXISTS (SELECT 1 FROM applications ab WHERE ab.user_id = u.id AND TRIM(ab.barangay) = ?))';
            array_push($params, $f['barangay'], $f['barangay']);
        }
        if ($f['status'] === 'NONE') {
            $where[] = 'NOT EXISTS (SELECT 1 FROM applications an WHERE an.user_id = u.id)';
        } elseif ($f['status'] !== '' || $f['type'] !== '') {
            [$appWhere, $appParams] = $this->applicationConditions(['status' => $f['status'], 'type' => $f['type']], 'af');
            $where[] = "EXISTS (SELECT 1 FROM applications af WHERE af.user_id = u.id AND $appWhere)";
            array_push($params, ...$appParams);
        }
        if ($f['account'] !== '') {
            $where[] = 'u.is_active = ' . ($f['account'] === 'active' ? 1 : 0);
        }
        return [implode(' AND ', $where), $params];
    }

    /** Conditions on one application row (alias $t): status and type. */
    private function applicationConditions(array $f, string $t): array
    {
        $where = [];
        $params = [];
        if (($f['status'] ?? '') !== '' && $f['status'] !== 'NONE') {
            $where[] = "$t.status = ?";
            $params[] = $f['status'];
        }
        if (($f['type'] ?? '') !== '') {
            $where[] = "$t.type = ?";
            $params[] = $f['type'];
        }
        return [implode(' AND ', $where), $params];
    }

    // ---- One applicant's full record ----

    /** Every application with category and, for renewals, the reference of the ID renewed. Newest first. */
    public function applications(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.*, c.name AS category_name, src.reference_no AS renews_reference, r.full_name AS reviewed_by_name
             FROM applications a
             JOIN categories c ON c.id = a.category_id
             LEFT JOIN applications src ON src.id = a.renewal_of_id
             LEFT JOIN users r ON r.id = a.reviewed_by
             WHERE a.user_id = ? ORDER BY a.submitted_at DESC, a.id DESC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    private function inList(array $ids): string
    {
        return implode(',', array_map('intval', $ids ?: [0]));
    }

    public function documents(array $appIds): array
    {
        return $this->pdo->query(
            'SELECT d.id, d.application_id, d.doc_key, d.original_name, d.mime, d.size_bytes, d.verification_status, d.staff_remarks,
                    d.uploaded_at, d.verified_at, v.full_name AS verified_by_name, a.reference_no,
                    COALESCE((SELECT dr.label FROM document_requirements dr WHERE dr.doc_key = d.doc_key AND (dr.category_id IS NULL OR dr.category_id = a.category_id) LIMIT 1), d.doc_key) AS doc_label
             FROM application_documents d
             JOIN applications a ON a.id = d.application_id
             LEFT JOIN users v ON v.id = d.verified_by
             WHERE d.application_id IN (' . $this->inList($appIds) . ')
             ORDER BY a.submitted_at DESC, d.uploaded_at, d.id'
        )->fetchAll();
    }

    public function appointments(array $appIds): array
    {
        return $this->pdo->query(
            'SELECT i.*, a.reference_no, c.full_name AS cancelled_by_name
             FROM appointments i JOIN applications a ON a.id = i.application_id
             LEFT JOIN users c ON c.id = i.cancelled_by
             WHERE i.application_id IN (' . $this->inList($appIds) . ')
             ORDER BY i.scheduled_at DESC'
        )->fetchAll();
    }

    public function decisions(array $appIds): array
    {
        return $this->pdo->query(
            'SELECT d.*, a.reference_no, u.full_name AS decided_by_name
             FROM application_decisions d JOIN applications a ON a.id = d.application_id
             LEFT JOIN users u ON u.id = d.decided_by
             WHERE d.application_id IN (' . $this->inList($appIds) . ')
             ORDER BY d.decided_at DESC, d.id DESC'
        )->fetchAll();
    }

    /** Status changes of the given applications, newest first, one page at a time. */
    public function statusHistory(array $appIds, int $page, int $perPage): array
    {
        $in = $this->inList($appIds);
        $pager = paginate((int) $this->pdo->query("SELECT COUNT(*) FROM application_status_history WHERE application_id IN ($in)")->fetchColumn(), $page, $perPage);
        $rows = $this->pdo->query(
            "SELECT h.*, a.reference_no FROM application_status_history h JOIN applications a ON a.id = h.application_id
             WHERE h.application_id IN ($in) ORDER BY h.changed_at DESC, h.id DESC LIMIT {$pager['perPage']} OFFSET {$pager['offset']}"
        )->fetchAll();
        return ['rows' => $rows] + $pager;
    }
}
