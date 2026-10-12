<?php
require_once __DIR__ . '/../models/EligibilityAssessment.php';

/**
 * Public, non-binding eligibility pre-check (Section 3, RA 8972 / RA 11861).
 * The result is only PRELIMINARY -- final eligibility is always decided by
 * the MSWD Social Worker during processing.
 *
 * Each completed check is stored as an assessment keyed by a random
 * token kept in the visitor's session (the database only ever sees its
 * SHA-256 hash). Logging in or registering links it to the account.
 * Methods that store or load need the PDO; the rules themselves don't.
 */
class EligibilityService
{
    public const NONE_OPTION = 'None of these apply to me';
    public const UNSURE_OPTION = "I'm not sure";
    /** Anonymous (never-claimed) assessments are deleted after this many days. */
    private const UNCLAIMED_RETENTION_DAYS = 30;

    private ?PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo;
    }

    /** Evaluates, stores as this visitor's preliminary assessment (linked to $userId if logged in), and returns the result. */
    public function record(array $answers, ?int $userId): array
    {
        $result = $this->evaluate($answers);
        $model = new EligibilityAssessment($this->pdo);
        $model->purgeUnclaimed(self::UNCLAIMED_RETENTION_DAYS);
        $id = $model->savePreliminary($this->tokenHash(true), $userId, $answers, $result['status'], $result['reason']);
        return $result + ['id' => $id];
    }

    /** This visitor's latest stored check as ['answers', 'result', 'checkedAt'], or null if they haven't checked yet. */
    public function current(): ?array
    {
        $tokenHash = $this->tokenHash(false);
        $row = $tokenHash ? (new EligibilityAssessment($this->pdo))->findByToken($tokenHash, isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null) : null;
        return $row ? $this->hydrate($row) : null;
    }

    /** The applicant's latest preliminary check (for their dashboard and staff review), or null. */
    public function latestForUser(int $userId): ?array
    {
        $row = (new EligibilityAssessment($this->pdo))->latestForUser($userId);
        return $row ? $this->hydrate($row) : null;
    }

    /** Called right after an applicant logs in or registers: attaches the check they made while signed out. */
    public function claimForUser(int $userId): void
    {
        $tokenHash = $this->tokenHash(false);
        if ($tokenHash) {
            (new EligibilityAssessment($this->pdo))->claimForUser($tokenHash, $userId);
        }
    }

    private function hydrate(array $row): array
    {
        $answers = $this->normalizeAnswers(json_decode($row['answers'], true) ?: []);
        return [
            'id' => (int) $row['id'],
            'answers' => $answers,
            'result' => ['eligible' => $row['preliminary_result'] === 'LIKELY_ELIGIBLE', 'status' => $row['preliminary_result'], 'reason' => $row['result_reason']],
            'checkedAt' => $row['created_at'],
        ];
    }

    public function resultForViewer(int $id, ?int $userId): ?array
    {
        $row = (new EligibilityAssessment($this->pdo))->findForViewer($id, $this->tokenHash(false), $userId);
        return $row ? $this->hydrate($row) : null;
    }

    public function recordView(int $id, ?int $userId): void
    {
        (new EligibilityAssessment($this->pdo))->recordView($id, $this->tokenHash(true), $userId);
    }

    public static function resultLabel(string $status): string
    {
        return ['LIKELY_ELIGIBLE' => 'May be eligible', 'NOT_ELIGIBLE' => 'May not meet criteria', 'NEEDS_GUIDANCE' => 'MSWDO guidance needed'][$status] ?? $status;
    }

    /** SHA-256 of this visitor's session token (created on first check), or null if none exists and $create is false. */
    private function tokenHash(bool $create): ?string
    {
        if (empty($_SESSION['eligibility_token'])) {
            if (!$create) {
                return null;
            }
            $_SESSION['eligibility_token'] = bin2hex(random_bytes(32));
        }
        return hash('sha256', $_SESSION['eligibility_token']);
    }

    public static function circumstances(): array
    {
        return [
            ['title' => 'Abandonment by the spouse', 'desc' => 'The spouse has abandoned the applicant and child/children.'],
            ['title' => 'Declaration of nullity or annulment of marriage', 'desc' => 'A judicial declaration of nullity or annulment, including recognition of a foreign divorce.'],
            ['title' => 'Legal guardian, adoptive parent, or foster parent', 'desc' => 'The applicant is the child’s legal guardian, adoptive parent, or foster parent.'],
            ['title' => 'Unmarried father or mother who keeps and rears the child/children', 'desc' => 'An unmarried father or mother keeps and rears the child/children.'],
            ['title' => 'Detention or imprisonment of the spouse', 'desc' => 'The spouse is detained or serving a sentence for at least three months.'],
            ['title' => 'Child/children as a consequence of rape', 'desc' => 'The child/children were conceived as a consequence of rape.'],
            ['title' => 'Death of the spouse', 'desc' => 'The applicant’s spouse has died.'],
            ['title' => 'Legal or de facto separation of spouse for at least six months', 'desc' => 'The spouses have been legally or de facto separated for at least six months.'],
            ['title' => 'Spouse or family member of an OFW', 'desc' => 'The applicant is the spouse or family member of an overseas Filipino worker.'],
        ];
    }

    /** Valid answers for the circumstance question: every listed title plus the "none" option. */
    public function circumstanceOptions(): array
    {
        return array_merge(array_column(self::circumstances(), 'title'), [self::NONE_OPTION, self::UNSURE_OPTION]);
    }

    /**
     * Normalizes raw form input to ['resident','circ','child'] with each
     * value either a recognised option or ''. Anything unrecognised is
     * treated as unanswered rather than trusted.
     */
    public function normalizeAnswers(array $input): array
    {
        $yesNo = ['Yes', 'No', self::UNSURE_OPTION];
        $resident = is_string($input['resident'] ?? null) ? $input['resident'] : '';
        $circ = is_string($input['circ'] ?? null) ? $input['circ'] : '';
        $child = is_string($input['child'] ?? null) ? $input['child'] : '';
        return [
            'resident' => in_array($resident, $yesNo, true) ? $resident : '',
            'circ' => in_array($circ, $this->circumstanceOptions(), true) ? $circ : '',
            'child' => in_array($child, $yesNo, true) ? $child : '',
        ];
    }

    public function missingAnswers(array $answers): bool
    {
        return $answers['resident'] === '' || $answers['circ'] === '' || $answers['child'] === '';
    }

    /**
     * Returns ['eligible' => bool, 'reason' => ?string]. Checks run in the
     * same order as the original client-side checker so the first failing
     * criterion is the one explained.
     */
    public function evaluate(array $answers): array
    {
        if (in_array(self::UNSURE_OPTION, $answers, true)) {
            return ['eligible' => false, 'status' => 'NEEDS_GUIDANCE', 'reason' => 'Some answers need clarification. MSWDO can help you understand the questions and assess your situation.'];
        }
        if ($answers['resident'] !== 'Yes') {
            return ['eligible' => false, 'status' => 'NOT_ELIGIBLE', 'reason' => 'The Solo Parent ID from MSWDO Virac is for residents of Virac; apply at the MSWDO of your own municipality.'];
        }
        if ($answers['circ'] === self::NONE_OPTION) {
            return ['eligible' => false, 'status' => 'NOT_ELIGIBLE', 'reason' => 'Your situation does not match the circumstances listed under RA 8972 / RA 11861.'];
        }
        if ($answers['child'] !== 'Yes') {
            return ['eligible' => false, 'status' => 'NOT_ELIGIBLE', 'reason' => 'The Solo Parent ID requires that you solely care for a qualifying child.'];
        }
        return ['eligible' => true, 'status' => 'LIKELY_ELIGIBLE', 'reason' => null];
    }
}
