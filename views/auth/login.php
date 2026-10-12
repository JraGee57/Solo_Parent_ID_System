<?php
/**
 * Combined Log In / Create Account card. Rendered both inside the portal
 * tab (includes/portal/login.php) and by the standalone login.php /
 * register.php, which pass their own tab URLs.
 *
 * @var string $authMode    'login' | 'signup'
 * @var string  $authError
 * @var ?string $authNotice  one-time notice, e.g. session expired
 * @var array   $oldSignup
 * @var string $loginTabUrl
 * @var string $signupTabUrl
 */
$returnKey = $_SESSION['applicant_return'] ?? null;
if (applicant_return_path($returnKey)) {
    $loginTabUrl = base_url('login.php?next=' . rawurlencode($returnKey));
    $signupTabUrl = base_url('register.php?next=' . rawurlencode($returnKey));
}
?>
<div class="auth-card">
  <div class="d-flex gap-2 mb-3">
    <a href="<?= e($loginTabUrl) ?>" class="auth-tab text-decoration-none text-center <?= $authMode === 'login' ? 'active' : '' ?>"<?= $authMode === 'login' ? ' aria-current="page"' : '' ?>>Log In</a>
    <a href="<?= e($signupTabUrl) ?>" class="auth-tab text-decoration-none text-center <?= $authMode === 'signup' ? 'active' : '' ?>"<?= $authMode === 'signup' ? ' aria-current="page"' : '' ?>>Create Account</a>
  </div>

  <?php if (!empty($authNotice)): ?>
    <div class="info-box" style="max-width:none" role="status"><?= e($authNotice) ?></div>
  <?php endif; ?>
  <?php if ($authError): ?>
    <div class="auth-error" role="alert"><?= e($authError) ?></div>
  <?php endif; ?>

  <?php if ($authMode === 'signup'): ?>
    <?php require __DIR__ . '/register.php'; ?>
  <?php else: ?>
    <p style="font-size:14px;color:var(--muted)">Applicant Log In &mdash; use your public applicant account.</p>
    <form method="post" class="d-flex flex-column gap-3">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="login">
      <label class="user-label">Username or mobile number
        <input class="form-control" type="text" name="username" autocomplete="username" required>
      </label>
      <label class="user-label">Password
        <input class="form-control" type="password" name="password" autocomplete="current-password" required>
      </label>
      <button type="submit" class="btn-navy">Log In</button>
    </form>
    <a href="<?= base_url('forgot-password.php') ?>" style="display:inline-block;font-size:13px;margin-top:12px">Forgot password?</a>
    <div style="font-size:13px;margin-top:12px">MSWD personnel? <a href="<?= base_url('mswd-login.php') ?>">Admin and Staff Log In</a></div>
  <?php endif; ?>
</div>
