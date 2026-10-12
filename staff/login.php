<?php
// Keep existing personnel login links and submitted forms working.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    require_once __DIR__ . '/../config/config.php';
    require_once __DIR__ . '/../includes/functions.php';
    header('Location: ' . base_url('mswd-login.php'));
    exit;
}
require __DIR__ . '/../mswd-login.php';
