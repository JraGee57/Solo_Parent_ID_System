<?php
require_once __DIR__ . '/Model.php';

/** Read side of sms_logs plus the admin-editable sms_templates (writes to sms_logs go through services/SMSService.php). */
class SmsLog extends Model
{
    /** Filter key => SQL condition. 'test' = logged in test mode (never sent); 'skipped' is its old name, kept for links. */
    private const FILTERS = [
        'sent' => "s.status = 'SENT' AND s.test_mode = 0",
        'test' => "s.status = 'SENT' AND s.test_mode = 1",
        'skipped' => "s.status = 'SENT' AND s.test_mode = 1",
        'failed' => "s.status = 'FAILED'",
        'pending' => "s.status = 'PENDING'",
    ];

    /** Paginated logs, with account-level SMS retaining a NULL reference_no. */
    public function page(string $filter, string $q, int $page, int $perPage = 10): array
    {
        $sql = 'SELECT s.*, a.reference_no FROM sms_logs s LEFT JOIN applications a ON a.id = s.application_id WHERE 1=1';
        $params = [];
        if (isset(self::FILTERS[$filter])) {
            $sql .= ' AND ' . self::FILTERS[$filter];
        }
        if ($q !== '') {
            $sql .= ' AND (a.reference_no LIKE ? OR s.mobile LIKE ? OR s.message LIKE ?)';
            $params = ["%$q%", "%$q%", "%$q%"];
        }
        $count = $this->pdo->prepare(str_replace('SELECT s.*, a.reference_no', 'SELECT COUNT(*)', $sql));
        $count->execute($params);
        $pager = paginate((int) $count->fetchColumn(), $page, $perPage);
        $stmt = $this->pdo->prepare($sql . " ORDER BY s.sent_at DESC, s.id DESC LIMIT {$pager['perPage']} OFFSET {$pager['offset']}");
        $stmt->execute($params);
        return ['rows' => $stmt->fetchAll()] + $pager;
    }

    /** Kept for callers that need the latest 200 logs. */
    public function search(string $filter, string $q): array
    {
        return $this->page($filter, $q, 1, 200)['rows'];
    }

    /** How many logs match a filter key (sent / test / failed / pending). */
    public function countByStatus(string $filter): int
    {
        if (!isset(self::FILTERS[$filter])) {
            return 0;
        }
        return (int) $this->pdo->query('SELECT COUNT(*) FROM sms_logs s WHERE ' . self::FILTERS[$filter])->fetchColumn();
    }

    public function templates(): array
    {
        return $this->pdo->query('SELECT * FROM sms_templates ORDER BY template_key')->fetchAll();
    }

    public function templateExists(string $key): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM sms_templates WHERE template_key = ?');
        $stmt->execute([$key]);
        return (bool) $stmt->fetchColumn();
    }

    public function updateTemplate(string $key, string $body): void
    {
        $this->pdo->prepare('UPDATE sms_templates SET body = ? WHERE template_key = ?')->execute([$body, $key]);
    }
}
