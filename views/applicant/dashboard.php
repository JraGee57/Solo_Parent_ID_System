<?php
/**
 * Applicant home: current application, appointment, ID and renewal status,
 * and latest notifications. Everything is live data.
 *
 * @var array  $user
 * @var bool   $isFirstLogin        true throughout the applicant's first sign-in session
 * @var ?array $latestApplication
 * @var ?array $latestTracker        ApplicationTrackerService::track() for it
 * @var ?array $idApplication
 * @var ?array $idExpiryInfo
 * @var array  $renewal              state: none|valid|due|expired|in_progress (+ daysLeft, expiresAt, windowDays, application)
 * @var ?array $upcomingAppointment  application row with interview_at / interview_location
 * @var bool   $canBookAppointment
 * @var array  $recentNotifications
 * @var ?array $eligibilityCheck     latest preliminary check: answers, result, checkedAt
 * @var int    $unreadNotificationCount
 * @var ?array $applicationDraft     saved, unsubmitted application (see application/draft-card.php)
 */
$icons = [
    'apply' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="13" y2="17"/>',
    'documents' => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7z"/>',
    'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
    'renew' => '<path d="M21 12a9 9 0 1 1-3-6.7"/><polyline points="21 3 21 9 15 9"/>',
    'bell' => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>',
    'chat' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
    'help' => '<circle cx="12" cy="12" r="9"/><path d="M9.1 9a3 3 0 0 1 5.8 1c0 2-3 2-3 4"/><path d="M12 17h.01"/>',
];
$notifIcons = [
    'registration' => 'apply', 'submission' => 'apply', 'appointment_confirmation' => 'calendar',
    'appointment_reminder' => 'calendar', 'correction' => 'documents', 'approval' => 'apply',
    'rejection' => 'apply', 'renewal_reminder' => 'renew',
];
$firstName = preg_split('/\s+/u', trim($user['full_name']))[0] ?? '';
$welcomeText = !empty($isFirstLogin) ? 'Welcome' : 'Welcome back';
?>
      <div class="applicant-home">
      <section class="dash-home-welcome" aria-labelledby="dashboard-welcome">
        <h2 class="dash-welcome" id="dashboard-welcome"><?= e($welcomeText) ?><?= $firstName !== '' ? ', ' . e($firstName) : '' ?></h2>
        <p class="dash-welcome-sub"><?= !empty($isFirstLogin) ? 'Welcome to your Solo Parent portal. Start your application and find the guidance you need here.' : 'Your application, appointments, and ID updates in one place.' ?></p>
      </section>

      <?php require __DIR__ . '/application/draft-card.php'; ?>

      <?php if ($latestApplication): $st = status_style($latestApplication['status']); ?>
        <div class="dash-card dash-home-application">
          <h2 class="dash-card-label dash-home-application-heading">Current Application <span class="doc-tag"><?= $latestApplication['type'] === 'renewal' ? 'RENEWAL' : 'NEW' ?></span></h2>
          <div class="dash-status-row">
            <div>
              <div class="dash-home-reference">Reference: <span><?= e($latestApplication['reference_no']) ?></span></div>
              <div class="dash-status-cat"><?= e($latestApplication['category_name']) ?> · Submitted <?= e(date('M j, Y', strtotime($latestApplication['submitted_at']))) ?></div>
            </div>
            <div class="status-pill dash-home-status" style="background:<?= e($st['bg']) ?>;color:<?= e($st['color']) ?>"><?= e($latestApplication['status']) ?></div>
          </div>
          <?php
          $doneCount = count(array_filter($latestTracker['stages'], fn($s) => $s['state'] === 'done'));
          $stageCount = count($latestTracker['stages']);
          $stageStateLabels = ['done' => 'Completed', 'active' => 'In progress', 'attention' => 'Needs your action', 'pending' => 'Not reached yet', 'stopped' => 'Stopped'];
          ?>
          <div class="dash-home-progress-summary">
            <div>
              <div class="dash-home-progress-caption">Current stage</div>
              <h3 class="dash-home-current-stage"><?= e($latestTracker['current']) ?></h3>
            </div>
            <span class="dash-home-progress-count"><?= $doneCount ?> of <?= $stageCount ?> steps completed</span>
          </div>
          <ol class="dash-home-stages" aria-label="Application progress">
            <?php foreach ($latestTracker['stages'] as $stageIndex => $s): ?>
              <li class="dash-home-stage <?= e($s['state']) ?>"<?= $s['label'] === $latestTracker['current'] && !$latestTracker['rejected'] ? ' aria-current="step"' : '' ?>>
                <span class="dash-home-stage-marker" aria-hidden="true">
                  <?php if ($s['state'] === 'done'): ?>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 4 4L19 6"/></svg>
                  <?php elseif ($s['state'] === 'attention'): ?>!
                  <?php elseif ($s['state'] === 'stopped'): ?>&ndash;
                  <?php else: ?><?= $stageIndex + 1 ?><?php endif; ?>
                </span>
                <div class="dash-home-stage-text">
                  <span class="dash-home-stage-label"><?= e($s['label']) ?></span>
                  <span class="dash-home-stage-state"><?= e($stageStateLabels[$s['state']]) ?></span>
                </div>
              </li>
            <?php endforeach; ?>
          </ol>
          <?php if ($latestTracker['correction']): ?>
            <div class="step-alert" role="alert">
              <b>Action needed:</b> MSWDO has asked for corrections<?= $latestTracker['correction']['reason'] ? ': ' . e($latestTracker['correction']['reason']) : '' ?>.
              <div class="mt-2"><a href="?section=documents" class="btn-red btn-sm" style="text-decoration:none;color:#fff">Fix My Documents</a></div>
            </div>
          <?php elseif ($latestTracker['rejected']): ?>
            <div class="step-alert" role="alert"><b>Not approved:</b> This application was not approved<?= $latestTracker['rejected']['reason'] ? ': ' . e($latestTracker['rejected']['reason']) : '.' ?></div>
          <?php endif; ?>
          <a href="?section=application" class="btn-outline text-decoration-none dash-card-link">View Application</a>
        </div>
      <?php elseif (!$applicationDraft): // a saved draft already has its own "Continue" card above ?>
        <div class="dash-card">
          <h2 class="dash-card-label">Get started with your application</h2>
          <p class="dash-home-body">Complete your personal and family details, review the required documents, and submit your application for MSWDO review.</p>
          <?php if ($eligibilityCheck): ?>
            <div style="font-size:14px;color:var(--ink);margin:8px 0 4px">
              Your preliminary eligibility check (<?= e(date('M j, Y', strtotime($eligibilityCheck['checkedAt']))) ?>):
              <?php if (($eligibilityCheck['result']['status'] ?? '') === 'NEEDS_GUIDANCE'): ?>
                <span class="status-pill" style="background:#fdf1d6;color:#8a6d1a">MSWDO guidance needed</span>
              <?php elseif ($eligibilityCheck['result']['eligible']): ?>
                <span class="status-pill" style="background:#dcf3e2;color:#1c7a3f">May be eligible</span>
              <?php else: ?>
                <span class="status-pill" style="background:#fdf1d6;color:#8a6d1a">May not meet the criteria</span>
              <?php endif; ?>
            </div>
            <div style="font-size:12px;color:var(--faint);margin-bottom:14px">Preliminary only — final eligibility is decided by MSWD.</div>
          <?php else: ?>
            <div style="font-size:14px;color:var(--muted);margin:8px 0 14px">Start your Solo Parent ID application online in a few easy steps.</div>
          <?php endif; ?>
          <div class="d-flex flex-wrap gap-2">
            <a href="<?= base_url('applicant/dashboard.php?section=apply') ?>" class="btn-navy text-decoration-none dash-card-link">Start Application</a>
          </div>
        </div>
      <?php endif; ?>

      <div class="dash-two-col">
        <div class="dash-card">
          <h2 class="dash-card-label dash-home-card-heading"><svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $icons['calendar'] ?></svg>Appointment</h2>
          <?php if ($upcomingAppointment): ?>
            <div class="dash-home-appointment-date">
              <div class="dash-home-date-tile" aria-hidden="true"><span><?= e(date('M', strtotime($upcomingAppointment['interview_at']))) ?></span><b><?= e(date('j', strtotime($upcomingAppointment['interview_at']))) ?></b></div>
              <div><div class="dash-home-date-label"><?= e(date('l, F j, Y', strtotime($upcomingAppointment['interview_at']))) ?></div><div class="dash-home-time"><?= e(date('g:i A', strtotime($upcomingAppointment['interview_at']))) ?></div></div>
            </div>
            <dl class="dash-home-details"><div><dt>Location</dt><dd><?= e($upcomingAppointment['interview_location'] ?: 'Contact MSWDO for the location') ?></dd></div><div><dt>Application</dt><dd><?= e($upcomingAppointment['reference_no']) ?></dd></div></dl>
            <a href="?section=appointment" class="btn-outline text-decoration-none dash-card-link">View or Reschedule</a>
          <?php elseif ($canBookAppointment): ?>
            <p class="dash-home-body">Your application is ready for an interview appointment. Choose a slot with MSWDO.</p>
            <a href="?section=appointment" class="btn-navy text-decoration-none dash-card-link">Book Appointment</a>
          <?php else: ?>
            <div class="dash-home-empty"><h3>No upcoming appointment</h3><p>If an interview is needed, you will receive an update and can book a slot here.</p></div>
          <?php endif; ?>
        </div>

        <div class="dash-card">
          <h2 class="dash-card-label dash-home-card-heading"><svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $icons['renew'] ?></svg>Solo Parent ID &amp; Renewal</h2>
          <?php if ($renewal['state'] === 'in_progress'): $rs = status_style($renewal['application']['status']); ?>
            <div style="font-size:14px;color:var(--ink);margin-bottom:6px">Renewal <b><?= e($renewal['application']['reference_no']) ?></b> is being processed.</div>
            <span class="status-pill" style="background:<?= e($rs['bg']) ?>;color:<?= e($rs['color']) ?>"><?= e($renewal['application']['status']) ?></span>
            <div class="mt-3"><a href="?section=renewal&type=status" class="btn-outline text-decoration-none dash-card-link">Renewal Status</a></div>
          <?php elseif ($renewal['state'] === 'none'): ?>
            <?php if ($idApplication): $idSt = status_style($idApplication['status']); ?>
              <div style="font-size:14px;color:var(--ink);margin-bottom:10px">Your ID is <span class="status-pill" style="background:<?= e($idSt['bg']) ?>;color:<?= e($idSt['color']) ?>"><?= e($idApplication['status']) ?></span> and will be released by MSWDO.</div>
            <?php else: ?>
              <div class="dash-home-empty"><h3>Your ID details will appear here</h3><p>Once MSWDO approves and releases your ID, you can view its details and renewal schedule.</p></div>
            <?php endif; ?>
          <?php else: ?>
            <div class="dash-home-validity <?= e($renewal['state']) ?>">
              <?php if ($renewal['state'] === 'expired'): ?>
                <span class="status-pill">Expired</span>
              <?php else: ?>
                <span class="status-pill"><?= $renewal['state'] === 'due' ? 'Expiring soon' : 'Valid' ?></span>
              <?php endif; ?>
              <div class="dash-home-expiry-label"><?= $renewal['state'] === 'expired' ? 'Expired on' : 'Valid until' ?></div>
              <div class="dash-home-expiry-date"><?= e(format_date_long($renewal['expiresAt'])) ?></div>
            </div>
            <div style="font-size:13px;color:var(--muted);margin-bottom:14px">
              <?php if ($renewal['state'] === 'expired'): ?>
                Renew now to keep your benefits.
              <?php elseif ($renewal['state'] === 'due'): ?>
                <?= (int) $renewal['daysLeft'] ?> day<?= $renewal['daysLeft'] === 1 ? '' : 's' ?> left — renewal is now open.
              <?php else: ?>
                <?= (int) $renewal['daysLeft'] ?> days left. Renewal opens <?= (int) $renewal['windowDays'] ?> days before expiry.
              <?php endif; ?>
            </div>
            <div class="d-flex flex-wrap gap-2">
              <?php if (in_array($renewal['state'], ['due', 'expired'], true)): ?>
                <a href="?section=renewal&type=new" class="btn-red text-decoration-none dash-card-link">Renew My ID</a>
              <?php endif; ?>
              <a href="?section=myid" class="btn-outline text-decoration-none dash-card-link">View My ID</a>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="dash-card dash-home-notifications">
        <div class="dash-home-notification-heading">
          <h2 class="dash-card-label m-0">Recent Notifications<?php if ($unreadNotificationCount > 0): ?> <span class="dash-home-unread-count"><?= (int) $unreadNotificationCount ?> unread</span><?php endif; ?></h2>
          <a href="?section=notifications" class="dash-home-view-all">View all<span class="visually-hidden"> notifications</span></a>
        </div>
        <?php if (!$recentNotifications): ?>
          <div class="dash-home-empty"><h3>You're all caught up</h3><p>Updates about your application, documents, and appointments will appear here.</p></div>
        <?php endif; ?>
        <?php foreach (array_slice($recentNotifications, 0, 3) as $n): ?>
          <a href="?section=notifications&amp;open=<?= (int) $n['id'] ?>" class="notif-preview dash-home-notification <?= $n['is_read'] ? '' : 'unread' ?>">
            <span class="dash-home-action-icon" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $icons[$notifIcons[$n['type']] ?? 'bell'] ?></svg></span>
            <div class="dash-home-notification-text">
              <?php if (!$n['is_read']): ?><span class="dash-home-unread-label">Unread</span><?php endif; ?>
              <div><?= e($n['message']) ?></div>
              <div class="when"><?= e(date('M j, Y g:i A', strtotime($n['created_at']))) ?></div>
            </div>
          </a>
        <?php endforeach; ?>
      </div>

      </div>
