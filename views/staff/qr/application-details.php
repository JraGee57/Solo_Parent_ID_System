<?php
/** Submitted information only. The ID design is reserved for released IDs. */
$submitted = $verificationCard ?? null;
$applicationRows = [
    'Name' => $a['full_name'],
    'Reference' => $a['reference_no'],
    'Type' => $a['type'] === 'renewal' ? 'ID Renewal' : 'New Solo Parent ID',
    'Category' => $a['category_name'],
];
if ($submitted) {
    $applicationRows += [
        'Birthdate' => format_date_long($submitted['birthdate']),
        'Sex' => $submitted['sex'],
        'Civil status' => $submitted['civil_status'],
        'Address' => $submitted['address'] . ', Brgy. ' . $submitted['barangay'],
        'Mobile number' => $submitted['mobile'],
        'Email' => $submitted['email'] ?: 'Not provided',
    ];
}
?>
<div class="qr-result-grid">
  <p style="font-size:13px;font-weight:700;margin:0 0 12px"><?= $a['status'] === 'Approved' ? 'Approved application — awaiting ID release' : 'Application information — ID not released' ?></p>
  <div class="review-grid">
    <?php foreach ($applicationRows as $label => $value): ?>
      <div class="review-row"><div class="k"><?= e($label) ?></div><div class="v"><?= e($value) ?></div></div>
    <?php endforeach; ?>
    <div class="review-row"><div class="k">Status</div><div class="v"><span class="status-pill" style="background:<?= e($st['bg']) ?>;color:<?= e($st['color']) ?>"><?= e($a['status']) ?></span></div></div>
    <?php if ($submitted): ?>
      <div class="review-row"><div class="k">Submitted</div><div class="v"><?= e(date('M j, Y', strtotime($submitted['submitted_at']))) ?></div></div>
      <?php foreach ($submitted['children'] ?? [] as $i => $child): ?><div class="review-row"><div class="k">Child <?= $i + 1 ?></div><div class="v"><?= e($child['full_name']) ?> &middot; <?= e(format_date_long($child['birthdate'])) ?></div></div><?php endforeach; ?>
      <?php foreach (['emergency_contact_name' => 'Emergency contact', 'emergency_contact_relation' => 'Relationship', 'emergency_contact_phone' => 'Emergency number', 'emergency_contact_address' => 'Emergency address'] as $key => $label): if (!empty($submitted[$key])): ?><div class="review-row"><div class="k"><?= e($label) ?></div><div class="v"><?= e($submitted[$key]) ?></div></div><?php endif; endforeach; ?>
    <?php endif; ?>
    <?php if ($a['appointment']): ?><div class="review-row"><div class="k">Appointment</div><div class="v"><?= e(date('M j, Y g:i A', strtotime($a['appointment']['scheduled_at']))) ?> &middot; <?= e($a['appointment']['location']) ?></div></div><?php endif; ?>
    <?php if ($outcome['slip']): ?><div class="review-row"><div class="k">Slip issued</div><div class="v"><?= e(date('M j, Y', strtotime($outcome['slip']['issued_at']))) ?> &middot; <?= e(QrSlipService::slipCode($outcome['slip']['token'])) ?></div></div><?php endif; ?>
  </div>
</div>
