<?php
/** CLI regression checks for public tracking; no retained database changes. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Command line only.'); }
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../controllers/ApplicationController.php';
function tracking_assert(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
    echo 'PASS: ' . $label . "\n";
}
$app = [
    'reference_no' => 'VIRAC-SP-TEST', 'full_name' => 'Tracking Example', 'type' => 'new',
    'status' => 'Pending Review', 'submitted_at' => '2026-01-01 09:00:00',
    'updated_at' => '2026-01-02 09:00:00', 'id_expires_at' => null,
    'mobile' => 'private-mobile', 'address' => 'private-address', 'email' => 'private-email',
    'eligibility_remarks' => 'private-assessment',
];
$history = [['status' => 'Pending Review', 'changed_at' => '2026-01-01 09:00:00', 'remarks' => 'private-remarks', 'changed_by_name' => 'private-staff']];
$summarize = fn(array $a, ?array $interview = null, ?array $release = null) => PublicApplicationTrackingService::summarize($a, $history, $interview, $release);
foreach (['Pending Review', 'Under Verification', 'For Revision', 'Interview Scheduled', 'Approved', 'ID Released', 'Rejected'] as $status) {
    $a = array_replace($app, ['status' => $status]);
    $result = $summarize($a);
    tracking_assert($result['title'] !== '' && $result['instruction'] !== '', $status . ' has actionable guidance');
}
$interview = ['status' => 'BOOKED', 'scheduled_at' => date('Y-m-d H:i:s', time() + 86400), 'location' => '<script>office</script>', 'result' => 'private-result'];
$release = ['scheduled_at' => date('Y-m-d H:i:s', time() + 172800), 'location' => '<script>office</script>', 'released_at' => null, 'sms_message' => 'private-sms'];
$approved = array_replace($app, ['status' => 'Approved']);
$result = $summarize($approved, $interview, $release);
tracking_assert($result['appointment']['label'] === 'ID release appointment' && $result['released_at'] === null && $result['status'] === 'Approved', 'Release appointment does not imply actual handover');
tracking_assert($result['title'] === 'Your ID release is scheduled', 'Scheduled release has claiming instructions');
tracking_assert($summarize($approved)['appointment'] === null, 'Approval alone does not invent an appointment');
$interviewApp = array_replace($app, ['status' => 'Interview Scheduled']);
tracking_assert($summarize($interviewApp, $interview)['appointment']['label'] === 'Interview appointment', 'Booked interview displays schedule');
foreach (['MISSED', 'CANCELLED'] as $status) {
    $result = $summarize($interviewApp, array_replace($interview, ['status' => $status]));
    tracking_assert($result['appointment'] === null && $result['title'] === 'Arrange another interview', $status . ' interview prompts rescheduling');
}
$past = array_replace($release, ['scheduled_at' => date('Y-m-d H:i:s', time() - 86400)]);
tracking_assert($summarize($approved, null, $past)['appointment']['past'], 'Past schedule prompts follow-up');
$releasedApp = array_replace($app, ['status' => 'ID Released', 'id_expires_at' => date('Y-m-d', time() + 86400)]);
$confirmed = array_replace($release, ['released_at' => '2026-01-03 10:00:00']);
$result = $summarize($releasedApp, $interview, $confirmed);
tracking_assert($result['released_at'] === $confirmed['released_at'] && !$result['expired'] && $result['appointment'] === null, 'Confirmed release shows validity and hides old appointments');
tracking_assert($summarize(array_replace($releasedApp, ['id_expires_at' => date('Y-m-d', time() - 86400)]), null, $confirmed)['expired'], 'Expired ID prompts renewal');
tracking_assert(!$summarize(array_replace($releasedApp, ['id_expires_at' => date('Y-m-d')]), null, $confirmed)['expired'], 'ID remains valid on its expiry date');
$farExpiry = date('Y-m-d', strtotime('+90 days'));
$validApp = array_replace($releasedApp, ['id_expires_at' => $farExpiry]);
$validSummary = $summarize($validApp, null, $confirmed)['id_summary'];
tracking_assert($validSummary['headline'] === 'ID received' && str_contains($validSummary['received'], 'staff confirmed'), 'Confirmed handover clearly says ID received');
tracking_assert(str_contains($validSummary['renewal'], 'Not needed yet'), 'Valid ID outside renewal window does not prompt renewal');
$dueSummary = $summarize(array_replace($releasedApp, ['id_expires_at' => date('Y-m-d', strtotime('+30 days'))]), null, $confirmed)['id_summary'];
tracking_assert($dueSummary['headline'] === 'ID valid — renewal due', 'Renewal window begins at its inclusive boundary');
$outsideSummary = $summarize(array_replace($releasedApp, ['id_expires_at' => date('Y-m-d', strtotime('+31 days'))]), null, $confirmed)['id_summary'];
tracking_assert($outsideSummary['headline'] === 'ID received', 'Day before renewal window still shows received and valid');
$legacySummary = $summarize($validApp)['id_summary'];
tracking_assert($legacySummary['headline'] === 'ID recorded as released' && !str_contains($legacySummary['received'], 'staff confirmed'), 'Legacy release does not invent handover confirmation');
tracking_assert($summarize($approved, null, $release)['id_summary']['received'] === 'Not yet', 'Scheduled ID is explicitly not yet received');
$renewal = ['reference_no' => 'VIRAC-SP-RENEWAL-TEST', 'status' => 'Under Verification'];
$renewing = PublicApplicationTrackingService::summarize($validApp, $history, null, $confirmed, $renewal)['id_summary'];
tracking_assert($renewing['headline'] === 'Renewal in progress' && $renewing['renewal_ref'] === $renewal['reference_no'], 'Filed renewal prompts tracking instead of a duplicate renewal');
$renewal['status'] = 'ID Released';
$renewed = PublicApplicationTrackingService::summarize($validApp, $history, null, $confirmed, $renewal)['id_summary'];
tracking_assert($renewed['headline'] === 'This ID has been renewed', 'Renewed old ID directs applicant to the new ID reference');
$renewal['status'] = 'Rejected';
$rejectedRenewal = PublicApplicationTrackingService::summarize($validApp, $history, null, $confirmed, $renewal)['id_summary'];
tracking_assert($rejectedRenewal['renewal_ref'] === null && $rejectedRenewal['headline'] === 'ID received', 'Rejected renewal does not claim ID was renewed');
$result = $summarize($approved, null, $release);
$json = json_encode($result);
foreach (['private-mobile', 'private-address', 'private-email', 'private-assessment', 'private-remarks', 'private-staff', 'private-sms'] as $secret) {
    tracking_assert(!str_contains($json, $secret), 'Public data excludes ' . $secret);
}
tracking_assert($result['masked_name'] !== $app['full_name'] && $result['updated_at'] === $app['updated_at'], 'Name is masked and latest update is accurate');
$query = $result['reference_no']; $user = null; $error = '';
ob_start(); require __DIR__ . '/../views/home/status.php'; $html = ob_get_clean();
tracking_assert(str_contains($html, '&lt;script&gt;office&lt;/script&gt;') && !str_contains($html, '<script>office</script>'), 'Appointment location is escaped');
tracking_assert(str_contains($html, 'Your next step') && str_contains($html, 'Application status history') && str_contains($html, 'Need help with your ID?'), 'Result includes guidance, status history and contact details');
$pdo->beginTransaction();
try {
    $reference = $pdo->query('SELECT reference_no FROM applications LIMIT 1')->fetchColumn();
    tracking_assert((bool) $reference, 'Application available for integration check');
    $_GET = ['ref' => $reference];
    $_SERVER['REMOTE_ADDR'] = 'tracking-check-' . bin2hex(random_bytes(8));
    ob_start(); (new ApplicationController($pdo))->publicStatus(); $html = ob_get_clean();
    tracking_assert(str_contains($html, e($reference)) && str_contains($html, 'Latest recorded update') && !str_contains($html, 'name="m4"'), 'Controller renders detailed reference-only tracking');
} finally { $pdo->rollBack(); }
