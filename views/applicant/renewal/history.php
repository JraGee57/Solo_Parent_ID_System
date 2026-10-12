<?php
/**
 * @var array $renewalHistory
 * @var array $referenceById  application id => reference number (for "renews ...")
 */
?>
        <?php if (!$renewalHistory): ?>
          <div class="dash-card">
            <div style="font-size:14px;color:var(--muted)">No renewal requests on file yet.</div>
          </div>
        <?php else: ?>
          <?php foreach ($renewalHistory as $r): $st = status_style($r['status']); ?>
            <div class="app-card">
              <div>
                <div class="app-ref"><?= e($r['reference_no']) ?></div>
                <div class="app-date">Submitted <?= e(date('M j, Y', strtotime($r['submitted_at']))) ?><?= $r['renewal_of_id'] && isset($referenceById[$r['renewal_of_id']]) ? ' · renews ' . e($referenceById[$r['renewal_of_id']]) : '' ?><?= $r['id_expires_at'] ? ' · valid until ' . e(format_date_long($r['id_expires_at'])) : '' ?></div>
              </div>
              <div class="status-pill" style="background:<?= e($st['bg']) ?>;color:<?= e($st['color']) ?>"><?= e($r['status']) ?></div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
