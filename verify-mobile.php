<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/csrf.php';
require __DIR__ . '/controllers/AuthController.php';

extract((new AuthController($pdo))->verifyMobile());

$pageTitle = 'Confirm Your Mobile Number — ' . setting('system_name');
require __DIR__ . '/includes/header.php';
require __DIR__ . '/views/auth/verify-mobile.php';
$footerVariant = 'portal';
require __DIR__ . '/includes/footer.php';
