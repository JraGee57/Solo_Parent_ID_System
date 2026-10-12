<?php
/**
 * Applicant Analytics: a visual summary of the signed-in applicant's own
 * application (the newest by default), its documents, interview and recent
 * activity, plus the ID they currently hold. Every figure is computed from
 * recorded data; sections without data say so.
 *
 * @var ?array $application         the application being shown (forUserDetailed row), or null
 * @var array  $applications        all of the applicant's own applications, newest first
 * @var ?array $tracker             ApplicationTrackerService::track() for it
 * @var ?array $documentCounts      ApplicantAnalyticsService::documentCounts()
 * @var array  $activity            ApplicantAnalyticsService::timeline()
 * @var ?array $applicationDraft    saved, unsubmitted application (step, stepLabel, updatedAt)
 * @var ?array $idApplication       the released (or approved, not yet released) ID application; may be older than $application
 * @var ?string $idReleasedAt       first "ID Released" date in its status history, if recorded
 * @var ?array $idExpiryInfo        date, expired
 * @var array  $renewal             state: none|valid|due|expired|in_progress (+ daysLeft, expiresAt, windowDays, application)
 */
$stageIcons = ['done' => '✓', 'active' => '•', 'attention' => '!', 'pending' => '', 'stopped' => '–'];
$apptStyles = [
    'BOOKED' => ['#dce8f5', '#1b3a6b', 'Scheduled'], 'COMPLETED' => ['#dcf3e2', '#1c7a3f', 'Completed'],
    'MISSED' => ['#fde8e8', '#a51c1c', 'Missed'], 'CANCELLED' => ['#f1f1f1', '#555', 'Cancelled'],
];
$toneColors = ['info' => 'var(--navy)', 'good' => '#1c7a3f', 'warn' => '#b8860b', 'bad' => '#a51c1c'];
/** A labelled "n / total" bar; nothing is drawn when the total is zero (no misleading 0%). */
$meter = function (string $label, int $n, int $total, string $color): string {
    if ($total <= 0) {
        return '';
    }
    $pct = (int) round($n / $total * 100);
    return '<div style="margin-bottom:12px"><div class="d-flex justify-content-between" style="font-size:13px;color:var(--ink)"><span>' . e($label) . '</span><b>' . $n . ' / ' . $total . '</b></div>'
        . '<div class="progress" style="height:8px;margin-top:4px" role="progressbar" aria-label="' . e($label) . '" aria-valuenow="' . $pct . '" aria-valuemin="0" aria-valuemax="100">'
        . '<div class="progress-bar" style="width:' . $pct . '%;background:' . $color . '"></div></div></div>';
};
$date = fn(?string $at, string $format = 'M j, Y') => $at ? date($format, strtotime($at)) : '—';
?>
      <div class="dash-welcome-sub">A summary of your own Solo Parent ID application and records. Only you can see this page.</div>

<?php if (!$application): ?>
      <div class="dash-card">
        <div class="dash-card-label">Application Overview</div>
        <?php if ($applicationDraft): ?>
          <div class="stat-grid" style="margin-bottom:14px">
            <div class="stat-tile"><div class="stat-label">Status</div><div class="stat-value" style="font-size:20px">Draft</div><div class="stat-sub">Not yet submitted</div></div>
            <div class="stat-tile"><div class="stat-label">Form Progress</div><div class="stat-value" style="font-size:20px">Step <?= (int) $applicationDraft['step'] ?> of 4</div><div class="stat-sub"><?= e($applicationDraft['stepLabel']) ?></div></div>
            <div class="stat-tile"><div class="stat-label">Last Saved</div><div class="stat-value" style="font-size:20px"><?= e($date($applicationDraft['updatedAt'])) ?></div></div>
          </div>
          <div style="font-size:14px;color:var(--muted);margin-bottom:14px">Your analytics will appear here once you submit your application.</div>
          <a href="?section=apply" class="btn-red text-decoration-none dash-card-link">Continue My Application</a>
        <?php else: ?>
          <div style="font-size:14px;color:var(--muted);margin:8px 0 14px">No application data yet. Once you submit an application, its progress, documents, interview and ID details will be summarised here.</div>
          <a href="?section=apply" class="btn-red text-decoration-none dash-card-link">Apply for Solo Parent ID</a>
        <?php endif; ?>
      </div>
<?php else:
    $st = status_style($application['status']);
    $stages = $tracker['stages'];
    $doneCount = count(array_filter($stages, fn($s) => $s['state'] === 'done'));
    $stageCount = count($stages);
    $c = $documentCounts;
    $appt = $tracker['appointment'];
    $assessmentStage = array_values(array_filter($stages, fn($s) => $s['key'] === 'assessment'))[0] ?? null;
?>
      <div class="dash-card" style="padding:14px 18px">
        <?php if (count($applications) > 1): ?>
          <form method="get" class="d-flex flex-wrap align-items-center gap-2">
            <input type="hidden" name="section" value="analytics">
            <label for="analytics-application" style="font-size:13px;font-weight:700;color:var(--ink)">Showing analytics for:</label>
            <select id="analytics-application" name="application_id" class="form-select form-select-sm" style="width:auto;max-width:100%">
              <?php foreach ($applications as $i => $a): ?>
                <option value="<?= (int) $a['id'] ?>" <?= (int) $a['id'] === (int) $application['id'] ? 'selected' : '' ?>>
                  <?= e($a['reference_no']) ?> · <?= $a['type'] === 'renewal' ? 'Renewal' : 'New' ?> · <?= e($a['status']) ?> · <?= e($date($a['submitted_at'])) ?><?= $i === 0 ? ' (latest)' : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn-outline" style="padding:5px 14px;font-size:13px">View</button>
          </form>
          <div style="font-size:12px;color:var(--muted);margin-top:6px">You have <?= count($applications) ?> applications on record. Overview, progress, documents, interview and activity below are for the one selected; the ID card shows the ID you currently hold.</div>
        <?php else: ?>
          <div style="font-size:13px;color:var(--ink)">Showing analytics for <b><?= e($application['reference_no']) ?></b> (<?= $application['type'] === 'renewal' ? 'renewal' : 'new application' ?>, submitted <?= e($date($application['submitted_at'])) ?>).</div>
        <?php endif; ?>
      </div>

      <div class="dash-section-title">Application Overview</div>
      <div class="stat-grid">
        <div class="stat-tile">
          <div class="stat-label">Current Status</div>
          <div style="margin-top:10px"><span class="status-pill" style="background:<?= e($st['bg']) ?>;color:<?= e($st['color']) ?>"><?= e($application['status']) ?></span></div>
          <div class="stat-sub" style="margin-top:6px"><?= $application['type'] === 'renewal' ? 'Renewal' : 'New application' ?></div>
        </div>
        <div class="stat-tile">
          <div class="stat-label">Progress</div>
          <div class="stat-value"><?= $doneCount ?> <span style="font-size:15px;color:var(--muted)">of <?= $stageCount ?></span></div>
          <div class="stat-sub">steps completed</div>
        </div>
        <div class="stat-tile">
          <div class="stat-label">Reference No.</div>
          <div class="stat-value" style="font-size:15px;overflow-wrap:anywhere"><?= e($application['reference_no']) ?></div>
          <div class="stat-sub"><?= e($application['category_name']) ?></div>
        </div>
        <div class="stat-tile">
          <div class="stat-label">Date Submitted</div>
          <div class="stat-value" style="font-size:17px"><?= e($date($application['submitted_at'])) ?></div>
        </div>
        <div class="stat-tile">
          <div class="stat-label">Last Updated</div>
          <div class="stat-value" style="font-size:17px"><?= e($date($application['updated_at'])) ?></div>
        </div>
      </div>

      <div class="dash-card">
        <div class="dash-card-label">Application Progress</div>
        <div style="font-size:13px;color:var(--muted);margin:0 0 6px">Current stage: <b style="color:var(--ink)"><?= e($tracker['current']) ?></b> · <?= $doneCount ?> of <?= $stageCount ?> steps done</div>
        <div class="tracker-mini" aria-hidden="true">
          <?php foreach ($stages as $s): ?><span class="<?= e($s['state']) ?>" title="<?= e($s['label']) ?>"></span><?php endforeach; ?>
        </div>
        <?php if ($tracker['rejected']): ?>
          <div class="step-alert">❌ Not approved<?= $tracker['rejected']['date'] ? ' on ' . e($date($tracker['rejected']['date'], 'F j, Y')) : '' ?><?= $tracker['rejected']['reason'] ? ': ' . e($tracker['rejected']['reason']) : '.' ?></div>
        <?php elseif ($tracker['correction']): ?>
          <div class="step-alert">⚠️ MSWDO asked for corrections<?= $tracker['correction']['reason'] ? ': ' . e($tracker['correction']['reason']) : '.' ?> &mdash; <a href="?section=documents">go to Document Checklist</a>.</div>
        <?php endif; ?>
        <ol class="tracker" aria-label="Application progress">
          <?php foreach ($stages as $i => $s): ?>
            <li class="tracker-step <?= e($s['state']) ?>" <?= in_array($s['state'], ['active', 'attention'], true) ? 'aria-current="step"' : '' ?>>
              <span class="tracker-dot" aria-hidden="true"><?= $stageIcons[$s['state']] !== '' ? $stageIcons[$s['state']] : $i + 1 ?></span>
              <div class="tracker-body">
                <div class="tracker-label"><?= e($s['label']) ?>
                  <?php if ($s['state'] === 'active'): ?><span class="tracker-tag">In progress</span><?php elseif ($s['state'] === 'attention'): ?><span class="tracker-tag warn">Needs your action</span><?php elseif ($s['state'] === 'pending'): ?><span class="tracker-tag" style="background:var(--surface-alt);color:var(--muted)">Pending</span><?php endif; ?>
                </div>
                <?php if ($s['date']): ?><div class="tracker-date"><?= e($date($s['date'], 'F j, Y g:i A')) ?></div><?php endif; ?>
              </div>
            </li>
          <?php endforeach; ?>
        </ol>
        <a href="?section=application" class="btn-outline text-decoration-none dash-card-link">View Full Application Details</a>
      </div>

      <div class="dash-two-col">
        <div class="dash-card">
          <div class="dash-card-label">Required Documents Checklist</div>
          <?php if ($c['required'] === 0 && $c['uploaded'] === 0): ?>
            <div style="font-size:14px;color:var(--muted)">No checklist requirements are recorded for this application.</div>
          <?php else: ?>
            <?= $meter('Required documents marked available', $c['requiredUploaded'], $c['required'], 'var(--navy)') ?>
            <?= $meter('Requirements verified by staff', $c['verified'], $c['required'], '#1c7a3f') ?>
            <table class="table table-sm mb-3" style="font-size:13px">
              <tbody>
                <tr><td>Required</td><td class="text-end"><b><?= $c['required'] ?></b></td></tr>
                <tr><td>Applicant marked available</td><td class="text-end"><b><?= $c['uploaded'] ?></b></td></tr>
                <tr><td>Verified by staff</td><td class="text-end"><b style="color:#1c7a3f"><?= $c['verified'] ?></b></td></tr>
                <tr><td>Awaiting verification</td><td class="text-end"><b><?= $c['awaiting'] ?></b></td></tr>
                <tr><td>Needing correction</td><td class="text-end"><b style="<?= $c['correction'] ? 'color:#a51c1c' : '' ?>"><?= $c['correction'] ?></b></td></tr>
                <tr><td>Unchecked (required)</td><td class="text-end"><b style="<?= $c['missing'] ? 'color:#a51c1c' : '' ?>"><?= $c['missing'] ?></b></td></tr>
              </tbody>
            </table>
          <?php endif; ?>
          <a href="?section=documents" class="btn-outline text-decoration-none dash-card-link"><?= ($c['correction'] || $c['missing']) && in_array($application['status'], Application::EDITABLE_STATUSES, true) ? 'Review Checklist' : 'View Checklist' ?></a>
        </div>

        <div class="dash-card">
          <div class="dash-card-label">Interview</div>
          <?php if (!$appt): ?>
            <div style="font-size:14px;color:var(--muted);margin-bottom:14px">No interview has been scheduled.</div>
          <?php else: [$bg, $fg, $label] = $apptStyles[$appt['status']] ?? ['#f1f1f1', '#555', $appt['status']]; ?>
            <table class="table table-sm mb-3" style="font-size:13px">
              <tbody>
                <tr><td>Status</td><td class="text-end"><span class="status-pill" style="background:<?= e($bg) ?>;color:<?= e($fg) ?>;padding:3px 10px"><?= e($label) ?></span></td></tr>
                <tr><td>Scheduled for</td><td class="text-end"><?= e($date($appt['scheduled_at'], 'M j, Y g:i A')) ?></td></tr>
                <tr><td>Location</td><td class="text-end"><?= e($appt['location']) ?></td></tr>
                <?php if ($appt['status'] === 'COMPLETED'): ?>
                  <tr><td>Completed on</td><td class="text-end"><?= e($date($appt['result_recorded_at'], 'M j, Y g:i A')) ?></td></tr>
                <?php endif; ?>
                <tr><td>Latest update</td><td class="text-end"><?= e($date($appt['updated_at'], 'M j, Y g:i A')) ?></td></tr>
              </tbody>
            </table>
            <?php
            // The outcome as the tracker recorded it, once the interview is no longer pending.
            $interviewResult = null;
            if ($appt['status'] !== 'BOOKED' && $assessmentStage) {
                $interviewResult = $assessmentStage['state'] === 'done' ? 'Assessed eligible by MSWDO.'
                    : (in_array($assessmentStage['state'], ['active', 'attention'], true) ? $assessmentStage['detail'] : null);
            }
            ?>
            <?php if ($interviewResult): ?>
              <div style="font-size:13px;color:var(--body-text);margin-bottom:14px"><b>Result:</b> <?= e($interviewResult) ?></div>
            <?php endif; ?>
          <?php endif; ?>
          <a href="?section=appointment" class="btn-outline text-decoration-none dash-card-link">View Appointment</a>
        </div>
      </div>
<?php endif; ?>

      <div class="dash-two-col">
        <div class="dash-card">
          <div class="dash-card-label">Current Solo Parent ID &amp; Renewal</div>
          <?php if (!$idApplication): ?>
            <div style="font-size:14px;color:var(--muted);margin-bottom:14px">No ID yet. Your Solo Parent ID details will appear here once MSWDO approves your application.</div>
          <?php else: $idSt = status_style($idApplication['status']);
              $released = $idApplication['status'] === 'ID Released';
              $idIsShown = $application && (int) $idApplication['id'] === (int) $application['id'];
              $renewalLabels = ['expired' => 'Expired — renewal needed', 'due' => 'Renewal open', 'valid' => 'Not yet due', 'none' => 'Not applicable yet'];
          ?>
            <table class="table table-sm mb-3" style="font-size:13px">
              <tbody>
                <tr><td>ID from application</td><td class="text-end"><?= e($idApplication['reference_no']) ?><div style="font-size:11px;color:var(--muted)"><?= $idIsShown ? 'the application shown above' : ($idApplication['type'] === 'renewal' ? 'earlier renewal' : 'earlier application') ?></div></td></tr>
                <tr><td>ID status</td><td class="text-end"><span class="status-pill" style="background:<?= e($idSt['bg']) ?>;color:<?= e($idSt['color']) ?>;padding:3px 10px"><?= e($idApplication['status']) ?></span></td></tr>
                <tr><td>Released</td><td class="text-end"><?= $released ? 'Yes' : 'Not yet — being prepared' ?></td></tr>
                <?php if ($released): ?><tr><td>Release date</td><td class="text-end"><?= $idReleasedAt ? e($date($idReleasedAt)) : 'Not recorded' ?></td></tr><?php endif; ?>
                <?php if ($idExpiryInfo): ?>
                  <tr><td>Expiration date</td><td class="text-end" style="<?= $idExpiryInfo['expired'] ? 'color:#a51c1c;font-weight:700' : '' ?>"><?= e(format_date_long($idExpiryInfo['date'])) ?><?= $idExpiryInfo['expired'] ? ' (expired)' : '' ?></td></tr>
                <?php endif; ?>
                <?php if ($renewal['state'] === 'in_progress'): $rs = status_style($renewal['application']['status']); ?>
                  <tr><td>Renewal</td><td class="text-end">In progress: <?= e($renewal['application']['reference_no']) ?><div style="margin-top:3px"><span class="status-pill" style="background:<?= e($rs['bg']) ?>;color:<?= e($rs['color']) ?>;padding:2px 10px;font-size:11px"><?= e($renewal['application']['status']) ?></span></div></td></tr>
                <?php else: ?>
                  <tr><td>Renewal</td><td class="text-end"><?= e($renewalLabels[$renewal['state']] ?? $renewal['state']) ?><?= in_array($renewal['state'], ['due', 'valid'], true) ? ' · ' . (int) $renewal['daysLeft'] . ' day' . ($renewal['daysLeft'] === 1 ? '' : 's') . ' left' : '' ?></td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          <?php endif; ?>
          <div class="d-flex flex-wrap gap-2">
            <a href="?section=myid" class="btn-outline text-decoration-none dash-card-link">My Solo Parent ID</a>
            <?php if (in_array($renewal['state'], ['due', 'expired'], true)): ?><a href="?section=renewal&type=new" class="btn-red text-decoration-none dash-card-link">Renew My ID</a><?php endif; ?>
          </div>
        </div>

        <div class="dash-card">
          <div class="dash-card-label">Recent Activity</div>
          <?php if (!$activity): ?>
            <div style="font-size:14px;color:var(--muted)">No activity yet.</div>
          <?php else: ?>
            <ol class="doc-trail" style="list-style:none;padding:0;margin:0;border-left:2px solid var(--border);font-size:13px">
              <?php foreach ($activity as $ev): ?>
                <li style="padding:4px 0 10px 12px;position:relative">
                  <span aria-hidden="true" style="position:absolute;left:-6px;top:9px;width:10px;height:10px;border-radius:50%;background:<?= $toneColors[$ev['tone']] ?>"></span>
                  <div style="color:var(--ink);font-weight:600"><?= e($ev['label']) ?></div>
                  <div class="when"><?= e($date($ev['date'], 'M j, Y g:i A')) ?></div>
                  <?php if ($ev['detail'] !== ''): ?><div class="note"><?= e($ev['detail']) ?></div><?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ol>
          <?php endif; ?>
        </div>
      </div>
