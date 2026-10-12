<?php
/**
 * PDO connection. Credentials come from config/app.php (override them in
 * config/local.php). Real prepared statements, exceptions on every error,
 * and utf8mb4 throughout.
 */
require_once __DIR__ . '/config.php';

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            // XAMPP's MariaDB default is non-strict, which silently turns an invalid
            // ENUM value (e.g. a bad status) into '' and truncates oversized text.
            // Strict mode makes those hard errors instead of corrupted data.
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'",
        ]
    );
} catch (PDOException $e) {
    // Logged in full, but never shown to visitors: the message can include the host and username.
    error_log('Database connection failed: ' . $e->getMessage());
    render_error_page(503, APP_DEBUG
        ? 'Database connection failed. Make sure MySQL is running and "' . DB_NAME . '" has been imported from database/schema.sql. (' . $e->getMessage() . ')'
        : null);
}
