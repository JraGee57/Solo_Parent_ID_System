<?php
/** Applicant declarations and separate staff review results. */
$documentErrors = $documentErrors ?? [];
$pendingCount = count(array_filter($docs, fn($d) => $d['verification_status'] === 'Pending'));
$missingRequiredCount = count(array_filter($docs, fn($d) => !$d['applicant_has'] && $d['is_required']));
?>
<div class="review-card" id="documents">
  <div class="review-card-head"><div class="title">Document Requirements and Verification Overview</div></div>
  <div class="info-box" style="max-width:none">Applicant selections indicate availability only. Staff verification is recorded separately.</div>
  <div class="doc-summary"><span><?= count($docs) - $pendingCount ?> of <?= count($docs) ?> reviewed</span><span><?= $missingRequiredCount ?> required requirements unchecked</span><span><?= $pendingCount ?> pending</span></div>
  <?php foreach ($docs as $d): $key = $d['doc_key']; ?>
    <div class="doc-review" id="doc-<?= e($key) ?>">
      <div class="doc-label"><?= e($d['label']) ?><?= $d['is_required'] ? ' *' : ' (optional)' ?></div>
      <div class="doc-filename">Applicant selection: <b><?= $d['applicant_has'] ? 'Available' : 'Not checked' ?></b></div>
      <div class="doc-filename">Staff status: <b><?= e($d['verification_status']) ?></b><?php if ($d['reviewed_at']): ?> · <?= e($d['verifier_name'] ?? 'Former staff') ?> · <?= e(date('M j, Y g:i A', strtotime($d['reviewed_at']))) ?><?php endif; ?></div>
      <?php if ($d['staff_remarks']): ?><div class="doc-error">Staff remarks: <?= e($d['staff_remarks']) ?></div><?php endif; ?>
      <?php if ($canVerify && $documentsEditable): ?>
        <form method="post" action="#doc-<?= e($key) ?>" class="doc-verify-form">
          <?= csrf_field() ?>
          <input type="hidden" name="form_action" value="update_checklist_review">
          <input type="hidden" name="doc_key" value="<?= e($key) ?>">
          <select name="verification_status" class="form-select" aria-label="Verification status for <?= e($d['label']) ?>">
            <?php foreach ($verificationStatuses as $status): ?><option value="<?= e($status) ?>" <?= $d['verification_status'] === $status ? 'selected' : '' ?>><?= e($status) ?></option><?php endforeach; ?>
          </select>
          <input type="text" name="staff_remarks" maxlength="500" value="<?= e($d['staff_remarks'] ?? '') ?>" placeholder="Staff remarks" class="form-control" aria-label="Remarks for <?= e($d['label']) ?>">
          <button type="submit" class="btn-navy">Save review</button>
        </form>
      <?php endif; ?>
      <?php if (!empty($documentErrors[$key])): ?><div class="field-error mt-1" role="alert"><?= e($documentErrors[$key]) ?></div><?php endif; ?>
    </div>
  <?php endforeach; ?>
  <?php if (!$docs): ?><div class="doc-filename">No document requirements configured.</div><?php endif; ?>
</div>
