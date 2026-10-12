<?php
/** @var array $office @var bool $hasApplication */
$links = [
    ['section=chat', 'chat', 'Chat Support', 'Ask the Help Assistant or send your question to an MSWDO social worker.'],
    ['section=requirements', 'documents', 'Requirements guide', 'Find the issuing office, what to bring, and steps to get each document.'],
    [$hasApplication ? 'section=application' : 'section=apply', 'application', $hasApplication ? 'Track my application' : 'Apply online', $hasApplication ? 'See your progress, staff remarks, and the next action needed.' : 'Start your Solo Parent ID application and save your progress.'],
    ['section=news', 'news', 'News & announcements', 'Check office schedules and the latest program updates.'],
];
$steps = [
    ['Apply online', 'Fill in your details and complete the document checklist. Save your draft if you need to finish later.'],
    ['Follow the review', 'MSWDO reviews your application and requirements. Check your tracker for corrections and instructions.'],
    ['Attend your interview', 'When appointment booking becomes available, choose a slot and bring the requested original documents and your application slip.'],
    ['Claim your Solo Parent ID', 'After approval, follow your release appointment or MSWDO instructions. Your ID appears in My ID after it is released.'],
];
$helpIcons = [
    'chat' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
    'documents' => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7z"/>',
    'application' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M8 13h8m-8 4h5"/>',
    'news' => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M7 7h10M7 11h10m-10 4h5"/>',
];
?>
<link rel="stylesheet" href="<?= public_asset('css/applicant-account.css') ?>">
<div class="applicant-help-page">
  <div class="help-workspace">
    <div class="help-main">
      <section class="help-resource-section" aria-labelledby="help-resources-heading"><div class="help-section-header"><h3 id="help-resources-heading">What do you need help with?</h3><p>Choose a topic to go straight to the right place.</p></div>
        <div class="help-resource-grid">
          <?php foreach ($links as [$query, $icon, $label, $text]): ?><a href="?<?= e($query) ?>" class="help-resource-link"><span class="help-resource-symbol" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><?= $helpIcons[$icon] ?></svg></span><span class="help-resource-title"><?= e($label) ?><span aria-hidden="true">&rarr;</span></span><span class="help-resource-text"><?= e($text) ?></span></a><?php endforeach; ?>
        </div>
      </section>
      <section class="help-process-card" aria-labelledby="help-process-heading"><div class="help-section-header"><div class="account-eyebrow">FROM APPLICATION TO RELEASE</div><h3 id="help-process-heading">How it works</h3><p>Know what happens next at each stage.</p></div><ol class="help-process-steps">
        <?php foreach ($steps as $i => [$title, $text]): ?><li><span class="help-step-number" aria-hidden="true"><?= str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) ?></span><div><h4><?= e($title) ?></h4><p><?= e($text) ?></p></div></li><?php endforeach; ?>
      </ol></section>
    </div>
    <aside class="help-contact-card" id="help-contact" aria-labelledby="help-contact-heading"><div class="account-eyebrow">WE’RE HERE TO HELP</div><h3 id="help-contact-heading">Contact MSWDO</h3><p class="help-office-name"><?= e($office['name']) ?></p>
      <dl><div><dt>Visit us</dt><dd><?= e($office['address']) ?></dd></div><div><dt>Office hours</dt><dd><?= e($office['hours']) ?></dd></div>
      <?php if ($office['phone'] !== ''): ?><div><dt>Phone</dt><dd><a href="tel:<?= e(preg_replace('/[^\d+]/', '', $office['phone'])) ?>"><?= e($office['phone']) ?></a></dd></div><?php endif; ?>
      <?php if ($office['email'] !== ''): ?><div><dt>Email</dt><dd><a href="mailto:<?= e($office['email']) ?>"><?= e($office['email']) ?></a></dd></div><?php endif; ?></dl>
      <a href="?section=chat" class="help-contact-action">Ask MSWDO staff <span aria-hidden="true">&rarr;</span></a>
      <div class="help-privacy-note"><strong>Keep your account private</strong><p>Never share your password or SMS reset code with anyone, including MSWDO staff.</p></div>
    </aside>
  </div>
</div>
