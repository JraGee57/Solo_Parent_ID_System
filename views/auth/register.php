<?php
/**
 * Create Account form, rendered inside views/auth/login.php's card.
 *
 * @var array $oldSignup  fullName, mobile, username
 */
?>
<form method="post" class="d-flex flex-column gap-3">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="signup">
  <label class="user-label">Full name
    <input class="form-control" type="text" name="fullName" placeholder="Juan Dela Cruz" value="<?= e($oldSignup['fullName']) ?>" maxlength="150" autocomplete="name" required>
  </label>
  <label class="user-label">Mobile number
    <input class="form-control" type="tel" name="mobile" placeholder="09XXXXXXXXX" value="<?= e($oldSignup['mobile']) ?>" inputmode="tel" autocomplete="tel" required>
  </label>
  <label class="user-label">Username
    <input class="form-control" type="text" name="username" value="<?= e($oldSignup['username']) ?>" pattern="(?=.*[A-Za-z])[A-Za-z0-9._]{4,30}" title="4 to 30 characters: letters, numbers, dots, or underscores, with at least one letter" autocomplete="username" required>
  </label>
  <label class="user-label">Password
    <input class="form-control" type="password" name="password" minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password" required>
  </label>
  <label class="user-label">Confirm password
    <input class="form-control" type="password" name="password_confirm" minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password" required>
  </label>
  <div style="font-size:12px;color:var(--faint);margin-top:-6px">At least <?= PASSWORD_MIN_LENGTH ?> characters, with at least one letter and one number. Your mobile number is used for SMS updates and password recovery.</div>
  <button type="submit" class="btn-navy">Create Account</button>
  <div style="font-size:12px;color:var(--faint)">Your account is stored securely in the MSWDO Virac database.</div>
</form>
