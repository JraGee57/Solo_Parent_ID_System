<?php
/**
 * Standalone error page: deliberately uses no database, session, or shared
 * header, since it may be shown because one of those failed.
 *
 * @var int     $status
 * @var ?string $debugDetail  only set when APP_DEBUG is on
 */
$messages = [
    403 => ['Access denied', 'Your account does not have permission to use this page. If you think this is a mistake, contact the system administrator.'],
    404 => ['Page not found', 'The page you were looking for does not exist.'],
    503 => ['Service unavailable', 'The system cannot reach its database right now. Please try again in a few minutes.'],
];
[$title, $text] = $messages[$status] ?? ['Something went wrong', 'An unexpected error occurred. Please try again. If it keeps happening, contact the MSWDO Virac office.'];
$home = defined('BASE_URL') ? BASE_URL . '/' : '/';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($title) ?> — e-SoloParentID</title>
<style>
  body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background: #f5f6f8; color: #1d2433; margin: 0; padding: 16px; }
  .box { max-width: 520px; margin: 12vh auto 0; background: #fff; border: 1px solid #e3e6ea; border-radius: 10px; padding: 28px; }
  h1 { font-size: 22px; margin: 0 0 8px; color: #1b3a6b; }
  p { font-size: 15px; line-height: 1.5; margin: 0 0 20px; color: #4a5568; }
  a { color: #fff; background: #1b3a6b; padding: 10px 18px; border-radius: 6px; text-decoration: none; font-size: 14px; display: inline-block; }
  pre { margin-top: 20px; font-size: 12px; background: #fdf1d6; padding: 12px; border-radius: 6px; overflow-x: auto; white-space: pre-wrap; }
</style>
</head>
<body>
  <div class="box">
    <h1><?= htmlspecialchars($title) ?></h1>
    <p><?= htmlspecialchars($text) ?></p>
    <a href="<?= htmlspecialchars($home) ?>">Back to home</a>
    <?php if (!empty($debugDetail)): ?><pre><?= htmlspecialchars($debugDetail) ?></pre><?php endif; ?>
  </div>
</body>
</html>
