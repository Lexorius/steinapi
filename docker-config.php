<?php
// This config file reads from environment variables for Docker deployment
return [
    'database' => [
        'host' => getenv('DB_HOST') ?: 'db',
        'name' => getenv('DB_NAME') ?: 'divera_stein_sync',
        'user' => getenv('DB_USER') ?: 'syncuser',
        'pass' => getenv('DB_PASSWORD') ?: 'syncpassword'
    ],
    'divera' => [
        'accesskey' => getenv('DIVERA_ACCESS_KEY') ?: 'your_divera_access_key'
    ],
    'stein' => [
        'buname' => intval(getenv('STEIN_BU_ID') ?: 12345),
        'apikey' => getenv('STEIN_API_KEY') ?: 'your_stein_api_key'
    ],
    'sync' => [
        'auto_sync_interval' => 300,
        'log_retention_days' => 30,
        'max_retries' => 3
    ]
];
?>