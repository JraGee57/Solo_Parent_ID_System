<?php
/**
 * @var array  $staff
 * @var string $ownDashboard
 * @var string $error
 * @var string $success
 * @var array  $slots  upcoming slots (any status) with booked (seat-holding) and ever_used counts
 * @var int    $defaultCapacity
 */
?>
<div class="portal-container">
  <a href="<?= e($ownDashboard) ?>" class="staff-back-link"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5m7-7-7 7 7 7"/></svg><span>Back</span></a>
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mt-2 mb-4">
    <h1 class="font-heading m-0" style="font-size:26px">Appointment Slots</h1>
    <span style="font-size:14px">Signed in as <b><?= e($staff['full_name']) ?></b></span>
  </div>
  <div style="font-size:13px;color:var(--muted);margin-bottom:20px;max-width:640px">Applicants pick their own interview/assessment appointment from the open slots below. Create a slot for each date/time block you can accommodate, with how many applicants it can hold. Slots on the same day can't overlap. A slot that has had bookings can't be deleted — cancel it instead, and everyone booked is notified.</div>

  <?php if ($error): ?><div class="auth-error mb-3" role="alert"><?= e($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="info-box" style="max-width:none" role="status"><?= e($success) ?></div><?php endif; ?>

  <div class="row g-4">
    <div class="col-12 col-lg-5">
      <div class="review-card">
        <div class="review-card-head"><div class="title">Add Slot</div></div>
        <form method="post" class="d-flex flex-column gap-3">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="create">
          <?php
            $dayNames = SETTINGS_REGISTRY['appointments']['keys']['appointment_days']['options'];
            [$slotOpen, $slotClose] = [setting('appointment_day_start'), setting('appointment_day_end')];
            $slotDaysAhead = (int) setting('appointment_max_days_ahead');
          ?>
          <div style="font-size:12px;color:var(--muted)">Allowed: <?= e(implode(', ', array_map(fn($n) => $dayNames[$n] ?? $n, setting_list('appointment_days')))) ?>, <?= e(date('g:i A', strtotime($slotOpen))) ?>–<?= e(date('g:i A', strtotime($slotClose))) ?>, up to <?= $slotDaysAhead ?> days ahead (set by an administrator in System Settings).</div>
          <label style="font-size:13px;font-weight:600;color:var(--ink)">
            Date
            <input type="date" name="slot_date" min="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d', strtotime('+' . $slotDaysAhead . ' days'))) ?>" class="form-control mt-1" style="padding:10px;border:1px solid var(--input-border);border-radius:6px" required>
          </label>
          <div class="d-flex gap-2">
            <label style="font-size:13px;font-weight:600;color:var(--ink);flex:1">
              Start time
              <input type="time" name="start_time" min="<?= e($slotOpen) ?>" max="<?= e($slotClose) ?>" class="form-control mt-1" style="padding:10px;border:1px solid var(--input-border);border-radius:6px" required>
            </label>
            <label style="font-size:13px;font-weight:600;color:var(--ink);flex:1">
              End time
              <input type="time" name="end_time" min="<?= e($slotOpen) ?>" max="<?= e($slotClose) ?>" class="form-control mt-1" style="padding:10px;border:1px solid var(--input-border);border-radius:6px" required>
            </label>
          </div>
          <label style="font-size:13px;font-weight:600;color:var(--ink)">
            Capacity (applicants)
            <input type="number" name="capacity" min="1" max="100" value="<?= (int) $defaultCapacity ?>" class="form-control mt-1" style="padding:10px;border:1px solid var(--input-border);border-radius:6px" required>
          </label>
          <button type="submit" class="btn-navy">Add Slot</button>
        </form>
      </div>
    </div>

    <div class="col-12 col-lg-7">
      <?php if (!$slots): ?>
        <div style="font-size:14px;color:var(--muted)">No upcoming slots yet. Add one to let applicants book appointments.</div>
      <?php endif; ?>
      <?php foreach ($slots as $s):
          $cancelled = $s['status'] === 'CANCELLED';
          $past = strtotime($s['slot_date'] . ' ' . $s['start_time']) <= time();
          $full = !$cancelled && (int) $s['booked'] >= (int) $s['capacity'];
      ?>
        <div class="app-card" style="<?= $cancelled ? 'opacity:.7' : '' ?>">
          <div>
            <div class="app-ref"><?= e(date('l, F j, Y', strtotime($s['slot_date']))) ?></div>
            <div class="app-date"><?= e(date('g:i A', strtotime($s['start_time']))) ?> &ndash; <?= e(date('g:i A', strtotime($s['end_time']))) ?> · <?= (int) $s['booked'] ?> / <?= (int) $s['capacity'] ?> booked</div>
            <?php if ($cancelled): ?><div class="app-date">Cancelled<?= $s['cancel_reason'] ? ': ' . e($s['cancel_reason']) : '' ?></div><?php endif; ?>
          </div>
          <div class="d-flex align-items-center gap-2 flex-wrap">
            <?php if ($cancelled): ?>
              <span class="status-pill" style="background:#f1f1f1;color:#555">Cancelled</span>
            <?php elseif ($past): ?>
              <span class="status-pill" style="background:#f1f1f1;color:#555">Started</span>
            <?php else: ?>
              <span class="status-pill" style="background:<?= $full ? '#fde8e8' : '#dcf3e2' ?>;color:<?= $full ? '#a51c1c' : '#1c7a3f' ?>"><?= $full ? 'Full' : 'Available' ?></span>
              <?php if ((int) $s['ever_used'] === 0): ?>
                <form method="post" class="m-0" onsubmit="return confirm('Delete this appointment slot?');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="slot_id" value="<?= (int) $s['id'] ?>">
                  <button type="submit" class="btn-danger-outline btn-sm">Delete</button>
                </form>
              <?php else: ?>
                <details class="slot-cancel">
                  <summary class="btn-outline" style="padding:6px 12px;font-size:12px">Cancel slot…</summary>
                  <form method="post" class="d-flex flex-column gap-2 mt-2" onsubmit="return confirm('Cancel this slot? Everyone booked in it (<?= (int) $s['booked'] ?>) will be notified.');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="cancel">
                    <input type="hidden" name="slot_id" value="<?= (int) $s['id'] ?>">
                    <input type="text" name="reason" aria-label="Reason for cancelling the slot (sent to booked applicants)" maxlength="255" required placeholder="Reason (sent to booked applicants)" class="form-control" style="font-size:13px;padding:6px 10px">
                    <button type="submit" class="btn-red" style="padding:6px 12px;font-size:12px">Cancel slot &amp; notify</button>
                  </form>
                </details>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
