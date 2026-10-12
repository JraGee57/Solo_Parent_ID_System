<?php
/**
 * Read-only application monitoring list.
 *
 * @var array  $staff
 * @var string $statusFilter
 * @var string $typeFilter
 * @var string $q
 * @var array  $validStatuses
 * @var array  $stageCounts  status => count
 * @var array  $apps
 */
?>
<div class="portal-container">
  <a href="<?= base_url('admin/dashboard.php') ?>" class=" staff-back-link"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5m7-7-7 7 7 7"/></svg><span>Back</span></a>
  <div class="page-head">
    <div>
      <h1 class="page-title">Application Monitoring</h1>
      <div class="page-sub">Read-only view. Application decisions (approve, reject, request corrections) are made by MSWD staff.</div>
    </div>
    <span style="font-size:14px">Signed in as <b><?= e($staff['full_name']) ?></b></span>
  </div>

  <nav class="d-flex flex-wrap gap-2 mb-4" aria-label="Filter by status">
    <?php foreach ($validStatuses as $vs): ?>
      <a href="?status=<?= urlencode($vs) ?>" class="status-pill text-decoration-none"<?= $statusFilter === $vs ? ' aria-current="page"' : '' ?> style="background:<?= e(status_style($vs)['bg']) ?>;color:<?= e(status_style($vs)['color']) ?>"><?= e($vs) ?> (<?= (int) ($stageCounts[$vs] ?? 0) ?>)</a>
    <?php endforeach; ?>
  </nav>

  <form method="get" class="filter-bar mb-4" role="search" aria-label="Search applications">
    <input type="text" name="q" <?= ($_GET['focus'] ?? '') === 'search' ? 'autofocus' : '' ?> aria-label="Search by reference number or name" value="<?= e($q) ?>" placeholder="Search reference no. or name" class="form-control filter-q">
    <select name="status" class="form-select filter-sm" aria-label="Status">
      <option value="ALL" <?= $statusFilter === 'ALL' ? 'selected' : '' ?>>All statuses</option>
      <?php foreach ($validStatuses as $s): ?>
        <option value="<?= e($s) ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= e($s) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="type" class="form-select filter-sm" aria-label="Application type">
      <option value="ALL" <?= $typeFilter === 'ALL' ? 'selected' : '' ?>>New + Renewals</option>
      <option value="new" <?= $typeFilter === 'new' ? 'selected' : '' ?>>New Applications</option>
      <option value="renewal" <?= $typeFilter === 'renewal' ? 'selected' : '' ?>>Renewals Only</option>
    </select>
    <button type="submit" class="btn-navy">Search</button>
  </form>

  <?php if (!$apps): ?>
    <div class="empty-state">
      <div class="empty-state-title">No applications match this search</div>
      Try a different reference number, name or status. <a href="<?= base_url('admin/applications.php') ?>">Clear search &amp; filters</a>
    </div>
  <?php endif; ?>

  <?php require APP_ROOT . '/views/partials/admin-list-meta.php'; ?>
  <?php if ($apps): ?><div class="admin-list-scroll" role="region" aria-label="Application monitoring records" tabindex="0"><?php endif; ?>
  <?php foreach ($apps as $app): $st = status_style($app['status']); ?>
    <a href="<?= base_url('admin/application_view.php?id=' . (int) $app['id']) ?>" class="app-card text-decoration-none" style="color:inherit">
      <div>
        <div class="app-ref"><?= e($app['reference_no']) ?><?php if ($app['type'] === 'renewal'): ?> <span class="doc-tag" style="vertical-align:middle">RENEWAL</span><?php endif; ?></div>
        <div class="app-date"><?= e($app['full_name']) ?> · <?= e($app['category_name']) ?></div>
        <div class="app-date">Submitted <?= e(date('M j, Y', strtotime($app['submitted_at']))) ?></div>
      </div>
      <div class="status-pill" style="background:<?= e($st['bg']) ?>;color:<?= e($st['color']) ?>"><?= e($app['status']) ?></div>
    </a>
  <?php endforeach; ?>
  <?php if ($apps): ?></div><?php endif; ?>
  <?php require APP_ROOT . '/views/partials/pagination.php'; ?>
</div>
