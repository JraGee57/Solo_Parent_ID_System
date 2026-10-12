<?php
/** @var array $history  application_status_history rows, newest first */
?>
      <div class="review-card">
        <div class="review-card-head"><div class="title">Status History</div></div>
        <?php foreach ($history as $h): ?>
          <div class="mb-2" style="font-size:13px">
            <b><?= e($h['status']) ?></b> — <?= e(date('M j, Y g:i A', strtotime($h['changed_at']))) ?><br>
            <?php if ($h['remarks']): ?><span style="color:var(--muted)"><?= e($h['remarks']) ?></span><br><?php endif; ?>
            <span style="color:var(--faint)">by <?= e($h['changed_by'] ?? 'system') ?></span>
          </div>
        <?php endforeach; ?>
      </div>
