<?php
/** @var PDO $pdo */
$announcements = announcements_list();

$activeId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$active = null;
foreach ($announcements as $a) {
    if ($a['id'] === $activeId) {
        $active = $a;
        break;
    }
}
if ($active) {
    mark_announcement_read($active['id']);
}
?>
<?php if ($active): ?>
  <div style="max-width:780px">
    <a href="<?= base_url('portal.php?tab=news') ?>" class="public-back-link"><svg aria-hidden="true" focusable="false" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 5-7 7 7 7M5 12h14"/></svg><span>Back to News &amp; Announcements</span></a>

    <div style="margin-top:14px;margin-bottom:20px">
      <span class="announcement-category"><?= e($active['category']) ?></span>
      <h2 class="font-heading" style="font-size:24px;margin:10px 0 6px;color:var(--ink)"><?= e($active['title']) ?></h2>
      <div style="font-size:13px;color:var(--muted)">Published <?= e(format_date_long($active['published_at'])) ?></div>
    </div>

    <?php if (!empty($active['image'])): ?>
      <img src="<?= asset('images/' . $active['image']) ?>" alt="<?= e($active['title']) ?>" style="width:100%;max-width:560px;height:auto;border-radius:12px;display:block;margin-bottom:20px">
    <?php endif; ?>

    <div class="body-copy" style="white-space:pre-line;max-width:none"><?= e($active['details']) ?></div>

    <?php if (!empty($active['attachment'])): ?>
      <a href="<?= asset('images/' . $active['attachment']) ?>" target="_blank" rel="noopener" class="btn-outline text-decoration-none" style="display:inline-block;margin-top:8px;padding:10px 18px">📎 View Attachment</a>
    <?php endif; ?>
  </div>
<?php else: ?>
  <div style="max-width:780px">
    <div style="font-size:14px;color:var(--muted);margin-bottom:20px">Official announcements, program updates, application reminders, and renewal schedules from MSWDO Virac.</div>

    <?php foreach ($announcements as $a): $unread = is_announcement_unread($a['id']); ?>
      <a href="<?= base_url('portal.php?tab=news&id=' . $a['id']) ?>" class="announcement-card text-decoration-none">
        <div class="announcement-card-head">
          <span class="announcement-category"><?= e($a['category']) ?></span>
          <?php if ($unread): ?><span class="announcement-new">NEW</span><?php endif; ?>
        </div>
        <div class="announcement-title"><?= e($a['title']) ?></div>
        <div class="announcement-desc"><?= e($a['short_desc']) ?></div>
        <div class="announcement-date">Published <?= e(format_date_long($a['published_at'])) ?></div>
      </a>
    <?php endforeach; ?>

    <?php if (!$announcements): ?>
      <div style="font-size:14px;color:var(--muted)">No announcements at this time. Please check back later.</div>
    <?php endif; ?>
  </div>
<?php endif; ?>
