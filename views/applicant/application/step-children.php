<?php
/** @var array $draft @var array $errors */
?>
<form method="post"<?= !empty($isApplicationWizard) ? ' id="application-step-form"' : '' ?>>
  <?= csrf_field() ?>
  <?php if (!empty($isApplicationWizard)): ?><input type="hidden" name="wizard_step" value="2"><?php endif; ?>
  <input type="hidden" name="action" value="continue">
  <input type="hidden" name="idx" id="remove-idx" value="">

  <?php foreach ($draft['children'] as $i => $child): ?>
    <div class="child-card">
      <div class="child-card-head">
        <div style="font-weight:700;font-size:14px;color:var(--ink)">Child <?= $i + 1 ?></div>
        <?php if (count($draft['children']) > 1): ?>
          <button type="submit" name="action" value="remove_child" formnovalidate onclick="document.getElementById('remove-idx').value='<?= $i ?>'" class="btn-remove-child">Remove</button>
        <?php endif; ?>
      </div>
      <div class="field-grid narrow">
        <label class="field-label">
          <span>Full name *</span>
          <input type="text" name="child_name[]" placeholder="Child's full name" value="<?= e($child['name']) ?>" class="<?= isset($errors['cn' . $i]) ? 'is-invalid' : '' ?>">
          <?php if (!empty($errors['cn' . $i])): ?><span class="field-error"><?= e($errors['cn' . $i]) ?></span><?php endif; ?>
        </label>
        <label class="field-label">
          <span>Birthdate *</span>
          <input type="date" name="child_birthdate[]" max="<?= e(today_iso()) ?>" value="<?= e($child['birthdate']) ?>" class="<?= isset($errors['cd' . $i]) ? 'is-invalid' : '' ?>">
          <?php if (!empty($errors['cd' . $i])): ?><span class="field-error"><?= e($errors['cd' . $i]) ?></span><?php endif; ?>
        </label>
      </div>
    </div>
  <?php endforeach; ?>

  <button type="submit" name="action" value="add_child" formnovalidate class="btn-add-child">+ Add another child</button>

  <div class="form-nav-buttons">
    <?php if (empty($isApplicationWizard)): ?><button type="submit" name="action" value="save_draft" formnovalidate class="btn-outline">Save &amp; finish later</button><?php endif; ?>
    <button type="submit" name="action" value="back" formnovalidate class="btn-outline">&larr; Personal Info</button>
    <button type="submit" name="action" value="continue" class="btn-navy">Continue to Documents &rarr;</button>
  </div>
</form>
