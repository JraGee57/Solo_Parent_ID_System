<?php
/**
 * Apply Online wizard shell: step bar, alerts, then the current step's view.
 *
 * @var array   $draft
 * @var array   $errors
 * @var ?string $submitError
 * @var int     $step
 * @var array   $stepMeta
 * @var string  $stepError
 * @var ?string $flashMessage
 */
$stepViews = [1 => 'step-personal.php', 2 => 'step-children.php', 3 => 'step-documents.php', 4 => 'review.php'];
$isApplicationWizard = true;
$highestStep = ApplicationService::furthestStep($draft);
?>
<div class="dash-form-wrap apply-form application-wizard">

<nav class="wizard-progress" aria-label="Application steps">
  <div class="wizard-progress-heading"><span>Apply for Solo Parent ID</span><span class="wizard-step-badge">Step <?= (int) $step ?> of 4</span></div>
  <ol class="wizard-step-list">
  <?php for ($n = 1; $n <= 4; $n++): $isActive = $step === $n; $isComplete = !$isActive && $n <= $highestStep; ?>
    <li class="wizard-step <?= $isComplete ? 'is-complete' : ($isActive ? 'is-current' : 'is-upcoming') ?>"<?= $isActive ? ' aria-current="step"' : '' ?>>
    <?php if ($isComplete): ?>
        <button type="submit" form="application-step-form" name="goto_step" value="<?= $n ?>" formnovalidate class="wizard-step-content" aria-label="Go to step <?= $n ?>, <?= e($stepMeta[$n][0]) ?>. Your changes will be saved.">
          <span class="wizard-step-number" aria-hidden="true">&check;</span>
          <span class="wizard-step-copy"><span class="wizard-step-label"><?= e($stepMeta[$n][0]) ?></span><span class="wizard-step-status">Visited &middot; Open</span></span>
        </button>
    <?php else: ?>
      <div class="wizard-step-content">
        <span class="wizard-step-number" aria-hidden="true"><?= $n ?></span>
        <span class="wizard-step-copy"><span class="wizard-step-label"><?= e($stepMeta[$n][0]) ?></span><span class="wizard-step-status"><?= $isActive ? 'In progress' : 'Up next' ?></span></span>
      </div>
    <?php endif; ?>
    </li>
  <?php endfor; ?>
  </ol>
  <p class="wizard-navigation-hint">Your changes are saved when you move between steps. Click any visited step to return to it.</p>
</nav>

<?php if ($flashMessage): ?><div class="info-box" style="max-width:none" role="status"><?= e($flashMessage) ?></div><?php endif; ?>

<?php if ($submitError): ?><div class="step-alert" role="alert"><?= e($submitError) ?></div><?php endif; ?>
<?php if ($stepError): ?><div class="step-alert" role="alert"><?= e($stepError) ?></div><?php endif; ?>

<?php require __DIR__ . '/' . $stepViews[$step]; ?>

</div>
