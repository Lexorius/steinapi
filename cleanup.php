<?php
// cleanup.php - Log cleanup script
require_once '/var/www/html/config/Database.php';

$config = require '/var/www/html/config/config.php';

echo "[" . date('Y-m-d H:i:s') . "] Starting cleanup process...\n";

try {
    $db = Database::getInstance()->getConnection();
    
    // Clean old sync logs
    $retentionDays = $config['sync']['log_retention_days'] ?? 30;
    $stmt = $db->prepare("
        DELETE FROM sync_log 
        WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
    ");
    $stmt->execute([$retentionDays]);
    $deletedRows = $stmt->rowCount();
    
    echo "[" . date('Y-m-d H:i:s') . "] Deleted $deletedRows old sync log entries\n";
    
    // Clean old log files
    $logDir = '/var/www/html/logs';
    $maxLogSize = 50 * 1024 * 1024; // 50MB
    
    foreach (glob($logDir . '/*.log') as $logFile) {
        if (filesize($logFile) > $maxLogSize) {
            // Rotate log file
            $backupFile = $logFile . '.' . date('Ymd_His') . '.bak';
            rename($logFile, $backupFile);
            touch($logFile);
            echo "[" . date('Y-m-d H:i:s') . "] Rotated large log file: " . basename($logFile) . "\n";
            
            // Delete old backup files (older than retention days)
            foreach (glob($logFile . '.*.bak') as $backup) {
                if (filemtime($backup) < strtotime("-$retentionDays days")) {
                    unlink($backup);
                    echo "[" . date('Y-m-d H:i:s') . "] Deleted old backup: " . basename($backup) . "\n";
                }
            }
        }
    }
    
    echo "[" . date('Y-m-d H:i:s') . "] Cleanup completed successfully\n";
    
} catch (Exception $e) {
    echo "[" . date('Y-m-d H:i:s') . "] Cleanup failed: " . $e->getMessage() . "\n";
    exit(1);
}
?>