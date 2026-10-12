<?php
/**
 * Previous / "Page X of Y" / Next links that keep the current filters.
 *
 * @var array $pager  from paginate(): page, totalPages, total
 */
?>
  <?php if ($pager['totalPages'] > 1): ?>
    <nav class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-3" aria-label="Pagination">
      <a href="<?= e(query_with(['page' => max(1, $pager['page'] - 1)])) ?>" class="btn-outline text-decoration-none" <?= $pager['page'] <= 1 ? 'style="pointer-events:none;opacity:.4" aria-disabled="true" tabindex="-1"' : '' ?>>&larr; Previous</a>
      <span style="font-size:13px;color:var(--muted)">Page <?= $pager['page'] ?> of <?= $pager['totalPages'] ?> (<?= $pager['total'] ?> total)</span>
      <a href="<?= e(query_with(['page' => min($pager['totalPages'], $pager['page'] + 1)])) ?>" class="btn-outline text-decoration-none" <?= $pager['page'] >= $pager['totalPages'] ? 'style="pointer-events:none;opacity:.4" aria-disabled="true" tabindex="-1"' : '' ?>>Next &rarr;</a>
    </nav>
  <?php endif; ?>
