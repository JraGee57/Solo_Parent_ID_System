<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/csrf.php';
require __DIR__ . '/controllers/AuthController.php';

$authController = new AuthController($pdo);
$error = $authController->handleStaffLogin();
$notice = $authController->takeNotice();

$pageTitle = 'MSWD Admin & Staff Login';
require __DIR__ . '/includes/header.php';
?>
<div class="portal-container" style="max-width:420px">
  <h1 class="font-heading mb-3" style="font-size:24px">MSWD Admin & Staff Login</h1>
  <div class="auth-card">
    <div class="d-flex gap-2 mb-3">
      <a href="<?= base_url('mswd-login.php?portal=staff') ?>" class="auth-tab text-decoration-none text-center <?= AUTH_PORTAL === 'staff' ? 'active' : '' ?>"<?= AUTH_PORTAL === 'staff' ? ' aria-current="page"' : '' ?>>Staff</a>
      <a href="<?= base_url('mswd-login.php?portal=admin') ?>" class="auth-tab text-decoration-none text-center <?= AUTH_PORTAL === 'admin' ? 'active' : '' ?>"<?= AUTH_PORTAL === 'admin' ? ' aria-current="page"' : '' ?>>Admin</a>
    </div>
    <?php if ($notice): ?><div class="info-box" style="max-width:none"><?= e($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="auth-error"><?= e($error) ?></div><?php endif; ?>
    <p style="font-size:14px;color:var(--muted)">Sign in with your MSWD <?= AUTH_PORTAL === 'admin' ? 'administrator' : 'staff' ?> account. Each portal stays signed in separately.</p>
    <form method="post" action="<?= base_url('mswd-login.php') ?>" class="d-flex flex-column gap-3">
      <?= csrf_field() ?>
      <input class="form-control" type="text" name="username" aria-label="Username" placeholder="Username" autocomplete="username" required>
      <input class="form-control" type="password" name="password" aria-label="Password" placeholder="Password" autocomplete="current-password" required>
      <button type="submit" class="btn-navy">Log In</button>
    </form>
    <a href="<?= base_url('forgot-password.php') ?>" style="display:inline-block;font-size:13px;margin-top:12px">Forgot password?</a>
  </div>
  <div style="font-size:12px;color:var(--faint);margin-top:12px">For MSWDO Virac administrators and staff. Applicants use <a href="<?= base_url('login.php') ?>">Applicant Log In</a>.</div>
</div>
<?php
$footerVariant = 'portal';
require __DIR__ . '/includes/footer.php';
