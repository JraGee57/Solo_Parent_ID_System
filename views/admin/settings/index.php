<?php
/**
 * System Settings: one form per section of config/settings.php, plus the
 * reminder jobs and read-only server information.
 *
 * @var string  $error
 * @var string  $success
 * @var ?string $errorSection         the section whose save failed (its form shows what was typed)
 * @var array   $posted
 * @var array   $current              section => key => value
 * @var array   $smsTemplates         key => [label, enabled, locked]
 * @var float   $serverUploadLimitMb
 * @var array   $recentReminders      latest renewal_reminders rows
 * @var array   $renewalRuns, $appointmentRuns  latest scheduled_job_runs of each job
 * @var array   $recentApptReminders
 */
$valueOf = function (string $section, string $key) use ($errorSection, $posted, $current) {
    if ($errorSection === $section) {
        $v = $posted[$key] ?? null;
        $def = SETTINGS_REGISTRY[$section]['keys'][$key];
        if ($def['type'] === 'list') {
            return is_array($v) ? array_map('strval', $v) : [];
        }
        if ($def['type'] === 'bool') {
            return !empty($v);
        }
        return is_string($v) ? $v : '';
    }
    $v = $current[$section][$key];
    $type = SETTINGS_REGISTRY[$section]['keys'][$key]['type'];
    return $type === 'list' ? explode(',', $v) : ($type === 'bool' ? $v === '1' : $v);
};
$renewalDays = (int) $current['renewals']['renewal_reminder_days'];
$apptHours = (int) $current['appointments']['appointment_reminder_hours'];
?>
<div class="portal-container">
  <a href="<?= base_url('admin/dashboard.php') ?>" class="staff-back-link"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5m7-7-7 7 7 7"/></svg><span>Back</span></a>
  <h1 class="font-heading mt-2 mb-2" style="font-size:26px">System Settings</h1>
  <p style="font-size:13px;color:var(--muted);max-width:760px">Only administrators can change these settings. Each section is saved on its own, and every change is recorded in the audit log with its old and new value.</p>

  <nav class="record-nav" aria-label="Settings sections">
    <?php foreach (SETTINGS_REGISTRY as $sectionKey => $section): ?><a href="#<?= e($sectionKey) ?>"><?= e($section['title']) ?></a><?php endforeach; ?>
    <a href="#requirements">Application Requirements</a><a href="#jobs">Reminder Jobs</a><a href="#server">Server</a>
  </nav>

  <?php if ($success): ?><div class="info-box" style="max-width:none" role="status"><?= e($success) ?></div><?php endif; ?>
  <?php if ($error && !$errorSection): ?><div class="auth-error mb-3" role="alert"><?= e($error) ?></div><?php endif; ?>

  <div class="settings-grid">
  <?php foreach (SETTINGS_REGISTRY as $sectionKey => $section): ?>
    <details class="admin-editor settings-card" id="<?= e($sectionKey) ?>" <?= $sectionKey === 'general' || $errorSection === $sectionKey ? 'open' : '' ?>>
      <summary><?= e($section['title']) ?></summary>
      <div class="admin-editor-content">
      <div class="settings-note"><?= e($section['note']) ?></div>
      <?php if ($errorSection === $sectionKey): ?><div class="auth-error mb-2" role="alert"><?= e($error) ?> Nothing in this section was saved.</div><?php endif; ?>
      <form method="post" action="<?= e(base_url('admin/settings.php')) ?>#<?= e($sectionKey) ?>" class="d-flex flex-column gap-2">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_section">
        <input type="hidden" name="section" value="<?= e($sectionKey) ?>">
        <?php foreach ($section['keys'] as $key => $def): $v = $valueOf($sectionKey, $key); $id = 'set-' . $key; ?>
          <?php if ($def['type'] === 'bool'): ?>
            <label class="settings-check"><input type="checkbox" name="<?= e($key) ?>" value="1" <?= $v ? 'checked' : '' ?>> <?= e($def['label']) ?></label>
          <?php elseif ($def['type'] === 'list'): ?>
            <fieldset class="user-label">
              <legend style="font-size:13px;font-weight:600;margin:0"><?= e($def['label']) ?></legend>
              <div class="d-flex flex-wrap gap-3">
                <?php foreach ($def['options'] as $opt => $optLabel): ?>
                  <label class="settings-check"><input type="checkbox" name="<?= e($key) ?>[]" value="<?= e((string) $opt) ?>" <?= in_array((string) $opt, $v, true) ? 'checked' : '' ?>> <?= e($optLabel) ?></label>
                <?php endforeach; ?>
              </div>
            </fieldset>
          <?php else: ?>
            <label class="user-label" for="<?= e($id) ?>"><?= e($def['label']) ?>
              <?php
                $attrs = match ($def['type']) {
                    'int' => 'type="number" step="1" min="' . $def['min'] . '" max="' . $def['max'] . '"',
                    'decimal' => 'type="number" step="' . ($def['step'] ?? '0.1') . '" min="' . $def['min'] . '" max="' . ($key === 'document_max_size_mb' ? min($def['max'], $serverUploadLimitMb) : $def['max']) . '"',
                    'time' => 'type="time"',
                    'email' => 'type="email" maxlength="' . $def['max'] . '"',
                    'phone' => 'type="tel" maxlength="' . $def['max'] . '"',
                    default => 'type="text" maxlength="' . $def['max'] . '"',
                };
              ?>
              <input class="form-control" id="<?= e($id) ?>" name="<?= e($key) ?>" value="<?= e((string) (!empty($def['readonly']) ? $def['default'] : $v)) ?>" <?= $attrs ?> <?= !empty($def['readonly']) ? 'readonly' : '' ?> required>
            </label>
          <?php endif; ?>
        <?php endforeach; ?>

        <?php if ($sectionKey === 'documents'): ?>
          <div class="settings-note">This server accepts uploads up to <?= e(format_mb($serverUploadLimitMb)) ?> (PHP <code>upload_max_filesize</code> / <code>post_max_size</code>), so the maximum can't be set higher.</div>
        <?php elseif ($sectionKey === 'appointments'): ?>
          <div class="settings-note">The days and hours apply when MSWD staff open new slots on <a href="<?= base_url('admin/appointment_slots.php') ?>">Appointment Slots</a>; slots already open are kept.</div>
        <?php elseif ($sectionKey === 'notifications'): ?>
          <fieldset class="user-label mt-1">
            <legend style="font-size:13px;font-weight:600;margin:0">SMS messages sent to applicants</legend>
            <?php foreach ($smsTemplates as $tKey => [$tLabel, $tOn, $tLocked]): ?>
              <?php $checked = $errorSection === 'notifications' ? ($tLocked || !empty($posted['sms_template'][$tKey])) : $tOn; ?>
              <label class="settings-check"><input type="checkbox" name="sms_template[<?= e($tKey) ?>]" value="1" <?= $checked ? 'checked' : '' ?> <?= $tLocked ? 'disabled' : '' ?>> <?= e($tLabel) ?><?= $tLocked ? ' <span class="opt">(always sent — needed to sign in)</span>' : '' ?></label>
            <?php endforeach; ?>
          </fieldset>
          <div class="settings-note">These apply only while "Send SMS notifications" is on. Edit the wording on <a href="<?= base_url('admin/sms.php') ?>">SMS &amp; Notifications</a>. Administrators always receive security and scheduled-job alerts.</div>
        <?php endif; ?>
        <button type="submit" class="btn-navy" style="align-self:flex-start">Save <?= e($section['title']) ?></button>
      </form>
      </div>
    </details>
  <?php endforeach; ?>

    <div class="review-card settings-card" id="requirements">
      <div class="review-card-head"><div class="title">Application Requirements</div></div>
      <div class="settings-note">The documents applicants must upload — standard ones and those for each solo-parent category.</div>
      <a href="<?= base_url('admin/requirements.php') ?>" class="btn-outline text-decoration-none" style="align-self:flex-start">Manage Requirements</a>
    </div>
  </div>

  <h2 class="font-heading mt-4 mb-3" style="font-size:20px" id="jobs">Reminder Jobs</h2>
  <div class="settings-grid">
      <div class="review-card settings-card">
        <div class="review-card-head"><div class="title">Renewal Reminders</div></div>
        <div style="font-size:13px;color:var(--muted);margin-bottom:14px">
          Sends an in-app notification and an SMS to every applicant whose released ID expires within <?= $renewalDays ?> day(s) — once per ID per expiry date, never twice. IDs with a renewal already filed and deactivated accounts are skipped; an ID whose window was missed gets a catch-up reminder after it expires.
          Schedule <code>cron/renewal-reminders.php</code> to run daily (Task Scheduler: <code>C:\xampp\php\php.exe</code> with that file as the argument), or run it now:
        </div>
        <form method="post" class="mb-3">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="send_reminders_now">
          <button type="submit" class="btn-outline">Send Reminders Now</button>
        </form>
        <?php $runs = $renewalRuns; require APP_ROOT . '/views/admin/settings/job-runs.php'; ?>
        <div style="font-size:12px;font-weight:700;color:var(--muted);margin:12px 0 6px">Recent Reminders</div>
        <?php if (!$recentReminders): ?>
          <div style="font-size:13px;color:var(--muted)">No renewal reminders sent yet.</div>
        <?php endif; ?>
        <?php foreach ($recentReminders as $r): ?>
          <div class="review-row">
            <div class="k"><?= e($r['reference_no']) ?> — <?= e($r['full_name']) ?></div>
            <div class="v" style="font-size:13px">
              <?= e(date('M j, Y g:i A', strtotime($r['sent_at']))) ?> ·
              <?= $r['status'] === 'SENT' ? 'Sent' : ($r['status'] === 'FAILED' ? '<span style="color:var(--error-text)">Failed (attempt ' . (int) $r['attempts'] . ')</span>' : 'Sending') ?>
              · expires <?= e(format_date_long($r['id_expires_at'])) ?> (<?= (int) $r['days_before'] >= 0 ? (int) $r['days_before'] . ' day(s) before' : abs((int) $r['days_before']) . ' day(s) after' ?>)
              <?php if ($r['sms_status']): ?>· SMS: <?= e($r['sms_status'] === 'DISABLED' ? 'switched off' : $r['sms_status']) ?><?php endif; ?> · <?= e($r['trigger_source']) ?>
              <?php if ($r['error']): ?><div style="color:var(--error-text)"><?= e($r['error']) ?></div><?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="review-card settings-card">
        <div class="review-card-head"><div class="title">Appointment Reminders</div></div>
        <div style="font-size:13px;color:var(--muted);margin-bottom:14px">
          Automatically texts every applicant with an interview/appointment starting within <?= $apptHours ?> hour(s), once per scheduled time (rescheduling resets it). Runs via a scheduled task
          (<code>cron/appointment-reminders.php</code>, e.g. hourly), or trigger it manually below.
        </div>
        <form method="post" class="mb-3">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="send_appointment_reminders_now">
          <button type="submit" class="btn-outline">Send Reminders Now</button>
        </form>
        <?php $runs = $appointmentRuns; require APP_ROOT . '/views/admin/settings/job-runs.php'; ?>
        <div style="font-size:12px;font-weight:700;color:var(--muted);margin-bottom:6px">Recently Sent</div>
        <?php if (!$recentApptReminders): ?>
          <div style="font-size:13px;color:var(--muted)">No appointment reminders sent yet.</div>
        <?php endif; ?>
        <?php foreach ($recentApptReminders as $r): ?>
          <div class="review-row"><div class="k"><?= e($r['reference_no']) ?> — <?= e($r['full_name']) ?></div><div class="v"><?= e(date('M j, Y g:i A', strtotime($r['scheduled_at']))) ?></div></div>
        <?php endforeach; ?>
      </div>
  </div>

  <div class="review-card mt-3" id="server">
    <div class="review-card-head"><div class="title">Server (read-only)</div></div>
    <div class="review-grid">
      <div class="review-row"><div class="k">Base URL</div><div class="v"><?= e(BASE_URL) ?></div></div>
      <div class="review-row"><div class="k">SMS provider</div><div class="v"><?= e(SMS_PROVIDER) ?> (<?= SMS_PROVIDER === 'test' ? 'test mode — messages are logged, not sent' : (SMS_API_KEY ? 'configured' : 'no API key') ?>)</div></div>
      <div class="review-row"><div class="k">Upload limit (PHP)</div><div class="v"><?= e(format_mb($serverUploadLimitMb)) ?></div></div>
      <div class="review-row"><div class="k">Timezone</div><div class="v"><?= e(date_default_timezone_get()) ?></div></div>
      <div class="review-row"><div class="k">PHP version</div><div class="v"><?= e(PHP_VERSION) ?></div></div>
    </div>
    <div style="font-size:12px;color:var(--faint);margin-top:12px">Database, SMS gateway and other credentials are set in <code>config/local.php</code> or environment variables (see the README), never on this page.</div>
  </div>
</div>
