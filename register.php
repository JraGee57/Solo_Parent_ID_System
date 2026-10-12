<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/csrf.php';
require __DIR__ . '/controllers/AuthController.php';

extract((new AuthController($pdo))->handleApplicantAuth('signup'));
$loginTabUrl = base_url('login.php');
$signupTabUrl = base_url('register.php');

$pageTitle = 'Create Account — ' . setting('system_name');
require __DIR__ . '/includes/header.php';
?>
<div class="portal-container" style="max-width:460px">
  <h1 class="font-heading mb-3" style="font-size:24px"><?= $authMode === 'signup' ? 'Create Account' : 'Log In' ?></h1>
  <?php require __DIR__ . '/views/auth/login.php'; ?>
</div>
<?php
$footerVariant = 'portal';
require __DIR__ . '/includes/footer.php';
