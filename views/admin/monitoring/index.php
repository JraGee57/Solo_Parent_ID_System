<?php
/**
 * @var string  $error
 * @var string  $success
 * @var array   $tableCounts  table => row count
 * @var int     $uploadsSize
 * @var int     $backupsSize
 * @var array   $backups      name, size, created
 * @var array   $recentActivity
 * @var ?string $lastActivityAt
 */
?>
<div class="portal-container">
  <a href="<?= base_url('admin/dashboard.php') ?>" class="staff-back-link"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5m7-7-7 7 7 7"/></svg><span>Back</span></a>
  <h1 class="font-heading mt-2 mb-4" style="font-size:26px">Database &amp; System Monitoring</h1>

  <?php if ($error): ?><div class="auth-error mb-3" role="alert"><?= e($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="info-box" style="max-width:none" role="status"><?= e($success) ?></div><?php endif; ?>

  <div class="dash-quick-grid" style="margin-bottom:24px">
    <div class="dash-card" style="margin-bottom:0"><div class="dash-card-label">Database</div><div style="font-size:20px;font-weight:700;color:#1c7a3f">Connected</div></div>
    <div class="dash-card" style="margin-bottom:0"><div class="dash-card-label">Documents Storage</div><div style="font-size:20px;font-weight:700;color:var(--ink)"><?= e(format_bytes($uploadsSize)) ?></div></div>
    <div class="dash-card" style="margin-bottom:0"><div class="dash-card-label">Backups Storage</div><div style="font-size:20px;font-weight:700;color:var(--ink)"><?= e(format_bytes($backupsSize)) ?></div></div>
    <div class="dash-card" style="margin-bottom:0"><div class="dash-card-label">Last System Activity</div><div style="font-size:14px;font-weight:700;color:var(--ink)"><?= $lastActivityAt ? e(date('M j, g:i A', strtotime($lastActivityAt))) : 'none yet' ?></div></div>
  </div>

  <div class="row g-4">
    <div class="col-12 col-lg-6">
      <div class="review-card">
        <div class="review-card-head"><div class="title">Table Row Counts</div></div>
        <div class="admin-list-scroll" role="region" aria-label="Database table counts" tabindex="0">
        <?php foreach ($tableCounts as $table => $count): ?>
          <div class="review-row"><div class="k"><?= e($table) ?></div><div class="v"><?= (int) $count ?></div></div>
        <?php endforeach; ?>
        </div>
      </div>

      <div class="review-card">
        <div class="review-card-head"><div class="title">Recent System Activity</div></div>
        <?php foreach ($recentActivity as $log): ?>
          <div class="mb-2" style="font-size:13px">
            <b><?= e($log['action']) ?></b> by <?= e($log['actor_name']) ?> — <?= e(date('M j, Y g:i A', strtotime($log['created_at']))) ?>
          </div>
        <?php endforeach; ?>
        <?php if (!$recentActivity): ?><div style="font-size:13px;color:var(--muted)">No activity logged yet.</div><?php endif; ?>
        <a href="<?= base_url('admin/audit_log.php') ?>" style="font-size:13px">View full audit log &rarr;</a>
      </div>
    </div>

    <div class="col-12 col-lg-6">
      <div class="review-card">
        <div class="review-card-head"><div class="title">Database Backup</div></div>
        <div style="font-size:13px;color:var(--muted);margin-bottom:14px">Creates a full structure + data export of the live database as a downloadable <code>.sql</code> file, stored outside public access.</div>
        <form method="post" class="mb-3">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="create_backup">
          <button type="submit" class="btn-navy">Create Backup Now</button>
        </form>

        <?php if (!$backups): ?>
          <div style="font-size:13px;color:var(--muted)">No backups yet.</div>
        <?php endif; ?>
        <?php if ($backups): ?><div class="admin-list-scroll" role="region" aria-label="Database backups" tabindex="0"><?php endif; ?>
        <?php foreach ($backups as $b): ?>
          <div class="app-card">
            <div>
              <div class="app-ref"><?= e($b['name']) ?></div>
              <div class="app-date"><?= e(format_bytes($b['size'])) ?> · <?= e(date('M j, Y g:i A', $b['created'])) ?></div>
            </div>
            <div class="d-flex gap-2">
              <a href="<?= base_url('admin/download_backup.php?name=' . urlencode($b['name'])) ?>" class="btn-outline text-decoration-none" style="padding:6px 12px;font-size:12px">Download</a>
              <form method="post" class="m-0" onsubmit="return confirm('Delete this backup file?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_backup">
                <input type="hidden" name="name" value="<?= e($b['name']) ?>">
                <button type="submit" class="btn-danger-outline btn-sm">Delete</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if ($backups): ?></div><?php endif; ?>
      </div>
    </div>
  </div>
</div>
