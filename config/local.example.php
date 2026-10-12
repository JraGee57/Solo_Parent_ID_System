<?php
/**
 * Copy to config/local.php and change what differs on this machine.
 * Any key left out keeps its default from config/app.php.
 */
return [
    'APP_ENV' => 'local',
    'APP_DEBUG' => false,

    'DB_HOST' => 'localhost',
    'DB_NAME' => 'virac_solo_parent',
    'DB_USER' => 'root',
    'DB_PASS' => '',

    // SMS: 'test' logs messages without sending them; 'semaphore' sends through Semaphore PH.
    'SMS_PROVIDER' => 'test',
    'SMS_API_KEY' => '',
    'SMS_API_URL' => 'https://api.semaphore.co/api/v4/messages',
    'SMS_SENDER_NAME' => 'VIRACMSWD',
    'SMS_TIMEOUT' => 10,
    'SMS_MAX_ATTEMPTS' => 3,
];
