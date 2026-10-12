<?php
/** CLI regression checks use a transaction and retain no test announcements. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Command line only.'); }
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../models/Announcement.php';
function news_assert(bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
}
$pdo->beginTransaction();
try {
    $admin = $pdo->query("SELECT id, full_name, role FROM users WHERE role='admin' LIMIT 1")->fetch();
    if (!$admin) { throw new RuntimeException('An admin account is required.'); }
    $model = new Announcement($pdo);
    $entry = ['title' => 'News test ' . bin2hex(random_bytes(8)), 'short_desc' => 'Summary', 'category' => 'Test', 'details' => '<script>plain text</script>', 'status' => 'draft', 'published_at' => date('Y-m-d\TH:i', time()-3600), 'expires_at' => '', 'priority' => '100'];
    news_assert($model->save($admin, 0, $entry) === '', 'Draft save');
    $id = (int) $pdo->lastInsertId();
    // Audit inserts have their own ID; find the announcement by its unique title.
    $stmt=$pdo->prepare('SELECT id FROM public_announcements WHERE title=?'); $stmt->execute([$entry['title']]); $id=(int)$stmt->fetchColumn();
    $visible = fn() => in_array($id, array_map('intval', array_column($model->published(), 'id')), true);
    news_assert(!$visible(), 'Draft must be private');
    $entry['status']='published'; news_assert($model->save($admin,$id,$entry)==='', 'Publish save');
    news_assert($visible(), 'Published must be public');
    news_assert((int)$model->published()[0]['id']===$id, 'Priority sorting');
    $entry['published_at']=date('Y-m-d\TH:i',time()+3600); news_assert($model->save($admin,$id,$entry)==='', 'Future save'); news_assert(!$visible(), 'Future publication must be private');
    $entry['published_at']=date('Y-m-d\TH:i',time()-7200);$entry['expires_at']=date('Y-m-d\TH:i',time()-3600); news_assert($model->save($admin,$id,$entry)==='', 'Expired save'); news_assert(!$visible(), 'Expired must be private');
    $entry['expires_at']=$entry['published_at'];news_assert($model->save($admin,$id,$entry)!=='', 'Reject invalid expiry');
    $entry['expires_at']='';$entry['title']=[];news_assert($model->save($admin,$id,$entry)!=='', 'Reject malformed title');
    foreach (['https://example.com','//example.com','admin/dashboard.php','staff/index.php', ['apply']] as $bad) { news_assert(applicant_return_path($bad)===null,'Reject foreign return destination'); }
    $_SESSION['applicant_return']='apply';news_assert(take_applicant_return_path()==='applicant/dashboard.php?section=apply','Applicant return');news_assert(!isset($_SESSION['applicant_return']),'Consume return once');
    news_assert(!can(['role'=>'applicant'],'content.manage') && !can(['role'=>'mswd_social_worker'],'content.manage') && can(['role'=>'admin'],'content.manage'),'Editor permissions');
    echo "PASS: draft/public/future/expiry visibility, priority, input validation, return destinations and permissions.\n";
} finally { $pdo->rollBack(); }
