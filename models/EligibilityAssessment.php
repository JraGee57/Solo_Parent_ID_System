<?php
require_once __DIR__ . '/Model.php';

/**
 * eligibility_assessments rows of type PRELIMINARY: the public checker's
 * complete check history and result-view events. A visitor is identified only by a hash of a random
 * token kept in their session (no personal data is asked for); after they
 * log in or register, the row is linked to their account.
 */
class EligibilityAssessment extends Model
{
    /** Latest preliminary assessment for this visitor token, or null. */
    public function findByToken(string $tokenHash, ?int $userId = null): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM eligibility_assessments WHERE session_token = ? AND assessment_type = 'PRELIMINARY' AND (user_id IS NULL OR user_id = ?) ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$tokenHash, $userId]);
        return $stmt->fetch() ?: null;
    }

    public function latestForUser(int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM eligibility_assessments WHERE user_id = ? AND assessment_type = 'PRELIMINARY' ORDER BY created_at DESC, id DESC LIMIT 1"
        );
        $stmt->execute([$userId]);
        return $stmt->fetch() ?: null;
    }

    /** Every completed check is stored separately; earlier answers remain intact. */
    public function savePreliminary(string $tokenHash, ?int $userId, array $answers, string $result, ?string $reason): int
    {
        $this->pdo->prepare(
            "INSERT INTO eligibility_assessments (session_token, user_id, assessment_type, answers, preliminary_result, result_reason) VALUES (?, ?, 'PRELIMINARY', ?, ?, ?)"
        )->execute([$tokenHash, $userId, json_encode($answers, JSON_UNESCAPED_UNICODE), $result, $reason]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Owners and the anonymous session that completed a check may open its result. */
    public function findForViewer(int $id, ?string $tokenHash, ?int $userId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM eligibility_assessments WHERE id = ? AND assessment_type = 'PRELIMINARY'
            AND (user_id = ? OR (user_id IS NULL AND session_token = ?))");
        $stmt->execute([$id, $userId, $tokenHash]);
        return $stmt->fetch() ?: null;
    }

    public function recordView(int $id, string $tokenHash, ?int $userId): void
    {
        $this->pdo->prepare('INSERT INTO eligibility_result_views (assessment_id, visitor_token, viewer_user_id) VALUES (?, ?, ?)')
            ->execute([$id, $tokenHash, $userId]);
    }

    /** Links this visitor's anonymous assessment(s) to the account they just logged in or registered with. */
    public function claimForUser(string $tokenHash, int $userId): int
    {
        $stmt = $this->pdo->prepare('UPDATE eligibility_assessments SET user_id = ? WHERE session_token = ? AND user_id IS NULL');
        $stmt->execute([$userId, $tokenHash]);
        $claimed = $stmt->rowCount();
        $this->pdo->prepare('UPDATE eligibility_result_views v JOIN eligibility_assessments e ON e.id = v.assessment_id
            SET v.viewer_user_id = ? WHERE e.session_token = ? AND e.user_id = ? AND v.viewer_user_id IS NULL AND v.visitor_token = ?')
            ->execute([$userId, $tokenHash, $userId, $tokenHash]);
        return $claimed;
    }

    /** Administrator-only caller supplies validated filters. One row per check. */
    public function logPage(array $filters, int $page): array
    {
        $where = ["e.assessment_type = 'PRELIMINARY'"];
        $params = [];
        foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
            if ($filters[$key] !== '') {
                $where[] = 'e.created_at ' . $operator . ' ?';
                $params[] = $filters[$key] . ($key === 'from' ? ' 00:00:00' : ' 23:59:59');
            }
        }
        if ($filters['result'] !== '') {
            $where[] = 'e.preliminary_result = ?';
            $params[] = $filters['result'];
        }
        if ($filters['circ'] !== '') {
            $where[] = "JSON_UNQUOTE(JSON_EXTRACT(e.answers, '$.circ')) = ?";
            $params[] = $filters['circ'];
        }
        if ($filters['audience'] !== '') {
            $where[] = 'e.user_id IS ' . ($filters['audience'] === 'guest' ? 'NULL' : 'NOT NULL');
        }
        $condition = implode(' AND ', $where);
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM eligibility_assessments e WHERE ' . $condition);
        $stmt->execute($params);
        $pager = paginate((int) $stmt->fetchColumn(), $page, 10);
        $stmt = $this->pdo->prepare("SELECT e.*, u.full_name, u.username,
            (SELECT MIN(v.viewed_at) FROM eligibility_result_views v WHERE v.assessment_id=e.id) AS first_viewed,
            (SELECT MAX(v.viewed_at) FROM eligibility_result_views v WHERE v.assessment_id=e.id) AS last_viewed,
            (SELECT COUNT(*) FROM eligibility_result_views v WHERE v.assessment_id=e.id) AS view_count,
            (SELECT COUNT(DISTINCT COALESCE(CONCAT('user:',v.viewer_user_id),CONCAT('guest:',v.visitor_token)))
             FROM eligibility_result_views v WHERE v.assessment_id=e.id) AS unique_viewers
            FROM eligibility_assessments e LEFT JOIN users u ON u.id=e.user_id WHERE $condition
            ORDER BY e.created_at DESC, e.id DESC LIMIT {$pager['perPage']} OFFSET {$pager['offset']}");
        $stmt->execute($params);
        return ['logs' => $stmt->fetchAll(), 'pager' => $pager];
    }

    /** Records one official MSWD assessment of an application ($result ELIGIBLE or NOT_ELIGIBLE). */
    public function saveMswd(int $applicationId, int $applicantUserId, string $result, ?string $notes, int $staffId): void
    {
        $this->pdo->prepare(
            "INSERT INTO eligibility_assessments (user_id, application_id, assessment_type, answers, preliminary_result, result_reason, assessed_by)
             VALUES (?, ?, 'MSWD', '{}', ?, ?, ?)"
        )->execute([$applicantUserId, $applicationId, $result, $notes, $staffId]);
    }

    /** The application's MSWD assessments, newest first, with the assessor's name. */
    public function mswdForApplication(int $applicationId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT e.*, u.full_name AS assessor_name FROM eligibility_assessments e
             LEFT JOIN users u ON u.id = e.assessed_by
             WHERE e.application_id = ? AND e.assessment_type = 'MSWD' ORDER BY e.created_at DESC, e.id DESC"
        );
        $stmt->execute([$applicationId]);
        return $stmt->fetchAll();
    }

    /** Anonymous checks nobody ever claimed are temporary: removed after $days days. */
    public function purgeUnclaimed(int $days): void
    {
        $this->pdo->prepare(
            "DELETE FROM eligibility_assessments WHERE assessment_type = 'PRELIMINARY' AND user_id IS NULL AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)"
        )->execute([$days]);
    }
}
