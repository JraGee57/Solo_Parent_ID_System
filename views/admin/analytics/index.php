<?php
/**
 * Administrator Analytics (AnalyticsService::build()). Every figure is
 * counted from the database for the chosen period.
 *
 * @var array $period          key, label, from, to, error
 * @var array $cards
 * @var array $monthly         ym => [new, renewal]
 * @var bool  $monthlyTruncated
 * @var array $byBarangay      [name, n, other?]
 * @var int   $barangayCount
 * @var array $byStatus        status => n (now)
 * @var array $byCategory      rows of name, n
 * @var array $bySex, $byCivilStatus  rows of name, n
 * @var array $expiringIds
 * @var array $appointments    byStatus, today, upcoming, slots, capacity, booked, utilization
 */
require_once APP_ROOT . '/views/partials/charts.php';
$apptLabels = ['BOOKED' => 'Booked', 'COMPLETED' => 'Completed', 'MISSED' => 'Missed (no-show)', 'CANCELLED' => 'Cancelled'];
$months = [];
foreach (array_keys($monthly) as $ym) {
    $months[$ym] = date(count($monthly) > 12 ? "M 'y" : 'M', strtotime($ym . '-01'));
}
$periodText = e($period['label']);
?>
<div class="portal-container vz-root">
  <a href="<?= base_url('admin/dashboard.php') ?>" class="no-print staff-back-link"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5m7-7-7 7 7 7"/></svg><span>Back</span></a>
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mt-2 mb-3">
    <div>
      <h1 class="font-heading m-0" style="font-size:26px">Analytics</h1>
      <div style="font-size:13px;color:var(--muted)">Period: <?= $periodText ?> · counted live from the database</div>
    </div>
    <div class="d-flex gap-2 no-print">
      <button type="button" onclick="window.print()" class="btn-outline" style="padding:8px 16px;font-size:13px">Print</button>
      <a href="<?= base_url('admin/reports.php') ?>" class="btn-navy text-decoration-none" style="padding:8px 16px;font-size:13px">Reports</a>
    </div>
  </div>

  <form method="get" class="analytics-filters" aria-label="Analytics period">
    <label>Period
      <select name="period" onchange="document.getElementById('custom-range').hidden = this.value !== 'custom'">
        <?php foreach (AnalyticsService::PERIODS as $k => $label): ?><option value="<?= e($k) ?>" <?= $period['key'] === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
      </select>
    </label>
    <span id="custom-range" class="analytics-range" <?= $period['key'] === 'custom' ? '' : 'hidden' ?>>
      <label>From<input type="date" name="from" value="<?= e($period['from']) ?>"></label>
      <label>To<input type="date" name="to" value="<?= e($period['to']) ?>"></label>
    </span>
    <button type="submit" class="btn-navy">Apply</button>
  </form>
  <?php if ($period['error']): ?><div class="auth-error mb-3" role="alert"><?= e($period['error']) ?> Showing the last 12 months instead.</div><?php endif; ?>

  <div class="stat-tiles">
    <div class="stat-tile"><div class="stat-label">Total applicants</div><div class="stat-value"><?= chart_num($cards['applicants']) ?></div><div class="stat-sub">registered accounts · <?= chart_num($cards['applicantsWithApplication']) ?> have applied</div></div>
    <div class="stat-tile"><div class="stat-label">New applications</div><div class="stat-value"><?= chart_num($cards['new']) ?></div><div class="stat-sub">submitted in period</div></div>
    <div class="stat-tile"><div class="stat-label">Renewals</div><div class="stat-value"><?= chart_num($cards['renewals']) ?></div><div class="stat-sub">submitted in period</div></div>
    <div class="stat-tile"><div class="stat-label">Approved</div><div class="stat-value"><?= chart_num($cards['approved']) ?></div><div class="stat-sub">approved in period</div></div>
    <div class="stat-tile"><div class="stat-label">Rejected</div><div class="stat-value"><?= chart_num($cards['rejected']) ?></div><div class="stat-sub">rejected in period</div></div>
    <div class="stat-tile"><div class="stat-label">Pending</div><div class="stat-value"><?= chart_num($cards['pending']) ?></div><div class="stat-sub">in progress now</div></div>
    <div class="stat-tile"><div class="stat-label">Expiring IDs</div><div class="stat-value"><?= chart_num($cards['expiring']) ?></div><div class="stat-sub">within <?= (int) $cards['expiringWindow'] ?> days · <?= chart_num($cards['expired']) ?> expired, not renewed</div></div>
    <div class="stat-tile"><div class="stat-label">Appointments</div><div class="stat-value"><?= chart_num($cards['appointments']) ?></div><div class="stat-sub">scheduled in period</div></div>
  </div>

  <div class="review-card">
    <div class="review-card-head"><div class="title">Applications by month</div></div>
    <div class="settings-note mb-2">New applications and renewals by submission month<?= $monthlyTruncated ? ' — the last ' . AnalyticsService::MAX_MONTHS . ' months of the period' : '' ?>.</div>
    <?= chart_stacked_columns($months, ['new' => ['New applications', '--series-1'], 'renewal' => ['Renewals', '--series-2']], $monthly, 'Applications submitted per month, new and renewal, ' . $period['label']) ?>
  </div>

  <div class="row g-3">
    <div class="col-12 col-lg-6">
      <div class="review-card h-100 mb-0">
        <div class="review-card-head"><div class="title">Applications by barangay</div></div>
        <div class="settings-note mb-2"><?= $barangayCount ?> barangay<?= $barangayCount === 1 ? '' : 's' ?> in the period<?= $barangayCount > AnalyticsService::BARANGAY_TOP ? '; the top ' . AnalyticsService::BARANGAY_TOP . ' are shown, the rest grouped as Other' : '' ?>.</div>
        <?php if ($byBarangay): ?>
          <?= chart_bars(array_map(fn($b) => [$b['name'], $b['n']], $byBarangay), 'Applications by barangay, ' . $period['label'], '--series-1', 'applications') ?>
        <?php else: ?><div style="font-size:13px;color:var(--muted)">No applications in this period.</div><?php endif; ?>
      </div>
    </div>
    <div class="col-12 col-lg-6">
      <div class="review-card h-100 mb-0">
        <div class="review-card-head"><div class="title">Applications by status (now)</div></div>
        <div class="settings-note mb-2">Every application on file by its current status — <?= chart_num($cards['pending']) ?> still in progress.</div>
        <?= chart_bars(array_map(fn($s, $n) => [$s, $n], array_keys($byStatus), $byStatus), 'Applications by current status', '--series-1', 'applications') ?>
      </div>
    </div>
    <div class="col-12 col-lg-6">
      <div class="review-card h-100 mb-0">
        <div class="review-card-head"><div class="title">Applications by category</div></div>
        <div class="settings-note mb-2">Solo-parent category of the applications submitted in the period.</div>
        <?= chart_bars(array_map(fn($c) => [$c['name'], $c['n']], $byCategory), 'Applications by solo-parent category, ' . $period['label'], '--series-1', 'applications') ?>
      </div>
    </div>
    <div class="col-12 col-lg-6">
      <div class="review-card h-100 mb-0">
        <div class="review-card-head"><div class="title">Appointment statistics</div></div>
        <div class="settings-note mb-2">Appointments scheduled in the period, by outcome.</div>
        <?= chart_bars(array_map(fn($s, $n) => [$apptLabels[$s] ?? $s, $n], array_keys($appointments['byStatus']), $appointments['byStatus']), 'Appointments by outcome, ' . $period['label'], '--series-1', 'appointments') ?>
        <div class="review-grid mt-3">
          <div class="review-row"><div class="k">Booked for today</div><div class="v"><?= chart_num($appointments['today']) ?></div></div>
          <div class="review-row"><div class="k">Upcoming (booked)</div><div class="v"><?= chart_num($appointments['upcoming']) ?></div></div>
        </div>
        <div style="font-size:13px;color:var(--ink);margin:12px 0 6px">Open slots from now on: <b><?= chart_num($appointments['booked']) ?></b> of <?= chart_num($appointments['capacity']) ?> seats booked<?= $appointments['utilization'] !== null ? ' (' . $appointments['utilization'] . '%)' : '' ?> across <?= chart_num($appointments['slots']) ?> slot<?= $appointments['slots'] === 1 ? '' : 's' ?></div>
        <?php if ($appointments['capacity'] > 0): ?>
          <?= chart_meter($appointments['booked'], $appointments['capacity'], 'Seats booked in open upcoming slots') ?>
        <?php else: ?><div style="font-size:13px;color:var(--muted)">No open slots are scheduled.</div><?php endif; ?>
      </div>
    </div>
  </div>

  <div class="review-card mt-3">
    <div class="review-card-head"><div class="title">Applicant demographics</div></div>
    <div class="settings-note mb-2">From the applications submitted in the period.</div>
    <?php if (!$bySex): ?>
      <div style="font-size:13px;color:var(--muted)">No applications in this period.</div>
    <?php else: ?>
      <div class="row g-4">
        <div class="col-12 col-md-6"><div style="font-size:12px;font-weight:700;color:var(--muted);margin-bottom:6px">Sex</div><?= chart_bars(array_map(fn($r) => [$r['name'], $r['n']], $bySex), 'Applications by sex of the applicant, ' . $period['label'], '--series-1', 'applications') ?></div>
        <div class="col-12 col-md-6"><div style="font-size:12px;font-weight:700;color:var(--muted);margin-bottom:6px">Civil status</div><?= chart_bars(array_map(fn($r) => [$r['name'], $r['n']], $byCivilStatus), 'Applications by civil status, ' . $period['label'], '--series-1', 'applications') ?></div>
      </div>
    <?php endif; ?>
  </div>

  <div class="review-card mt-3">
    <div class="review-card-head"><div class="title">IDs expiring within <?= (int) $cards['expiringWindow'] ?> days (<?= count($expiringIds) ?>)</div></div>
    <div class="settings-note mb-2">Released IDs expiring from today through the renewal reminder period (System Settings). <?= chart_num($cards['expired']) ?> already expired without a renewal.</div>
    <?php if (!$expiringIds): ?>
      <div style="font-size:13px;color:var(--muted)">No IDs expire in the next <?= (int) $cards['expiringWindow'] ?> days.</div>
    <?php else: ?>
      <div class="table-responsive"><table class="table table-sm" style="font-size:13px">
        <thead><tr><th>Reference</th><th>Name</th><th>Barangay</th><th>Expires</th><th>Days left</th><th>Renewal</th></tr></thead>
        <tbody>
          <?php foreach ($expiringIds as $x): $left = (int) floor((strtotime($x['id_expires_at']) - strtotime('today')) / 86400); ?>
            <tr>
              <td><a href="<?= e(application_url($staff, (int) $x['id'])) ?>"><?= e($x['reference_no']) ?></a></td>
              <td><?= e($x['full_name']) ?></td>
              <td><?= e($x['barangay']) ?></td>
              <td><?= e(date('M j, Y', strtotime($x['id_expires_at']))) ?></td>
              <td style="font-variant-numeric:tabular-nums"><?= $left === 0 ? 'today' : $left ?></td>
              <td><?= $x['renewal_filed'] ? 'Filed' : 'Not yet' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </div>
</div>
<script src="<?= public_asset('js/charts.js') ?>" defer></script>
