<?php
require_once __DIR__ . '/Model.php';

/**
 * chat_messages: one conversation per applicant (user_id) with the Help
 * Assistant (ASSISTANT) and, once the applicant asks for it (handoff = 1 on
 * their message), MSWDO staff (STAFF, staff_user_id = who replied).
 * A conversation is "waiting for staff" while its latest hand-off request
 * has no STAFF message after it.
 */
class ChatMessage extends Model
{
    public function add(int $userId, string $sender, string $message, ?string $topic = null, ?array $meta = null, bool $handoff = false, ?int $staffUserId = null): int
    {
        $this->pdo->prepare(
            'INSERT INTO chat_messages (user_id, sender, message, topic, meta, handoff, staff_user_id) VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$userId, $sender, $message, $topic, $meta !== null ? json_encode($meta) : null, $handoff ? 1 : 0, $staffUserId]);
        return (int) $this->pdo->lastInsertId();
    }

    /** The latest $limit messages of one conversation, oldest first, with meta decoded and the staff member's name. */
    public function thread(int $userId, int $limit = 100): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM (
                SELECT m.*, s.full_name AS staff_name FROM chat_messages m
                LEFT JOIN users s ON s.id = m.staff_user_id
                WHERE m.user_id = ? ORDER BY m.id DESC LIMIT ' . max(1, $limit) . '
             ) t ORDER BY id'
        );
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['meta'] = $row['meta'] !== null ? (json_decode($row['meta'], true) ?: []) : [];
        }
        return $rows;
    }

    /** How many messages the applicant sent in the last $seconds (for the rate limit). */
    public function sentSince(int $userId, int $seconds): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM chat_messages WHERE user_id = ? AND sender = 'APPLICANT' AND created_at >= NOW() - INTERVAL ? SECOND");
        $stmt->execute([$userId, $seconds]);
        return (int) $stmt->fetchColumn();
    }

    /** True while the applicant's latest hand-off request has no staff reply after it. */
    public function awaitingStaff(int $userId): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM chat_messages h
             WHERE h.user_id = ? AND h.handoff = 1
               AND NOT EXISTS (SELECT 1 FROM chat_messages s WHERE s.user_id = h.user_id AND s.sender = 'STAFF' AND s.id > h.id)"
        );
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Every conversation in which the applicant asked for staff: waiting ones
     * first (oldest request first), then answered ones (latest activity first).
     */
    public function staffInbox(int $limit = 100): array
    {
        return $this->pdo->query(
            "SELECT u.id AS user_id, u.full_name, u.username,
                    MIN(CASE WHEN h.waiting = 1 THEN h.created_at END) AS waiting_since,
                    MAX(h.waiting) AS waiting,
                    (SELECT MAX(created_at) FROM chat_messages x WHERE x.user_id = u.id) AS last_activity,
                    (SELECT message FROM chat_messages x WHERE x.user_id = u.id AND x.sender = 'APPLICANT' ORDER BY x.id DESC LIMIT 1) AS last_question
             FROM (
                SELECT m.user_id, m.created_at,
                       NOT EXISTS (SELECT 1 FROM chat_messages s WHERE s.user_id = m.user_id AND s.sender = 'STAFF' AND s.id > m.id) AS waiting
                FROM chat_messages m WHERE m.handoff = 1
             ) h
             JOIN users u ON u.id = h.user_id
             GROUP BY u.id, u.full_name, u.username
             ORDER BY waiting DESC, CASE WHEN MAX(h.waiting) = 1 THEN MIN(CASE WHEN h.waiting = 1 THEN h.created_at END) END ASC, last_activity DESC
             LIMIT " . max(1, $limit)
        )->fetchAll();
    }

    public function waitingCount(): int
    {
        return (int) $this->pdo->query(
            "SELECT COUNT(DISTINCT h.user_id) FROM chat_messages h
             WHERE h.handoff = 1 AND NOT EXISTS (SELECT 1 FROM chat_messages s WHERE s.user_id = h.user_id AND s.sender = 'STAFF' AND s.id > h.id)"
        )->fetchColumn();
    }

    /** True if this applicant ever asked for staff (staff may only open those conversations). */
    public function hasHandoff(int $userId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM chat_messages WHERE user_id = ? AND handoff = 1 LIMIT 1');
        $stmt->execute([$userId]);
        return (bool) $stmt->fetchColumn();
    }
}
