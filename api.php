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
            
            // Get sync_log stats
            $stmt = $db->query("
                SELECT 
                    COUNT(*) as total_syncs,
                    SUM(success) as successful_syncs,
                    COUNT(DISTINCT vehicle_name) as vehicles_synced,
                    MAX(created_at) as last_sync
                FROM sync_log
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Get system_status info
            $stmt = $db->query("
                SELECT 
                    last_sync as system_last_sync,
                    last_sync_count,
                    total_syncs as total_syncs_all_time,
                    auto_sync_enabled,
                    sync_interval
                FROM system_status 
                WHERE id = 1
            ");
            $systemStatus = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($systemStatus) {
                $stats = array_merge($stats, $systemStatus);
            }
            
            echo json_encode($stats);
            break;
            
        case 'logs':
            $limit = intval($_GET['limit'] ?? 50);
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("
                SELECT * FROM sync_log 
                ORDER BY created_at DESC 
                LIMIT 20
            ");
            $stmt->execute();
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
            
        case 'updateAutoSync':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Method not allowed');
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $enabled = $input['enabled'] ?? false;
            $interval = $input['interval'] ?? 300;
            
            $steinApi = new SteinAPI(
                $config['stein']['buname'],
                $config['stein']['apikey']
            );
            $diveraApi = new DiveraAPI($config['divera']['accesskey']);
            $syncManager = new SyncManager($steinApi, $diveraApi);
            
            $result = $syncManager->setAutoSync($enabled, $interval);
            echo json_encode(['success' => $result]);
            break;
            
        case 'systemStatus':
            $steinApi = new SteinAPI(
                $config['stein']['buname'],
                $config['stein']['apikey']
            );
            $diveraApi = new DiveraAPI($config['divera']['accesskey']);
            $syncManager = new SyncManager($steinApi, $diveraApi);
            
            $status = $syncManager->getSystemStatus();
            echo json_encode($status ?: ['error' => 'No system status found']);
            break;
            
        case 'vehicles':
            // Get list of all vehicles from both systems
            $steinApi = new SteinAPI(
                $config['stein']['buname'],
                $config['stein']['apikey']
            );
            $diveraApi = new DiveraAPI($config['divera']['accesskey']);
            
            $diveraData = $diveraApi->getVehicleStatus();
            $steinData = $steinApi->getAssets();
            
            $vehicles = [];
            
            // Process Stein assets
            foreach ($steinData as $asset) {
                if (in_array($asset['groupId'], [1, 5])) {
                    $vehicles[$asset['name']] = [
                        'name' => $asset['name'],
                        'stein_status' => $asset['status'],
                        'stein_comment' => $asset['comment'] ?? '',
                        'stein_id' => $asset['id'],
                        'divera_status' => null,
                        'divera_comment' => null,
                        'divera_id' => null
                    ];
                }
            }
            
            // Match with Divera data
            foreach ($diveraData as $asset) {
                if (!empty($asset['number']) && isset($vehicles[$asset['number']])) {
                    $vehicles[$asset['number']]['divera_status'] = $asset['fmsstatus'];
                    $vehicles[$asset['number']]['divera_comment'] = $asset['fmsstatus_note'];
                    $vehicles[$asset['number']]['divera_id'] = $asset['id'];
                }
            }
            
            echo json_encode(array_values($vehicles));
            break;
            
        case 'health':
            // Health check endpoint
            $db = Database::getInstance()->getConnection();
            
            // Check database connection
            try {
                $db->query("SELECT 1");
                $dbStatus = 'ok';
            } catch (Exception $e) {
                $dbStatus = 'error';
            }
            
            // Check last sync time
            $stmt = $db->query("SELECT last_sync FROM system_status WHERE id = 1");
            $lastSync = $stmt->fetchColumn();
            
            $health = [
                'status' => 'ok',
                'database' => $dbStatus,
                'last_sync' => $lastSync,
                'timestamp' => date('Y-m-d H:i:s'),
                'version' => '1.0.0'
            ];
            
            echo json_encode($health);
            break;
            
        default:
            throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}