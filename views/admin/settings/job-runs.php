<?php
/** Latest runs of a scheduled job. @var array $runs  ScheduledJobRun::recent() rows */
?>
<div style="font-size:12px;font-weight:700;color:var(--muted);margin-bottom:6px">Recent Runs</div>
<?php if (!$runs): ?>
  <div style="font-size:13px;color:var(--error-text);margin-bottom:6px">This job has never run. Make sure it is scheduled.</div>
<?php else: ?>
  <?php if (strtotime($runs[0]['started_at']) < time() - 2 * 86400): ?>
    <div style="font-size:13px;color:var(--error-text);margin-bottom:6px">Last run was more than 2 days ago — check the schedule.</div>
  <?php endif; ?>
  <?php foreach ($runs as $run): ?>
    <div class="review-row">
      <div class="k"><?= e(date('M j, Y g:i A', strtotime($run['started_at']))) ?> · <?= $run['trigger_source'] === 'manual' ? 'manual' . ($run['triggered_by_name'] ? ' by ' . e($run['triggered_by_name']) : '') : 'scheduled' ?></div>
      <div class="v" style="font-size:13px">
        <span style="color:<?= $run['status'] === 'SUCCESS' ? '#1c7a3f' : ($run['status'] === 'FAILED' ? 'var(--error-text)' : 'var(--muted)') ?>;font-weight:600"><?= e(ucfirst(strtolower($run['status']))) ?></span>
        · checked <?= (int) $run['checked'] ?>, sent <?= (int) $run['sent'] ?><?= (int) $run['failed'] ? ', failed ' . (int) $run['failed'] : '' ?>
        <?php if ($run['status'] !== 'SUCCESS' && $run['message']): ?><div style="color:var(--muted)"><?= e($run['message']) ?></div><?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>