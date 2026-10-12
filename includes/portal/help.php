<?php /** @var PDO $pdo */ ?>
<div style="max-width:780px">
  <p>Contact MSWDO for help with requirements, application corrections, interviews, and ID claiming.</p>
  <div class="info-box">
    <strong><?= e(setting('office_name')) ?></strong>
    <div><?= e(setting('office_address')) ?></div>
    <div>Office hours: <?= e(setting('office_hours')) ?></div>
    <?php if (setting('office_phone')): ?><div>Phone: <a href="tel:<?= e(preg_replace('/[^+0-9]/', '', setting('office_phone'))) ?>"><?= e(setting('office_phone')) ?></a></div><?php endif; ?>
    <?php if (setting('office_email')): ?><div>Email: <a href="mailto:<?= e(setting('office_email')) ?>"><?= e(setting('office_email')) ?></a></div><?php endif; ?>
  </div>
  <h2 class="section-title">Common Questions</h2>
  <details class="doc-group-card"><summary>How do I apply?</summary><p>Check your preliminary eligibility, create an account, complete the application and document checkboxes, then submit. Follow MSWDO instructions for document review, interviews, and ID claiming.</p><a href="<?= base_url('portal.php?tab=apply') ?>">Apply for Solo Parent ID</a></details>
  <details class="doc-group-card"><summary>Do I upload my required documents?</summary><p>Use the checkbox checklist to indicate the documents you have available. Staff verify documents separately. Bring originals and photocopies as instructed by MSWDO.</p><a href="<?= base_url('portal.php#requirements') ?>">View requirements by category</a></details>
  <details class="doc-group-card"><summary>Where can I find my application reference number?</summary><p>Your submitted application and application slip show the reference number. Log in to My Applications to find it. Public tracking only requires your application reference number.</p><a href="<?= base_url('login.php?next=applications') ?>">Log In to My Applications</a></details>
  <details class="doc-group-card"><summary>Does an eligibility result mean my application is approved?</summary><p>The checker gives a preliminary result. Final eligibility and approval depend on MSWDO assessment and document review. Monitor your application for appointment and claiming instructions.</p></details>
  <?php foreach (get_faqs($pdo) as $faq): ?>
    <details class="doc-group-card"><summary><?= e($faq['question']) ?></summary><p style="white-space:pre-line"><?= e($faq['answer']) ?></p></details>
  <?php endforeach; ?>
</div>
