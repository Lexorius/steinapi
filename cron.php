<?php
require_once 'config/Database.php';
require_once 'src/SteinAPI.php';
require_once 'src/DiveraAPI.php';
require_once 'src/SyncManager.php';

$config = require 'config/config.php';

try {
    // Initialize APIs
    $steinApi = new SteinAPI(
        $config['stein']['buname'],
        $config['stein']['apikey']
    );
    
    $diveraApi = new DiveraAPI($config['divera']['accesskey']);
    
    // Perform sync
    $syncManager = new SyncManager($steinApi, $diveraApi);
    $results = $syncManager->sync('both');
    
    // Log to console
    echo date('Y-m-d H:i:s') . " - Sync completed. ";
    echo count($results) . " vehicles synchronized.\n";
    
    foreach ($results as $result) {
        if ($result['success']) {
            echo "✓ {$result['vehicle']}: {$result['action']}\n";
        } else {
            echo "✗ {$result['vehicle']}: {$result['error']}\n";
        }
    }
    
    // Clean old logs
    $db = Database::getInstance()->getConnection();
    $retentionDays = $config['sync']['log_retention_days'];
    $stmt = $db->prepare("
        DELETE FROM sync_log 
        WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
    ");
    $stmt->execute([$retentionDays]);
    
} catch (Exception $e) {
    error_log("Sync cron failed: " . $e->getMessage());
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>