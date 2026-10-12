<?php
/**
 * Thin delegation layer: the real logic now lives in services/AuthService.php
 * and models/User.php. These functions keep their exact original
 * signatures/behavior so the ~50 existing pages across admin/, staff/,
 * applicant/, and shared pages that already call them keep working
 * completely unchanged. New code should use AuthService directly instead
 * of these functions.
 */
require_once __DIR__ . '/../services/AuthService.php';

function current_user(PDO $pdo): ?array
{
    return (new AuthService($pdo))->currentUser();
}

function login_user(array $user): void
{
    // No $pdo needed for a pure session write, but kept as a standalone
    // service call for a single source of truth.
    $service = new AuthService($GLOBALS['pdo']);
    $service->loginUser($user);
}

function logout_user(): void
{
    (new AuthService($GLOBALS['pdo']))->logoutUser();
}

function current_staff(PDO $pdo): ?array
{
    return (new AuthService($pdo))->currentStaff();
}

function login_staff(array $user): void
{
    (new AuthService($GLOBALS['pdo']))->loginStaff($user);
}

function logout_staff(): void
{
    (new AuthService($GLOBALS['pdo']))->logoutStaff();
}
