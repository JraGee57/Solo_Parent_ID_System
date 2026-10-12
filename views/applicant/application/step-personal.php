<?php
/** Personal information step. @var array $draft @var array $errors @var array $categories @var array $categoryDocs */
$invalid = fn(string $key) => isset($errors[$key]) ? 'is-invalid' : '';
$fieldAccessibility = function (string $key, string $hintId = '') use ($errors): string {
    $hasError = !empty($errors[$key]);
    $description = trim($hintId . ($hasError ? ' personal-error-' . $key : ''));
    return ($hasError ? ' aria-invalid="true"' : '')
        . ($description !== '' ? ' aria-describedby="' . e($description) . '"' : '');
};
?>
<form method="post" id="application-step-form" class="apply-personal-info">
  <?= csrf_field() ?>
  <input type="hidden" name="wizard_step" value="1">
  <input type="hidden" name="action" value="continue">

  <section class="personal-info-card" aria-labelledby="personal-details-heading">
    <header class="personal-info-card-head">
      <span class="personal-info-section-number" aria-hidden="true">01</span>
      <div><h3 id="personal-details-heading">Personal details</h3><p>Enter your details as they appear on your official records. Valid details also update My Profile when you continue or save your draft.</p></div>
    </header>
    <div class="field-grid personal-info-grid">
      <label class="field-label field-full">
        <span>Full name <span class="personal-required">*</span></span>
        <input type="text" name="fullName" placeholder="e.g. Juan Dela Cruz" value="<?= e($draft['fullName']) ?>" autocomplete="name" class="<?= $invalid('fullName') ?>"<?= $fieldAccessibility('fullName') ?>>
        <?php if (!empty($errors['fullName'])): ?><span class="field-error" id="personal-error-fullName"><?= e($errors['fullName']) ?></span><?php endif; ?>
      </label>
      <label class="field-label">
        <span>Birthdate <span class="personal-required">*</span></span>
        <input type="date" name="birthdate" value="<?= e($draft['birthdate']) ?>" max="<?= e(today_iso()) ?>" autocomplete="bday" class="<?= $invalid('birthdate') ?>"<?= $fieldAccessibility('birthdate') ?>>
        <?php if (!empty($errors['birthdate'])): ?><span class="field-error" id="personal-error-birthdate"><?= e($errors['birthdate']) ?></span><?php endif; ?>
      </label>
      <label class="field-label">
        <span>Sex <span class="personal-required">*</span></span>
        <select name="sex" class="<?= $invalid('sex') ?>"<?= $fieldAccessibility('sex') ?>>
          <option value="">Select sex</option>
          <option value="Female" <?= $draft['sex'] === 'Female' ? 'selected' : '' ?>>Female</option>
          <option value="Male" <?= $draft['sex'] === 'Male' ? 'selected' : '' ?>>Male</option>
        </select>
        <?php if (!empty($errors['sex'])): ?><span class="field-error" id="personal-error-sex"><?= e($errors['sex']) ?></span><?php endif; ?>
      </label>
      <label class="field-label">
        <span>Civil status <span class="personal-required">*</span></span>
        <select name="civilStatus" class="<?= $invalid('civilStatus') ?>"<?= $fieldAccessibility('civilStatus') ?>>
          <option value="">Select civil status</option>
          <?php foreach (CIVIL_STATUS_OPTIONS as $c): ?>
            <option value="<?= e($c) ?>" <?= $draft['civilStatus'] === $c ? 'selected' : '' ?>><?= e($c) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if (!empty($errors['civilStatus'])): ?><span class="field-error" id="personal-error-civilStatus"><?= e($errors['civilStatus']) ?></span><?php endif; ?>
      </label>
    </div>
  </section>

  <section class="personal-info-card" aria-labelledby="personal-contact-heading">
    <header class="personal-info-card-head">
      <span class="personal-info-section-number" aria-hidden="true">02</span>
      <div><h3 id="personal-contact-heading">Contact &amp; address</h3><p>Use your current contact details and residential address.</p></div>
    </header>
    <div class="field-grid personal-info-grid">
      <label class="field-label">
        <span>Mobile number <span class="personal-required">*</span><span class="personal-account-tag">Account number</span></span>
        <input type="tel" value="<?= e($draft['contact']) ?>" readonly aria-readonly="true" class="<?= $invalid('contact') ?>"<?= $fieldAccessibility('contact', 'personal-contact-hint') ?>>
        <span class="field-hint" id="personal-contact-hint">SMS updates go to this number. To change it, visit <a href="<?= base_url('applicant/dashboard.php?section=profile') ?>">My Profile</a>.</span>
        <?php if (!empty($errors['contact'])): ?><span class="field-error" id="personal-error-contact"><?= e($errors['contact']) ?></span><?php endif; ?>
      </label>
      <label class="field-label">
        <span>Email address (optional)</span>
        <input type="email" name="email" placeholder="name@email.com" value="<?= e($draft['email']) ?>" maxlength="150" autocomplete="email" class="<?= $invalid('email') ?>"<?= $fieldAccessibility('email', 'personal-email-hint') ?>>
        <span class="field-hint" id="personal-email-hint">You can leave this blank. Application updates are sent to your mobile number.</span>
        <?php if (!empty($errors['email'])): ?><span class="field-error" id="personal-error-email"><?= e($errors['email']) ?></span><?php endif; ?>
      </label>
      <label class="field-label">
        <span>Barangay <span class="personal-required">*</span></span>
        <input type="text" name="barangay" placeholder="e.g. Concepcion" value="<?= e($draft['barangay']) ?>" class="<?= $invalid('barangay') ?>"<?= $fieldAccessibility('barangay') ?>>
        <?php if (!empty($errors['barangay'])): ?><span class="field-error" id="personal-error-barangay"><?= e($errors['barangay']) ?></span><?php endif; ?>
      </label>
      <label class="field-label">
        <span>Complete address <span class="personal-required">*</span></span>
        <input type="text" name="address" placeholder="House no., street, purok" value="<?= e($draft['address']) ?>" autocomplete="street-address" class="<?= $invalid('address') ?>"<?= $fieldAccessibility('address') ?>>
        <?php if (!empty($errors['address'])): ?><span class="field-error" id="personal-error-address"><?= e($errors['address']) ?></span><?php endif; ?>
      </label>
    </div>
  </section>

  <?php require APP_ROOT . '/views/partials/emergency-contact-fields.php'; ?>

  <section class="personal-info-card personal-info-category" aria-labelledby="personal-category-heading">
    <header class="personal-info-card-head">
      <span class="personal-info-section-number" aria-hidden="true">03</span>
      <div><h3 id="personal-category-heading">Solo parent category</h3><p>Select the category that best describes your circumstances.</p></div>
    </header>
    <label class="field-label">
      <span>Category <span class="personal-required">*</span></span>
      <select name="categoryId" id="circumstance-select" class="<?= $invalid('circumstance') ?>"<?= $fieldAccessibility('circumstance', 'personal-category-hint') ?>>
        <option value="" <?= empty($draft['categoryId']) || !isset($categoryMap[$draft['categoryId']]) ? 'selected' : '' ?>>Select your category</option>
        <?php foreach ($categories as $c): ?>
          <option value="<?= (int) $c['id'] ?>" <?= (int) $draft['categoryId'] === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <span class="field-hint" id="personal-category-hint">Your category determines the additional documents required for your application.</span>
      <?php if (!empty($errors['circumstance'])): ?><span class="field-error" id="personal-error-circumstance"><?= e($errors['circumstance']) ?></span><?php endif; ?>
    </label>
    <?php if (!empty($legacyCategoryName)): ?><div class="info-box personal-category-notice" role="note">Your saved draft used the retired category “<?= e($legacyCategoryName) ?>”. Select one of the current categories to continue; the rest of your saved draft is preserved.</div><?php endif; ?>
    <div id="category-doc-box" class="info-box personal-category-notice" style="display:none" aria-live="polite"></div>
    <a href="<?= base_url('applicant/dashboard.php?section=requirements') ?>" class="personal-requirements-guide-link" target="_blank" rel="noopener">How to get your requirements</a>
  </section>

  <div class="form-nav-buttons personal-info-actions">
    <button type="submit" name="action" value="continue" class="btn-navy">Continue to Children <span aria-hidden="true">&rarr;</span></button>
  </div>
</form>

<dialog id="application-requirement-dialog" class="application-requirement-dialog" aria-labelledby="application-requirement-title">
  <div class="application-requirement-toolbar">
    <div><h2 id="application-requirement-title">Requirements Guide</h2><p>Your application stays open while you read.</p></div>
    <button type="button" class="btn-navy" id="application-requirement-back">&larr; Back to application</button>
  </div>
  <div id="application-requirement-content" class="application-requirement-content" aria-busy="false"></div>
</dialog>

<script>
  window.CATEGORY_DOCS = <?= json_encode($categoryDocs, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
