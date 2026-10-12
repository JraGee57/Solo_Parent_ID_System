<?php
/** @var PDO $pdo */
require_once __DIR__ . '/../../controllers/AuthController.php';

extract((new AuthController($pdo))->handleApplicantAuth());
$loginTabUrl = base_url('login.php');
$signupTabUrl = base_url('register.php');

require __DIR__ . '/../../views/auth/login.php';
