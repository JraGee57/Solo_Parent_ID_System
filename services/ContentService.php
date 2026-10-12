<?php
require_once __DIR__ . '/SMSService.php';
require_once __DIR__ . '/../models/Faq.php';
require_once __DIR__ . '/../models/Document.php';
require_once __DIR__ . '/../models/SmsLog.php';

/**
 * Admin-managed content applicants see: Chat Support FAQs, document
 * requirements per category, and SMS notification templates. Each method
 * validates, writes, audits, and returns [error, success] (one is '').
 */
class ContentService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // ---- FAQ ----

    /** @return array{0: string, 1: string} */
    public function saveFaq(array $admin, ?int $id, string $question, string $answer, int $sortOrder): array
    {
        if ($question === '' || $answer === '') {
            return ['Enter both a question and an answer.', ''];
        }
        $faqs = new Faq($this->pdo);
        if ($id === null) {
            $this->audit($admin, 'FAQ Created', 'faq', $faqs->create($question, $answer, $sortOrder), $question);
            return ['', 'FAQ added.'];
        }
        $faqs->update($id, $question, $answer, $sortOrder);
        $this->audit($admin, 'FAQ Updated', 'faq', $id, $question);
        return ['', 'FAQ updated.'];
    }

    /** @return array{0: string, 1: string} */
    public function toggleFaq(array $admin, int $id): array
    {
        $faqs = new Faq($this->pdo);
        $faqs->toggleActive($id);
        $faq = $faqs->find($id);
        $this->audit($admin, 'FAQ Visibility Toggled', 'faq', $id, $faq ? ($faq['is_active'] ? 'Shown: ' : 'Hidden: ') . $faq['question'] : null);
        return ['', 'FAQ visibility updated.'];
    }

    /** @return array{0: string, 1: string} */
    public function deleteFaq(array $admin, int $id): array
    {
        $faqs = new Faq($this->pdo);
        $faq = $faqs->find($id);
        $faqs->delete($id);
        $this->audit($admin, 'FAQ Deleted', 'faq', $id, $faq['question'] ?? null);
        return ['', 'FAQ deleted.'];
    }

    // ---- Document requirements ----

    /** $categoryId null = standard requirement for every category. @return array{0: string, 1: string} */
    public function createRequirement(array $admin, ?int $categoryId, string $docKey, string $label, bool $isRequired, int $sortOrder): array
    {
        if (!preg_match('/^[a-zA-Z0-9_]{1,50}$/', $docKey)) {
            return ['Doc key must be 1-50 letters, numbers, or underscores (no spaces).', ''];
        }
        if ($error = $this->requirementFieldError($label, $sortOrder)) {
            return [$error, ''];
        }
        if ($categoryId !== null && !in_array($categoryId, array_map('intval', array_column(get_categories($this->pdo), 'id')), true)) {
            return ['Choose a valid category.', ''];
        }
        $documents = new Document($this->pdo);
        // A doc key must be unique per applicant form: standard keys can't be reused by a category, and vice versa.
        if ($documents->requirementKeyConflicts($docKey, $categoryId)) {
            return [$categoryId === null ? 'That doc key is already used by a requirement.' : 'That doc key already exists for this category or as a standard requirement.', ''];
        }
        $id = $documents->createRequirement($categoryId, $docKey, $label, $isRequired, $sortOrder);
        $this->audit($admin, 'Document Requirement Created', 'document_requirement', $id, $label);
        return ['', 'Requirement added.'];
    }

    /** @return array{0: string, 1: string} */
    public function updateRequirement(array $admin, int $id, string $label, bool $isRequired, int $sortOrder): array
    {
        if ($error = $this->requirementFieldError($label, $sortOrder)) {
            return [$error, ''];
        }
        $documents = new Document($this->pdo);
        if (!$documents->findRequirement($id)) {
            return ['That requirement no longer exists.', ''];
        }
        $documents->updateRequirement($id, $label, $isRequired, $sortOrder);
        $this->audit($admin, 'Document Requirement Updated', 'document_requirement', $id, $label);
        return ['', 'Requirement updated.'];
    }

    /** @return array{0: string, 1: string} */
    public function deleteRequirement(array $admin, int $id): array
    {
        $documents = new Document($this->pdo);
        $requirement = $documents->findRequirement($id);
        if (!$requirement) {
            return ['That requirement no longer exists.', ''];
        }
        if ($documents->requirementInUse($requirement)) {
            return ['Applicants have already submitted this document, so it can\'t be removed. Mark it optional instead.', ''];
        }
        $documents->deleteRequirement($id);
        $this->audit($admin, 'Document Requirement Deleted', 'document_requirement', $id, $requirement['doc_key']);
        return ['', 'Requirement removed.'];
    }

    private function requirementFieldError(string $label, int $sortOrder): ?string
    {
        if ($label === '') {
            return 'Enter a requirement label.';
        }
        if (mb_strlen($label) > 255) {
            return 'Label is too long (up to 255 characters).';
        }
        if ($sortOrder < 0 || $sortOrder > 9999) {
            return 'Sort order must be between 0 and 9999.';
        }
        return null;
    }

    // ---- SMS templates ----

    /** @return array{0: string, 1: string} */
    public function updateSmsTemplate(array $admin, string $key, string $body): array
    {
        if ($body === '') {
            return ['Template text cannot be empty.', ''];
        }
        if (mb_strlen($body) > 500) {
            return ['Template text is too long (up to 500 characters).', ''];
        }
        $logs = new SmsLog($this->pdo);
        if (!$logs->templateExists($key) || !isset(SMSService::PLACEHOLDERS[$key])) {
            return ['Unknown template.', ''];
        }
        $allowed = SMSService::PLACEHOLDERS[$key];
        preg_match_all('/\{[a-z_]+\}/', $body, $m);
        $unknown = array_diff(array_unique($m[0]), $allowed);
        if ($unknown) {
            return ['This template can only use ' . implode(' ', $allowed) . ' (found ' . implode(' ', $unknown) . ').', ''];
        }
        if (!str_contains($body, $allowed[0])) {
            return ['This template must include ' . $allowed[0] . '.', ''];
        }
        $logs->updateTemplate($key, $body);
        audit_log($this->pdo, (int) $admin['id'], $admin['full_name'], $admin['role'], 'SMS Template Updated', 'sms_template', null, $key);
        return ['', 'Template updated.'];
    }

    private function audit(array $admin, string $action, string $entityType, int $entityId, ?string $details = null): void
    {
        audit_log($this->pdo, (int) $admin['id'], $admin['full_name'], $admin['role'], $action, $entityType, $entityId, $details);
    }
}
