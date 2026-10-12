<?php
/**
 * Shared page footer. Expects $footerVariant = 'home' | 'services' | 'portal' | 'staff' | 'admin'.
 * Every variant renders the same footer (views/partials/site-footer.php); only the second column's
 * links differ. 'staff' and 'admin' are set by views/layouts/page.php.
 */
$footerVariant = $footerVariant ?? 'services';
$footerPortalAbout = 'e-SoloParentID System — Municipal Social Welfare and Development Office (MSWDO), Virac, Catanduanes.';

$variants = [
    'home' => [
        'about' => "Virac, officially the Municipality of Virac, is the capital of the province of Catanduanes, Philippines.",
        'title' => 'CATEGORIES',
        'list' => [['News', null], ['Transparency', null], ['Resolutions', null], ['Solo Parent Services', null]],
    ],
    'services' => [
        'about' => "Browse and access LGU Virac's online services, starting with Solo Parent ID registration.",
        'title' => 'ONLINE SERVICES',
        'list' => [['Solo Parent ID Services', null], ['Apply Online', null], ['Check Status', null]],
    ],
    'portal' => [
        'about' => 'Online Services — Solo Parent ID registration, and status tracking for residents of Virac, Catanduanes.',
        'title' => 'ONLINE SERVICES',
        'list' => [
            ['Apply for Solo Parent ID', base_url(!empty($user) ? 'applicant/dashboard.php?section=apply' : 'portal.php?tab=apply')],
            ['My Applications', base_url(!empty($user) ? 'applicant/dashboard.php?section=application' : 'login.php?next=applications')],
            ['Track Application', base_url(!empty($user) ? 'applicant/dashboard.php?section=application' : 'portal.php?tab=status')],
            ['Contact & Help', base_url(!empty($user) ? 'applicant/dashboard.php?section=help' : 'portal.php?tab=help')],
        ],
    ],
    'staff' => [
        'about' => $footerPortalAbout,
        'title' => 'MSWD STAFF PORTAL',
        'list' => [
            ['Chat Support', base_url('staff/chat.php')],
            ['My Account', base_url('staff/account.php')],
        ],
    ],
    'admin' => [
        'about' => $footerPortalAbout,
        'title' => 'ADMINISTRATION PORTAL',
        'list' => [
            ['System Settings', base_url('admin/settings.php')],
            ['System Monitoring', base_url('admin/monitoring.php')],
            ['Chat Support FAQ', base_url('admin/faq.php')],
        ],
    ],
];
$v = $variants[$footerVariant] ?? $variants['services'];
$footerAbout = $v['about'];
$footerListTitle = $v['title'];
$footerList = $v['list'];
$footerGov = $footerVariant === 'home';
require APP_ROOT . '/views/partials/site-footer.php';
?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<script src="<?= public_asset('js/app.js') ?>"></script>
<?php if (($staff['role'] ?? '') === 'admin'): ?>
<script src="<?= public_asset('js/admin-management.js') ?>"></script>
<?php endif; ?>
</body>
</html>
