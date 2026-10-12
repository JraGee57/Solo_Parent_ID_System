<?php
/**
 * @var ?array  $idApplication
 * @var ?array  $idExpiryInfo
 * @var ?string $idQrUri     QR of the ID's secure slip token (null if the QR library isn't installed)
 * @var ?string $idSlipCode
 * @var ?array  $latestApplication
 */
?>
      <?php if ($idApplication && $idApplication['status'] === 'ID Released'): $st = status_style($idApplication['status']); ?>
        <div class="slip-card" style="margin:0 0 24px">
          <img src="<?= asset('images/solo-parent.png') ?>" alt="Virac Solo Parent ID" style="height:56px;object-fit:contain;margin-bottom:8px">
          <div style="font-size:13px;color:var(--muted)">MSWDO Virac — Solo Parent ID</div>
          <div class="ref"><?= e($idApplication['reference_no']) ?></div>

          <?php if ($idQrUri): ?>
            <img src="<?= e($idQrUri) ?>" alt="QR code for MSWDO verification" class="slip-qr">
          <?php endif; ?>
          <?php if ($idSlipCode): ?><div class="slip-code">Slip code: <b><?= e($idSlipCode) ?></b></div><?php endif; ?>

          <div class="text-start mt-3" style="font-size:14px">
            <div class="mb-1"><b>Applicant:</b> <?= e($idApplication['full_name']) ?></div>
            <div class="mb-1"><b>Category:</b> <?= e($idApplication['category_name']) ?></div>
            <div class="mb-1"><b>Status:</b> <span class="status-pill" style="background:<?= e($st['bg']) ?>;color:<?= e($st['color']) ?>"><?= e($idApplication['status']) ?></span></div>
            <?php if ($idExpiryInfo): ?>
              <div class="mb-3"><b>Expires:</b> <?= e(format_date_long($idExpiryInfo['date'])) ?><?php if ($idExpiryInfo['expired']): ?> <span class="status-pill" style="background:#fde8e8;color:#a51c1c">Expired</span><?php endif; ?></div>
            <?php endif; ?>
          </div>

          <a href="<?= base_url('applicant/dashboard.php?section=slip&ref=' . urlencode($idApplication['reference_no'])) ?>" target="_blank" class="btn-navy text-decoration-none">View Full Slip</a>
        </div>
      <?php elseif ($latestApplication): $st = status_style($latestApplication['status']); ?>
        <div class="dash-card">
          <div class="dash-card-label">ID Not Yet Issued</div>
          <div style="font-size:14px;color:var(--muted);margin-bottom:14px">
            Your application <b><?= e($latestApplication['reference_no']) ?></b> is currently
            <span class="status-pill" style="background:<?= e($st['bg']) ?>;color:<?= e($st['color']) ?>"><?= e($latestApplication['status']) ?></span>.
            Your Solo Parent ID will appear here once it's approved and released by MSWDO.
          </div>
          <a href="?section=application" class="btn-outline text-decoration-none">View Application</a>
        </div>
      <?php else: ?>
        <div class="dash-card">
          <div class="dash-card-label">No Solo Parent ID Yet</div>
          <div style="font-size:14px;color:var(--muted);margin:8px 0 14px">Apply for your Solo Parent ID to get started.</div>
          <a href="<?= base_url('applicant/dashboard.php?section=apply') ?>" class="btn-red text-decoration-none">Apply Online Now</a>
        </div>
      <?php endif; ?>
