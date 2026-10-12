<?php
/**
 * One applicant's full record (RecordService::record()).
 *
 * @var array  $staff
 * @var array  $applicant
 * @var array  $profile           birthdate, sex, civil_status, barangay, address (applicants row)
 * @var ?array $eligibilityCheck  the applicant's latest preliminary self-check (not an MSWD decision)
 * @var array  $applications      every application, newest first
 * @var array  $newApps
 * @var array  $renewals          each with renews_reference
 * @var ?array $selected          the application the lower sections are narrowed to, or null for all
 * @var array  $documents
 * @var array  $appointments
 * @var array  $decisions
 * @var array  $history           ['rows' => status changes, ...paginate() fields]
 */
$recordUrl = fn(array $extra = []) => base_url(role_portal_path($staff, 'applicant_view.php') . '?' . http_build_query(['id' => (int) $applicant['id']] + $extra));
$scopeLabel = $selected ? 'for ' . $selected['reference_no'] : 'across all applications';
$pill = function (string $status): string {
    $st = status_style($status);
    return '<span class="status-pill" style="background:' . e($st['bg']) . ';color:' . e($st['color']) . ';font-size:11px">' . e($status) . '</span>';
};
$docPill = function (string $status): string {
    [$bg, $fg] = match ($status) {
        'VERIFIED' => ['#dcf3e2', '#1c7a3f'],
        'NEEDS_CORRECTION', 'REJECTED', 'INVALID' => ['#fde8e8', '#a51c1c'],
        default => ['#fdf1d6', '#8a6d1a'],
    };
    return '<span class="status-pill" style="background:' . $bg . ';color:' . $fg . ';font-size:11px">' . e(document_status_label($status)) . '</span>';
};
$decisionLabels = ['APPROVED' => ['Approved', '#dcf3e2', '#1c7a3f'], 'REJECTED' => ['Rejected', '#fde8e8', '#7f1616'], 'NEEDS_CORRECTION' => ['Corrections requested', '#fde8e8', '#a51c1c']];
$apptLabels = ['BOOKED' => ['Booked', '#dce8f5', '#1b3a6b'], 'COMPLETED' => ['Completed', '#dcf3e2', '#1c7a3f'], 'CANCELLED' => ['Cancelled', '#eef0f3', '#5a6472'], 'MISSED' => ['Missed', '#fde8e8', '#a51c1c']];
$when = fn(?string $v, string $fmt = 'M j, Y g:i A') => $v ? date($fmt, strtotime($v)) : '—';
$empty = fn(string $text) => '<div style="font-size:13px;color:var(--muted)">' . e($text) . '</div>';
?>
<div class="portal-container">
  <a href="<?= e(base_url(role_portal_path($staff, 'applicants.php'))) ?>" class="staff-back-link"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5m7-7-7 7 7 7"/></svg><span>Back</span></a>
  <h1 class="font-heading mt-2 mb-0" style="font-size:26px"><?= e($applicant['full_name']) ?></h1>
  <div style="font-size:13px;color:var(--muted)">Applicant record · @<?= e($applicant['username']) ?></div>

  <nav class="record-nav mt-3" aria-label="Record sections">
    <a href="#profile">Profile</a><a href="#applications">Applications</a><a href="#renewals">Renewals</a><a href="#documents">Documents</a>
    <a href="#appointments">Appointments</a><a href="#decisions">Decisions</a><a href="#history">Status history</a>
  </nav>

  <div class="review-card" id="profile">
    <div class="review-card-head"><div class="title">Profile</div></div>
    <div class="d-flex align-items-center gap-3 mb-3">
      <?php if ($applicant['profile_photo']): ?>
        <img src="<?= base_url('avatar.php?user_id=' . (int) $applicant['id']) ?>" alt="" style="width:56px;height:56px;border-radius:50%;object-fit:cover">
      <?php else: ?>
        <div class="record-avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr(trim($applicant['full_name']), 0, 1))) ?></div>
      <?php endif; ?>
      <div>
        <div style="font-weight:700;font-size:16px;color:var(--ink)"><?= e($applicant['full_name']) ?></div>
        <div style="font-size:13px;color:var(--muted)">@<?= e($applicant['username']) ?></div>
      </div>
    </div>
    <div class="review-grid">
      <div class="review-row"><div class="k">Email</div><div class="v"><?= e($applicant['email']) ?></div></div>
      <div class="review-row"><div class="k">Mobile</div><div class="v">
        <?php if (SMSService::confirmedNumber($applicant['mobile'], $applicant['mobile_verified_at']) !== null): ?>
          <?= e($applicant['mobile']) ?><span style="display:block;font-size:12px;color:var(--muted)">Confirmed · used for SMS about their applications</span>
        <?php elseif ($applicant['mobile']): ?>
          <?= e($applicant['mobile']) ?><span style="display:block;font-size:12px;color:var(--muted)">Not confirmed · SMS goes to the number on each application</span>
        <?php else: ?>—<?php endif; ?>
      </div></div>
      <div class="review-row"><div class="k">Account status</div><div class="v"><span class="status-pill" style="background:<?= $applicant['is_active'] ? '#dcf3e2' : '#fde8e8' ?>;color:<?= $applicant['is_active'] ? '#1c7a3f' : '#a51c1c' ?>"><?= $applicant['is_active'] ? 'Active' : 'Deactivated' ?></span></div></div>
      <div class="review-row"><div class="k">Birthdate</div><div class="v"><?= e($profile['birthdate'] ? format_date_long($profile['birthdate']) : '—') ?></div></div>
      <div class="review-row"><div class="k">Sex</div><div class="v"><?= e($profile['sex'] ?: '—') ?></div></div>
      <div class="review-row"><div class="k">Civil status</div><div class="v"><?= e($profile['civil_status'] ?: '—') ?></div></div>
      <div class="review-row"><div class="k">Address</div><div class="v"><?= e(trim(($profile['address'] ?? '') . ($profile['barangay'] ? ', Brgy. ' . $profile['barangay'] : ''), ', ') ?: '—') ?></div></div>
      <div class="review-row"><div class="k">Preliminary self-check</div><div class="v">
        <?php if ($eligibilityCheck): ?>
          <?= $eligibilityCheck['result']['eligible'] ? 'May be eligible' : 'May not meet criteria' ?>
          <span style="color:var(--faint)">(<?= e(date('M j, Y', strtotime($eligibilityCheck['checkedAt']))) ?>; not an MSWD decision)</span>
        <?php else: ?>—<?php endif; ?>
      </div></div>
      <div class="review-row"><div class="k">Registered</div><div class="v"><?= e(date('F j, Y', strtotime($applicant['created_at']))) ?></div></div>
    </div>
  </div>

  <?php foreach ([['applications', 'Application History', $newApps, 'No new applications on file.'], ['renewals', 'Renewal History', $renewals, 'No renewal requests on file.']] as [$anchor, $title, $rows, $none]): ?>
  <div class="review-card" id="<?= $anchor ?>">
    <div class="review-card-head"><div class="title"><?= e($title) ?> (<?= count($rows) ?>)</div></div>
    <?php if (!$rows): ?><?= $empty($none) ?><?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm record-table">
        <thead><tr><th>Reference</th><?php if ($anchor === 'renewals'): ?><th>Renews</th><?php endif; ?><th>Category</th><th>Submitted</th><th>Status</th><th>Reviewed by</th><th>ID valid until</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($rows as $app): ?>
            <tr<?= $selected && (int) $selected['id'] === (int) $app['id'] ? ' class="record-selected"' : '' ?>>
              <td><a href="<?= e(application_url($staff, (int) $app['id'])) ?>"><b><?= e($app['reference_no']) ?></b></a></td>
              <?php if ($anchor === 'renewals'): ?><td><?= e($app['renews_reference'] ?: '—') ?></td><?php endif; ?>
              <td><?= e($app['category_name']) ?></td>
              <td><?= e($when($app['submitted_at'], 'M j, Y')) ?></td>
              <td><?= $pill($app['status']) ?></td>
              <td><?= e($app['reviewed_by_name'] ?: '—') ?></td>
              <td><?= e($app['id_expires_at'] ? $when($app['id_expires_at'], 'M j, Y') . (strtotime($app['id_expires_at']) < strtotime('today') ? ' (expired)' : '') : '—') ?></td>
              <td><a href="<?= e($recordUrl(['app' => (int) $app['id']])) ?>#documents" style="font-size:12px;white-space:nowrap">Records for this only</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>

  <?php if ($applications): ?>
  <form method="get" class="record-scope" id="scope">
    <input type="hidden" name="id" value="<?= (int) $applicant['id'] ?>">
    <label for="scope-app" style="font-weight:700;font-size:14px">Show documents, appointments, decisions and history for</label>
    <select id="scope-app" name="app" style="padding:7px;border:1px solid var(--input-border);border-radius:6px" onchange="this.form.submit()">
      <option value="0">All applications (<?= count($applications) ?>)</option>
      <?php foreach ($applications as $app): ?><option value="<?= (int) $app['id'] ?>" <?= $selected && (int) $selected['id'] === (int) $app['id'] ? 'selected' : '' ?>><?= e($app['reference_no']) ?> — <?= $app['type'] === 'renewal' ? 'Renewal' : 'New' ?>, <?= e($app['status']) ?></option><?php endforeach; ?>
    </select>
    <noscript><button type="submit" class="btn-outline">Show</button></noscript>
  </form>
  <?php endif; ?>

  <div class="review-card" id="documents">
    <div class="review-card-head"><div class="title">Documents (<?= count($documents) ?>) <span style="font-weight:400;font-size:12px;color:var(--muted)">· <?= e($scopeLabel) ?></span></div></div>
    <?php if (!$documents): ?><?= $empty('No documents on file.') ?><?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm record-table">
        <thead><tr><th>Application</th><th>Document</th><th>Uploaded</th><th>Status</th><th>Verified</th><th>Remarks</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($documents as $d): ?>
            <tr>
              <td><?= e($d['reference_no']) ?></td>
              <td><?= e($d['doc_label']) ?><div style="font-size:11px;color:var(--faint)"><?= e($d['original_name']) ?> · <?= e(number_format($d['size_bytes'] / 1024, 0)) ?> KB</div></td>
              <td><?= e($when($d['uploaded_at'])) ?></td>
              <td><?= $docPill($d['verification_status']) ?></td>
              <td><?= $d['verified_at'] ? e($when($d['verified_at'])) . '<div style="font-size:11px;color:var(--faint)">' . e($d['verified_by_name'] ?: 'former staff member') . '</div>' : '—' ?></td>
              <td><?= e($d['staff_remarks'] ?: '—') ?></td>
              <td><a href="<?= e(base_url('download.php?doc_id=' . (int) $d['id'])) ?>" target="_blank" rel="noopener">View</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <div class="review-card" id="appointments">
    <div class="review-card-head"><div class="title">Appointments (<?= count($appointments) ?>) <span style="font-weight:400;font-size:12px;color:var(--muted)">· <?= e($scopeLabel) ?></span></div></div>
    <?php if (!$appointments): ?><?= $empty('No appointments on file.') ?><?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm record-table">
        <thead><tr><th>Application</th><th>Date &amp; time</th><th>Location</th><th>Status</th><th>Outcome / notes</th></tr></thead>
        <tbody>
          <?php foreach ($appointments as $i): [$al, $abg, $afg] = $apptLabels[$i['status']]; ?>
            <tr>
              <td><?= e($i['reference_no']) ?></td>
              <td><?= e($when($i['scheduled_at'])) ?></td>
              <td><?= e($i['location']) ?></td>
              <td><span class="status-pill" style="background:<?= $abg ?>;color:<?= $afg ?>;font-size:11px"><?= $al ?></span></td>
              <td>
                <?php if ($i['status'] === 'CANCELLED'): ?>Cancelled <?= e($when($i['cancelled_at'])) ?><?= $i['cancelled_by_name'] ? ' by ' . e($i['cancelled_by_name']) : '' ?><?= $i['cancel_reason'] ? ': ' . e($i['cancel_reason']) : '' ?>
                <?php elseif ($i['result']): ?><?= e($i['result']) ?> <span style="color:var(--faint)">(<?= e($when($i['result_recorded_at'])) ?>)</span>
                <?php else: ?><?= e($i['notes'] ?: '—') ?><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <div class="review-card" id="decisions">
    <div class="review-card-head"><div class="title">Decisions (<?= count($decisions) ?>) <span style="font-weight:400;font-size:12px;color:var(--muted)">· <?= e($scopeLabel) ?></span></div></div>
    <?php if (!$decisions): ?><?= $empty('No approve, reject or correction decision yet.') ?><?php endif; ?>
    <?php foreach ($decisions as $d): [$dl, $dbg, $dfg] = $decisionLabels[$d['decision']]; ?>
      <div class="case-entry">
        <div><b><?= e($d['reference_no']) ?></b> <span class="status-pill" style="background:<?= $dbg ?>;color:<?= $dfg ?>;font-size:11px"><?= e($dl) ?></span>
          <span class="when"><?= e($when($d['decided_at'])) ?> · <?= e($d['decided_by_name'] ?? 'former staff member') ?></span></div>
        <?php if ($d['reason']): ?><div class="text"><?= e($d['reason']) ?></div><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="review-card" id="history">
    <div class="review-card-head"><div class="title">Status History (<?= (int) $history['total'] ?>) <span style="font-weight:400;font-size:12px;color:var(--muted)">· <?= e($scopeLabel) ?></span></div></div>
    <?php if (!$history['rows']): ?><?= $empty('No status changes recorded.') ?><?php endif; ?>
    <?php foreach ($history['rows'] as $h): ?>
      <div class="case-entry">
        <div><b><?= e($h['reference_no']) ?></b> <?= $pill($h['status']) ?> <span class="when"><?= e($when($h['changed_at'])) ?> · <?= e($h['changed_by'] ?: 'System') ?></span></div>
        <?php if ($h['remarks']): ?><div class="text"><?= e($h['remarks']) ?></div><?php endif; ?>
      </div>
    <?php endforeach; ?>
    <?php if ($history['totalPages'] > 1): $base = ['app' => $selected ? (int) $selected['id'] : 0]; ?>
      <div class="d-flex justify-content-between align-items-center mt-2">
        <a href="<?= e($recordUrl($base + ['hpage' => max(1, $history['page'] - 1)])) ?>#history" class="btn-outline text-decoration-none" style="<?= $history['page'] <= 1 ? 'pointer-events:none;opacity:.4' : '' ?>">&larr; Newer</a>
        <span style="font-size:13px;color:var(--muted)">Page <?= $history['page'] ?> of <?= $history['totalPages'] ?></span>
        <a href="<?= e($recordUrl($base + ['hpage' => min($history['totalPages'], $history['page'] + 1)])) ?>#history" class="btn-outline text-decoration-none" style="<?= $history['page'] >= $history['totalPages'] ? 'pointer-events:none;opacity:.4' : '' ?>">Older &rarr;</a>
      </div>
    <?php endif; ?>
  </div>
</div>
