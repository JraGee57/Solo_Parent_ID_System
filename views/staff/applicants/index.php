<?php
/**
 * @var array  $staff
 * @var string $ownDashboard
 * @var array  $filters     q, barangay, status, type, account, sort (validated)
 * @var array  $barangays
 * @var array  $statuses
 * @var array  $applicants  each with app_count, last_activity, profile_barangay and up to 3 'applications'
 * @var array  $pager
 */
$active = array_filter(array_diff_key($filters, ['sort' => 1]), fn($v) => $v !== '');
$field = 'padding:9px;border:1px solid var(--input-border);border-radius:6px;font-size:14px';
?>
<div class="portal-container">
  <a href="<?= e($ownDashboard) ?>" class="staff-back-link"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5m7-7-7 7 7 7"/></svg><span>Back</span></a>
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mt-2 mb-3">
    <h1 class="font-heading m-0" style="font-size:26px">Applicant Records</h1>
    <span style="font-size:14px">Signed in as <b><?= e($staff['full_name']) ?></b></span>
  </div>

  <form method="get" class="record-filters mb-3" role="search" aria-label="Search applicant records">
    <input type="text" name="q" value="<?= e($filters['q']) ?>" maxlength="100" placeholder="Name, reference no., username, email or mobile" aria-label="Name, reference number, username, email or mobile" style="<?= $field ?>;flex:2 1 260px">
    <select name="barangay" aria-label="Barangay" style="<?= $field ?>">
      <option value="">All barangays</option>
      <?php foreach ($barangays as $b): ?><option value="<?= e($b) ?>" <?= $filters['barangay'] === $b ? 'selected' : '' ?>><?= e($b) ?></option><?php endforeach; ?>
    </select>
    <select name="status" aria-label="Application status" style="<?= $field ?>">
      <option value="">Any status</option>
      <?php foreach ($statuses as $s): ?><option value="<?= e($s) ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?>
      <option value="NONE" <?= $filters['status'] === 'NONE' ? 'selected' : '' ?>>No application yet</option>
    </select>
    <select name="type" aria-label="Application type" style="<?= $field ?>">
      <option value="">New &amp; renewal</option>
      <?php foreach (RecordService::TYPES as $k => $label): ?><option value="<?= $k ?>" <?= $filters['type'] === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
    </select>
    <select name="account" aria-label="Account status" style="<?= $field ?>">
      <option value="">All accounts</option>
      <?php foreach (RecordService::ACCOUNT_STATES as $k => $label): ?><option value="<?= $k ?>" <?= $filters['account'] === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
    </select>
    <select name="sort" aria-label="Sort by" style="<?= $field ?>">
      <?php foreach (RecordService::SORTS as $k => $label): ?><option value="<?= $k ?>" <?= $filters['sort'] === $k ? 'selected' : '' ?>>Sort: <?= e($label) ?></option><?php endforeach; ?>
    </select>
    <button type="submit" class="btn-navy">Search</button>
    <?php if ($active): ?><a href="<?= e(base_url(role_portal_path($staff, 'applicants.php'))) ?>" class="btn-outline text-decoration-none">Clear</a><?php endif; ?>
  </form>

  <div style="font-size:13px;color:var(--muted);margin-bottom:12px" role="status">
    <?= (int) $pager['total'] ?> applicant record<?= (int) $pager['total'] === 1 ? '' : 's' ?><?= $active ? ' match these filters' : '' ?>.
    <?php if ($filters['status'] !== '' && $filters['status'] !== 'NONE' || $filters['type'] !== ''): ?>Applications shown are the ones that match.<?php endif; ?>
  </div>

  <?php if (!$applicants): ?>
    <div style="font-size:14px;color:var(--muted)">No applicant records match this search.</div>
  <?php endif; ?>

  <?php foreach ($applicants as $a): ?>
    <a href="<?= e(base_url(role_portal_path($staff, 'applicant_view.php') . '?id=' . (int) $a['id'])) ?>" class="app-card text-decoration-none" style="color:inherit;align-items:flex-start">
      <div style="min-width:0">
        <div class="app-ref"><?= e($a['full_name']) ?> <span style="color:var(--faint);font-weight:400">@<?= e($a['username']) ?></span></div>
        <div class="app-date"><?= e($a['email']) ?><?php if ($a['mobile']): ?> · <?= e($a['mobile']) ?><?php if (SMSService::confirmedNumber($a['mobile'], $a['mobile_verified_at']) === null): ?> (not confirmed)<?php endif; ?><?php endif; ?><?php if ($a['profile_barangay']): ?> · Brgy. <?= e($a['profile_barangay']) ?><?php endif; ?></div>
        <div class="app-date"><?= (int) $a['app_count'] ?> application<?= (int) $a['app_count'] === 1 ? '' : 's' ?><?php if ($a['last_activity']): ?> · latest submitted <?= e(date('M j, Y', strtotime($a['last_activity']))) ?><?php endif; ?></div>
        <?php if ($a['applications']): ?>
          <div class="d-flex flex-wrap gap-2 mt-2">
            <?php foreach ($a['applications'] as $app): $st = status_style($app['status']); ?>
              <span class="record-chip"><b><?= e($app['reference_no']) ?></b> · <?= $app['type'] === 'renewal' ? 'Renewal' : 'New' ?> · <span class="status-pill" style="background:<?= e($st['bg']) ?>;color:<?= e($st['color']) ?>;font-size:11px;padding:1px 7px"><?= e($app['status']) ?></span></span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
      <span class="status-pill" style="background:<?= $a['is_active'] ? '#dcf3e2' : '#fde8e8' ?>;color:<?= $a['is_active'] ? '#1c7a3f' : '#a51c1c' ?>"><?= $a['is_active'] ? 'Active' : 'Deactivated' ?></span>
    </a>
  <?php endforeach; ?>

<?php require APP_ROOT . '/views/partials/pagination.php'; ?>
</div>
