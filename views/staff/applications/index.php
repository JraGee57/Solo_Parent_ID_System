<?php
/**
 * @var array  $staff
 * @var string $ownDashboard
 * @var array  $filters   status, type, barangay, q
 * @var array  $statuses
 * @var array  $apps
 * @var array  $pager
 */
$hasFilters = $filters['q'] !== '' || $filters['barangay'] !== '' || $filters['status'] !== 'ALL' || $filters['type'] !== 'ALL';
?>
<div class="portal-container">
  <a href="<?= e($ownDashboard) ?>" class="staff-back-link"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5m7-7-7 7 7 7"/></svg><span>Back</span></a>
  <div class="page-head">
    <div>
      <h1 class="page-title">Applications</h1>
      <div class="page-sub">Search, review and process Solo Parent ID applications and renewals.</div>
    </div>
    <span style="font-size:14px">Signed in as <b><?= e($staff['full_name']) ?></b></span>
  </div>

  <form method="get" class="filter-bar" role="search" aria-label="Search applications">
    <input type="search" name="q" <?= ($_GET['focus'] ?? '') === 'search' ? 'autofocus' : '' ?> aria-label="Search by reference number or name" value="<?= e($filters['q']) ?>" placeholder="Search reference no. or name" class="form-control filter-q">
    <input type="text" name="barangay" aria-label="Barangay" value="<?= e($filters['barangay']) ?>" placeholder="Barangay" class="form-control filter-sm">
    <select name="status" class="form-select filter-sm" aria-label="Status">
      <option value="ALL" <?= $filters['status'] === 'ALL' ? 'selected' : '' ?>>All statuses</option>
      <?php foreach ($statuses as $s): ?>
        <option value="<?= e($s) ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>><?= e($s) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="type" class="form-select filter-sm" aria-label="Application type">
      <option value="ALL" <?= $filters['type'] === 'ALL' ? 'selected' : '' ?>>New + Renewals</option>
      <option value="new" <?= $filters['type'] === 'new' ? 'selected' : '' ?>>New Applications</option>
      <option value="renewal" <?= $filters['type'] === 'renewal' ? 'selected' : '' ?>>Renewals Only</option>
    </select>
    <button type="submit" class="btn-navy">Search</button>
  </form>

  <div class="result-meta" role="status">
    <span><b style="color:var(--ink)"><?= (int) $pager['total'] ?></b> application<?= (int) $pager['total'] === 1 ? '' : 's' ?><?= $hasFilters ? ' match your search' : ' in total' ?></span>
    <?php if ($hasFilters): ?><a href="<?= base_url('staff/index.php') ?>">Clear search &amp; filters</a><?php endif; ?>
  </div>

  <?php if (!$apps): ?>
    <div class="empty-state">
      <div class="empty-state-title"><?= $hasFilters ? 'No applications match your search' : 'No applications yet' ?></div>
      <?= $hasFilters ? 'Check the reference number or name spelling, or clear the filters to see every application.' : 'New applications will appear here as soon as applicants submit them.' ?>
    </div>
  <?php endif; ?>
  <?php foreach ($apps as $app): $st = status_style($app['status']); ?>
    <a href="<?= base_url('staff/application.php?id=' . (int) $app['id']) ?>" class="app-card text-decoration-none" style="color:inherit">
      <div>
        <div class="app-ref"><?= e($app['reference_no']) ?><?php if ($app['type'] === 'renewal'): ?> <span class="doc-tag" style="vertical-align:middle">RENEWAL</span><?php endif; ?></div>
        <div class="app-date"><?= e($app['full_name']) ?> · <?= e($app['category_name']) ?> · Brgy. <?= e($app['barangay']) ?></div>
        <div class="app-date">Submitted <?= e(date('M j, Y', strtotime($app['submitted_at']))) ?></div>
      </div>
      <div class="status-pill" style="background:<?= e($st['bg']) ?>;color:<?= e($st['color']) ?>"><?= e($app['status']) ?></div>
    </a>
  <?php endforeach; ?>

<?php require APP_ROOT . '/views/partials/pagination.php'; ?>
</div>
