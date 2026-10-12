<?php
/**
 * @var string $error
 * @var string $success
 * @var array  $categories
 * @var array  $categoriesById  id => name
 * @var array  $requirements    standard first, then grouped by category
 */
?>
<div class="portal-container">
  <a href="<?= base_url('admin/settings.php') ?>" class="staff-back-link"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5m7-7-7 7 7 7"/></svg><span>Back</span></a>
  <h1 class="font-heading mt-2 mb-4" style="font-size:26px">Application Requirements</h1>
  <div style="font-size:13px;color:var(--muted);margin-bottom:20px;max-width:640px">Category-specific requirements apply to new applications. Standard requirements are retained for the existing renewal and legacy application workflow.</div>

  <?php if ($error): ?><div class="auth-error mb-3" role="alert"><?= e($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="info-box" style="max-width:none" role="status"><?= e($success) ?></div><?php endif; ?>

  <div class="row g-4">
    <div class="col-12 col-lg-5">
      <div class="review-card">
        <div class="review-card-head"><div class="title">Add Requirement</div></div>
        <form method="post" class="d-flex flex-column gap-3">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="create">
          <select name="category_id" class="form-select" style="padding:10px;border:1px solid var(--input-border);border-radius:6px">
            <option value="">Renewal / legacy standard requirement</option>
            <?php foreach ($categories as $c): ?>
              <option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <input class="form-control" type="text" name="doc_key" aria-label="Document key" maxlength="50" pattern="[A-Za-z0-9_]+" placeholder="Doc key (e.g. medCert2)" required>
          <input class="form-control" type="text" name="label" aria-label="Requirement label" maxlength="255" placeholder="Requirement label" required>
          <label class="d-flex align-items-center gap-2" style="font-size:13px">
            <input type="checkbox" name="is_required" value="1" checked> Required (not optional)
          </label>
          <input class="form-control" type="number" name="sort_order" aria-label="Sort order" value="0" min="0" max="9999" placeholder="Sort order">
          <button type="submit" class="btn-navy">Add Requirement</button>
        </form>
      </div>
    </div>

    <div class="col-12 col-lg-7">
      <?php
      $lastCat = 'unset';
      foreach ($requirements as $r):
          $catLabel = $r['category_id'] === null ? 'Renewal / legacy standard' : ($categoriesById[$r['category_id']] ?? 'Unknown category');
          if ($catLabel !== $lastCat): $lastCat = $catLabel; ?>
            <div style="font-weight:700;font-size:13px;color:var(--ink);margin:18px 0 8px"><?= e($catLabel) ?></div>
          <?php endif; ?>
        <div class="review-card" style="margin-bottom:10px">
          <form method="post" class="d-flex flex-wrap align-items-center gap-2">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="req_id" value="<?= (int) $r['id'] ?>">
            <span style="font-size:12px;color:var(--faint);min-width:100px"><?= e($r['doc_key']) ?></span>
            <input class="form-control" type="text" name="label" maxlength="255" required value="<?= e($r['label']) ?>" style="flex:1 1 200px;padding:8px;border:1px solid var(--input-border);border-radius:6px;font-size:13px">
            <label class="d-flex align-items-center gap-1" style="font-size:12px">
              <input type="checkbox" name="is_required" value="1" <?= $r['is_required'] ? 'checked' : '' ?>> Required
            </label>
            <input class="form-control" type="number" name="sort_order" min="0" max="9999" value="<?= (int) $r['sort_order'] ?>" style="width:70px;padding:8px;border:1px solid var(--input-border);border-radius:6px;font-size:13px">
            <button type="submit" class="btn-outline" style="padding:6px 12px;font-size:12px">Save</button>
          </form>
          <form method="post" class="mt-2" onsubmit="return confirm('Remove this requirement?');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="req_id" value="<?= (int) $r['id'] ?>">
            <button type="submit" class="btn-danger-outline btn-sm">Delete</button>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
