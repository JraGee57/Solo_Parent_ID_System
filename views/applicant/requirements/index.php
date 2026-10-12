<?php
/** @var array $requirementGuides @var ?string $selectedGuideKey @var bool $guideNotFound */
$visibleGuides = $selectedGuideKey !== null ? [$selectedGuideKey => $requirementGuides[$selectedGuideKey]] : $requirementGuides;
?>
<div class="requirements-guide">
  <div class="requirements-guide-intro">
    <h2>How to get your requirements</h2>
    <p>A document-by-document guide for applicants in Virac, Catanduanes. Start with MSWDO to confirm the documents for your category, then use the office routes and request steps below.</p>
    <p class="requirements-guide-reviewed">Sources reviewed <?= e(format_date_long(RequirementGuideService::REVIEWED_ON)) ?>. Each guide identifies confirmed national procedures and local arrangements you need to check. Follow your application checklist; these instructions do not add requirements.</p>
    <div class="requirements-guide-advisory"><strong>Before visiting PSA Virac</strong><p>From April 6, 2026, an online CRS appointment is required, including for National ID holders. The appointment must be under the actual requester's name. <a href="https://appointment.psa.gov.ph/" target="_blank" rel="noopener">Book through PSA</a> or read the <a href="https://rsso05.psa.gov.ph/content/full-implementation-civil-registration-service-appointment-system-crs-outlet-virac" target="_blank" rel="noopener">official Virac advisory</a>.</p></div>
    <a href="?section=help#help-contact" class="requirements-guide-contact">Contact MSWDO for help &rarr;</a>
  </div>
  <details class="requirement-guide-card requirements-office-directory">
    <summary><span>Offices to contact in Virac and the Bicol region</span><span class="requirement-guide-toggle" aria-hidden="true">+</span></summary>
    <div class="requirement-guide-body">
      <p>Contact the issuing office before travelling. Older directory entries are marked; fees, appointment slots and release dates must be confirmed with the office.</p>
      <ul class="requirements-office-list">
        <?php foreach (RequirementGuideService::offices() as $office): ?>
          <li><h3><?= e($office['name']) ?></h3><p><?= e($office['purpose']) ?></p><p><?= e($office['location']) ?><?php if ($office['contact']): ?><br><?= e($office['contact']) ?><?php endif; ?></p><a href="<?= e($office['url']) ?>" target="_blank" rel="noopener"><?= e($office['source']) ?> &rarr;</a></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </details>
  <?php if ($guideNotFound): ?><div class="info-box" role="status">That requirement is no longer in the current checklist. Browse the available guides below or contact MSWDO.</div><?php endif; ?>
  <?php if ($selectedGuideKey !== null): ?><a href="?section=requirements" class="requirements-guide-back">&larr; Browse all requirements</a><?php endif; ?>
  <?php if (!$visibleGuides): ?><div class="empty-state"><div class="empty-state-title">No requirements configured yet</div>Contact MSWDO for the current documentary requirements.</div><?php endif; ?>
  <div class="requirements-guide-list">
    <?php foreach ($visibleGuides as $key => $guide): ?>
      <details class="requirement-guide-card"<?= $selectedGuideKey === $key ? ' open' : '' ?>>
        <summary><span><?= e($guide['label']) ?></span><span class="requirement-guide-toggle" aria-hidden="true">+</span></summary>
        <div class="requirement-guide-body">
          <?php if (count($guide['labels']) > 1): ?><p class="requirement-guide-variants">Also listed as: <?= e(implode(' · ', array_slice($guide['labels'], 1))) ?></p><?php endif; ?>
          <p class="requirement-guide-basis"><?= e($guide['basis']) ?></p>
          <h3>Where to get it</h3><p><?= e($guide['where']) ?></p>
          <h3>What to prepare</h3><ul><?php foreach ($guide['prepare'] as $item): ?><li><?= e($item) ?></li><?php endforeach; ?></ul>
          <h3>How to request it</h3><ol><?php foreach ($guide['steps'] as $step): ?><li><?= e($step) ?></li><?php endforeach; ?></ol>
          <?php if ($guide['notes']): ?><h3>Before you request or pay</h3><ul><?php foreach ($guide['notes'] as $note): ?><li><?= e($note) ?></li><?php endforeach; ?></ul><?php endif; ?>
          <?php if ($guide['sources']): ?>
            <div class="requirement-guide-sources"><h3>Official reference</h3><?php foreach ($guide['sources'] as $label => $url): ?><a href="<?= e($url) ?>" target="_blank" rel="noopener"><?= e($label) ?> &rarr;</a><?php endforeach; ?></div>
          <?php else: ?><p class="requirement-guide-local">Ask MSWDO or the issuing office for the local request procedure.</p><?php endif; ?>
        </div>
      </details>
    <?php endforeach; ?>
  </div>
</div>
