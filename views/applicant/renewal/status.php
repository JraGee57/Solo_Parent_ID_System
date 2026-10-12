<?php
/**
 * @var ?array $latestRenewal
 * @var ?array $renewalTracker  ApplicationTrackerService::track() for it
 * @var array  $user
 */
?>
        <?php if (!$latestRenewal): ?>
          <div class="dash-card">
            <div style="font-size:14px;color:var(--muted)">You have no renewal requests yet.</div>
          </div>
        <?php else: $st = status_style($latestRenewal['status']); ?>
          <div class="dash-card">
            <div class="dash-status-row">
              <div>
                <div class="dash-status-ref"><?= e($latestRenewal['reference_no']) ?></div>
                <div class="dash-status-cat">Submitted <?= e(date('M j, Y', strtotime($latestRenewal['submitted_at']))) ?></div>
              </div>
              <div class="status-pill" style="background:<?= e($st['bg']) ?>;color:<?= e($st['color']) ?>"><?= e($latestRenewal['status']) ?></div>
            </div>
            <?php if ($latestRenewal['interview_at']): ?>
              <div class="info-box" style="max-width:none"><?= appointment_summary_html($latestRenewal) ?></div>
            <?php endif; ?>
            <?php $tracker = $renewalTracker; $trackerUserId = (int) $user['id']; require APP_ROOT . '/views/applicant/application/tracker.php'; ?>
          </div>
        <?php endif; ?>
