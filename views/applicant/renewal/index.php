<?php
/**
 * ID Renewal: the applicant's current ID, then (when renewal is open) the
 * 4-step renewal wizard pre-filled from that record.
 *
 * @var ?array  $renewalSource      the current released ID, with its children
 * @var ?array  $renewalInProgress  a renewal already being processed (blocks a second one)
 * @var ?array  $renewalWindow      RenewalService::window()
 * Wizard only (renewal open):
 * @var array   $draft
 * @var array   $errors
 * @var ?string $submitError
 * @var int     $step
 * @var array   $stepMeta
 * @var array   $docDefs
 * @var array   $changes            label => [previous, new] compared with the current ID
 * @var ?string $draftSavedAt
 * @var ?string $flashMessage
 */
?>
<?php if (!$renewalSource): ?>
  <div class="dash-card">
    <div class="dash-card-label">No Released ID Yet</div>
    <div style="font-size:14px;color:var(--muted);margin:8px 0 14px">You can request a renewal once your Solo Parent ID has been approved and released by MSWDO. Check your application status in the meantime.</div>
    <a href="?section=application" class="btn-outline text-decoration-none">View My Application</a>
  </div>
<?php else: $w = $renewalWindow; ?>
  <div class="dash-card">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
      <div class="dash-card-label">Your Current Solo Parent ID</div>
      <?php if ($w['expiresAt']): ?>
        <span class="status-pill" style="background:<?= $w['expired'] ? '#fde8e8' : ($w['open'] ? '#fdf1d6' : '#dcf3e2') ?>;color:<?= $w['expired'] ? '#a51c1c' : ($w['open'] ? '#8a6d1a' : '#1c7a3f') ?>"><?= $w['expired'] ? 'Expired' : ($w['open'] ? 'Due for renewal' : 'Valid') ?></span>
      <?php endif; ?>
    </div>
    <div class="review-grid mt-2">
      <div class="review-row"><div class="k">Reference</div><div class="v"><?= e($renewalSource['reference_no']) ?><?= $renewalSource['type'] === 'renewal' ? ' (renewal)' : '' ?></div></div>
      <div class="review-row"><div class="k">Issued to</div><div class="v"><?= e($renewalSource['full_name']) ?></div></div>
      <div class="review-row"><div class="k">Category</div><div class="v"><?= e($renewalSource['category_name']) ?></div></div>
      <div class="review-row"><div class="k">Expires</div><div class="v"><?= $w['expiresAt'] ? e(format_date_long($w['expiresAt'])) : 'Not recorded' ?></div></div>
      <div class="review-row"><div class="k">Children</div><div class="v"><?= e(implode(', ', array_column($renewalSource['children'], 'full_name')) ?: '—') ?></div></div>
    </div>
    <div style="margin-top:14px"><a href="?section=myid" class="btn-outline text-decoration-none" style="padding:6px 14px;font-size:13px;display:inline-block">View My Solo Parent ID</a></div>
  </div>

  <?php if ($renewalInProgress): ?>
    <div class="dash-card">
      <div class="dash-card-label">Renewal Already in Progress</div>
      <div style="font-size:14px;color:var(--muted);margin:8px 0 14px">Your renewal <b><?= e($renewalInProgress['reference_no']) ?></b> is being processed (<?= e($renewalInProgress['status']) ?>). You can request another renewal once this one is completed.</div>
      <a href="?section=renewal&amp;type=status" class="btn-outline text-decoration-none">View Renewal Status</a>
    </div>
  <?php elseif (!$w['open']): ?>
    <div class="dash-card">
      <div class="dash-card-label">Not Due for Renewal Yet</div>
      <div style="font-size:14px;color:var(--muted);margin:8px 0 0">Renewal opens on <b style="color:var(--ink)"><?= e(format_date_long($w['opensOn'])) ?></b>, <?= (int) $w['windowDays'] ?> days before your ID expires. We'll send you a reminder.</div>
    </div>
  <?php else:
      $stepViews = [1 => APP_ROOT . '/views/applicant/renewal/step-details.php', 2 => APP_ROOT . '/views/applicant/application/step-children.php',
                    3 => APP_ROOT . '/views/applicant/application/step-documents.php', 4 => APP_ROOT . '/views/applicant/application/review.php'];
      // Shared step views expect these.
      $categoryMap = [(int) $renewalSource['category_id'] => $renewalSource['category_name']];
      $catOnlyKeys = [];
      $submitLabel = 'Submit Renewal';
  ?>
    <div class="dash-form-wrap">
      <?php if ($flashMessage): ?><div class="info-box" style="max-width:none" role="status"><?= e($flashMessage) ?></div><?php endif; ?>
      <div class="draft-meta">
        <span><?= $draftSavedAt ? 'Renewal draft saved to your account · last saved ' . e(date('M j, Y g:i A', strtotime($draftSavedAt))) : 'Your details below come from your current ID. Your progress is saved as you go.' ?></span>
        <?php if ($draftSavedAt): ?>
          <form method="post" class="m-0" onsubmit="return confirm('Discard this renewal draft? Everything you changed and selected will be deleted.')">
            <?= csrf_field() ?>
            <button type="submit" name="action" value="discard_draft" formnovalidate class="draft-discard">Discard draft</button>
          </form>
        <?php endif; ?>
      </div>

      <div class="step-bar">
        <?php for ($n = 1; $n <= 4; $n++): ?>
          <?php if ($n < $step): ?>
            <form method="post" class="step-item done">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="goto">
              <input type="hidden" name="n" value="<?= $n ?>">
              <button type="submit" style="border:none;background:none;padding:0;width:100%;font-family:inherit;cursor:pointer">
                <div class="step-circle">✓</div>
                <div class="step-label"><?= e($stepMeta[$n][0]) ?></div>
                <div class="step-progress"></div>
              </button>
            </form>
          <?php else: ?>
            <div class="step-item <?= $step === $n ? 'active' : '' ?>">
              <div class="step-circle"><?= $n ?></div>
              <div class="step-label"><?= e($stepMeta[$n][0]) ?></div>
              <div class="step-progress"></div>
            </div>
          <?php endif; ?>
        <?php endfor; ?>
      </div>

      <div class="step-title"><?= e($stepMeta[$step][0]) ?></div>
      <div class="step-desc"><?= e($stepMeta[$step][1]) ?></div>
      <?php if ($submitError): ?><div class="step-alert" role="alert"><?= e($submitError) ?></div><?php endif; ?>
      <?php if ($errors): ?><div class="step-alert" role="alert">Please fix the highlighted <?= count($errors) === 1 ? 'field' : count($errors) . ' fields' ?> before continuing.</div><?php endif; ?>

      <?php if ($step === 4): ?>
        <div class="review-card">
          <div class="review-card-head"><div class="title">Changes from your current ID</div></div>
          <?php if (!$changes): ?>
            <div style="font-size:13px;color:var(--muted)">No changes — your renewal uses the same details as your current ID.</div>
          <?php else: ?>
            <div class="review-grid">
              <?php foreach ($changes as $label => [$old, $new]): ?>
                <div class="review-row"><div class="k"><?= e($label) ?></div><div class="v"><span style="text-decoration:line-through;color:var(--muted)"><?= e($old ?: '—') ?></span> → <b><?= e($new ?: '—') ?></b></div></div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php require $stepViews[$step]; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>
