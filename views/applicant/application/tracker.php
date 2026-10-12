<?php
/**
 * Applicant progress tracker + status timeline for one application, built by
 * ApplicationTrackerService from recorded data only.
 *
 * @var array $tracker  ApplicationTrackerService::track() result
 * @var int   $trackerUserId  the viewing applicant (their own history entries read "You")
 */
$icons = ['done' => '✓', 'active' => '•', 'attention' => '!', 'pending' => '', 'stopped' => '–'];
$n = 0;
?>
<?php if ($tracker['rejected']): ?>
  <div class="step-alert">❌ Not approved<?= $tracker['rejected']['date'] ? ' on ' . e(date('F j, Y', strtotime($tracker['rejected']['date']))) : '' ?><?= $tracker['rejected']['reason'] ? ': ' . e($tracker['rejected']['reason']) : '.' ?></div>
<?php elseif ($tracker['correction']): ?>
  <div class="step-alert">⚠️ MSWDO asked for corrections<?= $tracker['correction']['reason'] ? ': ' . e($tracker['correction']['reason']) : '.' ?> &mdash; <a href="?section=documents">review your Required Documents Checklist</a>.</div>
<?php endif; ?>

<ol class="tracker" aria-label="Application progress">
  <?php foreach ($tracker['stages'] as $s): $n++; ?>
    <li class="tracker-step <?= e($s['state']) ?>" <?= in_array($s['state'], ['active', 'attention'], true) ? 'aria-current="step"' : '' ?>>
      <span class="tracker-dot" aria-hidden="true"><?= $icons[$s['state']] !== '' ? $icons[$s['state']] : $n ?></span>
      <div class="tracker-body">
        <div class="tracker-label"><?= e($s['label']) ?>
          <?php if ($s['state'] === 'active'): ?><span class="tracker-tag">In progress</span><?php elseif ($s['state'] === 'attention'): ?><span class="tracker-tag warn">Needs your action</span><?php endif; ?>
        </div>
        <?php if ($s['date']): ?><div class="tracker-date"><?= e(date('F j, Y g:i A', strtotime($s['date']))) ?></div><?php endif; ?>
        <?php if ($s['detail']): ?><div class="tracker-detail"><?= e($s['detail']) ?></div><?php endif; ?>
      </div>
    </li>
  <?php endforeach; ?>
</ol>

<?php $release = $tracker['idReleaseSchedule'] ?? null; $trackedStatus = $app['status'] ?? ($latestRenewal['status'] ?? ''); ?>
<?php if ($release && $release['released_at']): ?>
  <div class="info-box" style="max-width:none"><b>Solo Parent ID Released</b><br>Your ID was handed over on <?= e(date('F j, Y g:i A', strtotime($release['released_at']))) ?>.</div>
<?php elseif ($trackedStatus === 'Approved' && $release): ?>
  <div class="review-card mt-3">
    <div class="review-card-head"><div class="title">Solo Parent ID Release Appointment</div></div>
    <div class="review-row"><div class="k">Date</div><div class="v"><?= e(date('F j, Y', strtotime($release['scheduled_at']))) ?></div></div>
    <div class="review-row"><div class="k">Time</div><div class="v"><?= e(date('g:i A', strtotime($release['scheduled_at']))) ?></div></div>
    <div class="review-row"><div class="k">Location</div><div class="v"><?= e($release['location']) ?></div></div>
    <div class="tracker-detail mt-2">Your Solo Parent ID is ready for scheduled release. Please visit the MSWDO office at your scheduled date and time. Your application will be marked ID Released after staff confirms that you have received your ID.</div>
  </div>
<?php endif; ?>

<?php if ($tracker['history']): ?>
  <details class="doc-trail">
    <summary>Status history (<?= count($tracker['history']) ?>)</summary>
    <ol>
      <?php foreach (array_reverse($tracker['history']) as $h): ?>
        <li>
          <span class="when"><?= e(date('M j, Y g:i A', strtotime($h['changed_at']))) ?></span>
          <b><?= e($h['status']) ?></b> <span class="who">· <?= (int) $h['changed_by_user_id'] === $trackerUserId ? 'You' : 'MSWDO' ?></span>
          <?php if ($h['remarks']): ?><div class="note"><?= e($h['remarks']) ?></div><?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ol>
  </details>
<?php endif; ?>
