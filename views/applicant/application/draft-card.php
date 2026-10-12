<?php
/**
 * "Continue your application" card for a saved, unsubmitted application draft.
 *
 * @var ?array $applicationDraft  step, stepLabel, updatedAt (ApplicationService::draftSummary), or null
 */
?>
<?php if (!empty($applicationDraft)): ?>
  <div class="draft-card">
    <div>
      <div class="draft-card-title">Continue your application</div>
      <div class="draft-card-meta">Step <?= (int) $applicationDraft['step'] ?> of 4 (<?= e($applicationDraft['stepLabel']) ?>) · last saved <?= e(date('M j, Y g:i A', strtotime($applicationDraft['updatedAt']))) ?></div>
    </div>
    <div class="draft-card-actions">
      <a href="<?= base_url('applicant/dashboard.php?section=apply') ?>" class="btn-navy text-decoration-none">Continue</a>
      <form method="post" action="<?= base_url('applicant/dashboard.php?section=apply') ?>" class="m-0" onsubmit="return confirm('Discard this draft? Everything you entered and selected will be deleted.')">
        <?= csrf_field() ?>
        <button type="submit" name="action" value="discard_draft" class="draft-discard">Discard</button>
      </form>
    </div>
  </div>
<?php endif; ?>
