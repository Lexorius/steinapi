<?php
// api.php - API Endpoint für das Dashboard
require_once 'config/Database.php';
require_once 'src/SteinAPI.php';
require_once 'src/DiveraAPI.php';
require_once 'src/SyncManager.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

$config = require 'config/config.php';

try {
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        case 'stats':
            $db = Database::getInstance()->getConnection();
            $stmt = $db->query("
                SELECT 
                    COUNT(*) as total_syncs,
                    SUM(success) as successful_syncs,
                    COUNT(DISTINCT vehicle_name) as vehicles_synced,
                    MAX(created_at) as last_sync
                FROM sync_log
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");
            echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
            break;
            
        case 'logs':
            $limit = intval($_GET['limit'] ?? 50);
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("
                SELECT * FROM sync_log 
                ORDER BY created_at DESC 
                LIMIT ?
            ");
            $stmt->execute($limit);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            break;
            
        case 'sync':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Method not allowed');
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $direction = $input['direction'] ?? 'both';
            
            $steinApi = new SteinAPI(
                $config['stein']['buname'],
                $config['stein']['apikey']
            );
            
            $diveraApi = new DiveraAPI($config['divera']['accesskey']);
            
            $syncManager = new SyncManager($steinApi, $diveraApi);
            $results = $syncManager->sync($direction);
            
            echo json_encode([
                'success' => true,
                'synced_count' => count($results),
                'results' => $results
            ]);
            break;
            
        case 'fieldConfig':
            $db = Database::getInstance()->getConnection();
            $stmt = $db->query("SELECT * FROM sync_config");
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            break;
            
        case 'updateField':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Method not allowed');
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $field = $input['field'] ?? '';
            $active = $input['active'] ? 1 : 0;
            
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("
                INSERT INTO sync_config (field_name, active) 
                VALUES (?, ?) 
                ON DUPLICATE KEY UPDATE active = ?
            ");
            $stmt->execute([$field, $active, $active]);
            
            echo json_encode(['success' => true]);
            break;
            
        default:
            throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>