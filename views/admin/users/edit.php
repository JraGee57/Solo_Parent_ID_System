<?php
/**
 * @var string $error
 * @var string $success
 * @var array  $target            the account being managed
 * @var bool   $isSelf
 * @var bool   $locked
 * @var int    $applicationCount
 * @var array  $roleLabels
 * @var string $scope             activity filter: '' | by | on
 * @var array  $activity          ['rows' => audit_logs, ...paginate() fields]
 */
$editUrl = fn(array $q = []) => base_url('admin/user_edit.php?' . http_build_query(['id' => (int) $target['id']] + $q));
$when = fn(?string $v) => $v ? date('M j, Y g:i A', strtotime($v)) : '—';
$userIcon = require APP_ROOT . '/includes/user-management-icons.php';
?>
<div class="portal-container user-management" style="max-width:900px">
  <a href="<?= base_url('admin/users.php') ?>" class="staff-back-link"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5m7-7-7 7 7 7"/></svg><span>Back</span></a>
  <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mt-2 mb-3">
    <div>
      <h1 class="font-heading m-0" style="font-size:26px"><?= e($target['full_name']) ?></h1>
      <div style="font-size:13px;color:var(--muted)">@<?= e($target['username']) ?> · <?= e($roleLabels[$target['role']] ?? $target['role']) ?><?= $isSelf ? ' · this is you' : '' ?></div>
    </div>
    <div class="d-flex flex-wrap gap-1">
      <span class="status-pill" style="background:<?= $target['is_active'] ? '#dcf3e2' : '#fde8e8' ?>;color:<?= $target['is_active'] ? '#1c7a3f' : '#a51c1c' ?>"><?= $target['is_active'] ? 'Active' : 'Deactivated' ?></span>
      <?php if ($locked): ?><span class="status-pill" style="background:#fde8e8;color:#a51c1c">Locked out</span><?php endif; ?>
      <?php if ($target['must_change_password']): ?><span class="status-pill" style="background:#fdf1d6;color:#8a6d1a">Temporary password</span><?php endif; ?>
      <?php if ($target['role'] === 'applicant' && $target['mobile'] && !$target['mobile_verified_at']): ?><span class="status-pill" style="background:#fdf1d6;color:#8a6d1a">Mobile not confirmed</span><?php endif; ?>
    </div>
  </div>

  <?php if ($error): ?><div class="auth-error mb-3" role="alert"><?= e($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="info-box" style="max-width:none" role="status"><?= e($success) ?></div><?php endif; ?>

  <div class="review-card">
    <div class="review-card-head"><div class="title">Account</div></div>
    <div class="review-grid mb-3">
      <div class="review-row"><div class="k">Created</div><div class="v"><?= e($when($target['created_at'])) ?></div></div>
      <div class="review-row"><div class="k">Last sign-in</div><div class="v"><?= $target['last_login_at'] ? e($when($target['last_login_at'])) : 'Never' ?></div></div>
      <div class="review-row"><div class="k">Failed sign-in attempts</div><div class="v"><?= (int) $target['failed_login_attempts'] ?><?= $locked ? ' · locked until ' . e(date('g:i A', strtotime($target['locked_until']))) : '' ?></div></div>
      <?php if ($target['role'] === 'applicant'): ?>
        <div class="review-row"><div class="k">Mobile number</div><div class="v"><?php if (!$target['mobile']): ?>None<?php elseif ($target['mobile_verified_at']): ?><?= e($target['mobile']) ?> · confirmed <?= e($when($target['mobile_verified_at'])) ?><?php else: ?><?= e($target['mobile']) ?> · <b>not confirmed</b> — the applicant can't use their account until they enter the texted code<?php endif; ?></div></div>
        <div class="review-row"><div class="k">Applications</div><div class="v"><?= $applicationCount ?><?php if (can($staff, 'applicants.view')): ?> · <a href="<?= e(base_url('admin/applicant_view.php?id=' . (int) $target['id'])) ?>">Applicant record</a><?php endif; ?></div></div>
      <?php endif; ?>
    </div>
    <form method="post" class="d-flex flex-column gap-2" style="max-width:520px">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update">
      <label class="user-label">Full name<input class="form-control" type="text" name="full_name" value="<?= e($target['full_name']) ?>" maxlength="150" required></label>
      <label class="user-label">Email address<input class="form-control" type="email" name="email" value="<?= e($target['email']) ?>" maxlength="150" required></label>
      <label class="user-label">Mobile number <span class="opt">(optional)</span><input class="form-control" type="text" name="mobile" value="<?= e($target['mobile']) ?>" placeholder="09XXXXXXXXX" maxlength="20"></label>
      <div style="font-size:12px;color:var(--faint)">The username <b><?= e($target['username']) ?></b> can't be changed.</div>
      <div class="user-form-actions">
        <button type="submit" class="btn-navy user-action"><?= $userIcon('save') ?> Save Details</button>
        <a href="<?= e(base_url('admin/users.php')) ?>" class="btn-outline user-action">Cancel</a>
      </div>
    </form>
  </div>

  <?php if ($isSelf): ?>
    <div class="review-card">
      <div class="review-card-head"><div class="title">Role, status &amp; access</div></div>
      <div style="font-size:14px;color:var(--muted)">You can't change your own role, deactivate yourself or reset your own access — another administrator can. To change your password, use <a href="<?= e(base_url('admin/account.php')) ?>">My Account</a>.</div>
    </div>
  <?php else: ?>
    <div class="row g-3 mb-3">
      <div class="col-12 col-md-6">
        <div class="review-card h-100 mb-0">
          <div class="review-card-head"><div class="title">Role</div></div>
          <form method="post" class="d-flex flex-wrap gap-2 align-items-center">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="change_role">
            <select name="role" class="form-select" style="max-width:220px" aria-label="Role">
              <?php foreach ($roleLabels as $key => $label): ?><option value="<?= e($key) ?>" <?= $target['role'] === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select>
            <button type="submit" class="btn-outline user-action" onclick="return confirm('Change this account\'s role? It takes effect on their next page load.')"><?= $userIcon('role') ?> Assign Role</button>
          </form>
          <div style="font-size:12px;color:var(--faint);margin-top:8px">Administrator: system management. MSWD Staff: processes applications. Solo Parent: applies for their own ID.<?= $applicationCount ? ' This account has applications on file, so it stays a Solo Parent account.' : '' ?></div>
        </div>
      </div>
      <div class="col-12 col-md-6">
        <div class="review-card h-100 mb-0">
          <div class="review-card-head"><div class="title">Status &amp; access</div></div>
          <div class="user-access-actions">
            <form method="post" class="m-0" onsubmit="return confirm('<?= $target['is_active'] ? 'Deactivate this account? They are signed out immediately.' : 'Activate this account?' ?>')">
              <?= csrf_field() ?>
              <button type="submit" name="action" value="<?= $target['is_active'] ? 'deactivate' : 'activate' ?>" class="user-action <?= $target['is_active'] ? 'btn-danger-outline' : 'btn-outline user-action-success' ?>"><?= $userIcon('power') ?> <?= $target['is_active'] ? 'Deactivate' : 'Activate' ?></button>
            </form>
            <form method="post" class="m-0" onsubmit="return confirm('Issue a temporary password? Their current password stops working and they must choose a new one at sign-in.')">
              <?= csrf_field() ?>
              <button type="submit" name="action" value="reset_access" class="btn-outline user-action user-action-warning"><?= $userIcon('key') ?> Reset Access</button>
            </form>
            <?php if ($target['role'] === 'applicant' && $target['mobile'] && !$target['mobile_verified_at']): ?>
              <form method="post" class="m-0" onsubmit="return confirm('Mark <?= e($target['mobile']) ?> as confirmed? Only do this after checking the number belongs to this applicant (for example, by calling it).')">
                <?= csrf_field() ?>
                <button type="submit" name="action" value="confirm_mobile" class="btn-outline user-action user-action-success"><?= $userIcon('mobile') ?> Confirm Mobile</button>
              </form>
            <?php endif; ?>
            <?php if ($locked || (int) $target['failed_login_attempts'] > 0): ?>
              <form method="post" class="m-0">
                <?= csrf_field() ?>
                <button type="submit" name="action" value="unlock" class="btn-outline user-action"><?= $userIcon('unlock') ?> Unlock Account</button>
              </form>
            <?php endif; ?>
          </div>
          <div style="font-size:12px;color:var(--faint);margin-top:8px">Reset Access issues a temporary password (shown once), clears any lockout and makes them choose a new password at sign-in.<?php if ($target['role'] === 'applicant' && $target['mobile'] && !$target['mobile_verified_at']): ?> Confirm Mobile is for an applicant whose verification text never arrives, once MSWDO has checked the number another way.<?php endif; ?></div>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <div class="review-card" id="activity">
    <div class="review-card-head">
      <div class="title">Account Activity (<?= (int) $activity['total'] ?>)</div>
      <nav class="user-activity-filters" aria-label="Account activity filters">
        <?php foreach (['' => 'All', 'by' => 'By this account', 'on' => 'Changes to this account'] as $key => $label): ?>
          <a href="<?= e($editUrl($key !== '' ? ['scope' => $key] : [])) ?>#activity" class="user-activity-filter<?= $scope === $key ? ' is-active' : '' ?>" <?= $scope === $key ? 'aria-current="true"' : '' ?>><?= e($label) ?></a>
        <?php endforeach; ?>
      </nav>
    </div>
    <?php if (!$activity['rows']): ?><div style="font-size:13px;color:var(--muted)">No activity recorded.</div><?php endif; ?>
    <?php foreach ($activity['rows'] as $log): ?>
      <div class="case-entry">
        <div><b><?= e($log['action']) ?></b> <span class="when"><?= e($when($log['created_at'])) ?> · <?= e($log['actor_name']) ?><?= (int) $log['user_id'] !== (int) $target['id'] ? ' (' . e($roleLabels[$log['actor_role']] ?? $log['actor_role']) . ')' : '' ?><?= $log['ip_address'] ? ' · ' . e($log['ip_address']) : '' ?></span></div>
        <?php if ($log['details'] || $log['entity_type']): ?><div class="text"><?= e($log['details'] ?? '') ?><?= $log['entity_type'] && $log['entity_type'] !== 'user' ? ' <span style="color:var(--faint)">[' . e($log['entity_type']) . ($log['entity_id'] ? ' #' . (int) $log['entity_id'] : '') . ']</span>' : '' ?></div><?php endif; ?>
      </div>
    <?php endforeach; ?>
    <?php if ($activity['totalPages'] > 1): ?>
      <div class="d-flex justify-content-between align-items-center mt-2">
        <a href="<?= e($editUrl(array_filter(['scope' => $scope, 'apage' => max(1, $activity['page'] - 1)]))) ?>#activity" class="btn-outline user-action" <?= $activity['page'] <= 1 ? 'aria-disabled="true" tabindex="-1"' : '' ?>>&larr; Newer</a>
        <span style="font-size:13px;color:var(--muted)">Page <?= $activity['page'] ?> of <?= $activity['totalPages'] ?></span>
        <a href="<?= e($editUrl(array_filter(['scope' => $scope, 'apage' => min($activity['totalPages'], $activity['page'] + 1)]))) ?>#activity" class="btn-outline user-action" <?= $activity['page'] >= $activity['totalPages'] ? 'aria-disabled="true" tabindex="-1"' : '' ?>>Older &rarr;</a>
      </div>
    <?php endif; ?>
  </div>
</div>
