<?php /** @var array $pager Pagination summary for admin record lists. */ ?>
<div class="admin-list-meta" role="status">
  <span><?php if ($pager['total']): ?>Showing <?= (int) $pager['offset'] + 1 ?>&ndash;<?= min((int) $pager['total'], (int) $pager['offset'] + (int) $pager['perPage']) ?> of <?= (int) $pager['total'] ?> records<?php else: ?>0 records<?php endif; ?></span>
  <span><?= (int) $pager['perPage'] ?> per page</span>
</div>
