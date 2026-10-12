<?php
/**
 * @var array  $staff  the signed-in admin
 * @var string $error
 * @var string $success
 * @var array  $form           create-form values to redisplay after an error
 * @var array  $filters        role, status, q
 * @var array  $roleLabels
 * @var array  $statusFilters
 * @var array  $users          one page of accounts
 * @var array  $pager
 */
$field = 'padding:9px;border:1px solid var(--input-border);border-radius:6px;font-size:14px';
$roleStyle = ['admin' => ['#1b3a6b', '#fff'], 'mswd_social_worker' => ['#dce8f5', '#1b3a6b'], 'applicant' => ['#eef0f3', '#3a4250']];
$userIcon = require APP_ROOT . '/includes/user-management-icons.php';
?>
<div class="portal-container user-management">
  <a href="<?= base_url('admin/dashboard.php') ?>" class="staff-back-link"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5m7-7-7 7 7 7"/></svg><span>Back</span></a>
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mt-2 mb-4">
    <h1 class="font-heading m-0" style="font-size:26px">Manage Users</h1>
    <span style="font-size:14px">Signed in as <b><?= e($staff['full_name']) ?></b></span>
  </div>

  <?php if ($error): ?><div class="auth-error mb-3" role="alert"><?= e($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="info-box" style="max-width:none" role="status"><?= e($success) ?></div><?php endif; ?>

  <div class="row g-4">
    <div class="col-12 col-lg-4">
      <div class="review-card">
        <div class="review-card-head"><div class="title">Create Account</div></div>
        <form method="post" class="d-flex flex-column gap-2">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="create">
          <label class="user-label">Full name<input class="form-control" type="text" name="fullName" value="<?= e($form['fullName'] ?? '') ?>" maxlength="150" required></label>
          <label class="user-label">Email address<input class="form-control" type="email" name="email" value="<?= e($form['email'] ?? '') ?>" maxlength="150" required></label>
          <label class="user-label">Mobile number <span class="opt">(optional)</span><input class="form-control" type="text" name="mobile" value="<?= e($form['mobile'] ?? '') ?>" placeholder="09XXXXXXXXX" maxlength="20"></label>
          <label class="user-label">Username<input class="form-control" type="text" name="username" value="<?= e($form['username'] ?? '') ?>" pattern="[A-Za-z0-9._\-]{3,50}" maxlength="50" autocomplete="off" required></label>
          <label class="user-label">Initial password<input class="form-control" type="password" name="password" minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password" placeholder="Min. <?= PASSWORD_MIN_LENGTH ?>, letters + numbers" required></label>
          <label class="user-label">Role
            <select name="role" class="form-select" required>
              <option value="">Select role</option>
              <?php foreach ($roleLabels as $key => $label): ?><option value="<?= e($key) ?>" <?= ($form['role'] ?? '') === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select>
          </label>
          <p style="font-size:13px;color:var(--muted);margin:0">New MSWD staff automatically have access to all existing applicant records, applications and appointments when they sign in. Previous staff history is preserved.</p>
          <label style="font-size:13px;display:flex;gap:8px;align-items:flex-start"><input type="checkbox" name="mustChange" value="1" <?= !$form || !empty($form['mustChange']) ? 'checked' : '' ?> style="margin-top:3px"> Require a new password at first sign-in</label>
          <button type="submit" class="btn-navy user-action user-action-create"><?= $userIcon('add') ?> Create Account</button>
        </form>
      </div>
    </div>

    <div class="col-12 col-lg-8">
      <form method="get" class="record-filters mb-3" role="search" aria-label="Filter accounts">
        <input type="text" name="q" value="<?= e($filters['q']) ?>" maxlength="100" placeholder="Name, username, email or mobile" aria-label="Search accounts" style="<?= $field ?>;flex:2 1 220px">
        <select name="role" aria-label="Role" style="<?= $field ?>">
          <option value="">All roles</option>
          <?php foreach ($roleLabels as $key => $label): ?><option value="<?= e($key) ?>" <?= $filters['role'] === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
        </select>
        <select name="status" aria-label="Account status" style="<?= $field ?>">
          <option value="">Any status</option>
          <?php foreach ($statusFilters as $key => $label): ?><option value="<?= e($key) ?>" <?= $filters['status'] === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
        </select>
        <button type="submit" class="btn-navy user-action"><?= $userIcon('filter') ?> Apply Filters</button>
        <?php if ($filters['q'] !== '' || $filters['role'] !== '' || $filters['status'] !== ''): ?><a href="<?= e(base_url('admin/users.php')) ?>" class="btn-outline user-action"><?= $userIcon('clear') ?> Clear</a><?php endif; ?>
      </form>
      <div class="user-list-summary" role="status">
        <span><?php if ($users): ?>Showing <?= (int) $pager['offset'] + 1 ?>&ndash;<?= (int) $pager['offset'] + count($users) ?> of <?= (int) $pager['total'] ?> accounts<?php else: ?>0 accounts<?php endif; ?></span>
        <span><?= UserManagementService::PER_PAGE ?> per page</span>
      </div>

      <?php if (!$users): ?>
        <div class="empty-state compact">No accounts match this filter. Try another name, role or account status.</div>
      <?php endif; ?>

      <?php if ($users): ?>
      <div class="user-list-scroll" role="region" aria-label="User accounts" tabindex="0">
        <table class="user-list-table">
          <caption class="visually-hidden">User accounts with role, status and account actions</caption>
          <thead><tr><th scope="col">Account</th><th scope="col">Role</th><th scope="col">Status</th><th scope="col" class="user-actions-heading">Actions</th></tr></thead>
          <tbody>
      <?php foreach ($users as $u): [$rbg, $rfg] = $roleStyle[$u['role']] ?? ['#eef0f3', '#3a4250']; $isLocked = $u['locked_until'] && strtotime($u['locked_until']) > time(); ?>
        <tr>
          <td class="user-account-cell">
            <a href="<?= e(base_url('admin/user_edit.php?id=' . (int) $u['id'])) ?>" class="user-account-name"><?= e($u['full_name']) ?><?= (int) $u['id'] === (int) $staff['id'] ? ' <span class="user-self-label">(you)</span>' : '' ?></a>
            <div class="user-account-meta">@<?= e($u['username']) ?></div>
            <div class="user-account-meta">Last sign-in: <?= $u['last_login_at'] ? e(date('M j, Y', strtotime($u['last_login_at']))) : 'Never' ?></div>
          </td>
          <td class="user-role-cell"><span class="user-mobile-label" aria-hidden="true">Role</span>
            <span class="status-pill" style="background:<?= $rbg ?>;color:<?= $rfg ?>"><?= e($roleLabels[$u['role']] ?? $u['role']) ?></span>
          </td>
          <td class="user-status-cell"><span class="user-mobile-label" aria-hidden="true">Status</span><div class="user-status-badges">
            <span class="status-pill" style="background:<?= $u['is_active'] ? '#dcf3e2' : '#fde8e8' ?>;color:<?= $u['is_active'] ? '#1c7a3f' : '#a51c1c' ?>"><?= $u['is_active'] ? 'Active' : 'Deactivated' ?></span>
            <?php if ($isLocked): ?><span class="status-pill" style="background:#fde8e8;color:#a51c1c">Locked out</span><?php endif; ?>
            <?php if ($u['must_change_password']): ?><span class="status-pill" style="background:#fdf1d6;color:#8a6d1a">Temporary password</span><?php endif; ?>
          </div></td>
          <td><div class="user-table-actions">
          <a href="<?= e(base_url('admin/user_edit.php?id=' . (int) $u['id'])) ?>" class="btn-outline user-action" aria-label="Edit account <?= e($u['username']) ?>"><?= $userIcon('edit') ?> Edit</a>
        <?php if ((int) $u['id'] !== (int) $staff['id']): ?>
          <form method="post" class="m-0" onsubmit="return confirm(<?= e(json_encode('Permanently delete the account @' . $u['username'] . '? This cannot be undone.')) ?>);">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
            <button type="submit" class="btn-danger-outline user-action user-icon-action" aria-label="Delete account <?= e($u['username']) ?>" title="Delete account <?= e($u['username']) ?>"><?= $userIcon('delete') ?></button>
          </form>
        <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
      <?php require APP_ROOT . '/views/partials/pagination.php'; ?>
    </div>
  </div>
</div>
