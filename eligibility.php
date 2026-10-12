<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/csrf.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/controllers/PublicEligibilityController.php';

$user = current_user($pdo);
$pageTitle = 'Eligibility Checker — ' . setting('system_name');
require __DIR__ . '/includes/header.php';

(new PublicEligibilityController($pdo))->handle($user);

$footerVariant = 'portal';
require __DIR__ . '/includes/footer.php';
