<?php
/**
 * Standard public-site page: shared header, one content view, portal footer.
 * includes/header.php reads $pageTitle and (optionally) $user/$staff from this scope.
 * Staff/admin pages get <main> (it grows so the footer sits at the bottom of short pages)
 * and their own portal footer instead of the public one.
 *
 * @var string $pageTitle
 * @var string $contentView  path relative to APP_ROOT
 */
require APP_ROOT . '/includes/header.php';
if (!empty($staff)) {
    echo '<main class="portal-main">';
    require APP_ROOT . '/' . $contentView;
    echo '</main>';
    $footerVariant = $staff['role'] === 'admin' ? 'admin' : 'staff';
} else {
    require APP_ROOT . '/' . $contentView;
    $footerVariant = 'portal';
}
require APP_ROOT . '/includes/footer.php';
