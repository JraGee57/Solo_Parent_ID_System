<?php
require_once __DIR__ . '/Model.php';

/**
 * application_drafts: an applicant's saved, not-yet-submitted work -- one
 * new-application draft and one renewal draft per applicant ($kind).
 */
class ApplicationDraft extends Model
{
    public const KINDS = ['application', 'renewal'];

    /** ['data' => decoded array, 'updated_at' => ...] or null. */
    public function find(int $userId, string $kind = 'application'): ?array
    {
        $stmt = $this->pdo->prepare('SELECT data, updated_at FROM application_drafts WHERE user_id = ? AND kind = ?');
        $stmt->execute([$userId, $kind]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return ['data' => json_decode($row['data'], true) ?: [], 'updated_at' => $row['updated_at']];
    }

    /** Creates or updates the draft; true if it was created (the applicant had none). */
    public function save(int $userId, array $data, string $kind = 'application'): bool
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO application_drafts (user_id, kind, data) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE data = VALUES(data), updated_at = NOW()'
        );
        $stmt->execute([$userId, $kind, json_encode($data, JSON_UNESCAPED_UNICODE)]);
        return $stmt->rowCount() === 1;
    }

    public function delete(int $userId, string $kind = 'application'): void
    {
        $this->pdo->prepare('DELETE FROM application_drafts WHERE user_id = ? AND kind = ?')->execute([$userId, $kind]);
    }

    /** Every draft folder name that belongs to a saved draft (<user_id> or renewal_<user_id>), as array keys. */
    public function folderNames(): array
    {
        $names = [];
        foreach ($this->pdo->query('SELECT user_id, kind FROM application_drafts')->fetchAll() as $row) {
            $names[($row['kind'] === 'renewal' ? 'renewal_' : '') . $row['user_id']] = true;
        }
        return $names;
    }
}