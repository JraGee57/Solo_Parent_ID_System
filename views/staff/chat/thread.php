<?php
/**
 * @var array $applicant
 * @var array $thread        chat_messages rows, oldest first
 * @var array $applications  the applicant's applications (latest first)
 * @var ?string $error
 * @var string $typed
 * @var ?string $flashMessage
 */
?>
<div class="portal-container" style="max-width:860px">
  <a href="<?= e(base_url('staff/chat.php')) ?>" class="staff-back-link"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5m7-7-7 7 7 7"/></svg><span>Back</span></a>
  <h1 class="font-heading mt-2 mb-1" style="font-size:24px"><?= e($applicant['full_name']) ?></h1>
  <div style="font-size:13px;color:var(--muted);margin-bottom:14px">
    @<?= e($applicant['username']) ?>
    <?php if (can($staff, 'applicants.view')): ?> · <a href="<?= e(base_url('staff/applicant_view.php?id=' . (int) $applicant['id'])) ?>">Applicant record</a><?php endif; ?>
    <?php foreach (array_slice($applications, 0, 3) as $app): ?>
      · <a href="<?= e(base_url('staff/application.php?id=' . (int) $app['id'])) ?>"><?= e($app['reference_no']) ?></a> (<?= e($app['status']) ?>)
    <?php endforeach; ?>
  </div>
  <?php if ($flashMessage): ?><div class="info-box" style="max-width:none" role="status"><?= e($flashMessage) ?></div><?php endif; ?>

  <div class="chat-box" style="max-width:none">
    <div class="chat-thread" id="chat-thread">
      <?php foreach ($thread as $i => $m): ?>
        <?php
          $fromApplicant = $m['sender'] === 'APPLICANT';
          $who = $fromApplicant ? $applicant['full_name'] : ($m['sender'] === 'STAFF' ? 'MSWDO staff' . ($m['staff_name'] ? ' · ' . $m['staff_name'] : '') : 'Help Assistant');
        ?>
        <div class="chat-msg <?= $m['sender'] === 'STAFF' ? 'chat-msg-mine' : ($fromApplicant ? 'chat-msg-staff' : 'chat-msg-bot') ?>"<?= $i === count($thread) - 1 ? ' id="chat-latest"' : '' ?>>
          <div class="chat-meta"><?= e($who) ?> · <?= e(date('M j, g:i A', strtotime($m['created_at']))) ?><?= $m['handoff'] ? ' · <span class="chat-tag">Asked for staff</span>' : '' ?></div>
          <div class="chat-bubble"><?= nl2br(e($m['message'])) ?></div>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if ($error): ?><div class="auth-error mb-2" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" action="<?= e(base_url('staff/chat.php?user=' . (int) $applicant['id'])) ?>" class="chat-input">
      <?= csrf_field() ?>
      <label for="chat-reply" style="font-size:13px;font-weight:700">Reply to <?= e($applicant['full_name']) ?></label>
      <textarea id="chat-reply" name="reply" rows="3" maxlength="1000" required><?= e($typed) ?></textarea>
      <div class="chat-actions"><button type="submit" class="btn-navy">Send reply</button></div>
    </form>
    <div class="chat-hint">The applicant is notified in the portal. Record any decision on the application itself, not only in the chat.</div>
  </div>
</div>
<script>
(function () { var t = document.getElementById('chat-thread'); if (t) t.scrollTop = t.scrollHeight; })();
</script>
