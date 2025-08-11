<?php
// ===================================
// config/config.php - Konfigurationsdatei
// ===================================

return [
    'database' => [
        'host' => 'localhost',
        'name' => 'divera_stein_sync',
        'user' => 'your_db_user',
        'pass' => 'your_db_password'
    ],
    'divera' => [
        'accesskey' => 'your_divera_access_key'
    ],
    'stein' => [
        'buname' => 'your_business_unit_id', // Integer
        'apikey' => 'your_stein_api_key'
    ],
    'sync' => [
        'auto_sync_interval' => 300, // Sekunden (5 Minuten)
        'log_retention_days' => 30,  // Tage
        'max_retries' => 3
    ]
];
?>