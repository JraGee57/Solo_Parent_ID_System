<?php
/**
 * Audit log viewer (administrators only — AdminController::auditLog()).
 *
 * @var array   $staff
 * @var array   $filters      q, category, action, role, user, from, to
 * @var ?string $filterError
 * @var array   $rows         this page of audit_logs
 * @var array   $pager
 * @var array   $targets      "entity_type:entity_id" => ['label', 'link' => [key, id] | null]
 * @var array   $actions      action names for the filter (narrowed to the category)
 * @var array   $roles        actor roles present in the log
 * @var ?string $userName     the account filtered on, if any
 */
$field = 'padding:8px;border:1px solid var(--input-border);border-radius:6px;font-size:14px;background:#fff';
$active = array_filter($filters, fn($v) => $v !== '' && $v !== 0);
$targetUrl = fn(array $link) => base_url(($link[0] === 'user_id' ? 'admin/user_edit.php' : 'admin/application_view.php') . '?id=' . $link[1]);
?>
<div class="portal-container" style="max-width:1200px">
  <a href="<?= base_url('admin/dashboard.php') ?>" class="staff-back-link"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5m7-7-7 7 7 7"/></svg><span>Back</span></a>
  <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mt-2 mb-3">
    <div>
      <h1 class="font-heading m-0" style="font-size:26px">Audit Log</h1>
      <div style="font-size:13px;color:var(--muted);max-width:680px">Every sign-in, application, document, decision, appointment, account and settings change, with who did it and when. Records are permanent: the database refuses any edit or deletion, including from this page.</div>
    </div>
    <a href="<?= e(base_url('admin/audit_log.php') . '?' . http_build_query($active + ['output' => 'csv'])) ?>" class="btn-navy text-decoration-none" style="padding:8px 16px;font-size:13px">Download CSV</a>
  </div>

  <nav class="d-flex flex-wrap gap-2 mb-3" aria-label="Event category">
    <?php foreach (['' => 'All events'] + array_map(fn($c) => $c[0], AuditLogService::CATEGORIES) as $key => $label): ?>
      <?php $on = $filters['category'] === $key; ?>
      <a href="<?= e(base_url('admin/audit_log.php') . '?' . http_build_query(array_diff_key($active, ['category' => 1, 'action' => 1]) + ($key !== '' ? ['category' => $key] : []))) ?>" class="status-pill text-decoration-none" <?= $on ? 'aria-current="page"' : '' ?> style="background:<?= $on ? 'var(--navy)' : '#eef1f5' ?>;color:<?= $on ? '#fff' : 'var(--ink)' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </nav>

  <form method="get" action="<?= e(base_url('admin/audit_log.php')) ?>" class="analytics-filters" role="search" aria-label="Filter the audit log">
    <?php if ($filters['category'] !== ''): ?><input type="hidden" name="category" value="<?= e($filters['category']) ?>"><?php endif; ?>
    <?php if ($filters['user']): ?><input type="hidden" name="user" value="<?= (int) $filters['user'] ?>"><?php endif; ?>
    <label>Search<input type="text" name="q" value="<?= e($filters['q']) ?>" maxlength="100" placeholder="User, action or details" style="<?= $field ?>;min-width:200px"></label>
    <label>Action
      <select name="action" style="<?= $field ?>;max-width:240px">
        <option value="">All</option>
        <?php foreach ($actions as $a): ?><option value="<?= e($a) ?>" <?= $filters['action'] === $a ? 'selected' : '' ?>><?= e($a) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label>Role
      <select name="role" style="<?= $field ?>">
        <option value="">All</option>
        <?php foreach ($roles as $r): ?><option value="<?= e($r) ?>" <?= $filters['role'] === $r ? 'selected' : '' ?>><?= e(AuditLogService::roleLabel($r)) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label>From<input type="date" name="from" value="<?= e($filters['from']) ?>" style="<?= $field ?>"></label>
    <label>to<input type="date" name="to" value="<?= e($filters['to']) ?>" style="<?= $field ?>"></label>
    <button type="submit" class="btn-navy">Apply</button>
    <a href="<?= e(base_url('admin/audit_log.php')) ?>" class="btn-outline text-decoration-none">Reset</a>
  </form>
  <?php if ($filterError): ?><div class="auth-error mb-3" role="alert"><?= e($filterError) ?> The date range was not applied.</div><?php endif; ?>

  <div style="font-size:13px;color:var(--muted);margin-bottom:10px" role="status">
    <b style="color:var(--ink)"><?= number_format($pager['total']) ?></b> event<?= $pager['total'] === 1 ? '' : 's' ?>
    <?php if ($userName !== null): ?>
      by or about <b style="color:var(--ink)"><?= e($userName) ?></b> · <a href="<?= e(base_url('admin/audit_log.php') . '?' . http_build_query(array_diff_key($active, ['user' => 1]))) ?>">show everyone</a>
    <?php endif; ?>
  </div>

  <?php if (!$rows): ?>
    <div class="review-card" style="font-size:14px;color:var(--muted)">No audit events match these filters.</div>
  <?php else: ?>
    <?php require APP_ROOT . '/views/partials/admin-list-meta.php'; ?>
    <div class="table-responsive report-table" role="region" aria-label="Audit log records" tabindex="0">
      <table class="table table-sm table-hover audit-table">
        <thead><tr><th scope="col">Timestamp</th><th scope="col">User</th><th scope="col">Role</th><th scope="col">Action</th><th scope="col">Target</th><th scope="col">Details</th></tr></thead>
        <tbody>
          <?php foreach ($rows as $log): ?>
            <?php $target = $log['entity_type'] !== null ? ($targets[$log['entity_type'] . ':' . $log['entity_id']] ?? null) : null; ?>
            <tr>
              <td style="white-space:nowrap">
                <?= e(date('M j, Y', strtotime($log['created_at']))) ?><br><span style="color:var(--muted)"><?= e(date('g:i:s A', strtotime($log['created_at']))) ?></span>
                <?php if ($log['ip_address']): ?><div style="font-size:11px;color:var(--faint)" title="<?= e((string) $log['user_agent']) ?>"><?= e($log['ip_address']) ?></div><?php endif; ?>
              </td>
              <td>
                <?php if ($log['user_id']): ?>
                  <a href="<?= e(base_url('admin/audit_log.php') . '?user=' . (int) $log['user_id']) ?>" title="Show this account's events"><?= e($log['actor_name']) ?></a>
                <?php else: ?>
                  <?= e($log['actor_name']) ?>
                <?php endif; ?>
              </td>
              <td><?= e(AuditLogService::roleLabel($log['actor_role'])) ?></td>
              <td>
                <b><?= e($log['action']) ?></b>
                <div style="font-size:11px;color:var(--muted)"><?= e(AuditLogService::CATEGORIES[AuditLogService::category($log['action'])][0]) ?></div>
              </td>
              <td>
                <?php if ($target && $target['link']): ?>
                  <a href="<?= e($targetUrl($target['link'])) ?>"><?= e($target['label']) ?></a>
                <?php elseif ($target): ?>
                  <?= e($target['label']) ?>
                <?php elseif ($log['entity_type'] !== null): ?>
                  <span style="color:var(--muted)"><?= e(ucfirst(str_replace('_', ' ', $log['entity_type']))) ?><?= $log['entity_id'] !== null ? ' #' . (int) $log['entity_id'] : '' ?></span>
                <?php else: ?>
                  <span style="color:var(--faint)">—</span>
                <?php endif; ?>
              </td>
              <td style="max-width:360px;overflow-wrap:anywhere"><?= $log['details'] !== null ? e($log['details']) : '<span style="color:var(--faint)">—</span>' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php require APP_ROOT . '/views/partials/pagination.php'; ?>
  <?php endif; ?>
</div>
