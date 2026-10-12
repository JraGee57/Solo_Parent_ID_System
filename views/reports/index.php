<?php
/**
 * Reports page, shared by staff/reports.php and admin/reports.php (ReportController::handle()).
 *
 * @var array   $staff
 * @var string  $type
 * @var array   $typeLabels
 * @var string  $description
 * @var array   $filters         from, to, q + report filters
 * @var ?string $filterError
 * @var array   $filterOptions   name => [label, value => label]
 * @var string  $filterText
 * @var string  $dateLabel
 * @var array   $columns         key => [label, format]
 * @var array   $rows            this page
 * @var array   $totals          column totals (grouped reports)
 * @var array   $pager
 * @var string  $basePath
 * @var bool    $canExport
 */
$field = 'padding:8px;border:1px solid var(--input-border);border-radius:6px;font-size:14px;background:#fff';
$exportQuery = fn(string $output) => base_url($basePath) . '?' . http_build_query(array_filter($filters, fn($v) => $v !== '') + ['type' => $type, 'output' => $output]);
?>
<div class="portal-container" style="max-width:1200px">
  <a href="<?= e(base_url($staff['role'] === 'admin' ? 'admin/dashboard.php' : 'staff/dashboard.php')) ?>" style="font-size:13px">&larr; Back to dashboard</a>
  <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mt-2 mb-3">
    <div>
      <h1 class="font-heading m-0" style="font-size:26px"><?= e($typeLabels[$type]) ?></h1>
      <div style="font-size:13px;color:var(--muted);max-width:640px"><?= e($description) ?></div>
    </div>
    <?php if ($canExport): ?>
      <div class="d-flex gap-2">
        <a href="<?= e($exportQuery('print')) ?>" target="_blank" rel="noopener" class="btn-outline text-decoration-none" style="padding:8px 16px;font-size:13px">Print</a>
        <a href="<?= e($exportQuery('csv')) ?>" class="btn-navy text-decoration-none" style="padding:8px 16px;font-size:13px">Download CSV</a>
      </div>
    <?php endif; ?>
  </div>

  <nav class="d-flex flex-wrap gap-2 mb-3" aria-label="Report type">
    <?php foreach ($typeLabels as $key => $label): ?>
      <a href="<?= e(base_url($basePath) . '?type=' . $key) ?>" class="status-pill text-decoration-none" <?= $type === $key ? 'aria-current="page"' : '' ?> style="background:<?= $type === $key ? 'var(--navy)' : '#eef1f5' ?>;color:<?= $type === $key ? '#fff' : 'var(--ink)' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </nav>

  <form method="get" action="<?= e(base_url($basePath)) ?>" class="analytics-filters" role="search" aria-label="Filter the report">
    <input type="hidden" name="type" value="<?= e($type) ?>">
    <label>Search<input type="text" name="q" value="<?= e($filters['q']) ?>" maxlength="100" style="<?= $field ?>;min-width:200px"></label>
    <label><?= e($dateLabel) ?> from<input type="date" name="from" value="<?= e($filters['from']) ?>" style="<?= $field ?>"></label>
    <label>to<input type="date" name="to" value="<?= e($filters['to']) ?>" style="<?= $field ?>"></label>
    <?php foreach ($filterOptions as $name => [$label, $options]): ?>
      <label><?= e($label) ?>
        <select name="<?= e($name) ?>" style="<?= $field ?>">
          <option value="">All</option>
          <?php foreach ($options as $value => $optLabel): ?><option value="<?= e((string) $value) ?>" <?= $filters[$name] === (string) $value ? 'selected' : '' ?>><?= e($optLabel) ?></option><?php endforeach; ?>
        </select>
      </label>
    <?php endforeach; ?>
    <button type="submit" class="btn-navy">Apply</button>
    <a href="<?= e(base_url($basePath) . '?type=' . $type) ?>" class="btn-outline text-decoration-none">Reset</a>
  </form>
  <?php if ($filterError): ?><div class="auth-error mb-3" role="alert"><?= e($filterError) ?> The date range was not applied.</div><?php endif; ?>

  <div style="font-size:13px;color:var(--muted);margin-bottom:10px" role="status"><b style="color:var(--ink)"><?= number_format($pager['total']) ?></b> record<?= $pager['total'] === 1 ? '' : 's' ?> · <?= e($filterText) ?></div>

  <?php if (!$rows): ?>
    <div class="review-card" style="font-size:14px;color:var(--muted)">No records match these filters.</div>
  <?php else: ?>
    <div class="table-responsive report-table">
      <table class="table table-sm table-hover">
        <thead><tr><?php foreach ($columns as [$label, $format]): ?><th scope="col" class="<?= $format === 'int' ? 'num' : '' ?>"><?= e($label) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
            <tr><?php foreach ($columns as $key => [, $format]): ?><td class="<?= $format === 'int' ? 'num' : '' ?>"><?= e(ReportService::formatCell($row[$key] ?? null, $format)) ?></td><?php endforeach; ?></tr>
          <?php endforeach; ?>
        </tbody>
        <?php if ($totals): ?>
          <tfoot><tr><?php $first = true; foreach ($columns as $key => [, $format]): ?><th class="<?= $format === 'int' ? 'num' : '' ?>"><?= $first ? 'Total (all pages)' : (isset($totals[$key]) ? number_format((int) $totals[$key]) : '') ?></th><?php $first = false; endforeach; ?></tr></tfoot>
        <?php endif; ?>
      </table>
    </div>
    <?php require APP_ROOT . '/views/partials/pagination.php'; ?>
  <?php endif; ?>
</div>
