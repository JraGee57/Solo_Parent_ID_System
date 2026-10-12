<?php /** @var array $myApplications @var array $checklistByApp @var array $editableStatuses */ ?>
<div class="applicant-document-checklists">
<?php if (!$myApplications): ?>
  <div class="empty-state document-checklist-empty"><div class="empty-state-title">No checklist yet</div><p>Start an application to see the documents you need.</p><a href="?section=apply" class="btn-navy">Start an Application</a></div>
<?php else: ?>
  <div class="document-checklist-intro"><p>Mark the documents you already have. Your selections show availability; MSWDO verification and approval are separate.</p><a href="?section=requirements">Browse requirement guides &rarr;</a></div>
  <?php foreach ($myApplications as $app):
    $editable = in_array($app['status'], $editableStatuses, true);
    $items = $checklistByApp[$app['id']] ?? [];
    $availableCount = count(array_filter($items, fn($item) => !empty($item['applicant_has'])));
    $verifiedCount = count(array_filter($items, fn($item) => $item['verification_status'] === 'Verified'));
    $correctionCount = count(array_filter($items, fn($item) => $item['verification_status'] === 'Needs Correction'));
    $groups = [
      ['title' => 'Required documents', 'items' => array_filter($items, fn($item) => !empty($item['is_required']))],
      ['title' => 'Optional documents', 'items' => array_filter($items, fn($item) => empty($item['is_required']))],
    ];
    $applicationStyle = status_style($app['status']);
  ?>
  <section class="document-checklist-card" aria-labelledby="document-checklist-<?= (int) $app['id'] ?>">
    <header class="document-checklist-header">
      <div><span class="document-checklist-eyebrow"><?= $app['type'] === 'renewal' ? 'ID renewal' : 'Solo Parent ID application' ?></span><h2 id="document-checklist-<?= (int) $app['id'] ?>"><?= e($app['reference_no']) ?></h2><p><?= e($app['category_name']) ?></p></div>
      <span class="status-pill" style="background:<?= e($applicationStyle['bg']) ?>;color:<?= e($applicationStyle['color']) ?>"><?= e($app['status']) ?></span>
    </header>
    <dl class="document-checklist-summary" aria-label="Saved checklist summary">
      <div><dt>Saved as available</dt><dd><?= $availableCount ?> <span>of <?= count($items) ?></span></dd></div>
      <div><dt>Staff verified</dt><dd><?= $verifiedCount ?></dd></div>
      <div><dt>Needs correction</dt><dd><?= $correctionCount ?></dd></div>
    </dl>
    <?php if (!$editable): ?><p class="document-checklist-lock" role="note">This checklist is read-only while your application is <?= e($app['status']) ?>.</p><?php endif; ?>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="save_checklist"><input type="hidden" name="application_id" value="<?= (int) $app['id'] ?>">
      <?php foreach ($groups as $group): if (!$group['items']) continue; ?>
        <section class="document-checklist-group" aria-label="<?= e($group['title']) ?>">
          <h3><?= e($group['title']) ?><span><?= count($group['items']) ?></span></h3>
          <?php foreach ($group['items'] as $item):
            $verificationClass = match ($item['verification_status']) { 'Verified' => 'is-verified', 'Needs Correction' => 'needs-correction', default => 'is-pending' };
          ?>
          <div class="document-checklist-item<?= !$editable ? ' is-read-only' : '' ?>">
            <label class="document-checklist-select">
              <input type="checkbox" name="checklist[]" value="<?= e($item['doc_key']) ?>" <?= !empty($item['applicant_has']) ? 'checked' : '' ?> <?= !$editable ? 'disabled' : '' ?>>
              <span><span class="document-checklist-name"><?= e($item['label']) ?></span><span class="document-checklist-required"><?= !empty($item['is_required']) ? 'Required' : 'Optional' ?></span></span>
            </label>
            <div class="document-checklist-item-meta"><span class="document-verification <?= $verificationClass ?>">Staff: <?= e($item['verification_status']) ?></span><a href="<?= base_url('applicant/dashboard.php?section=requirements&doc=' . rawurlencode($item['doc_key'])) ?>" target="_blank" rel="noopener" aria-label="How to get <?= e($item['label']) ?> (opens in a new tab)">How to get it <span aria-hidden="true">&nearr;</span></a></div>
            <?php if (!empty($item['staff_remarks'])): ?><p class="document-checklist-remark"><strong>Staff note:</strong> <?= e($item['staff_remarks']) ?></p><?php endif; ?>
          </div>
          <?php endforeach; ?>
        </section>
      <?php endforeach; ?>
      <?php if (!$items): ?><p class="document-checklist-lock">No documents are listed for this application. Contact MSWDO for guidance.</p><?php endif; ?>
      <?php if ($editable && $items): ?><footer class="document-checklist-actions"><p>Click Save checklist to keep your changes.</p><button type="submit" class="btn-navy">Save checklist</button></footer><?php endif; ?>
    </form>
  </section>
  <?php endforeach; ?>
<?php endif; ?>
</div>
