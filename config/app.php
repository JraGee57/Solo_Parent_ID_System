<?php
/**
 * Environment-specific settings. The defaults suit a stock local XAMPP
 * install. Each setting is taken, in order, from a server environment
 * variable of the same name (e.g. SetEnv in Apache, or the shell for cron),
 * then config/local.php (copy local.example.php; git-ignored so real
 * credentials never reach version control), then the default below.
 */
$localConfig = is_file(__DIR__ . '/local.php') ? (require __DIR__ . '/local.php') : [];
$env = function (string $key, $default) use ($localConfig) {
    $fromServer = getenv($key);
    return $fromServer !== false && $fromServer !== '' ? $fromServer : ($localConfig[$key] ?? $default);
};

// Debug mode shows PHP errors and exception details in the browser.
// Keep it off anywhere real applicant data is served.
define('APP_ENV', (string) $env('APP_ENV', 'local'));
define('APP_DEBUG', (bool) $env('APP_DEBUG', false));

define('DB_HOST', (string) $env('DB_HOST', 'localhost'));
define('DB_NAME', (string) $env('DB_NAME', 'virac_solo_parent'));
define('DB_USER', (string) $env('DB_USER', 'root'));
define('DB_PASS', (string) $env('DB_PASS', ''));

define('LOG_PATH', APP_ROOT . '/storage/logs');

// SMS gateway (services/SMSService.php). SMS_PROVIDER:
//   'test'      -- test mode (default): every message is logged in sms_logs, flagged as not delivered; nothing is sent.
//   'semaphore' -- Semaphore PH (https://semaphore.co): needs SMS_API_KEY; SMS_SENDER_NAME must be approved by Semaphore.
// SMS_API_URL can point at the provider's sandbox or a local mock gateway for testing.
define('SMS_PROVIDER', strtolower((string) $env('SMS_PROVIDER', 'test')));
define('SMS_API_KEY', (string) $env('SMS_API_KEY', ''));
define('SMS_API_URL', (string) $env('SMS_API_URL', 'https://api.semaphore.co/api/v4/messages'));
define('SMS_SENDER_NAME', (string) $env('SMS_SENDER_NAME', 'VIRACMSWD'));
define('SMS_TIMEOUT', max(2, (int) $env('SMS_TIMEOUT', 10)));          // seconds per gateway call
define('SMS_MAX_ATTEMPTS', max(1, (int) $env('SMS_MAX_ATTEMPTS', 3)));  // a failed SMS can be retried up to this many attempts in total

unset($localConfig, $env);
