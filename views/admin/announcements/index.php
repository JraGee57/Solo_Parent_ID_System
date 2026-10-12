<?php
$entry = $editing ?? ['id' => 0, 'title' => '', 'short_desc' => '', 'category' => 'Program Update', 'details' => '', 'status' => 'draft', 'published_at' => date('Y-m-d H:i:s'), 'expires_at' => '', 'priority' => 0];
$datetimeValue = function ($value): string {
    return is_string($value) && strtotime($value) !== false ? date('Y-m-d\TH:i', strtotime($value)) : '';
};
?>
<div class="portal-container">
<a href="<?= base_url('admin/dashboard.php') ?>" class="staff-back-link">&larr; Back</a>
<h1 class="font-heading mt-3 mb-3" style="font-size:26px">Public Announcements</h1>
<p>Manage news shown on News &amp; Announcements. Higher priority appears first. Dates use Philippine local time. Save as Draft to withdraw an announcement.</p>
<?php if ($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if ($notice): ?><div class="alert alert-success" role="status"><?= e($notice) ?></div><?php endif; ?>
<div class="row g-4"><div class="col-12 col-lg-5">
<div class="review-card">
<h2 style="font-size:20px"><?= !empty($entry['id']) ? 'Edit Announcement' : 'New Announcement' ?></h2>
<form method="post" class="d-grid gap-3">
<?= csrf_field() ?>
<input type="hidden" name="id" value="<?= e(is_scalar($entry['id'] ?? null) ? $entry['id'] : 0) ?>">
<?php foreach (['title' => 'Title', 'short_desc' => 'Short Description', 'category' => 'Category'] as $key => $label): ?>
<label><?= e($label) ?><input class="form-control" name="<?= e($key) ?>" maxlength="<?= $key === 'title' ? 200 : ($key === 'category' ? 80 : 500) ?>" required value="<?= e(is_string($entry[$key] ?? null) ? $entry[$key] : '') ?>"></label>
<?php endforeach; ?>
<label>Details<textarea class="form-control" name="details" rows="6" maxlength="20000" required><?= e(is_string($entry['details'] ?? null) ? $entry['details'] : '') ?></textarea></label>
<label>Status<select class="form-select" name="status"><option value="draft">Draft</option><option value="published" <?= ($entry['status'] ?? '') === 'published' ? 'selected' : '' ?>>Published</option></select></label>
<label>Publication Date<input class="form-control" type="datetime-local" name="published_at" required value="<?= e($datetimeValue($entry['published_at'] ?? '')) ?>"></label>
<label>Expiry Date (optional)<input class="form-control" type="datetime-local" name="expires_at" value="<?= e($datetimeValue($entry['expires_at'] ?? '')) ?>"></label>
<label>Priority (0&ndash;100)<input class="form-control" type="number" min="0" max="100" name="priority" required value="<?= e(is_scalar($entry['priority'] ?? null) ? $entry['priority'] : 0) ?>"></label>
<div class="d-flex flex-wrap gap-2"><button class="btn-navy" type="submit">Save Announcement</button> <a href="<?= base_url('admin/announcements.php') ?>" class="btn-outline">New Announcement</a></div>
</form></div>
</div><div class="col-12 col-lg-7">
<div class="review-card"><div class="review-card-head"><div class="title">Announcement List</div></div>
<?php require APP_ROOT . '/views/partials/admin-list-meta.php'; ?>
<div class="table-responsive" role="region" aria-label="Public announcements" tabindex="0"><table class="table"><thead><tr><th scope="col">Title</th><th scope="col">Status</th><th scope="col">Publication</th><th scope="col">Expiry</th><th scope="col">Priority</th><th scope="col">Action</th></tr></thead><tbody>
<?php foreach ($announcements as $a): ?><tr><td><?= e($a['title']) ?></td><td><?= e(ucfirst($a['status'])) ?></td><td><?= e(date('M j, Y', strtotime($a['published_at']))) ?></td><td><?= $a['expires_at'] ? e(date('M j, Y', strtotime($a['expires_at']))) : 'None' ?></td><td><?= (int) $a['priority'] ?></td><td><a class="btn-outline" href="<?= base_url('admin/announcements.php?edit=' . $a['id']) ?>" aria-label="Edit announcement <?= e($a['title']) ?>">Edit</a></td></tr><?php endforeach; ?>
<?php if (!$announcements): ?><tr><td colspan="6">No announcements yet.</td></tr><?php endif; ?>
</tbody></table></div>
<?php require APP_ROOT . '/views/partials/pagination.php'; ?>
</div></div></div></div>
