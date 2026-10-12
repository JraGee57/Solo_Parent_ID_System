<?php
/** Editable fields inside the current Personal Info/renewal form. */
$emergencyFields = [
    'emergencyName' => ['Full name', 'text', 150, 'e.g. Maria Santos'],
    'emergencyRelation' => ['Relationship to you', 'text', 80, 'e.g. Mother, sibling, friend'],
    'emergencyPhone' => ['Contact number', 'tel', 30, 'e.g. 09171234567'],
    'emergencyAddress' => ['Complete address', 'text', 255, 'House no., street, barangay, municipality'],
];
?>
<section class="personal-info-card" aria-labelledby="emergency-contact-heading">
  <header class="personal-info-card-head">
    <div><h3 id="emergency-contact-heading">Emergency contact</h3><p>In case of emergency, you may contact the following person. This section is optional; if you add a contact, provide their name and contact number. These details appear on the back of your ID.</p></div>
  </header>
  <div class="field-grid personal-info-grid">
    <?php foreach ($emergencyFields as $key => [$label, $type, $limit, $placeholder]): $hasError = !empty($errors[$key]); ?>
      <label class="field-label<?= $key === 'emergencyAddress' ? ' field-full' : '' ?>">
        <span><?= e($label) ?></span>
        <input type="<?= e($type) ?>" name="<?= e($key) ?>" maxlength="<?= $limit ?>" placeholder="<?= e($placeholder) ?>" value="<?= e($draft[$key] ?? '') ?>" class="<?= $hasError ? 'is-invalid' : '' ?>" autocomplete="off"<?= $hasError ? ' aria-invalid="true" aria-describedby="error-' . e($key) . '"' : '' ?>>
        <?php if ($hasError): ?><span class="field-error" id="error-<?= e($key) ?>"><?= e($errors[$key]) ?></span><?php endif; ?>
      </label>
    <?php endforeach; ?>
  </div>
</section>
