<?php
/** CLI checks use connection-local temporary tables; no real SMS or announcements are changed. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Command line only.'); }
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../models/SmsLog.php';
require_once __DIR__ . '/../models/Announcement.php';
require_once __DIR__ . '/../services/SMSService.php';

function layout_assert(bool $ok, string $message): void
{
    if (!$ok) { throw new RuntimeException($message); }
}

$exitCode = 0;
try {
    foreach (['sms_logs', 'public_announcements'] as $table) {
        $definition = $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM)[1];
        $definition = preg_replace('/^CREATE TABLE/', 'CREATE TEMPORARY TABLE', $definition);
        // Temporary tables cannot have foreign keys. Only their local fixture rows are used.
        $definition = preg_replace('/,\s*CONSTRAINT\s+[^\n]+/', '', $definition);
        $pdo->exec($definition);
    }
    $insertSms = $pdo->prepare('INSERT INTO sms_logs (mobile,message,status,test_mode,template_key) VALUES (?,?,?,?,?)');
    $insertNews = $pdo->prepare("INSERT INTO public_announcements (title,short_desc,category,details,status,published_at,priority) VALUES (?,'Summary','Test','Details','draft',NOW(),0)");
    for ($i = 1; $i <= 27; $i++) {
        [$state, $testMode] = [['SENT', 0], ['SENT', 1], ['FAILED', 0], ['PENDING', 0]][($i - 1) % 4];
        $insertSms->execute(['09123456789', 'Fixture ' . $i, $state, $testMode, $i === 3 ? 'password_reset' : 'status_change']);
        $insertNews->execute(['Fixture announcement ' . $i]);
    }
    $sms = new SmsLog($pdo);
    $news = new Announcement($pdo);
    foreach ([$sms->page('ALL', '', 1), $news->page(1)] as $page) {
        layout_assert(count($page['rows']) === 10 && $page['total'] === 27 && $page['totalPages'] === 3, 'Incorrect first page size or count.');
    }
    layout_assert(!array_intersect(array_column($sms->page('ALL', '', 1)['rows'], 'id'), array_column($sms->page('ALL', '', 2)['rows'], 'id')), 'SMS pages overlap.');
    layout_assert(!array_intersect(array_column($news->page(1)['rows'], 'id'), array_column($news->page(2)['rows'], 'id')), 'Announcement pages overlap.');
    layout_assert(count($sms->page('ALL', '', 999)['rows']) === 7 && $news->page(999)['page'] === 3, 'Last page was not clamped.');
    layout_assert($sms->page('ALL', '', -2)['page'] === 1 && $news->page(-2)['page'] === 1, 'Negative page was not clamped.');
    foreach (['sent', 'test', 'failed', 'pending'] as $filter) {
        $page = $sms->page($filter, '', 1);
        layout_assert($page['total'] === $sms->countByStatus($filter), 'SMS status count mismatch.');
        foreach ($page['rows'] as $row) {
            $matches = match ($filter) {
                'sent' => $row['status'] === 'SENT' && !$row['test_mode'],
                'test' => $row['status'] === 'SENT' && (bool) $row['test_mode'],
                'failed' => $row['status'] === 'FAILED',
                'pending' => $row['status'] === 'PENDING',
            };
            layout_assert($matches, 'Incorrect status filter results.');
        }
    }
    layout_assert($sms->page('test', '', 1)['total'] === $sms->page('skipped', '', 1)['total'], 'Legacy test filter changed.');
    layout_assert($sms->page('ALL', 'Fixture 2', 1)['total'] === 9, 'SMS message search failed.');
    layout_assert(count($sms->search('ALL', '')) === 27, 'Existing SMS search compatibility changed.');

    $staff = ['id' => 0, 'full_name' => 'Layout fixture', 'role' => 'admin'];
    $error = $success = ''; $statusFilter = 'ALL'; $q = ''; $editedTemplate = '';
    $templates = [['template_key' => 'status_change', 'label' => 'Status update', 'body' => 'Reference {ref}: {status}']];
    $totals = ['sent' => 7, 'test' => 7, 'failed' => 7, 'pending' => 6];
    foreach (['ALL', 'failed', 'test', 'nothing-matches'] as $filter) {
        $pager = $sms->page($filter === 'nothing-matches' ? 'ALL' : $filter, $filter === 'nothing-matches' ? 'nonexistent' : '', 1);
        $logs = $pager['rows'];
        ob_start();
        require APP_ROOT . '/views/admin/sms/index.php';
        $html = ob_get_clean();
        layout_assert(substr_count($html, '<tr>') === ($logs ? count($logs) + 1 : 0), 'Incorrect SMS row rendering.');
        foreach ($logs as $log) {
            $retry = $log['status'] === 'FAILED' && $log['attempts'] < SMS_MAX_ATTEMPTS && $log['template_key'] !== 'password_reset';
            layout_assert(str_contains($html, 'aria-label="Retry SMS ' . $log['id'] . '"') === $retry, 'Retry restrictions changed.');
        }
        if ($filter === 'test') { layout_assert(str_contains($html, 'Test (not sent)'), 'Test messages look delivered.'); }
        if (!$logs) { layout_assert(str_contains($html, 'No SMS logs match'), 'Empty state missing.'); }
    }
    echo "Admin pagination, SMS filters/search, retry restrictions, test labels and populated/empty rendering passed. No SMS sent or live records changed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    $exitCode = 1;
} finally {
    $pdo->exec('DROP TEMPORARY TABLE IF EXISTS sms_logs, public_announcements');
}
exit($exitCode);
