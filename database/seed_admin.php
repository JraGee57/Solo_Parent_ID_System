<?php
/**
 * One-time helper to create the first MSWDO staff/admin account.
 * Run once from a browser (http://localhost/virac-soloparent/database/seed_admin.php)
 * or from the CLI (php seed_admin.php), then delete this file.
 */
require __DIR__ . '/../config/database.php';

$username = 'admin';
$password = 'Admin@12345';
$fullName = 'MSWDO Virac Administrator';
$email    = 'mswdo.virac@example.gov.ph';

$stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
$stmt->execute([$username]);
if ($stmt->fetch()) {
    echo "An account with username '$username' already exists. Nothing to do.\n";
    exit;
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$stmt = $pdo->prepare('INSERT INTO users (full_name, email, username, password_hash, role) VALUES (?, ?, ?, ?, ?)');
$stmt->execute([$fullName, $email, $username, $hash, 'admin']);

echo "Admin account created.\n";
echo "Username: $username\n";
echo "Password: $password\n";
echo "Please log in at /admin/login.php and change this password, then delete database/seed_admin.php.\n";
