<?php
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../includes/data.php';
require __DIR__ . '/../controllers/ApplicantController.php';

(new ApplicantController($pdo))->handle();
