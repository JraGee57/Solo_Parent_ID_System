<?php
/** @var PDO $pdo */
require_once __DIR__ . '/../../controllers/ApplicationController.php';

(new ApplicationController($pdo))->publicStatus();
