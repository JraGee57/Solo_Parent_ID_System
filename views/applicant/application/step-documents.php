<?php
/** @var array $draft @var array $errors @var array $docDefs @var array $catOnlyKeys */
$standardDocs = array_values(array_filter($docDefs, fn($d) => !in_array($d['doc_key'], $catOnlyKeys, true)));
$categoryDocs = array_values(array_filter($docDefs, fn($d) => in_array($d['doc_key'], $catOnlyKeys, true)));
$groups = [['title' => 'Standard requirements', 'items' => $standardDocs]];
if ($categoryDocs) {
    $groups[] = ['title' => 'Category-specific requirements', 'items' => $categoryDocs];
}
?>
<form method="post" class="apply-step-form"<?= !empty($isApplicationWizard) ? ' id="application-step-form"' : '' ?>>
  <?= csrf_field() ?>
  <?php if (!empty($isApplicationWizard)): ?><input type="hidden" name="wizard_step" value="3"><?php endif; ?>
  <div class="info-box apply-checklist-notice" role="note">Checking a document means you have indicated that it is available. It does not mean the MSWDO has verified it.</div>
  <section class="checklist-card" aria-labelledby="checklist-heading">
    <header class="checklist-card-head">
      <h3 id="checklist-heading">Required Documents Checklist</h3>
      <p>Select the documents you already have. Fields marked <b>*</b> are required.</p>
    </header>
    <?php foreach ($groups as $group): if (!$group['items']) { continue; } ?>
      <section class="checklist-group" aria-label="<?= e($group['title']) ?>">
        <h4><?= e($group['title']) ?></h4>
        <?php foreach ($group['items'] as $d): $key = $d['doc_key']; ?>
          <label class="checklist-row">
            <span class="checklist-row-label">
              <span class="checklist-document-name"><?= e($d['label']) ?></span>
              <span class="checklist-requirement"><?= !empty($d['is_required']) ? 'Required' : 'Optional' ?></span>
            </span>
            <input class="checklist-checkbox" type="checkbox" name="checklist[]" value="<?= e($key) ?>" <?= !empty($draft['checkedDocuments'][$key]) ? 'checked' : '' ?> aria-label="I have <?= e($d['label']) ?>">
          </label>
        <?php endforeach; ?>
      </section>
    <?php endforeach; ?>
  </section>
  <div class="form-nav-buttons">
    <?php if (empty($isApplicationWizard)): ?><button type="submit" name="action" value="save_draft" formnovalidate class="btn-outline">Save &amp; finish later</button><?php endif; ?>
    <button type="submit" name="action" value="back" formnovalidate class="btn-outline">&larr; Children</button>
    <button type="submit" name="action" value="continue" class="btn-navy">Continue to Review &rarr;</button>
  </div>
</form>
