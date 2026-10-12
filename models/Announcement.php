<?php
require_once __DIR__ . '/Model.php';

class Announcement extends Model
{
    public function published(): array
    {
        return $this->pdo->query("SELECT * FROM public_announcements WHERE status = 'published' AND published_at <= NOW() AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY priority DESC, published_at DESC, id DESC")->fetchAll();
    }

    public function all(): array
    {
        return $this->pdo->query('SELECT * FROM public_announcements ORDER BY updated_at DESC, id DESC')->fetchAll();
    }

    public function page(int $page, int $perPage = 10): array
    {
        $pager = paginate((int) $this->pdo->query('SELECT COUNT(*) FROM public_announcements')->fetchColumn(), $page, $perPage);
        $rows = $this->pdo->query("SELECT * FROM public_announcements ORDER BY updated_at DESC, id DESC LIMIT {$pager['perPage']} OFFSET {$pager['offset']}")->fetchAll();
        return ['rows' => $rows] + $pager;
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM public_announcements WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** Validates editorial input before storing plain text. */
    public function save(array $admin, int $id, array $input): string
    {
        $values = [];
        foreach (['title' => 200, 'short_desc' => 500, 'category' => 80, 'details' => 20000] as $key => $limit) {
            $value = is_string($input[$key] ?? null) ? trim($input[$key]) : '';
            if ($value === '' || mb_strlen($value) > $limit || ($key === 'details' && strlen($value) > 65535)) {
                return 'Enter ' . str_replace('_', ' ', $key) . ' (up to ' . $limit . ' characters).';
            }
            $values[$key] = $value;
        }
        $status = $input['status'] ?? null;
        if (!in_array($status, ['draft', 'published'], true)) { return 'Choose Draft or Published.'; }
        $dates = [];
        foreach (['published_at', 'expires_at'] as $key) {
            $value = is_string($input[$key] ?? null) ? $input[$key] : '';
            if ($key === 'expires_at' && $value === '') { $dates[$key] = null; continue; }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value)) { return 'Enter a valid publication or expiry date.'; }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value);
            if (!$date || $date->format('Y-m-d\TH:i') !== $value) { return 'Enter a valid publication or expiry date.'; }
            $dates[$key] = $date->format('Y-m-d H:i:s');
        }
        if ($dates['expires_at'] !== null && $dates['expires_at'] <= $dates['published_at']) { return 'Expiry must be after publication.'; }
        $priority = filter_var($input['priority'] ?? null, FILTER_VALIDATE_INT);
        if ($priority === false || $priority < 0 || $priority > 100) { return 'Priority must be between 0 and 100.'; }
        if ($id && !$this->find($id)) { return 'This announcement no longer exists.'; }
        $args = [...array_values($values), $status, $dates['published_at'], $dates['expires_at'], $priority];
        if ($id) {
            $stmt = $this->pdo->prepare('UPDATE public_announcements SET title=?, short_desc=?, category=?, details=?, status=?, published_at=?, expires_at=?, priority=? WHERE id=?');
            $stmt->execute([...$args, $id]);
        } else {
            $stmt = $this->pdo->prepare('INSERT INTO public_announcements (title, short_desc, category, details, status, published_at, expires_at, priority) VALUES (?,?,?,?,?,?,?,?)');
            $stmt->execute($args);
            $id = (int) $this->pdo->lastInsertId();
        }
        audit_log($this->pdo, (int) $admin['id'], $admin['full_name'], $admin['role'], 'Public Announcement Saved', 'public_announcement', $id, $values['title'] . ' (' . $status . ')');
        return '';
    }
}
