<?php
/** Public content only; applicant records are never included. */
$query = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 100) : '';
$entries = [
    ['title' => 'Eligibility Checker', 'description' => 'Preliminary solo parent eligibility assessment.', 'url' => base_url('eligibility.php')],
    ['title' => 'Apply for Solo Parent ID', 'description' => 'Application form, registration and document checklist.', 'url' => base_url(!empty($user) ? 'applicant/dashboard.php?section=apply' : 'portal.php?tab=apply')],
    ['title' => 'Track Application', 'description' => 'Application status, reference number and mobile verification.', 'url' => base_url(!empty($user) ? 'applicant/dashboard.php?section=application' : 'portal.php?tab=status')],
    ['title' => 'Benefits of Having a Solo Parent ID', 'description' => 'Work schedule, parental leave, education, discounts and cash subsidy.', 'url' => base_url('portal.php#benefits')],
    ['title' => 'How to Apply', 'description' => 'Application steps, checklist, interview and ID claiming.', 'url' => base_url('portal.php#how-to-apply')],
    ['title' => 'Contact & Help', 'description' => 'MSWDO contact information, office hours and frequently asked questions.', 'url' => base_url('portal.php?tab=help')],
];
foreach (required_doc_groups($pdo) as $group) {
    $entries[] = ['title' => 'Document Requirements: ' . $group['title'], 'description' => implode('; ', array_column($group['items'], 'label')), 'url' => base_url('portal.php#requirements')];
}
foreach (get_faqs($pdo) as $faq) {
    $entries[] = ['title' => $faq['question'], 'description' => $faq['answer'], 'url' => base_url('portal.php?tab=help')];
}
foreach (announcements_list($pdo) as $news) {
    $entries[] = ['title' => $news['title'], 'description' => $news['short_desc'], 'search_text' => $news['details'], 'url' => base_url('portal.php?tab=news&id=' . $news['id'])];
}
$terms = preg_split('/\s+/u', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
$matches = $query === '' ? array_slice($entries, 0, 6) : array_values(array_filter($entries, function ($entry) use ($terms) {
    $text = mb_strtolower($entry['title'] . ' ' . $entry['description'] . ' ' . ($entry['search_text'] ?? ''));
    foreach ($terms as $term) {
        if (mb_strpos($text, $term) === false) { return false; }
    }
    return true;
}));
?>
<form action="<?= base_url('portal.php') ?>" method="get" role="search" class="portal-search-bar" style="padding:0;margin:0 0 20px">
  <input type="hidden" name="tab" value="search">
  <label class="visually-hidden" for="portal-search">Search services, requirements, and announcements</label>
  <input id="portal-search" type="search" name="q" maxlength="100" placeholder="Search services, requirements, announcements" value="<?= e($query) ?>">
</form>
<p class="section-sub"><?= $query === '' ? 'Search above, or choose a service below.' : count($matches) . ' result(s) for: ' . e($query) ?></p>
<div class="portal-search-results">
<?php foreach ($matches as $entry): ?>
  <a class="portal-search-result" href="<?= e($entry['url']) ?>">
    <h2><?= e($entry['title']) ?></h2>
    <p><?= e(mb_strimwidth($entry['description'], 0, 240, '...')) ?></p>
    <span>Open &rarr;</span>
  </a>
<?php endforeach; ?>
</div>
<?php if (!$matches): ?>
  <div class="info-box">No matching content found. Try “eligibility”, “requirements”, or “track application”, or <a href="<?= base_url('portal.php?tab=help') ?>">contact MSWDO for help</a>.</div>
<?php endif; ?>
