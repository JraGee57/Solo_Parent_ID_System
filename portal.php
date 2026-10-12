<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/csrf.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/data.php';

$validTabs = ['overview', 'login', 'apply', 'myapps', 'status', 'news', 'help', 'search'];
$tab = $_GET['tab'] ?? 'overview';
if (!in_array($tab, $validTabs, true)) {
    $tab = 'overview';
}

$staff = current_staff($pdo);
if ($staff) {
    redirect(base_url($staff['role'] === 'admin' ? 'admin/dashboard.php' : 'staff/dashboard.php'));
}
$user = current_user($pdo);
// Old login-tab links use the same screens as the navbar and drawer.
if ($tab === 'login' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(base_url($user ? 'applicant/dashboard.php' : (($_GET['mode'] ?? '') === 'signup' ? 'register.php' : 'login.php')));
}
if ($tab === 'myapps') {
    redirect(base_url($user ? 'applicant/dashboard.php?section=application' : 'login.php?next=applications'));
}
if ($tab === 'login' && $user) {
    $tab = 'overview';
}
// Applying needs a confirmed mobile number (status updates are texted to it).
if ($user && empty($user['mobile_verified_at']) && in_array($tab, ['apply', 'myapps'], true)) {
    redirect(base_url('verify-mobile.php'));
}

$pageTitle = 'Solo Parent ID Services — ' . setting('system_name');
$unreadAnnouncementCount = count_unread_announcements(announcements_list($pdo));
require __DIR__ . '/includes/header.php';
?>

<div class="portal-container<?= $tab === 'overview' ? ' portal-home' : '' ?>">
  <?php if ($tab !== 'overview' && !($tab === 'news' && !empty($_GET['id']))): ?>
    <a href="<?= base_url('portal.php') ?>" class="public-back-link"><svg aria-hidden="true" focusable="false" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 5-7 7 7 7M5 12h14"/></svg><span>Back to Home</span></a>
  <?php endif; ?>
  <div class="portal-tabs">
    <?php if ($tab === 'overview'): ?>
      <h1 class="font-heading" style="font-size:26px;margin:0;color:var(--ink)">A Guide to the Solo Parent ID</h1>
    <?php else: ?>
      <h1 class="font-heading" style="font-size:26px;margin:0;color:var(--ink)"><?= e(['login' => 'Log in or create an account', 'apply' => 'Apply online', 'myapps' => 'My applications', 'status' => 'Track Application', 'news' => 'News & Announcements', 'help' => 'Contact & Help', 'search' => 'Search Services'][$tab] ?? 'Solo Parent ID portal') ?></h1>
    <?php endif; ?>
    <div class="portal-tabs-auth">
      <?php if ($user): ?>
        <div class="signed-in-pill">
          <div style="font-size:14px;color:var(--ink)">Signed in as <b><?= e($user['full_name']) ?></b></div>
          <form method="post" action="<?= base_url('logout.php') ?>" class="m-0">
            <?= csrf_field() ?>
            <button type="submit" class="btn-outline" style="padding:6px 14px;font-size:13px">Log Out</button>
          </form>
        </div>
      <?php endif; /* logged out: Log In / Create Account lives in the header (includes/header.php) */ ?>
    </div>
  </div>

  <?php
  switch ($tab) {
      case 'login':
          require __DIR__ . '/includes/portal/login.php';
          break;
      case 'apply':
          require __DIR__ . '/includes/portal/apply.php';
          break;
      case 'myapps':
          require __DIR__ . '/includes/portal/myapps.php';
          break;
      case 'status':
          require __DIR__ . '/includes/portal/status.php';
          break;
      case 'search':
          require __DIR__ . '/includes/portal/search.php';
          break;
      case 'help':
          require __DIR__ . '/includes/portal/help.php';
          break;
      case 'news':
          require __DIR__ . '/includes/portal/news.php';
          break;
      default:
          require __DIR__ . '/includes/portal/overview.php';
  }
  ?>
</div>

<?php
$footerVariant = 'portal';
require __DIR__ . '/includes/footer.php';
