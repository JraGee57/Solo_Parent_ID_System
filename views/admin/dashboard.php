<?php
/**
 * Administrator dashboard -- system-level overview: users, applications,
 * activity, SMS, audit/security and trend summary. All counts are live.
 *
 * @var array  $staff
 * @var array  $stats         application + SMS counts (ReportService::dashboardStats)
 * @var array  $userStats     applicants, mswdStaff, admins, deactivated, locked, newThisMonth
 * @var array  $auditStats    eventsToday, loginsToday, accessDenied7d, failedLogins7d, passwordResets7d
 * @var array  $summary       thisMonth, lastMonth, approvalRate (null if nothing decided), decided
 * @var ?int   $lastBackupAt  timestamp of the newest database backup
 * @var array  $recentActivity
 */
$trend = $summary['thisMonth'] - $summary['lastMonth'];
?>
<div class="portal-container">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
    <div>
      <h1 class="page-title">Administrator Dashboard</h1>
      <div style="font-size:13px;color:var(--muted)">System-level overview · <?= e(date('l, F j, Y')) ?></div>
    </div>
    <span style="font-size:14px">Signed in as <b><?= e($staff['full_name']) ?></b></span>
  </div>

  <div class="dash-section-title">Applications</div>
  <div class="stat-grid">
    <a href="<?= base_url('admin/applications.php') ?>" class="stat-tile">
      <div class="stat-label">Total Applications</div>
      <div class="stat-value"><?= (int) $stats['totalApplications'] ?></div>
      <div class="stat-sub"><?= (int) $stats['totalRenewals'] ?> of them renewals</div>
    </a>
    <a href="<?= base_url('admin/analytics.php') ?>" class="stat-tile">
      <div class="stat-label">This Month</div>
      <div class="stat-value"><?= (int) $summary['thisMonth'] ?></div>
      <div class="stat-sub"><?= $trend === 0 ? 'same as' : ($trend > 0 ? '+' . $trend . ' vs' : $trend . ' vs') ?> last month (<?= (int) $summary['lastMonth'] ?>)</div>
    </a>
    <a href="<?= base_url('admin/analytics.php') ?>" class="stat-tile">
      <div class="stat-label">Approval Rate</div>
      <div class="stat-value"><?= $summary['approvalRate'] === null ? '—' : (int) $summary['approvalRate'] . '%' ?></div>
      <div class="stat-sub">of <?= (int) $summary['decided'] ?> decided application<?= (int) $summary['decided'] === 1 ? '' : 's' ?></div>
    </a>
    <a href="<?= base_url('admin/analytics.php') ?>" class="stat-tile">
      <div class="stat-label">Expiring IDs</div>
      <div class="stat-value <?= $stats['expiringIds'] > 0 ? 'stat-alert' : '' ?>"><?= (int) $stats['expiringIds'] ?></div>
      <div class="stat-sub">within <?= (int) $stats['expiringWindow'] ?> days</div>
    </a>
  </div>

  <div class="dash-section-title">Users</div>
  <div class="stat-grid">
    <a href="<?= base_url('admin/users.php?role=applicant') ?>" class="stat-tile">
      <div class="stat-label">Registered Solo Parents</div>
      <div class="stat-value"><?= (int) $userStats['applicants'] ?></div>
      <div class="stat-sub"><?= (int) $userStats['newThisMonth'] ?> new this month</div>
    </a>
    <a href="<?= base_url('admin/users.php?role=mswd_social_worker') ?>" class="stat-tile">
      <div class="stat-label">MSWD Staff</div>
      <div class="stat-value"><?= (int) $userStats['mswdStaff'] ?></div>
      <div class="stat-sub"><?= (int) $userStats['admins'] ?> administrator<?= (int) $userStats['admins'] === 1 ? '' : 's' ?></div>
    </a>
    <a href="<?= base_url('admin/users.php') ?>" class="stat-tile">
      <div class="stat-label">Active Accounts</div>
      <div class="stat-value"><?= (int) $stats['activeUsers'] ?></div>
      <div class="stat-sub"><?= (int) $userStats['deactivated'] ?> deactivated</div>
    </a>
    <a href="<?= base_url('admin/users.php') ?>" class="stat-tile">
      <div class="stat-label">Locked Out Now</div>
      <div class="stat-value <?= $userStats['locked'] > 0 ? 'stat-alert' : '' ?>"><?= (int) $userStats['locked'] ?></div>
      <div class="stat-sub">after failed logins</div>
    </a>
  </div>

  <div class="dash-section-title">Activity &amp; Security</div>
  <div class="stat-grid">
    <a href="<?= base_url('admin/audit_log.php?from=' . date('Y-m-d')) ?>" class="stat-tile">
      <div class="stat-label">Audit Events Today</div>
      <div class="stat-value"><?= (int) $auditStats['eventsToday'] ?></div>
      <div class="stat-sub"><?= (int) $auditStats['loginsToday'] ?> login<?= (int) $auditStats['loginsToday'] === 1 ? '' : 's' ?></div>
    </a>
    <a href="<?= base_url('admin/audit_log.php?action=Access+Denied') ?>" class="stat-tile">
      <div class="stat-label">Access Denied</div>
      <div class="stat-value <?= $auditStats['accessDenied7d'] > 0 ? 'stat-alert' : '' ?>"><?= (int) $auditStats['accessDenied7d'] ?></div>
      <div class="stat-sub">last 7 days</div>
    </a>
    <a href="<?= base_url('admin/audit_log.php?action=Login+Failed') ?>" class="stat-tile">
      <div class="stat-label">Failed Sign-ins</div>
      <div class="stat-value <?= $auditStats['failedLogins7d'] > 0 ? 'stat-alert' : '' ?>"><?= (int) $auditStats['failedLogins7d'] ?></div>
      <div class="stat-sub">last 7 days, incl. wrong reset codes</div>
    </a>
    <a href="<?= base_url('admin/audit_log.php?action=Password+Reset+Requested') ?>" class="stat-tile">
      <div class="stat-label">Password Resets</div>
      <div class="stat-value"><?= (int) $auditStats['passwordResets7d'] ?></div>
      <div class="stat-sub">last 7 days</div>
    </a>
    <a href="<?= base_url('admin/monitoring.php') ?>" class="stat-tile">
      <div class="stat-label">System Health</div>
      <div class="stat-value" style="font-size:20px;color:#1c7a3f">✓ Database OK</div>
      <div class="stat-sub"><?= $lastBackupAt ? 'Last backup ' . e(date('M j, Y g:i A', $lastBackupAt)) : 'No backup yet' ?></div>
    </a>
  </div>

  <div class="review-card">
    <div class="review-card-head"><div class="title">Applications at a Glance (Monitoring)</div></div>
    <div class="review-grid">
      <a href="<?= base_url('admin/applications.php?type=new&status=Pending Review') ?>" class="review-row text-decoration-none"><div class="k">Pending</div><div class="v"><?= $stats['pendingNew'] ?></div></a>
      <a href="<?= base_url('admin/applications.php?status=Under Verification') ?>" class="review-row text-decoration-none"><div class="k">Under Review</div><div class="v"><?= $stats['underVerification'] ?></div></a>
      <a href="<?= base_url('admin/applications.php?type=new&status=Approved') ?>" class="review-row text-decoration-none"><div class="k">Approved</div><div class="v"><?= $stats['approvedNew'] ?></div></a>
      <a href="<?= base_url('admin/applications.php?status=Rejected') ?>" class="review-row text-decoration-none"><div class="k">Rejected</div><div class="v"><?= $stats['rejectedApps'] ?></div></a>
      <a href="<?= base_url('admin/applications.php?status=For Revision') ?>" class="review-row text-decoration-none"><div class="k">Needs Correction</div><div class="v"><?= $stats['forRevision'] ?></div></a>
      <a href="<?= base_url('admin/applications.php?type=renewal&status=Pending Review') ?>" class="review-row text-decoration-none"><div class="k">Pending Renewals</div><div class="v"><?= $stats['pendingRenewals'] ?></div></a>
    </div>
  </div>

  <div class="review-card">
    <div class="review-card-head"><div class="title">SMS Notification Status</div></div>
    <div class="review-grid">
      <a href="<?= base_url('admin/sms.php?status=sent') ?>" class="review-row text-decoration-none"><div class="k">Sent</div><div class="v"><?= $stats['smsSent'] ?></div></a>
      <a href="<?= base_url('admin/sms.php?status=failed') ?>" class="review-row text-decoration-none"><div class="k">Failed</div><div class="v"><?= $stats['smsFailed'] ?></div></a>
      <a href="<?= base_url('admin/sms.php?status=test') ?>" class="review-row text-decoration-none"><div class="k">Test mode (logged, not sent)</div><div class="v"><?= $stats['smsLoggedOnly'] ?></div></a>
    </div>
  </div>

  <div class="dash-quick-grid" style="margin-bottom:24px">
    <a href="<?= base_url('admin/applicants.php') ?>" class="dash-quick-card">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
      Applicant Records
    </a>
    <a href="<?= base_url('admin/applications.php') ?>" class="dash-quick-card">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 3h7l5 5v13H7z"/><path d="M14 3v5h5"/></svg>
      Application Monitoring
    </a>
    <a href="<?= base_url('admin/users.php') ?>" class="dash-quick-card">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
      User Management
    </a>
    <a href="<?= base_url('admin/analytics.php') ?>" class="dash-quick-card">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
      Analytics &amp; Reports
    </a>
    <a href="<?= base_url('admin/reports.php') ?>" class="dash-quick-card">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="13" y2="17"/></svg>
      Reporting
    </a>
    <a href="<?= base_url('admin/sms.php') ?>" class="dash-quick-card">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
      SMS Management
    </a>
    <a href="<?= base_url('admin/settings.php') ?>" class="dash-quick-card">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
      System Settings
    </a>
    <a href="<?= base_url('admin/monitoring.php') ?>" class="dash-quick-card">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>
      DB &amp; System Monitoring
    </a>
    <a href="<?= base_url('admin/audit_log.php') ?>" class="dash-quick-card">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12h6"/><path d="M9 16h6"/><path d="M13 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M13 3v5h5"/></svg>
      Audit Logs
    </a>
    <a href="<?= base_url('admin/appointment_slots.php') ?>" class="dash-quick-card">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
      Appointment Slots
    </a>
    <a href="<?= base_url('admin/faq.php') ?>" class="dash-quick-card">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 2-3 4"/><line x1="12" y1="17" x2="12" y2="17.01"/></svg>
      Manage FAQ
    </a>
  </div>

  <div class="review-card">
    <div class="review-card-head"><div class="title">Recent Activity</div></div>
    <?php foreach ($recentActivity as $log): ?>
      <div class="mb-2" style="font-size:13px">
        <b><?= e($log['action']) ?></b> by <?= e($log['actor_name']) ?> — <?= e(date('M j, Y g:i A', strtotime($log['created_at']))) ?>
      </div>
    <?php endforeach; ?>
    <a href="<?= base_url('admin/audit_log.php') ?>" style="font-size:13px">View full audit log &rarr;</a>
  </div>
</div>
