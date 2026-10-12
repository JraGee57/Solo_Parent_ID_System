<?php
/** @var array $apps */
?>
<?php if (!$apps): ?>
  <div style="font-size:14px;color:var(--muted)">You have not submitted any applications yet.</div>
<?php endif; ?>

<?php foreach ($apps as $app): $st = status_style($app['status']); ?>
  <div class="app-card">
    <div>
      <div class="app-ref"><?= e($app['reference_no']) ?></div>
      <div class="app-date">Submitted <?= e(date('M j, Y', strtotime($app['submitted_at']))) ?></div>
    </div>
    <div class="d-flex align-items-center flex-wrap gap-2">
      <div class="status-pill" style="background:<?= e($st['bg']) ?>;color:<?= e($st['color']) ?>"><?= e($app['status']) ?></div>
      <a href="<?= base_url('applicant/dashboard.php?section=slip&ref=' . urlencode($app['reference_no'])) ?>" class="btn-outline text-decoration-none" style="padding:6px 12px;font-size:12px">View Slip</a>
    </div>
  </div>
<?php endforeach; ?>
