<?php
/**
 * The two mobile numbers on an application, as review-rows: the applicant's
 * confirmed account number (where every SMS about the application goes,
 * see SMSService::recipientFor()) and the number supplied on the
 * application when it was submitted (kept as part of the record). When
 * they are the same number, one row says so.
 *
 * @var array $app findForReview() row (mobile, account_mobile, account_mobile_verified_at)
 */
$confirmedMobile = SMSService::confirmedNumber($app['account_mobile'], $app['account_mobile_verified_at']);
if ($confirmedMobile !== null) {
    $confirmedMobile = SMSService::normalizeNumber($confirmedMobile) ?? $confirmedMobile;
}
$applicationMobile = SMSService::normalizeNumber((string) $app['mobile']) ?? (string) $app['mobile'];
$hint = 'display:block;font-size:12px;color:var(--muted);font-weight:400';
?>
<?php if ($confirmedMobile !== null && $confirmedMobile === $applicationMobile): ?>
<div class="review-row"><div class="k">Mobile number</div><div class="v">
  <?= e($confirmedMobile) ?><span style="<?= $hint ?>">Confirmed on the account and used for SMS notifications; also the number supplied when the application was submitted.</span>
</div></div>
<?php else: ?>
<div class="review-row"><div class="k">Confirmed mobile number</div><div class="v">
  <?php if ($confirmedMobile !== null): ?>
    <?= e($confirmedMobile) ?> <span class="status-pill" style="background:#fdf1d6;color:#8a6d1a;font-size:11px">Changed since submission</span><span style="<?= $hint ?>">Used for SMS notifications</span>
  <?php else: ?>
    None<span style="<?= $hint ?>">No confirmed number on the account; SMS goes to the application mobile number</span>
  <?php endif; ?>
</div></div>
<div class="review-row"><div class="k">Application mobile number</div><div class="v">
  <?= e($applicationMobile ?: '—') ?><span style="<?= $hint ?>">Number supplied when the application was submitted<?= $confirmedMobile !== null ? ' — not used for SMS' : ' — used for SMS until a number is confirmed' ?></span>
</div></div>
<?php endif; ?>
