<?php
/** Integration checks in a disposable database; existing applicant records are untouched. */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Command line only.');
}
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../services/ApplicationReviewService.php';
require __DIR__ . '/../services/ApplicationTrackerService.php';
require __DIR__ . '/../services/UserManagementService.php';
require __DIR__ . '/../services/RecordService.php';
require_once __DIR__ . '/../services/IdReleaseService.php';
require_once __DIR__ . '/../services/SettingsService.php';

function review_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$source = $pdo;
$testDatabase = 'solo_review_test_' . bin2hex(random_bytes(6));
$created = false;
$exitCode = 0;
try {
    $source->exec('CREATE DATABASE `' . $testDatabase . '` CHARACTER SET utf8mb4');
    $created = true;
    $test = new PDO('mysql:host=' . DB_HOST . ';dbname=' . $testDatabase . ';charset=utf8mb4', DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
         PDO::ATTR_EMULATE_PREPARES => false]);
    $test->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($source->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'')->fetchAll(PDO::FETCH_NUM) as $table) {
        $name = str_replace('`', '``', $table[0]);
        $definition = $source->query('SHOW CREATE TABLE `' . $name . '`')->fetch(PDO::FETCH_NUM)[1];
        $test->exec($definition);
    }
    $test->exec('SET FOREIGN_KEY_CHECKS = 1');
    foreach ($source->query('SELECT * FROM roles')->fetchAll() as $row) {
        $columns = implode(',', array_map(fn($key) => '`' . $key . '`', array_keys($row)));
        $test->prepare('INSERT INTO roles (' . $columns . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
    }
    $insertUser = $test->prepare('INSERT INTO users (full_name, email, username, password_hash, role) VALUES (?, ?, ?, ?, ?)');
    foreach (['applicant', 'mswd_social_worker', 'admin'] as $role) {
        $insertUser->execute([$role, $role . '@example.test', $role, password_hash('test fixture', PASSWORD_DEFAULT), $role]);
        $ids[$role] = (int) $test->lastInsertId();
    }
    $staff = ['id' => $ids['mswd_social_worker'], 'role' => 'mswd_social_worker', 'full_name' => 'Review fixture'];
    // Copy only requirement definitions; no real accounts or applications enter this database.
    foreach (['categories', 'document_requirements'] as $table) {
        foreach ($source->query('SELECT * FROM ' . $table)->fetchAll() as $row) {
            $columns = implode(',', array_map(fn($key) => '`' . $key . '`', array_keys($row)));
            $test->prepare('INSERT INTO ' . $table . ' (' . $columns . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
        }
    }
    $category = $test->query('SELECT category_id FROM document_requirements WHERE is_required = 1 AND category_id IS NOT NULL LIMIT 1')->fetchColumn();
    review_assert($category !== false, 'A category with required documents is needed for this test.');
    $insert = $test->prepare("INSERT INTO applications (reference_no,user_id,type,full_name,barangay,address,mobile,email,birthdate,sex,civil_status,category_id,consent_at,submitted_at) VALUES (?,?,?,'Review fixture','Test','Test','09123456789','fixture@example.test','1990-01-01','Female','Single',?,NOW(),NOW())");
    $applications = new Application($test);
    $documents = new Document($test);
    $service = new ApplicationReviewService($test);
    foreach (['new', 'renewal'] as $type) {
        $insert->execute(['REVIEW-TEST-' . $type, $ids['applicant'], $type, $category]);
        $id = (int) $test->lastInsertId();
        $app = $applications->findForReview($id);
        $service->startReviewOnOpen(['id' => $ids['admin'], 'role' => 'admin', 'full_name' => 'Admin'], $app);
        review_assert($applications->findForReview($id)['status'] === 'Pending Review', 'An admin view started a review.');
        $service->startReviewOnOpen($staff, $app);
        $service->startReviewOnOpen($staff, $app); // simulate a second view with the same stale snapshot
        $app = $applications->findForReview($id);
        review_assert($app['status'] === 'Under Verification' && (int) $app['reviewed_by'] === $staff['id'], 'Opening did not start review.');
        review_assert(count($applications->history($id)) === 1, 'Repeated opening duplicated review history.');
        $requirements = $documents->checklistForReview($id, (int) $category, $type === 'renewal');
        foreach ($requirements as $row) {
            $test->prepare('INSERT INTO application_document_checklists (application_id,doc_key,applicant_has) VALUES (?,?,1)')->execute([$id, $row['doc_key']]);
        }
        $submitted = [];
        foreach ($documents->checklistForReview($id, (int) $category, $type === 'renewal') as $row) {
            if ($row['is_required']) {
                $submitted[$row['id']] = ['status' => 'Verified', 'remarks' => ''];
            }
        }
        review_assert(count($submitted) > 0, 'Required checklist is empty.');
        $first = array_key_first($submitted);
        $submitted[$first]['status'] = 'Pending';
        review_assert($service->reviewChecklistItems($staff, $app, $submitted) === null, 'Partial verification failed.');
        review_assert($applications->findForReview($id)['eligibility_status'] === 'Not Assessed', 'Partial verification marked eligible.');
        $submitted[$first]['status'] = 'Verified';
        review_assert($service->reviewChecklistItems($staff, $app, $submitted) === null, 'Complete verification failed.');
        $app = $applications->findForReview($id);
        review_assert($app['eligibility_status'] === 'Eligible', 'Complete verification did not mark eligible.');
        $assessment = new EligibilityAssessment($test);
        review_assert(count($assessment->mswdForApplication($id)) === 1, 'Official assessment was not recorded.');
        review_assert($service->reviewChecklistItems($staff, $app, $submitted) === null, 'Repeated verification failed.');
        review_assert(count($assessment->mswdForApplication($id)) === 1, 'Repeated verification duplicated the assessment.');
        $tracker = (new ApplicationTrackerService($test))->track($app);
        $stages = array_column($tracker['stages'], null, 'key');
        review_assert($stages['review']['state'] === 'done' && $stages['documents']['state'] === 'done', 'Applicant tracker did not reflect automatic review and verification.');
        review_assert($stages['assessment']['state'] !== 'done' && $stages['assessment']['date'] === null, 'Eligibility alone completed the MSWD assessment.');
        $appointments = new Appointment($test);
        $appointments->upsertStaffScheduled($id, '2020-01-01 09:00:00', 'Test office', null, $staff['id']);
        $stages = array_column((new ApplicationTrackerService($test))->track($app)['stages'], null, 'key');
        review_assert($stages['assessment']['state'] !== 'done', 'A booked interview completed the MSWD assessment.');
        $appointments->markMissed((int) $appointments->interviewFor($id)['id']);
        $stages = array_column((new ApplicationTrackerService($test))->track($app)['stages'], null, 'key');
        review_assert($stages['assessment']['state'] === 'attention', 'A missed interview completed the MSWD assessment.');
        $appointments->upsertStaffScheduled($id, '2020-01-01 09:00:00', 'Test office', null, $staff['id']);
        review_assert($service->recordInterviewOutcome($staff, $id, 'Invalid', '') !== null, 'Invalid interview outcome was accepted.');
        review_assert($service->recordInterviewOutcome($staff, $id, 'Unsuccessful', '') === null, 'Recording an unsuccessful interview without notes failed.');
        $stages = array_column((new ApplicationTrackerService($test))->track($app)['stages'], null, 'key');
        review_assert($stages['assessment']['state'] === 'attention' && $stages['decision']['state'] === 'pending', 'Unsuccessful interview completed assessment or allowed approval.');
        $checks = array_column($service->approvalChecklist($app), null, 'label');
        review_assert(!$checks['Successful interview recorded']['ok'], 'Unsuccessful interview passed approval checks.');
        review_assert($service->recordInterviewOutcome($staff, $id, 'Successful', 'Interview completed.') === null, 'Recording a successful interview failed.');
        $stages = array_column((new ApplicationTrackerService($test))->track($app)['stages'], null, 'key');
        review_assert($stages['assessment']['state'] === 'done' && $stages['assessment']['date'] !== null, 'Successful interview did not complete MSWD assessment.');
        $checks = array_column($service->approvalChecklist($app), null, 'label');
        review_assert($checks['Successful interview recorded']['ok'], 'Successful interview failed approval checks.');
        $submitted[$first]['status'] = 'Pending';
        review_assert($service->reviewChecklistItems($staff, $app, $submitted) === null, 'Undoing verification failed.');
        $app = $applications->findForReview($id);
        review_assert($app['eligibility_status'] === 'Not Assessed', 'Undoing verification retained automatic eligibility.');
        review_assert($service->assessEligibility($staff, $app, 'Not Eligible', '') !== null, 'No assessment accepted without a reason.');
        review_assert($service->assessEligibility($staff, $app, 'Not Eligible', 'Staff assessment reason') === null, 'Manual No assessment failed.');
        $app = $applications->findForReview($id);
        review_assert($service->verifyAllChecklistItems($staff, $app) === null, 'Verify All failed.');
        $rows = $documents->checklistForReview($id, (int) $category, $type === 'renewal');
        review_assert(!array_filter($rows, fn($row) => $row['verification_status'] !== 'Verified' || $row['reviewed_by'] != $staff['id'] || !$row['reviewed_at']), 'Verify All did not verify and stamp every document.');
        $app = $applications->findForReview($id);
        review_assert($app['eligibility_status'] === 'Eligible', 'Verify All did not trigger eligibility.');
        $historyCount = count($applications->history($id));
        review_assert($service->verifyAllChecklistItems($staff, $app) === null, 'Repeated Verify All failed.');
        review_assert(count($applications->history($id)) === $historyCount, 'Repeated Verify All duplicated history.');
        $test->prepare("UPDATE applications SET status = 'Rejected' WHERE id = ?")->execute([$id]);
        review_assert($service->verifyAllChecklistItems($staff, $applications->findForReview($id)) !== null, 'Verify All changed a final application.');
        $service->startReviewOnOpen($staff, $applications->findForReview($id));
        review_assert($applications->findForReview($id)['status'] === 'Rejected', 'Opening reopened a final application.');
    }
    // Add staff after existing work: shared records must remain visible and retain their authors.
    $before = $applications->findForReview($id);
    $historyBefore = $applications->history($id);
    $admin = (new User($test))->findById($ids['admin']);
    [$error, $success, $newStaffId] = (new UserManagementService($test))->create($admin, [
        'fullName' => 'New staff member', 'email' => 'new.staff@example.test',
        'username' => 'new.staff', 'password' => 'StaffTest123', 'role' => 'mswd_social_worker',
    ]);
    review_assert($error === '' && $newStaffId !== null, 'Admin could not create additional staff: ' . $error);
    $newStaff = (new User($test))->findById($newStaffId);
    review_assert((new AuthService($test))->verifyCredentials($newStaff, 'StaffTest123'), 'New staff cannot authenticate.');
    review_assert(can($newStaff, 'applications.process') && can($newStaff, 'applicants.view'), 'New staff lacks shared record permissions.');
    review_assert(count($applications->recentlyReviewed()) === 2, 'Shared dashboard omitted previous staff work.');
    foreach ($applications->recentlyReviewed() as $recent) {
        review_assert((int) $recent['reviewed_by'] === $staff['id'] && $recent['reviewer_name'] !== null, 'Shared activity lost the original reviewer.');
    }
    review_assert($applications->search([], 1, 20)['total'] === 2, 'New staff queue omitted existing applications.');
    $records = new RecordService($test);
    review_assert($records->search($records->filters([]), 1)['total'] === 1, 'Existing applicant record is unavailable to new staff.');
    review_assert($applications->findForReview($id) === $before && $applications->history($id) === $historyBefore, 'Creating staff or reading shared records changed existing work.');
    review_assert($service->addNote($newStaff, $before, 'Follow-up by new staff.') === null, 'New staff cannot update an existing case.');
    $notes = $applications->notes($id);
    review_assert(count($notes) === 1 && (int) $notes[0]['author_id'] === $newStaffId, 'New work was not attributed to new staff.');
    review_assert($applications->history($id) === $historyBefore, 'New staff follow-up overwrote previous status history.');
    // A stale saved validity cannot extend future IDs; exercise both new and renewal handovers.
    set_setting($test, 'id_validity_years', '5');
    $GLOBALS['__settings_cache'] = ['sms_enabled' => '0', 'id_validity_years' => '5'];
    [$settingsError] = (new SettingsService($test))->saveSection($admin, 'renewals', [
        'id_validity_years' => '5', 'renewal_reminder_days' => '30',
    ]);
    review_assert($settingsError !== '', 'Settings allowed a validity longer than one year.');
    $releaseService = new IdReleaseService($test);
    foreach ($test->query('SELECT id FROM applications')->fetchAll(PDO::FETCH_COLUMN) as $releaseId) {
        $releaseId = (int) $releaseId;
        $test->prepare("UPDATE applications SET status = 'Approved' WHERE id = ?")->execute([$releaseId]);
        $test->prepare("INSERT INTO id_release_schedules (application_id,scheduled_at,location,scheduled_by,sms_message) VALUES (?,NOW(),'Test office',?,'Fixture')")->execute([$releaseId, $staff['id']]);
        $releaseApp = $applications->findForReview($releaseId);
        (new QrSlipService($test))->issue($releaseApp, $staff['id']);
        review_assert($releaseService->confirmRelease($staff, $releaseApp) === null, 'Confirming ID release failed.');
        $expiry = $applications->findForReview($releaseId)['id_expires_at'];
        $expected = $test->query('SELECT DATE_ADD(DATE(released_at), INTERVAL 1 YEAR) FROM id_release_schedules WHERE application_id = ' . $releaseId)->fetchColumn();
        review_assert($expiry === $expected, 'Released ID did not expire one calendar year after handover.');
        $qrExpiry = $test->query('SELECT expires_at FROM qr_application_slips WHERE application_id = ' . $releaseId . ' AND revoked_at IS NULL')->fetchColumn();
        review_assert(substr((string) $qrExpiry, 0, 10) === $expiry, 'QR expiry did not follow ID expiry.');
        review_assert($releaseService->confirmRelease($staff, $releaseApp) !== null, 'Repeated release was accepted.');
        review_assert($applications->findForReview($releaseId)['id_expires_at'] === $expiry, 'Repeated release extended ID validity.');
    }
    $expiryBefore = $test->query('SELECT id, id_expires_at FROM applications ORDER BY id')->fetchAll();
    $test->exec(file_get_contents(__DIR__ . '/migrate_fixed_id_validity.sql'));
    review_assert($test->query('SELECT id, id_expires_at FROM applications ORDER BY id')->fetchAll() === $expiryBefore, 'Validity migration changed an existing expiry date.');
    echo "Staff workflow, shared records, one-year new/renewal ID expiry, QR expiry and existing expiry preservation passed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    $exitCode = 1;
} finally {
    if ($created) {
        $source->exec('DROP DATABASE `' . $testDatabase . '`');
    }
}
exit($exitCode);
