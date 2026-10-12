<?php
/**
 * The applicant's own profile. Only the fields in the form are editable;
 * username and account status are shown read-only.
 *
 * @var array   $user
 * @var string  $profileError
 * @var string  $profileSuccess
 * @var array   $form               full_name, email, mobile, birthdate, sex, civil_status, barangay, address
 * @var ?string $profileUpdatedAt
 * @var array   $civilStatusOptions
 */
$missing = array_keys(array_filter(['birthdate' => 1, 'sex' => 1, 'civil_status' => 1, 'barangay' => 1, 'address' => 1], fn($_, $k) => $form[$k] === '', ARRAY_FILTER_USE_BOTH));
?>
<link rel="stylesheet" href="<?= public_asset('css/applicant-account.css') ?>">
<div class="profile-page">
      <?php if ($profileError): ?><div class="auth-error mb-3" role="alert"><?= e($profileError) ?></div><?php endif; ?>
      <?php if ($profileSuccess): ?><div class="info-box profile-completion-note" role="status"><?= e($profileSuccess) ?></div><?php endif; ?>

      <?php if ($missing): ?>
        <div class="info-box" style="max-width:none">📝 Complete your profile — these details are filled into your application form for you: <?= e(implode(', ', array_map(fn($k) => str_replace('_', ' ', $k), $missing))) ?>.</div>
      <?php endif; ?>

  <div class="profile-workspace">
    <aside class="profile-sidebar" aria-label="Profile photo and account details">
      <div class="profile-identity"><div class="account-eyebrow">APPLICANT PROFILE</div><h3><?= e($user['full_name']) ?></h3><span>Solo Parent Applicant</span></div>
      <div class="review-card">
        <div class="review-card-head"><div class="title">Profile Photo</div></div>
        <div class="profile-photo-row">
          <?php if ($user['profile_photo']): ?>
            <img src="<?= base_url('avatar.php?user_id=' . (int) $user['id']) ?>" alt="Your profile photo" style="width:72px;height:72px;border-radius:50%;object-fit:cover">
          <?php else: ?>
            <div class="dash-user-avatar" style="width:72px;height:72px;font-size:28px;position:static" aria-hidden="true"><?= e(mb_strtoupper(mb_substr(trim($user['full_name']), 0, 1))) ?></div>
          <?php endif; ?>
          <form method="post" enctype="multipart/form-data" class="profile-photo-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="upload_photo">
            <label class="btn-doc-choose">
              <?= $user['profile_photo'] ? 'Change Photo' : 'Choose Photo' ?>
              <input type="file" name="photo" accept=".jpg,.jpeg,.png,image/jpeg,image/png" class="visually-hidden" onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()">
            </label>
          </form>
          <?php if ($user['profile_photo']): ?>
            <form method="post" class="m-0" onsubmit="return confirm('Remove your profile photo?');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="remove_photo">
              <button type="submit" class="btn-doc-remove">Remove</button>
            </form>
          <?php endif; ?>
        </div>
        <div style="font-size:12px;color:var(--faint);margin-top:10px">JPG or PNG, max 2MB. Only you and MSWDO staff can see it.</div>
      </div>

      <div class="profile-progress"><div><strong>Personal details</strong><span><?= 5 - count($missing) ?> of 5 filled</span></div><progress max="5" value="<?= 5 - count($missing) ?>" aria-label="Personal profile fields filled"></progress><p>Saved profile details prefill new applications.</p></div>
      <div class="review-card">
        <div class="review-card-head"><div class="title">Account</div></div>
        <div class="review-grid">
          <div class="review-row"><div class="k">Username</div><div class="v"><?= e($user['username']) ?> <span style="color:var(--faint)">(cannot be changed)</span></div></div>
          <div class="review-row"><div class="k">Account type</div><div class="v">Solo Parent</div></div>
        </div>
      </div>

    </aside>
    <div class="profile-main">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_info">

        <div class="review-card">
          <div class="review-card-head"><div class="title">Personal Information</div></div>
          <div class="field-grid">
            <label class="field-label field-full">
              <span>Full name *</span>
              <input type="text" name="full_name" value="<?= e($form['full_name']) ?>" maxlength="150" autocomplete="name" required>
            </label>
            <label class="field-label">
              <span>Birthdate</span>
              <input type="date" name="birthdate" value="<?= e($form['birthdate']) ?>" min="1900-01-01" max="<?= e(today_iso()) ?>">
            </label>
            <label class="field-label">
              <span>Sex</span>
              <select name="sex">
                <option value="">Select</option>
                <?php foreach (['Female', 'Male'] as $s): ?>
                  <option value="<?= e($s) ?>" <?= $form['sex'] === $s ? 'selected' : '' ?>><?= e($s) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="field-label field-full">
              <span>Civil status</span>
              <select name="civil_status">
                <option value="">Select civil status</option>
                <?php foreach ($civilStatusOptions as $c): ?>
                  <option value="<?= e($c) ?>" <?= $form['civil_status'] === $c ? 'selected' : '' ?>><?= e($c) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          </div>
        </div>

        <div class="review-card">
          <div class="review-card-head"><div class="title">Contact &amp; Address</div></div>
          <div class="field-grid">
            <label class="field-label">
              <span>Email address (optional)</span>
              <input type="email" name="email" value="<?= e($form['email']) ?>" maxlength="150" autocomplete="email">
            </label>
            <label class="field-label">
              <span>Mobile number *</span>
              <input type="tel" name="mobile" value="<?= e($form['mobile']) ?>" placeholder="09XXXXXXXXX" inputmode="tel" autocomplete="tel" required>
            </label>
            <label class="field-label">
              <span>Barangay</span>
              <input type="text" name="barangay" value="<?= e($form['barangay']) ?>" placeholder="e.g. Concepcion" maxlength="100">
            </label>
            <label class="field-label">
              <span>Complete address</span>
              <input type="text" name="address" value="<?= e($form['address']) ?>" placeholder="House no., street, purok" maxlength="255" autocomplete="street-address">
            </label>
          </div>
          <div style="font-size:12px;color:var(--faint);margin-top:4px">Your mobile number receives SMS updates and password-reset codes, and you can sign in with it. It must be unique to your account; a new number is confirmed with a texted code.</div>
        </div>

        <div class="profile-save-row">
          <button type="submit" class="btn-navy">Save Profile</button>
          <?php if ($profileUpdatedAt): ?><span style="font-size:12px;color:var(--faint)">Last updated <?= e(date('M j, Y g:i A', strtotime($profileUpdatedAt))) ?></span><?php endif; ?>
        </div>
      </form>

      <div class="review-card" id="password">
        <div class="review-card-head"><div class="title">Change Password</div></div>
        <?php if (!empty($user['must_change_password'])): ?>
          <div class="auth-error mb-3" role="alert">MSWDO reset your access. Enter the temporary password you were given as your current password, then choose a new one to continue.</div>
        <?php endif; ?>
        <form method="post" class="profile-password-form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="change_password">
          <label class="field-label"><span>Current password</span><input class="form-control" type="password" name="current_password" aria-label="Current password" placeholder="Current password" autocomplete="current-password" required></label>
          <label class="field-label"><span>New password</span><input class="form-control" type="password" name="new_password" aria-label="New password" placeholder="New password" minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password" required></label>
          <label class="field-label"><span>Confirm new password</span><input class="form-control" type="password" name="new_password_confirm" aria-label="Confirm new password" placeholder="Confirm new password" minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password" required></label>
          <div style="font-size:12px;color:var(--faint)">At least <?= PASSWORD_MIN_LENGTH ?> characters, with at least one letter and one number.</div>
          <button type="submit" class="btn-outline" style="align-self:flex-start">Update Password</button>
        </form>
      </div>
    </div>
  </div>
</div>
