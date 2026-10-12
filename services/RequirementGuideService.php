<?php
/** Applicant preparation guidance, keyed by the configured requirement's stable doc_key. */
class RequirementGuideService
{
    public static function catalog(PDO $pdo): array
    {
        $rows = $pdo->query('SELECT r.doc_key, r.label FROM document_requirements r
            LEFT JOIN categories c ON c.id = r.category_id
            WHERE r.category_id IS NULL OR c.is_active = 1
            ORDER BY (r.category_id IS NULL) DESC, r.sort_order, r.id')->fetchAll(PDO::FETCH_ASSOC);
        return self::forRequirements($rows);
    }

    public static function forRequirements(array $requirements): array
    {
        $guides = [];
        foreach ($requirements as $requirement) {
            $key = (string) $requirement['doc_key'];
            if ($key === 'photo') {
                continue; // The 2x2 photo is excluded from the preparation guide.
            }
            if (!isset($guides[$key])) {
                $guides[$key] = ['key' => $key, 'label' => $requirement['label'], 'labels' => []] + self::instructions($key);
            }
            if (!in_array($requirement['label'], $guides[$key]['labels'], true)) {
                $guides[$key]['labels'][] = $requirement['label'];
            }
        }
        return $guides;
    }

    public const REVIEWED_ON = '2026-10-12';

    public static function offices(): array
    {
        return self::content()['offices'];
    }

    public static function instructions(string $key): array
    {
        $content = self::content();
        $canonical = $content['aliases'][$key] ?? $key;
        return $content['guides'][$canonical] ?? $content['fallback'];
    }

    private static function content(): array
    {
        static $content = null;
        return $content ??= require __DIR__ . '/../includes/requirement-guides.php';
    }
}
