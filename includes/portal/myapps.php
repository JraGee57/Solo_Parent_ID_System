<?php
/** @var PDO $pdo @var ?array $user */
require_once __DIR__ . '/../../controllers/ApplicationController.php';

(new ApplicationController($pdo))->myApplications($user);
