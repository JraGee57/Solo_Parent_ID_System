<?php
/**
 * Printable report: a plain page with every matching row, the filters
 * used, and who generated it when. Opens the print dialog on load.
 *
 * @var string $title
 * @var string $description
 * @var string $filterText
 * @var array  $columns  key => [label, format]
 * @var array  $rows
 * @var array  $totals
 * @var array  $account
 */
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> — <?= e(setting('office_name')) ?></title>
<style>
  body { font-family: Arial, Helvetica, sans-serif; color: #1a2233; background: #fff; margin: 24px; font-size: 12px; }
  header { border-bottom: 2px solid #1b3a6b; padding-bottom: 10px; margin-bottom: 14px; }
  .org { font-size: 12px; color: #5a6472; }
  h1 { font-size: 20px; margin: 4px 0; color: #1b3a6b; }
  .meta { font-size: 11px; color: #5a6472; line-height: 1.6; }
  table { width: 100%; border-collapse: collapse; }
  th, td { text-align: left; padding: 4px 6px; border-bottom: 1px solid #e2e6ec; vertical-align: top; }
  thead th { border-bottom: 1.5px solid #1a2233; font-size: 11px; }
  thead { display: table-header-group; }
  tr { page-break-inside: avoid; }
  .num { text-align: right; font-variant-numeric: tabular-nums; }
  tfoot th { border-top: 1.5px solid #1a2233; border-bottom: none; }
  .actions { margin-bottom: 14px; }
  .actions button { padding: 6px 14px; font-size: 13px; cursor: pointer; }
  footer { margin-top: 14px; font-size: 10px; color: #8a93a3; }
  @media print { body { margin: 0; } .actions { display: none; } @page { margin: 12mm; } }
</style>
</head>
<body>
  <div class="actions"><button type="button" onclick="window.print()">Print</button> <button type="button" onclick="window.close()">Close</button></div>
  <header>
    <div class="org"><?= e(setting('office_name')) ?> · <?= e(setting('system_name')) ?></div>
    <h1><?= e($title) ?></h1>
    <div class="meta">
      <?= e($description) ?><br>
      Filters: <?= e($filterText) ?> · <?= number_format(count($rows)) ?> record<?= count($rows) === 1 ? '' : 's' ?><br>
      Generated <?= e(date('F j, Y g:i A')) ?> by <?= e($account['full_name']) ?> (<?= e(User::ROLE_LABELS[$account['role']] ?? $account['role']) ?>)
    </div>
  </header>
  <?php if (!$rows): ?>
    <p>No records match these filters.</p>
  <?php else: ?>
    <table>
      <thead><tr><?php foreach ($columns as [$label, $format]): ?><th class="<?= $format === 'int' ? 'num' : '' ?>"><?= e($label) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
        <?php foreach ($rows as $row): ?>
          <tr><?php foreach ($columns as $key => [, $format]): ?><td class="<?= $format === 'int' ? 'num' : '' ?>"><?= e(ReportService::formatCell($row[$key] ?? null, $format)) ?></td><?php endforeach; ?></tr>
        <?php endforeach; ?>
      </tbody>
      <?php if ($totals): ?>
        <tfoot><tr><?php $first = true; foreach ($columns as $key => [, $format]): ?><th class="<?= $format === 'int' ? 'num' : '' ?>"><?= $first ? 'Total' : (isset($totals[$key]) ? number_format((int) $totals[$key]) : '') ?></th><?php $first = false; endforeach; ?></tr></tfoot>
      <?php endif; ?>
    </table>
  <?php endif; ?>
  <footer>Confidential: contains personal information of applicants. Handle according to the Data Privacy Act of 2012 (RA 10173).</footer>
  <script>window.addEventListener('load', function () { window.print(); });</script>
</body>
</html>
