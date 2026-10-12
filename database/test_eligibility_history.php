<?php
/** Transactional regression checks; generated accounts, checks and views are rolled back. */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Command line only.');
}
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../services/EligibilityService.php';

function eligibility_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$originalSession = $_SESSION;
$exitCode = 0;
$pdo->beginTransaction();
try {
    $suffix = bin2hex(random_bytes(8));
    $users = [];
    for ($i = 0; $i < 2; $i++) {
        $name = 'eligibility_test_' . $suffix . '_' . $i;
        $pdo->prepare("INSERT INTO users (full_name, email, username, password_hash, role) VALUES (?, ?, ?, ?, 'applicant')")
            ->execute(['Eligibility regression fixture', $name . '@example.test', $name, password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT)]);
        $users[] = (int) $pdo->lastInsertId();
    }
    $_SESSION = ['eligibility_token' => bin2hex(random_bytes(32))];
    $token = hash('sha256', $_SESSION['eligibility_token']);
    $service = new EligibilityService($pdo);
    $model = new EligibilityAssessment($pdo);
    $answers = ['resident' => 'Yes', 'circ' => 'Abandonment by the spouse', 'child' => 'Yes'];
    $first = $service->record($answers, null);
    $second = $service->record(array_replace($answers, ['resident' => 'No']), null);
    $guidance = $service->record(array_replace($answers, ['child' => EligibilityService::UNSURE_OPTION]), null);
    eligibility_assert($first['id'] !== $second['id'] && $second['id'] !== $guidance['id'], 'Rechecking must append history.');
    eligibility_assert($model->findForViewer($first['id'], $token, null)['preliminary_result'] === 'LIKELY_ELIGIBLE', 'Earlier result was overwritten.');
    eligibility_assert($second['status'] === 'NOT_ELIGIBLE' && $guidance['status'] === 'NEEDS_GUIDANCE', 'Incorrect outcome.');
    eligibility_assert($service->current()['id'] === $guidance['id'], 'Latest guest result is incorrect.');
    eligibility_assert($service->missingAnswers($service->normalizeAnswers(['resident' => ['Yes']])), 'Malformed input should be rejected.');
    eligibility_assert($model->findForViewer($first['id'], hash('sha256', 'another visitor'), null) === null, 'Another guest can read the result.');

    $service->recordView($first['id'], null);
    $service->recordView($first['id'], null);
    $service->recordView($guidance['id'], null);
    $service->claimForUser($users[0]);
    eligibility_assert($model->findForViewer($first['id'], null, $users[0]) !== null, 'Owner cannot read claimed history.');
    eligibility_assert($model->findForViewer($first['id'], $token, $users[1]) === null, 'A different account can read owned history.');
    eligibility_assert($model->latestForUser($users[0])['id'] == $guidance['id'], 'Claimed history is missing.');
    $_SESSION['user_id'] = $users[1];
    eligibility_assert($service->current() === null, 'A shared browser exposes another account\'s result.');
    $stmt = $pdo->prepare('SELECT COUNT(*), COUNT(DISTINCT viewer_user_id) FROM eligibility_result_views WHERE assessment_id = ?');
    $stmt->execute([$first['id']]);
    $counts = $stmt->fetch(PDO::FETCH_NUM);
    eligibility_assert((int) $counts[0] === 2 && (int) $counts[1] === 1, 'Refresh views or account association are incorrect.');
    $page = $model->logPage(['from' => '', 'to' => '', 'result' => 'NEEDS_GUIDANCE', 'circ' => $answers['circ'], 'audience' => 'registered'], 1);
    $logs = array_column($page['logs'], null, 'id');
    eligibility_assert(isset($logs[$guidance['id']]) && (int) $logs[$guidance['id']]['view_count'] === 1, 'Admin log filters or view counts are incorrect.');
    echo "Eligibility history, view events, guest association, ownership and admin filters passed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    $exitCode = 1;
} finally {
    $pdo->rollBack();
    $_SESSION = $originalSession;
}
exit($exitCode);
