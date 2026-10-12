<?php
/**
 * @var string $error
 * @var string $success
 */
?>
<div class="portal-container" style="max-width:460px">
  <h1 class="font-heading mb-3" style="font-size:24px">Forgot Password</h1>
  <div class="auth-card">
    <?php if ($error): ?><div class="auth-error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <?php if ($success): ?>
      <div class="info-box" style="max-width:none" role="status"><?= e($success) ?></div>
      <a href="<?= base_url('reset-password.php') ?>" class="btn-navy text-decoration-none text-center d-block">Enter reset code</a>
    <?php else: ?>
      <p style="font-size:14px;color:var(--muted)">Enter your username or the mobile number on your account. We'll text you a 6-digit reset code, valid for <?= (int) PASSWORD_RESET_CODE_MINUTES ?> minutes.</p>
      <form method="post" class="d-flex flex-column gap-3">
        <?= csrf_field() ?>
        <input class="form-control" type="text" name="identifier" aria-label="Username or mobile number" placeholder="Username or mobile number" required>
        <button type="submit" class="btn-navy">Send reset code</button>
      </form>
    <?php endif; ?>
  </div>
  <div style="font-size:13px;margin-top:12px">
    <a href="<?= base_url('login.php') ?>">Back to applicant log in</a> ·
    <a href="<?= base_url('mswd-login.php') ?>">MSWD admin and staff log in</a>
  </div>
</div>
