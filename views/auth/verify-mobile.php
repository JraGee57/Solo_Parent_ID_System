<?php
/**
 * @var array  $user     the signed-in applicant (mobile not yet confirmed)
 * @var string $error
 * @var string $notice   one-time message (code sent, etc.)
 * @var bool   $hasCode  an unexpired code is waiting to be entered
 */
?>
<div class="portal-container" style="max-width:460px">
  <h1 class="font-heading mb-3" style="font-size:24px">Confirm Your Mobile Number</h1>
  <div class="auth-card">
    <p style="font-size:14px;color:var(--muted)">Your account is linked to <b style="color:var(--ink)"><?= e($user['mobile']) ?></b>. MSWDO texts your application updates and password-reset codes to this number, so please confirm it is yours before you continue.</p>
    <?php if ($notice): ?><div class="info-box" style="max-width:none" role="status"><?= e($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="auth-error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" class="d-flex flex-column gap-3">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="verify">
      <label for="verify-code" class="visually-hidden">6-digit code</label>
      <input class="form-control" type="text" id="verify-code" name="code" placeholder="6-digit code" inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="one-time-code" required <?= $hasCode ? 'autofocus' : '' ?>>
      <button type="submit" class="btn-navy">Confirm Number</button>
    </form>
    <form method="post" class="mt-3">
      <?= csrf_field() ?>
      <button type="submit" name="action" value="resend" class="btn-outline w-100"><?= $hasCode ? 'Send a new code' : 'Send me a code' ?></button>
    </form>
    <div style="font-size:12px;color:var(--faint);margin-top:12px">
      A code works for <?= MOBILE_CODE_MINUTES ?> minutes. Wrong number? <a href="<?= base_url('applicant/dashboard.php?section=profile') ?>">Correct it on My Profile</a> and a code goes to the new number. No text arriving? Call the MSWDO office — staff can confirm your number for you.
    </div>
    <form method="post" action="<?= base_url('logout.php') ?>" class="mt-3">
      <?= csrf_field() ?>
      <button type="submit" class="notif-link-btn">Log out</button>
    </form>
  </div>
</div>
