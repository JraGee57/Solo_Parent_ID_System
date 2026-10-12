<?php /** @var array $checks */ ?>
<div class="portal-container">
  <a href="<?= base_url('applicant/dashboard.php') ?>">&larr; My dashboard</a>
  <h1 class="font-heading mt-3">My Eligibility History</h1>
  <p class="eligibility-help">Your most recent 100 preliminary checks. These are guidance only; MSWD determines official eligibility.</p>
  <a href="<?= base_url('eligibility.php') ?>" class="btn-navy text-decoration-none">Check again</a>
  <?php if (!$checks): ?><p class="mt-3">You have not completed any eligibility checks yet.</p><?php endif; ?>
  <?php foreach ($checks as $check): ?>
    <article class="eligibility-log-card">
      <div><?= e(date('M j, Y g:i A', strtotime($check['created_at']))) ?></div>
      <strong><?= e(EligibilityService::resultLabel($check['preliminary_result'])) ?></strong>
      <a class="d-block mt-2" href="<?= base_url('eligibility.php?result=1&check=' . (int) $check['id']) ?>">View my result</a>
    </article>
  <?php endforeach; ?>
</div>
