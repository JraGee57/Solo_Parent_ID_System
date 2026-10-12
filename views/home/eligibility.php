<?php
/**
 * Eligibility questions. Standalone on eligibility.php; also embedded in
 * the applicant dashboard with $standalone = false.
 *
 * @var array  $answers
 * @var string $error
 * @var array  $circumstances
 * @var string $noneOption
 * @var string $formAction
 * @var bool   $standalone
 */
$standalone = $standalone ?? true;
$yesNo = ['Yes', 'No', $unsureOption ?? EligibilityService::UNSURE_OPTION];
?>
<?php if ($standalone): ?>
<div class="portal-container">
  <a href="<?= base_url('portal.php') ?>" class="public-back-link"><svg aria-hidden="true" focusable="false" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 5-7 7 7 7M5 12h14"/></svg><span>Back to Home</span></a>
<?php endif; ?>
  <div class="elig-panel">
    <?php $headingTag = !empty($embedded) ? 'h2' : 'h1'; ?><<?= $headingTag ?> class="font-heading mb-1" style="font-size:22px;color:var(--ink)">Eligibility Checker</<?= $headingTag ?>>
    <div style="font-size:14px;color:var(--muted);margin-bottom:22px">Answer three quick questions to get a <b>preliminary</b> result on whether you may qualify for a Solo Parent ID. No account or personal details needed. This is a guide only &mdash; final eligibility is decided by the MSWD Social Worker after verifying your documents.</div>

    <?php if ($error): ?><div class="auth-error mb-3"><?= e($error) ?></div><?php endif; ?>

    <form method="post" action="<?= e($formAction) ?>" data-eligibility-wizard>
      <div class="eligibility-progress" hidden data-eligibility-progress>
        <div data-eligibility-step-label aria-live="polite">Question 1 of 3</div>
        <progress max="3" value="1" aria-label="Question progress"></progress>
      </div>
      <?= csrf_field() ?>

      <fieldset class="mb-3" data-eligibility-step tabindex="-1">
        <legend style="font-weight:700;font-size:15px;color:var(--ink);margin-bottom:10px">1. Are you a resident of Virac, Catanduanes?</legend>
        <p class="eligibility-help">Choose whether you currently live in Virac. If you are unsure about residency, MSWDO can guide you.</p>
        <div class="d-flex flex-wrap gap-2">
          <?php foreach ($yesNo as $opt): ?>
            <label class="option-btn option-radio" style="flex:1 1 120px">
              <input type="radio" name="resident" value="<?= e($opt) ?>" <?= $answers['resident'] === $opt ? 'checked' : '' ?> required> <?= e($opt) ?>
            </label>
          <?php endforeach; ?>
        </div>
      </fieldset>

      <fieldset class="mb-3" data-eligibility-step tabindex="-1">
        <legend style="font-weight:700;font-size:15px;color:var(--ink);margin-bottom:10px">2. Which best describes your situation?</legend>
        <p class="eligibility-help">Choose the closest description. You can select ?I?m not sure? and ask MSWDO for guidance.</p>
        <div class="d-flex flex-column gap-2">
          <?php foreach (array_merge(array_column($circumstances, 'title'), [$noneOption, $unsureOption ?? EligibilityService::UNSURE_OPTION]) as $opt): ?>
            <label class="option-btn wide option-radio">
              <input type="radio" name="circ" value="<?= e($opt) ?>" <?= $answers['circ'] === $opt ? 'checked' : '' ?> required> <?= e($opt) ?>
            </label>
          <?php endforeach; ?>
        </div>
      </fieldset>

      <fieldset class="mb-3" data-eligibility-step tabindex="-1">
        <legend style="font-weight:700;font-size:15px;color:var(--ink);margin-bottom:10px">3. Are you solely caring for a child below 22 years old, or a child of any age with a disability?</legend>
        <p class="eligibility-help">If you need help understanding whether your circumstances fit this question, select ?I?m not sure.?</p>
        <div class="d-flex flex-wrap gap-2">
          <?php foreach ($yesNo as $opt): ?>
            <label class="option-btn option-radio" style="flex:1 1 120px">
              <input type="radio" name="child" value="<?= e($opt) ?>" <?= $answers['child'] === $opt ? 'checked' : '' ?> required> <?= e($opt) ?>
            </label>
          <?php endforeach; ?>
        </div>
      </fieldset>

      <div class="d-flex flex-wrap gap-2">
        <button type="button" class="btn-outline" hidden data-eligibility-back>Back</button>
        <button type="button" class="btn-navy" hidden data-eligibility-next>Next</button>
        <button type="submit" class="btn-navy" data-eligibility-submit>See my result</button>
      </div>
      <p class="eligibility-help mt-3 mb-0">Your answers and result views are recorded for authorized MSWDO monitoring. Guest checks use an anonymous session identifier and are retained for 30 days unless linked to your account when you log in or register.</p>
      <?php if (!empty($loggedIn)): ?><a href="<?= base_url('eligibility.php?history=1') ?>" class="d-inline-block mt-3">My eligibility history</a><?php endif; ?>
    </form>
  </div>
<?php if ($standalone): ?>
</div>
<?php endif; ?>
