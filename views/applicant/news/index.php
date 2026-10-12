<?php
/**
 * @var array  $announcements
 * @var ?array $activeAnnouncement
 */
?>
      <?php if ($activeAnnouncement): ?>
        <div style="max-width:780px">
          <a href="?section=news" style="font-size:13px">&larr; Back to News &amp; Announcements</a>

          <div style="margin-top:14px;margin-bottom:20px">
            <span class="announcement-category"><?= e($activeAnnouncement['category']) ?></span>
            <h2 class="font-heading" style="font-size:24px;margin:10px 0 6px;color:var(--ink)"><?= e($activeAnnouncement['title']) ?></h2>
            <div style="font-size:13px;color:var(--muted)">Published <?= e(format_date_long($activeAnnouncement['published_at'])) ?></div>
          </div>

          <?php if (!empty($activeAnnouncement['image'])): ?>
            <img src="<?= asset('images/' . $activeAnnouncement['image']) ?>" alt="<?= e($activeAnnouncement['title']) ?>" style="width:100%;max-width:560px;height:auto;border-radius:12px;display:block;margin-bottom:20px">
          <?php endif; ?>

          <div class="body-copy" style="white-space:pre-line;max-width:none"><?= e($activeAnnouncement['details']) ?></div>

          <?php if (!empty($activeAnnouncement['attachment'])): ?>
            <a href="<?= asset('images/' . $activeAnnouncement['attachment']) ?>" target="_blank" rel="noopener" class="btn-outline text-decoration-none" style="display:inline-block;margin-top:8px;padding:10px 18px">📎 View Attachment</a>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <?php foreach ($announcements as $a): $unread = is_announcement_unread($a['id']); ?>
          <a href="?section=news&id=<?= $a['id'] ?>" class="announcement-card text-decoration-none">
            <div class="announcement-card-head">
              <span class="announcement-category"><?= e($a['category']) ?></span>
              <?php if ($unread): ?><span class="announcement-new">NEW</span><?php endif; ?>
            </div>
            <div class="announcement-title"><?= e($a['title']) ?></div>
            <div class="announcement-desc"><?= e($a['short_desc']) ?></div>
            <div class="announcement-date">Published <?= e(format_date_long($a['published_at'])) ?></div>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
