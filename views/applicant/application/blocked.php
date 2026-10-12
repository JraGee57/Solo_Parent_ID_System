<?php
/**
 * Apply tab when the applicant already has an application that isn't
 * Rejected -- only one active application is allowed per applicant.
 *
 * @var array $app  the blocking applications row
 */
$released = $app['status'] === 'ID Released';
$renewal = $app['type'] === 'renewal';
$st = status_style($app['status']);
?>
<div class="dash-form-wrap">
  <div class="dash-card">
    <div class="dash-card-label"><?= $released ? 'You Already Have a Solo Parent ID' : ($renewal ? 'Your Renewal Is Being Processed' : 'You Already Have an Application') ?></div>
    <div class="d-flex align-items-center flex-wrap gap-2" style="margin:8px 0">
      <b><?= e($app['reference_no']) ?></b>
      <span class="status-pill" style="background:<?= e($st['bg']) ?>;color:<?= e($st['color']) ?>"><?= e($app['status']) ?></span>
    </div>
    <div style="font-size:14px;color:var(--muted);margin-bottom:14px">
      <?php if ($released): ?>
        Your ID has been released. When it is due for renewal, request a renewal instead of a new application.
      <?php elseif ($renewal): ?>
        You already hold a Solo Parent ID, so a new application isn't needed. Track your renewal from your dashboard.
      <?php else: ?>
        Only one application can be active at a time. You can track this one's progress from your dashboard; a new application can be filed only if it is rejected.
      <?php endif; ?>
    </div>
    <div class="d-flex flex-wrap gap-2">
      <?php if ($released): ?>
        <a href="<?= base_url('applicant/dashboard.php?section=renewal&type=new') ?>" class="btn-navy text-decoration-none">Go to ID Renewal</a>
      <?php endif; ?>
      <a href="<?= base_url('applicant/dashboard.php?section=application') ?>" class="btn-outline text-decoration-none">View My Application</a>
    </div>
  </div>
</div>
