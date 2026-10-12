<?php
/**
 * Preliminary Eligibility Result. Standalone on eligibility.php; also
 * embedded in the applicant dashboard with $standalone = false. This is a
 * guide only -- never presented as MSWD's decision.
 *
 * @var array  $answers
 * @var array  $result     ['eligible' => bool, 'reason' => ?string]
 * @var string $checkedAt
 * @var bool   $loggedIn   an applicant is signed in (offer "Start application" instead of login/register)
 * @var string $applyUrl
 * @var string $restartUrl
 * @var bool   $standalone
 */
$standalone = $standalone ?? true;
?>
<?php if ($standalone): ?>
<div class="portal-container">
  <a href="<?= base_url('portal.php') ?>" class="public-back-link"><svg aria-hidden="true" focusable="false" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 5-7 7 7 7M5 12h14"/></svg><span>Back to Home</span></a>
<?php endif; ?>
  <div class="elig-panel">
    <div style="font-size:12px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:var(--muted)">Preliminary Eligibility Result</div>
    <div style="font-size:12px;color:var(--faint);margin-bottom:14px">Checked <?= e(date('F j, Y g:i A', strtotime($checkedAt))) ?></div>

    <div class="bullet-list" style="margin-bottom:18px;font-size:14px">
      <div class="bullet-row"><div class="bullet-dot"></div><div>Resident of Virac: <b><?= e($answers['resident']) ?></b></div></div>
      <div class="bullet-row"><div class="bullet-dot"></div><div>Situation: <b><?= e($answers['circ']) ?></b></div></div>
      <div class="bullet-row"><div class="bullet-dot"></div><div>Solely caring for a qualifying child: <b><?= e($answers['child']) ?></b></div></div>
    </div>

    <?php if (($result['status'] ?? '') === 'NEEDS_GUIDANCE'): ?>
      <div class="result-box warning" role="status">
        <div class="title">MSWDO guidance can help clarify your result.</div>
        <div class="desc"><?= e($result['reason']) ?></div>
      </div>
    <?php elseif ($result['eligible']): ?>
      <div class="result-box success" role="status">
        <div style="flex:1 1 260px">
          <div class="title">You may be eligible to apply.</div>
          <div class="desc">Based on your answers, you appear to meet the preliminary criteria for a Solo Parent ID. <?= $loggedIn ? 'You can start your application now.' : 'Log in or create an account to start your application.' ?></div>
        </div>
        <?php if ($loggedIn): ?>
          <a href="<?= e($applyUrl) ?>" class="btn-red" style="padding:12px 22px;font-size:14px;text-decoration:none;display:inline-block">Start Your Application</a>
        <?php else: ?>
          <div class="d-flex flex-column gap-2" style="flex:0 0 auto">
            <a href="<?= base_url('login.php') ?>" class="btn-red text-center" style="padding:12px 22px;font-size:14px;text-decoration:none;display:inline-block">Proceed to Login / Register</a>
            <a href="<?= base_url('register.php') ?>" style="font-size:13px;text-align:center">New here? Create an account</a>
          </div>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="result-box warning" role="status">
        <div class="title">Based on your answers, you may not currently meet the preliminary criteria.</div>
        <div class="desc"><?= e($result['reason']) ?></div>
        <div class="desc" style="margin-top:8px">If your situation is different from what the questions describe, or it changes, MSWDO can still assess you in person: MSWDO Office, Municipal Building, Salvacion, Virac (Mon–Fri, 8:00 AM – 5:00 PM). You can also <a href="<?= e($restartUrl) ?>">check again</a>.</div>
      </div>
    <?php endif; ?>

    <div style="font-size:13px;color:var(--ink);background:var(--surface-alt, #f3f5f8);border-radius:6px;padding:10px 14px;margin-top:14px">
      ℹ️ This is a <b>preliminary</b> result only, not an official decision. Final eligibility is subject to MSWD verification and assessment of your documents and circumstances.
    </div>
    <?php if (!empty($requirements)): ?>
      <section class="mt-4" aria-labelledby="eligibility-requirements-heading">
        <h2 id="eligibility-requirements-heading" class="font-heading" style="font-size:18px">Documents for your selected situation</h2>
        <p class="eligibility-help">Use this list to prepare, then tick the documents you have available in the application checklist. A checked box is your declaration only. Follow MSWDO instructions for document review, your interview, and ID claiming.</p>
        <ul><?php foreach ($requirements as $requirement): ?><li><?= e($requirement['label']) ?><?= $requirement['is_required'] ? '' : ' (optional)' ?></li><?php endforeach; ?></ul>
      </section>
    <?php endif; ?>
    <section class="mt-4" id="eligibility-contact" aria-labelledby="eligibility-contact-heading">
      <h2 id="eligibility-contact-heading" class="font-heading" style="font-size:18px">Contact MSWDO</h2>
      <div><?= e(setting('office_address')) ?></div>
      <div class="eligibility-help"><?= e(setting('office_hours')) ?></div>
      <?php if (setting('office_phone') !== ''): ?><a href="tel:<?= e(preg_replace('/[^\d+]/', '', setting('office_phone'))) ?>"><?= e(setting('office_phone')) ?></a><?php endif; ?>
      <?php if (setting('office_email') !== ''): ?><a class="ms-2" href="mailto:<?= e(setting('office_email')) ?>"><?= e(setting('office_email')) ?></a><?php endif; ?>
    </section>
    <div style="margin-top:16px">
      <a href="<?= e($restartUrl) ?>" class="btn-outline text-decoration-none" style="padding:8px 16px;font-size:13px">Check again</a>
      <?php if ($loggedIn): ?><a href="<?= base_url('eligibility.php?history=1') ?>" class="ms-3">My eligibility history</a><?php endif; ?>
    </div>
  </div>
<?php if ($standalone): ?>
</div>
<?php endif; ?>
