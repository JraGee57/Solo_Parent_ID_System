<?php
/**
 * The signed-in staff member's or administrator's own account.
 *
 * @var array  $staff
 * @var string $ownDashboard
 * @var string $error
 * @var string $success
 * @var array  $roleLabels
 */
?>
<div class="portal-container" style="max-width:640px">
  <a href="<?= e($ownDashboard) ?>" class="staff-back-link"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5m7-7-7 7 7 7"/></svg><span>Back</span></a>
  <h1 class="font-heading mt-2 mb-3" style="font-size:26px">My Account</h1>

  <?php if (!empty($staff['must_change_password'])): ?>
    <div class="auth-error mb-3" role="alert">An administrator reset your access. Choose a new password to continue using the system.</div>
  <?php endif; ?>
  <?php if ($error): ?><div class="auth-error mb-3" role="alert"><?= e($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="info-box" style="max-width:none" role="status"><?= e($success) ?></div><?php endif; ?>

  <div class="review-card">
    <div class="review-card-head"><div class="title">Account details</div></div>
    <div class="review-grid">
      <div class="review-row"><div class="k">Name</div><div class="v"><?= e($staff['full_name']) ?></div></div>
      <div class="review-row"><div class="k">Username</div><div class="v"><?= e($staff['username']) ?></div></div>
      <div class="review-row"><div class="k">Role</div><div class="v"><?= e($roleLabels[$staff['role']] ?? $staff['role']) ?></div></div>
      <div class="review-row"><div class="k">Email</div><div class="v"><?= e($staff['email']) ?></div></div>
      <div class="review-row"><div class="k">Mobile</div><div class="v"><?= e($staff['mobile'] ?: '—') ?></div></div>
      <div class="review-row"><div class="k">Last sign-in</div><div class="v"><?= $staff['last_login_at'] ? e(date('M j, Y g:i A', strtotime($staff['last_login_at']))) : '—' ?></div></div>
    </div>
    <div style="font-size:12px;color:var(--faint);margin-top:10px">To change your name, email or mobile number, ask an administrator.</div>
  </div>

  <div class="review-card" id="password">
    <div class="review-card-head"><div class="title">Change password</div></div>
    <form method="post" class="d-flex flex-column gap-2" style="max-width:380px">
      <?= csrf_field() ?>
      <label class="user-label">Current password<input class="form-control" type="password" name="current_password" autocomplete="current-password" required></label>
      <label class="user-label">New password<input class="form-control" type="password" name="new_password" minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password" required></label>
      <label class="user-label">Confirm new password<input class="form-control" type="password" name="new_password_confirm" minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password" required></label>
      <div style="font-size:12px;color:var(--faint)">At least <?= PASSWORD_MIN_LENGTH ?> characters, with at least one letter and one number.</div>
      <button type="submit" class="btn-navy" style="align-self:flex-start">Update Password</button>
    </form>
  </div>
</div>
