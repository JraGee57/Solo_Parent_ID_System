<?php
/**
 * @var array $inbox   conversations where the applicant asked for staff (waiting first)
 * @var int $waiting
 * @var ?string $flashMessage
 * @var string $ownDashboard
 */
?>
<div class="portal-container" style="max-width:860px">
  <a href="<?= e($ownDashboard) ?>" class="staff-back-link"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5m7-7-7 7 7 7"/></svg><span>Back</span></a>
  <h1 class="font-heading mt-2 mb-1" style="font-size:26px">Chat Support</h1>
  <p style="font-size:14px;color:var(--muted);margin-bottom:16px">
    Questions applicants sent to MSWDO staff from the Help Assistant. <b><?= (int) $waiting ?></b> waiting for a reply.
    The assistant only gives guidance; decisions on eligibility and approval are yours to make in the application review.
  </p>
  <?php if ($flashMessage): ?><div class="info-box" style="max-width:none" role="status"><?= e($flashMessage) ?></div><?php endif; ?>

  <?php foreach ($inbox as $c): ?>
    <a href="<?= e(base_url('staff/chat.php?user=' . (int) $c['user_id'])) ?>" class="chat-inbox-row">
      <div style="min-width:0">
        <div style="font-weight:700"><?= e($c['full_name']) ?> <span style="font-weight:400;color:var(--faint);font-size:12px">@<?= e($c['username']) ?></span></div>
        <div style="font-size:13px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e(fit_text((string) $c['last_question'], 120)) ?></div>
      </div>
      <div style="text-align:right;white-space:nowrap;font-size:12px">
        <?php if ((int) $c['waiting']): ?>
          <span class="status-pill" style="background:var(--warn-bg);color:var(--warn-text)">Waiting since <?= e(date('M j, g:i A', strtotime($c['waiting_since']))) ?></span>
        <?php else: ?>
          <span class="status-pill" style="background:var(--success-bg);color:var(--success-text)">Answered</span>
        <?php endif; ?>
        <div style="color:var(--faint);margin-top:4px">Last activity <?= e(date('M j, g:i A', strtotime($c['last_activity']))) ?></div>
      </div>
    </a>
  <?php endforeach; ?>
  <?php if (!$inbox): ?>
    <div style="font-size:14px;color:var(--muted)">No applicant has asked for staff yet.</div>
  <?php endif; ?>
</div>
