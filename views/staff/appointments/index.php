<?php
/**
 * @var array   $staff
 * @var string  $ownDashboard
 * @var string  $error
 * @var ?string $flashMessage
 * @var bool    $canManage          MSWD staff (cancel / mark missed); administrators view only
 * @var array   $awaitingOutcome    past appointments still BOOKED
 * @var array   $todayAppointments  today's, any status but CANCELLED
 * @var array   $upcomingByDate     Y-m-d => BOOKED appointments after today
 * @var array   $slotsByDate        Y-m-d => slots with booked count
 */
$apptStyles = [
    'BOOKED' => ['#dce8f5', '#1b3a6b', 'Booked'], 'COMPLETED' => ['#dcf3e2', '#1c7a3f', 'Completed'],
    'MISSED' => ['#fde8e8', '#a51c1c', 'Missed'], 'CANCELLED' => ['#f1f1f1', '#555', 'Cancelled'],
];
/** One appointment row: link to the application, time, status, and (for MSWD) the actions allowed now. */
$row = function (array $iv, string $timeFormat) use ($staff, $canManage, $apptStyles): void {
    [$bg, $fg, $label] = $apptStyles[$iv['status']];
    $started = strtotime($iv['scheduled_at']) <= time();
    ?>
    <div class="appt-row">
      <a href="<?= application_url($staff, (int) $iv['application_id']) ?>" class="text-decoration-none" style="color:inherit;min-width:0;flex:1 1 220px">
        <div class="app-ref"><?= e($iv['reference_no']) ?> <span style="font-weight:400;color:var(--muted)">· <?= e($iv['full_name']) ?></span></div>
        <div class="app-date"><?= e(date($timeFormat, strtotime($iv['scheduled_at']))) ?> · <?= e($iv['location']) ?><?= $iv['slot_id'] ? '' : ' · set by staff' ?></div>
      </a>
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <span class="status-pill" style="background:<?= e($bg) ?>;color:<?= e($fg) ?>"><?= e($label) ?></span>
        <?php if ($canManage && $iv['status'] === 'BOOKED'): ?>
          <?php if ($started): ?>
            <a href="<?= application_url($staff, (int) $iv['application_id']) ?>#interview" class="btn-outline text-decoration-none" style="padding:6px 12px;font-size:12px">Record result</a>
            <form method="post" class="m-0" onsubmit="return confirm('Mark this appointment as missed (no-show)?');">
              <?= csrf_field() ?>
              <input type="hidden" name="appointment_id" value="<?= (int) $iv['id'] ?>">
              <button type="submit" name="action" value="missed" class="btn-outline" style="padding:6px 12px;font-size:12px">Mark missed</button>
            </form>
          <?php endif; ?>
          <details class="slot-cancel">
            <summary class="btn-outline" style="padding:6px 12px;font-size:12px">Cancel…</summary>
            <form method="post" class="d-flex flex-column gap-2 mt-2">
              <?= csrf_field() ?>
              <input type="hidden" name="appointment_id" value="<?= (int) $iv['id'] ?>">
              <input type="text" name="reason" aria-label="Reason for cancelling (sent to the applicant)" maxlength="255" required placeholder="Reason (sent to the applicant)" class="form-control" style="font-size:13px;padding:6px 10px">
              <button type="submit" name="action" value="cancel" class="btn-red" style="padding:6px 12px;font-size:12px">Cancel &amp; notify</button>
            </form>
          </details>
        <?php endif; ?>
      </div>
    </div>
    <?php
};
?>
<div class="portal-container">
  <a href="<?= e($ownDashboard) ?>" class="staff-back-link"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5m7-7-7 7 7 7"/></svg><span>Back</span></a>
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mt-2 mb-4">
    <h1 class="font-heading m-0" style="font-size:26px">Appointment Management</h1>
    <a href="<?= base_url(role_portal_path($staff, 'appointment_slots.php')) ?>" class="btn-outline text-decoration-none" style="padding:8px 16px;font-size:13px">Manage Slots</a>
  </div>

  <?php if ($error): ?><div class="auth-error mb-3" role="alert"><?= e($error) ?></div><?php endif; ?>
  <?php if ($flashMessage): ?><div class="info-box" style="max-width:none" role="status"><?= e($flashMessage) ?></div><?php endif; ?>

  <?php if ($awaitingOutcome): ?>
    <div class="review-card" style="border-color:var(--red)">
      <div class="review-card-head"><div class="title">Needs Outcome (<?= count($awaitingOutcome) ?>)</div></div>
      <div style="font-size:13px;color:var(--muted);margin-bottom:8px">These appointments have passed but are still marked booked. Record the interview result, or mark a no-show as missed so the applicant can book again.</div>
      <?php if ($staff['role'] === 'admin'): ?><div class="admin-list-scroll" role="region" aria-label="Appointments needing an outcome" tabindex="0"><?php endif; ?>
      <?php foreach ($awaitingOutcome as $iv) { $row($iv, 'M j, Y g:i A'); } ?>
      <?php if ($staff['role'] === 'admin'): ?></div><?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="review-card">
    <div class="review-card-head"><div class="title">Today's Appointments (<?= e(date('F j, Y')) ?>)</div></div>
    <?php if (!$todayAppointments): ?>
      <div style="font-size:13px;color:var(--muted)">No appointments scheduled for today.</div>
    <?php endif; ?>
    <?php if ($staff['role'] === 'admin' && $todayAppointments): ?><div class="admin-list-scroll" role="region" aria-label="Today's appointments" tabindex="0"><?php endif; ?>
    <?php foreach ($todayAppointments as $iv) { $row($iv, 'g:i A'); } ?>
    <?php if ($staff['role'] === 'admin' && $todayAppointments): ?></div><?php endif; ?>
  </div>

  <div class="review-card">
    <div class="review-card-head"><div class="title">Upcoming (Calendar)</div></div>
    <?php if (!$upcomingByDate): ?>
      <div style="font-size:13px;color:var(--muted)">No upcoming appointments beyond today.</div>
    <?php endif; ?>
    <?php if ($staff['role'] === 'admin' && $upcomingByDate): ?><div class="admin-list-scroll" role="region" aria-label="Upcoming appointments" tabindex="0"><?php endif; ?>
    <?php foreach ($upcomingByDate as $date => $items): ?>
      <div style="margin-bottom:14px">
        <div style="font-weight:700;font-size:13px;color:var(--ink);margin-bottom:6px"><?= e(date('l, F j, Y', strtotime($date))) ?> · <?= count($items) ?> booked</div>
        <?php foreach ($items as $iv) { $row($iv, 'g:i A'); } ?>
      </div>
    <?php endforeach; ?>
    <?php if ($staff['role'] === 'admin' && $upcomingByDate): ?></div><?php endif; ?>
  </div>

  <div class="review-card">
    <div class="review-card-head"><div class="title">Slots</div></div>
    <?php if (!$slotsByDate): ?>
      <div style="font-size:13px;color:var(--muted)">No upcoming slots configured. <a href="<?= base_url(role_portal_path($staff, 'appointment_slots.php')) ?>">Add one.</a></div>
    <?php endif; ?>
    <?php if ($staff['role'] === 'admin' && $slotsByDate): ?><div class="admin-list-scroll" role="region" aria-label="Upcoming appointment slots" tabindex="0"><?php endif; ?>
    <?php foreach ($slotsByDate as $date => $items): ?>
      <div style="margin-bottom:10px">
        <div style="font-weight:700;font-size:13px;color:var(--ink);margin-bottom:6px"><?= e(date('l, F j, Y', strtotime($date))) ?></div>
        <div class="d-flex flex-wrap gap-2">
          <?php foreach ($items as $s):
              $cancelled = $s['status'] === 'CANCELLED';
              $full = (int) $s['booked'] >= (int) $s['capacity'];
              [$bg, $fg] = $cancelled ? ['#f1f1f1', '#555'] : ($full ? ['#fde8e8', '#a51c1c'] : ['#dcf3e2', '#1c7a3f']);
          ?>
            <span class="status-pill" style="background:<?= $bg ?>;color:<?= $fg ?>">
              <?= e(date('g:i A', strtotime($s['start_time']))) ?>&ndash;<?= e(date('g:i A', strtotime($s['end_time']))) ?>
              <?= $cancelled ? '(cancelled)' : '(' . (int) $s['booked'] . '/' . (int) $s['capacity'] . ($full ? ' full' : '') . ')' ?>
            </span>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if ($staff['role'] === 'admin' && $slotsByDate): ?></div><?php endif; ?>
  </div>
</div>
