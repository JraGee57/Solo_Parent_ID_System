<?php
/**
 * For a renewal: the record it renews (link) and what the applicant changed.
 *
 * @var ?array $renewalOf       the renewed application (with children), or null
 * @var array  $renewalChanges  label => [previous, new]
 * @var array  $staff           the viewer (decides where the link goes)
 */
?>
<?php if ($renewalOf): ?>
  <div class="review-card">
    <div class="review-card-head">
      <div class="title">Renewal of <a href="<?= application_url($staff, (int) $renewalOf['id']) ?>"><?= e($renewalOf['reference_no']) ?></a></div>
      <span style="font-size:12px;color:var(--muted)"><?= e($renewalOf['status']) ?><?= $renewalOf['id_expires_at'] ? ' · expire' . ($renewalOf['id_expires_at'] < date('Y-m-d') ? 'd ' : 's ') . e(format_date_long($renewalOf['id_expires_at'])) : '' ?></span>
    </div>
    <?php if (!$renewalChanges): ?>
      <div style="font-size:13px;color:var(--muted)">No changes from the previous record.</div>
    <?php else: ?>
      <div style="font-size:12px;font-weight:700;color:var(--ink);margin-bottom:4px">Changed since the previous record</div>
      <div class="review-grid">
        <?php foreach ($renewalChanges as $label => [$old, $new]): ?>
          <div class="review-row"><div class="k"><?= e($label) ?></div><div class="v"><span style="text-decoration:line-through;color:var(--muted)"><?= e($old ?: '—') ?></span> → <b><?= e($new ?: '—') ?></b></div></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>
