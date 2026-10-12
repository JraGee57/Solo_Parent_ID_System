<?php
/**
 * Reference content for the public portal. Document requirements are read
 * from the configured database definitions; other informational copy is
 * maintained here.
 */

function benefit_cards(): array
{
    return [
        ['icon' => 'clock', 'title' => 'Flexible Work Schedule', 'desc' => 'Employers are encouraged to provide flexible work arrangements to accommodate parental responsibilities.'],
        ['icon' => 'calendar', 'title' => 'Parental Leave (7 Days)', 'desc' => 'Seven (7) days of paid parental leave per year, available after at least one year of employment. Not convertible to cash.'],
        ['icon' => 'education', 'title' => 'Educational Assistance', 'desc' => "Access to scholarships and educational grants for the solo parent's children through government programs."],
        ['icon' => 'discount', 'title' => 'Price Discounts', 'desc' => 'Discounts on medicines, groceries, and essential goods at drugstores, pharmacies, and grocery stores under RA 11861.'],
        ['icon' => 'subsidy', 'title' => 'Monthly Cash Subsidy', 'desc' => 'Eligible indigent solo parents who earn minimum wage may receive an additional monthly cash subsidy of ₱1,000 under RA 11861.'],
        ['icon' => 'id', 'title' => 'Valid Government ID', 'desc' => 'The Solo Parent ID is a valid government-issued identification card accepted in private and government transactions.'],
    ];
}

/** Local decorative SVGs; icon paths are fixed, never supplied by users. */
function benefit_icon_svg(string $key): string
{
    $paths = [
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4m10-4v4M3 10h18m-12 5 2 2 4-4"/>',
        'education' => '<path d="m2 9 10-5 10 5-10 5L2 9Zm4 2v6c4 3 8 3 12 0v-6m4-2v7"/>',
        'discount' => '<path d="M3 3h8l10 10-8 8L3 11V3Z"/><circle cx="7" cy="7" r="1"/><path d="m11 11 5 5m0-5h.01M11 16h.01"/>',
        'subsidy' => '<rect x="2" y="5" width="20" height="14" rx="2"/><circle cx="12" cy="12" r="3"/><path d="M5 9h1m12 6h1"/>',
        'id' => '<rect x="2" y="5" width="20" height="14" rx="2"/><circle cx="8" cy="10" r="2"/><path d="M5 16c0-3 6-3 6 0m3-6h5m-5 4h5"/>',
    ];
    return '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . ($paths[$key] ?? $paths['id']) . '</svg>';
}

function eligibility_list(): array
{
    require_once __DIR__ . '/../services/EligibilityService.php';
    return EligibilityService::circumstances();
}

/** Home requirements use the same configured definitions as the application checklist. */
function required_doc_groups(PDO $pdo): array
{
    $groups = [];
    foreach (get_categories($pdo) as $category) {
        $groups[] = [
            'title' => $category['name'],
            'items' => get_document_requirements($pdo, (int) $category['id']),
        ];
    }
    $groups[] = ['title' => 'Renewal requirements', 'items' => get_document_requirements($pdo, null)];
    return $groups;
}

function apply_steps_overview(): array
{
    return [
        ['n' => 1, 'title' => 'Check Eligibility', 'desc' => 'Get a preliminary result; final eligibility is assessed by MSWDO'],
        ['n' => 2, 'title' => 'Fill Application', 'desc' => 'Complete the online form with your personal information'],
        ['n' => 3, 'title' => 'Complete the Checklist', 'desc' => 'Tick the documents you have available; MSWDO verifies them separately'],
        ['n' => 4, 'title' => 'Track & Claim', 'desc' => 'Track your application, attend the scheduled interview and document review, and claim your ID when notified'],
    ];
}

function important_reminders(): array
{
    return [
        'The Solo Parent ID is valid for one year and must be renewed at the MSWDO upon expiration. The e-SoloParentID system will send you an SMS reminder 30 days before your ID expires.',
        'The Solo Parent ID is a valid government-issued ID accepted in private and government transactions.',
        'If you marry or re-marry, your Solo Parent ID may be revoked by the MSWDO.',
        'If your children are given up for adoption, the Solo Parent ID benefits will be terminated.',
        'The 7-day parental leave is available to holders who have rendered at least one year of service. Present your valid Solo Parent ID to your employer when filing for leave.',
        'The Solo Parent ID is free of charge. No fees are collected for application, issuance, or renewal.',
        'Application is subject to MSWDO review and approval. A Social Worker may conduct a home visit to verify your situation.',
    ];
}

const CIVIL_STATUS_OPTIONS = ['Single', 'Married', 'Widowed', 'Separated', 'Annulled'];

/** Only active, published news is shown on the public portal. */
function announcements_list(?PDO $pdo = null): array
{
    $pdo = $pdo ?? ($GLOBALS['pdo'] ?? null);
    require_once __DIR__ . '/../models/Announcement.php';
    return $pdo instanceof PDO ? (new Announcement($pdo))->published() : [];
}
