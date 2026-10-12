<?php
require_once __DIR__ . '/Model.php';

/** Chat Support FAQ entries. Applicants only see active ones (get_faqs()); admins manage all of them here. */
class Faq extends Model
{
    public function all(): array
    {
        return $this->pdo->query('SELECT * FROM faqs ORDER BY sort_order, id')->fetchAll();
    }

    public function create(string $question, string $answer, int $sortOrder): int
    {
        $this->pdo->prepare('INSERT INTO faqs (question, answer, sort_order) VALUES (?, ?, ?)')->execute([$question, $answer, $sortOrder]);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, string $question, string $answer, int $sortOrder): void
    {
        $this->pdo->prepare('UPDATE faqs SET question = ?, answer = ?, sort_order = ? WHERE id = ?')->execute([$question, $answer, $sortOrder, $id]);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM faqs WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function toggleActive(int $id): void
    {
        $this->pdo->prepare('UPDATE faqs SET is_active = NOT is_active WHERE id = ?')->execute([$id]);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM faqs WHERE id = ?')->execute([$id]);
    }
}
