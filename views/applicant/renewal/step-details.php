<?php
/**
 * Renewal step 1: details pre-filled from the ID being renewed. Identity
 * fields are shown locked; contact details and civil status can be updated.
 *
 * @var array $draft
 * @var array $errors
 * @var array $renewalSource
 */
$invalid = fn(string $key) => isset($errors[$key]) ? 'is-invalid' : '';
?>
<div class="locked-fields">
  <div class="locked-title">From your current ID <span>(can't be changed in a renewal — visit MSWDO if any of these is wrong)</span></div>
  <div class="review-grid">
    <div class="review-row"><div class="k">Full name</div><div class="v"><?= e($draft['fullName']) ?></div></div>
    <div class="review-row"><div class="k">Birthdate</div><div class="v"><?= e(format_date_long($draft['birthdate'])) ?></div></div>
    <div class="review-row"><div class="k">Sex</div><div class="v"><?= e($draft['sex']) ?></div></div>
    <div class="review-row"><div class="k">Solo parent category</div><div class="v"><?= e($renewalSource['category_name']) ?></div></div>
  </div>
</div>
<?php foreach (['fullName', 'birthdate', 'sex', 'circumstance'] as $k): if (!empty($errors[$k])): ?>
  <div class="step-alert"><?= e($errors[$k]) ?> Please contact the MSWDO office to correct your record.</div>
<?php endif; endforeach; ?>

<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="continue">
  <div class="field-grid">
    <label class="field-label">
      <span>Barangay *</span>
      <input type="text" name="barangay" maxlength="100" value="<?= e($draft['barangay']) ?>" class="<?= $invalid('barangay') ?>">
      <?php if (!empty($errors['barangay'])): ?><span class="field-error"><?= e($errors['barangay']) ?></span><?php endif; ?>
    </label>
    <label class="field-label">
      <span>Complete address *</span>
      <input type="text" name="address" maxlength="255" value="<?= e($draft['address']) ?>" autocomplete="street-address" class="<?= $invalid('address') ?>">
      <?php if (!empty($errors['address'])): ?><span class="field-error"><?= e($errors['address']) ?></span><?php endif; ?>
    </label>
    <label class="field-label">
      <span>Mobile number *</span>
      <input type="tel" value="<?= e($draft['contact']) ?>" readonly aria-readonly="true" class="<?= $invalid('contact') ?>">
      <span class="field-hint">Your confirmed account number — application updates are texted here. To use a different number, <a href="<?= base_url('applicant/dashboard.php?section=profile') ?>">change it on My Profile</a>.</span>
      <?php if (!empty($errors['contact'])): ?><span class="field-error"><?= e($errors['contact']) ?></span><?php endif; ?>
    </label>
    <label class="field-label">
      <span>Email *</span>
      <input type="email" name="email" maxlength="150" value="<?= e($draft['email']) ?>" autocomplete="email" class="<?= $invalid('email') ?>">
      <?php if (!empty($errors['email'])): ?><span class="field-error"><?= e($errors['email']) ?></span><?php endif; ?>
    </label>
    <label class="field-label field-full">
      <span>Civil status *</span>
      <select name="civilStatus" class="<?= $invalid('civilStatus') ?>">
        <option value="">Select civil status</option>
        <?php foreach (CIVIL_STATUS_OPTIONS as $c): ?>
          <option value="<?= e($c) ?>" <?= $draft['civilStatus'] === $c ? 'selected' : '' ?>><?= e($c) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (!empty($errors['civilStatus'])): ?><span class="field-error"><?= e($errors['civilStatus']) ?></span><?php endif; ?>
    </label>
  </div>
  <?php require APP_ROOT . '/views/partials/emergency-contact-fields.php'; ?>
  <div class="form-nav-buttons">
    <button type="submit" name="action" value="save_draft" formnovalidate class="btn-outline">Save &amp; finish later</button>
    <button type="submit" name="action" value="continue" class="btn-navy">Continue</button>
  </div>
</form>
