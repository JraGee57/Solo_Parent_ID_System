<?php
require_once __DIR__ . '/Model.php';

/**
 * Read side of audit_logs (writes go through audit_log() in includes/functions.php).
 * Records are never edited or deleted: the database refuses UPDATE and DELETE
 * on the table (triggers in database/migrate_step26_audit.sql).
 */
class AuditLog extends Model
{
    public const EXPORT_LIMIT = 10000;

    /** Count of events matching a fixed, code-supplied WHERE clause (never user input). */
    public function countWhere(string $where): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM audit_logs WHERE $where")->fetchColumn();
    }

    public function recent(int $limit): array
    {
        return $this->pdo->query('SELECT * FROM audit_logs ORDER BY created_at DESC, id DESC LIMIT ' . (int) $limit)->fetchAll();
    }

    /**
     * One page of events matching $f, newest first. $f (all optional):
     * q (actor, action or details text), actions (exact action names; an
     * empty list matches nothing), role, user (events by or about that
     * account), from/to (Y-m-d).
     */
    public function search(array $f, int $page, int $perPage): array
    {
        [$where, $params] = $this->where($f);
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE $where");
        $count->execute($params);
        $pager = paginate((int) $count->fetchColumn(), $page, $perPage);
        $stmt = $this->pdo->prepare("SELECT * FROM audit_logs WHERE $where ORDER BY created_at DESC, id DESC LIMIT {$pager['perPage']} OFFSET {$pager['offset']}");
        $stmt->execute($params);
        return ['rows' => $stmt->fetchAll()] + $pager;
    }

    /** Every event matching $f for export, or null if there are more than EXPORT_LIMIT. */
    public function all(array $f): ?array
    {
        [$where, $params] = $this->where($f);
        $stmt = $this->pdo->prepare("SELECT * FROM audit_logs WHERE $where ORDER BY created_at DESC, id DESC LIMIT " . (self::EXPORT_LIMIT + 1));
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        return count($rows) > self::EXPORT_LIMIT ? null : $rows;
    }

    /** [sql, params] for search()/all(). */
    private function where(array $f): array
    {
        $where = ['1=1'];
        $params = [];
        if (($f['q'] ?? '') !== '') {
            $where[] = '(actor_name LIKE ? OR action LIKE ? OR details LIKE ?)';
            $like = '%' . addcslashes($f['q'], '%_\\') . '%';
            array_push($params, $like, $like, $like);
        }
        if (isset($f['actions'])) {
            if (!$f['actions']) {
                $where[] = '0=1';
            } else {
                $where[] = 'action IN (' . implode(',', array_fill(0, count($f['actions']), '?')) . ')';
                array_push($params, ...array_values($f['actions']));
            }
        }
        if (($f['role'] ?? '') !== '') {
            $where[] = 'actor_role = ?';
            $params[] = $f['role'];
        }
        if (!empty($f['user'])) {
            $where[] = "(user_id = ? OR (entity_type = 'user' AND entity_id = ?))";
            array_push($params, (int) $f['user'], (int) $f['user']);
        }
        if (is_ymd($f['from'] ?? '')) {
            $where[] = 'created_at >= ?';
            $params[] = $f['from'] . ' 00:00:00';
        }
        if (is_ymd($f['to'] ?? '')) {
            $where[] = 'created_at <= ?';
            $params[] = $f['to'] . ' 23:59:59';
        }
        return [implode(' AND ', $where), $params];
    }

    /**
     * One account's activity, newest first: what the account did ($scope
     * 'by'), what was done to it ($scope 'on', e.g. role changes, lockouts —
     * not routine sign-ins/sign-outs, which target the account too), or both ('').
     */
    public function forAccount(int $userId, string $scope, int $page, int $perPage): array
    {
        $where = match ($scope) {
            'by' => 'user_id = ?',
            'on' => "entity_type = 'user' AND entity_id = ? AND action NOT IN ('Login', 'Logout')",
            default => "(user_id = ? OR (entity_type = 'user' AND entity_id = ?))",
        };
        $params = $scope === '' || !in_array($scope, ['by', 'on'], true) ? [$userId, $userId] : [$userId];
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE $where");
        $count->execute($params);
        $pager = paginate((int) $count->fetchColumn(), $page, $perPage);
        $stmt = $this->pdo->prepare("SELECT * FROM audit_logs WHERE $where ORDER BY created_at DESC, id DESC LIMIT {$pager['perPage']} OFFSET {$pager['offset']}");
        $stmt->execute($params);
        return ['rows' => $stmt->fetchAll()] + $pager;
    }

    public function distinctActions(): array
    {
        return $this->pdo->query('SELECT DISTINCT action FROM audit_logs ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
    }

    public function distinctRoles(): array
    {
        return $this->pdo->query('SELECT DISTINCT actor_role FROM audit_logs ORDER BY actor_role')->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * What the events' targets currently are, keyed "entity_type:entity_id":
     * ['label', 'application_id' | 'user_id' | null]. Records that no longer
     * exist are left out (the event keeps its own type, id and details).
     */
    public function targets(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            if ($row['entity_type'] !== null && $row['entity_id'] !== null) {
                $ids[$row['entity_type']][(int) $row['entity_id']] = true;
            }
        }
        $lookups = [
            'application' => ['SELECT id, reference_no AS label, id AS application_id FROM applications', 'application_id'],
            'user' => ["SELECT id, CONCAT(full_name, ' (@', username, ')') AS label, id AS user_id FROM users", 'user_id'],
            'application_document' => ["SELECT d.id, CONCAT(d.doc_key, ' · ', a.reference_no) AS label, a.id AS application_id FROM application_documents d JOIN applications a ON a.id = d.application_id", 'application_id', 'd.id'],
            'appointment_slot' => ["SELECT id, CONCAT('Slot ', DATE_FORMAT(slot_date, '%b %e, %Y'), ' ', TIME_FORMAT(start_time, '%l:%i %p')) AS label FROM appointment_slots", null],
            'faq' => ['SELECT id, question AS label FROM faqs', null],
            'document_requirement' => ['SELECT id, label FROM document_requirements', null],
        ];
        $targets = [];
        foreach ($ids as $type => $set) {
            if (!isset($lookups[$type])) {
                continue;
            }
            [$sql, $linkKey] = $lookups[$type];
            $idColumn = $lookups[$type][2] ?? 'id';
            $stmt = $this->pdo->prepare("$sql WHERE $idColumn IN (" . implode(',', array_fill(0, count($set), '?')) . ')');
            $stmt->execute(array_keys($set));
            foreach ($stmt->fetchAll() as $t) {
                $targets[$type . ':' . $t['id']] = ['label' => $t['label'], 'link' => $linkKey ? [$linkKey, (int) $t[$linkKey]] : null];
            }
        }
        return $targets;
    }
}
