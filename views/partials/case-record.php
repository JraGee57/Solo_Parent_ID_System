<?php
/**
 * Read-only MSWD case record for an application: the official eligibility
 * assessments, every decision (with reason and who made it), and internal
 * case notes. Staff see it under their forms; administrators see it on the
 * monitoring view.
 *
 * @var array $mswdAssessments  EligibilityAssessment::mswdForApplication()
 * @var array $decisions        Application::decisions()
 * @var array $notes            Application::notes()
 */
$decisionLabels = ['APPROVED' => ['Approved', '#dcf3e2', '#1c7a3f'], 'REJECTED' => ['Rejected', '#fde8e8', '#7f1616'], 'NEEDS_CORRECTION' => ['Corrections requested', '#fde8e8', '#a51c1c']];
?>
<div class="review-card" id="decisions">
  <div class="review-card-head"><div class="title">Decisions</div></div>
  <?php if (!$decisions): ?><div style="font-size:13px;color:var(--muted)">No approve, reject or correction decision yet.</div><?php endif; ?>
  <?php foreach ($decisions as $d): [$dl, $dbg, $dfg] = $decisionLabels[$d['decision']]; ?>
    <div class="case-entry">
      <div><span class="status-pill" style="background:<?= $dbg ?>;color:<?= $dfg ?>;font-size:11px"><?= e($dl) ?></span>
        <span class="when"><?= e(date('M j, Y g:i A', strtotime($d['decided_at']))) ?> · <?= e($d['decided_by_name'] ?? 'former staff member') ?></span></div>
      <?php if ($d['reason']): ?><div class="text"><?= e($d['reason']) ?></div><?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<?php if (!isset($showAssessmentHistory) || $showAssessmentHistory): ?>
  <div class="review-card">
    <div class="review-card-head"><div class="title">Assessment History</div></div>
    <?php if (!$mswdAssessments): ?><div style="font-size:13px;color:var(--muted)">MSWD has not assessed eligibility yet.</div><?php endif; ?>
    <?php foreach ($mswdAssessments as $a): $eligible = $a['preliminary_result'] === 'ELIGIBLE'; ?>
      <div class="case-entry">
        <div><span class="status-pill" style="background:<?= $eligible ? '#dcf3e2' : '#fde8e8' ?>;color:<?= $eligible ? '#1c7a3f' : '#a51c1c' ?>;font-size:11px"><?= $eligible ? 'Eligible' : 'Not Eligible' ?></span>
          <span class="when"><?= e(date('M j, Y g:i A', strtotime($a['created_at']))) ?> · <?= e($a['assessor_name'] ?? 'former staff member') ?></span></div>
        <?php if ($a['result_reason']): ?><div class="text"><?= e($a['result_reason']) ?></div><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="review-card" id="notes">
  <div class="review-card-head"><div class="title">Case Notes <span style="font-weight:400;font-size:12px;color:var(--muted)">· internal, not shown to the applicant</span></div></div>
  <?php if (isset($noteForm)) { $noteForm(); } ?>
  <?php if (!$notes): ?><div style="font-size:13px;color:var(--muted)">No case notes yet.</div><?php endif; ?>
  <?php foreach ($notes as $n): ?>
    <div class="case-entry">
      <div class="when"><?= e(date('M j, Y g:i A', strtotime($n['created_at']))) ?> · <?= e($n['author_name']) ?></div>
      <div class="text" style="white-space:pre-line"><?= e($n['note']) ?></div>
    </div>
  <?php endforeach; ?>
</div>
