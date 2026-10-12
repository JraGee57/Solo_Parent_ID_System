<?php
/** @var string $ownDashboard  -- plus the variables of views/partials/notification-list.php */
?>
<div class="portal-container" style="max-width:760px">
  <a href="<?= e($ownDashboard) ?>" class="staff-back-link"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5m7-7-7 7 7 7"/></svg><span>Back</span></a>
  <h1 class="font-heading mt-2 mb-3" style="font-size:26px">Notifications</h1>
  <?php require APP_ROOT . '/views/partials/notification-list.php'; ?>
</div>