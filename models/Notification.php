<?php
require_once __DIR__ . '/Model.php';

/** notifications: one row per recipient; is_read/read_at track whether they've seen it. */
class Notification extends Model
{
    /** Latest notifications of a user (dashboard widgets). */
    public function forUser(int $userId, int $limit = 100): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT ' . (int) $limit);
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public function pageFor(int $userId, bool $unreadOnly, int $limit, int $offset): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM notifications WHERE user_id = ?' . ($unreadOnly ? ' AND is_read = 0' : '')
            . ' ORDER BY created_at DESC, id DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public function countFor(int $userId, bool $unreadOnly): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ?' . ($unreadOnly ? ' AND is_read = 0' : ''));
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    public function findForUser(int $userId, int $notificationId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM notifications WHERE id = ? AND user_id = ?');
        $stmt->execute([$notificationId, $userId]);
        return $stmt->fetch() ?: null;
    }

    public function unreadCount(int $userId): int
    {
        return count_unread_notifications($this->pdo, $userId);
    }

    /** Marks one notification read -- only if it belongs to $userId. Returns false otherwise. */
    public function markRead(int $userId, int $notificationId): bool
    {
        $stmt = $this->pdo->prepare('UPDATE notifications SET is_read = 1, read_at = COALESCE(read_at, NOW()) WHERE id = ? AND user_id = ?');
        $stmt->execute([$notificationId, $userId]);
        return $stmt->rowCount() === 1 || (bool) $this->findForUser($userId, $notificationId);
    }

    /** Returns how many were marked. */
    public function markAllRead(int $userId): int
    {
        $stmt = $this->pdo->prepare('UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0');
        $stmt->execute([$userId]);
        return $stmt->rowCount();
    }

    /** Ids of active accounts with one of $roles. */
    public function activeUserIds(array $roles): array
    {
        if (!$roles) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($roles), '?'));
        $stmt = $this->pdo->prepare("SELECT id FROM users WHERE is_active = 1 AND role IN ($placeholders) ORDER BY id");
        $stmt->execute(array_values($roles));
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
