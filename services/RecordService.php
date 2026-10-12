<?php
require_once __DIR__ . '/../models/ApplicantRecord.php';
require_once __DIR__ . '/../models/Applicant.php';
require_once __DIR__ . '/../models/Application.php';
require_once __DIR__ . '/EligibilityService.php';

/**
 * Centralised record management for MSWD staff and administrators
 * (permission applicants.view): find applicant records by name, reference
 * number, barangay, application status or type, and see everything kept
 * about one applicant -- profile, applications and renewals, documents,
 * appointments, decisions and the full status history. Read-only; changes
 * happen on the application's own processing page.
 */
class RecordService
{
    public const PER_PAGE = 20;
    public const HISTORY_PER_PAGE = 25;
    public const TYPES = ['new' => 'New application', 'renewal' => 'Renewal'];
    public const ACCOUNT_STATES = ['active' => 'Active accounts', 'inactive' => 'Deactivated accounts'];
    public const SORTS = ['name' => 'Name (A-Z)', 'recent' => 'Latest application'];

    private PDO $pdo;
    private ApplicantRecord $records;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->records = new ApplicantRecord($pdo);
    }

    public function barangays(): array
    {
        return $this->records->barangays();
    }

    /** Search filters from the query string; anything not on the allowed lists is ignored. */
    public function filters(array $get): array
    {
        $str = fn(string $k) => is_string($get[$k] ?? null) ? trim($get[$k]) : '';
        $status = $str('status');
        return [
            'q' => mb_substr($str('q'), 0, 100),
            'barangay' => in_array($str('barangay'), $this->barangays(), true) ? $str('barangay') : '',
            'status' => $status === 'NONE' || in_array($status, Application::STATUSES, true) ? $status : '',
            'type' => isset(self::TYPES[$str('type')]) ? $str('type') : '',
            'account' => isset(self::ACCOUNT_STATES[$str('account')]) ? $str('account') : '',
            'sort' => isset(self::SORTS[$str('sort')]) ? $str('sort') : 'name',
        ];
    }

    /** One page of matching applicants, each with up to 3 (matching) applications under 'applications'. */
    public function search(array $filters, int $page, int $perPage = self::PER_PAGE): array
    {
        // "No application" can't be combined with an application type.
        if ($filters['status'] === 'NONE') {
            $filters['type'] = '';
        }
        $result = $this->records->search($filters, $page, $perPage);
        $apps = $this->records->applicationsFor(array_column($result['rows'], 'id'), $filters);
        foreach ($result['rows'] as &$row) {
            $row['applications'] = $apps[$row['id']] ?? [];
        }
        unset($row);
        return $result;
    }

    /**
     * Everything on file for one applicant, or null if $userId isn't an
     * applicant. $applicationId narrows documents, appointments, decisions
     * and status history to one of their applications (ignored if it isn't theirs).
     */
    public function record(int $userId, int $applicationId, int $historyPage): ?array
    {
        $applicantModel = new Applicant($this->pdo);
        $applicant = $applicantModel->findApplicant($userId);
        if (!$applicant) {
            return null;
        }
        $applications = $this->records->applications($userId);
        $selected = null;
        foreach ($applications as $app) {
            if ((int) $app['id'] === $applicationId) {
                $selected = $app;
            }
        }
        $ids = $selected ? [(int) $selected['id']] : array_map('intval', array_column($applications, 'id'));
        return [
            'applicant' => $applicant,
            'profile' => $applicantModel->profile($userId),
            'eligibilityCheck' => (new EligibilityService($this->pdo))->latestForUser($userId),
            'newApps' => array_values(array_filter($applications, fn($a) => $a['type'] === 'new')),
            'renewals' => array_values(array_filter($applications, fn($a) => $a['type'] === 'renewal')),
            'applications' => $applications,
            'selected' => $selected,
            'documents' => $this->records->documents($ids),
            'appointments' => $this->records->appointments($ids),
            'decisions' => $this->records->decisions($ids),
            'history' => $this->records->statusHistory($ids, $historyPage, self::HISTORY_PER_PAGE),
        ];
    }
}
