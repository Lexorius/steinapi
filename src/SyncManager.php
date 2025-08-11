<?php
// src/SyncManager.php
class SyncManager {
    private $db;
    private $steinApi;
    private $diveraApi;
    private $syncConfig;
    
    public function __construct($steinApi, $diveraApi) {
        $this->db = Database::getInstance()->getConnection();
        $this->steinApi = $steinApi;
        $this->diveraApi = $diveraApi;
        $this->loadSyncConfig();
        
        // Set timezone to Europe/Berlin
        date_default_timezone_set('Europe/Berlin');
        
        // Also set MySQL timezone
        try {
            $this->db->exec("SET time_zone = '+01:00'"); // CET
            // For DST aware: $this->db->exec("SET time_zone = 'Europe/Berlin'");
        } catch (Exception $e) {
            // If timezone tables are not loaded, use offset
            error_log("Could not set MySQL timezone: " . $e->getMessage());
        }
    }
    
    private function loadSyncConfig() {
        $stmt = $this->db->query("SELECT * FROM sync_config WHERE active = 1");
        $this->syncConfig = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function sync($direction = 'both') {
        $results = [];
        
        // Use Europe/Berlin timezone
        $timezone = new DateTimeZone('Europe/Berlin');
        $syncStartTime = new DateTime('now', $timezone);
        $syncStartTimeString = $syncStartTime->format('Y-m-d H:i:s');
        
        // Get data from both systems
        $diveraData = $this->diveraApi->getVehicleStatus();
        $steinData = $this->steinApi->getAssets();
        
        // Create lookup arrays
        $diveraAssets = [];
        foreach ($diveraData as $asset) {
            if (!empty($asset['number'])) {
                $diveraAssets[$asset['number']] = $asset;
            }
        }
        
        $steinAssets = [];
        foreach ($steinData as $asset) {
            if (in_array($asset['groupId'], [1, 5])) {
                $steinAssets[$asset['name']] = $asset;
            }
        }
        
        // Sync logic
        foreach ($steinAssets as $name => $steinAsset) {
            if (!isset($diveraAssets[$name])) {
                continue;
            }
            
            $diveraAsset = $diveraAssets[$name];
            $steinComment = $steinAsset['comment'] ?? '';
            
            $needsSync = false;
            if ($diveraAsset['fmsstatus'] != DiveraAPI::FMS_STEIN_MAP[$steinAsset['status']]) {
                $needsSync = true;
            }
            if ($diveraAsset['fmsstatus_note'] != $steinComment) {
                $needsSync = true;
            }
            
            if (!$needsSync) {
                continue;
            }
            
            $syncResult = $this->performSync(
                $diveraAsset, 
                $steinAsset, 
                $direction
            );
            
            if ($syncResult) {
                $results[] = $syncResult;
                $this->logSync($syncResult);
            }
        }
        
        // Update system_status with last sync time
        $this->updateSystemStatus($syncStartTimeString, count($results));
        
        return $results;
    }
    
    private function performSync($diveraAsset, $steinAsset, $direction) {
        $result = [
            'vehicle' => $diveraAsset['name'],
            'divera_id' => $diveraAsset['id'],
            'stein_id' => $steinAsset['id'],
            'action' => null,
            'success' => false,
            'fields_synced' => []
        ];
        
        $diveraTs = $diveraAsset['fmsstatus_ts'];
        
        // Convert Stein timestamp to Europe/Berlin timezone
        $steinDateTime = new DateTime($steinAsset['lastModified'], new DateTimeZone('Europe/Berlin'));
        $steinTs = $steinDateTime->getTimestamp();
        
        $syncToDivera = false;
        $syncToStein = false;
        
        if ($direction === 'both') {
            if ($steinTs > $diveraTs) {
                $syncToDivera = true;
            } else {
                $syncToStein = true;
            }
        } elseif ($direction === 'divera') {
            $syncToDivera = true;
        } elseif ($direction === 'stein') {
            $syncToStein = true;
        }
        
        try {
            if ($syncToDivera && $this->shouldSyncField('status')) {
                $this->diveraApi->setVehicleStatus($diveraAsset['id'], $steinAsset);
                $result['action'] = 'stein_to_divera';
                $result['success'] = true;
                $result['fields_synced'] = $this->getActiveSyncFields();
            } elseif ($syncToStein) {
                $payload = [
                    'status' => DiveraAPI::FMS_STEIN_MAP[$diveraAsset['fmsstatus']],
                    'comment' => $diveraAsset['fmsstatus_note']
                ];
                $this->steinApi->updateAsset($steinAsset['id'], $payload);
                $result['action'] = 'divera_to_stein';
                $result['success'] = true;
                $result['fields_synced'] = ['status', 'comment'];
            }
        } catch (Exception $e) {
            $result['error'] = $e->getMessage();
        }
        
        return $result;
    }
    
    private function shouldSyncField($field) {
        foreach ($this->syncConfig as $config) {
            if ($config['field_name'] === $field) {
                return $config['active'] == 1;
            }
        }
        return true;
    }
    
    private function getActiveSyncFields() {
        $fields = [];
        foreach ($this->syncConfig as $config) {
            if ($config['active'] == 1) {
                $fields[] = $config['field_name'];
            }
        }
        return empty($fields) ? ['status', 'comment'] : $fields;
    }
    
    private function logSync($result) {
        $stmt = $this->db->prepare("
            INSERT INTO sync_log (
                vehicle_name, divera_id, stein_id, 
                sync_direction, fields_synced, success, 
                error_message, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $result['vehicle'],
            $result['divera_id'],
            $result['stein_id'],
            $result['action'],
            json_encode($result['fields_synced']),
            $result['success'] ? 1 : 0,
            $result['error'] ?? null
        ]);
    }
    
    private function updateSystemStatus($syncTime, $syncedCount) {
        try {
            // Check if system_status entry exists
            $stmt = $this->db->query("SELECT COUNT(*) FROM system_status");
            $exists = $stmt->fetchColumn() > 0;
            
            if ($exists) {
                // Update existing entry
                $stmt = $this->db->prepare("
                    UPDATE system_status 
                    SET last_sync = ?, 
                        last_sync_count = ?,
                        total_syncs = total_syncs + 1,
                        updated_at = NOW() 
                    WHERE id = 1
                ");
                $stmt->execute([$syncTime, $syncedCount]);
            } else {
                // Create new entry
                $stmt = $this->db->prepare("
                    INSERT INTO system_status 
                    (last_sync, last_sync_count, total_syncs, auto_sync_enabled, sync_interval) 
                    VALUES (?, ?, 1, 0, 300)
                ");
                $stmt->execute([$syncTime, $syncedCount]);
            }
        } catch (Exception $e) {
            error_log("Failed to update system status: " . $e->getMessage());
        }
    }
    
    public function getSystemStatus() {
        try {
            $stmt = $this->db->query("
                SELECT 
                    last_sync,
                    last_sync_count,
                    total_syncs,
                    auto_sync_enabled,
                    sync_interval,
                    updated_at
                FROM system_status 
                WHERE id = 1
            ");
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return null;
        }
    }
    
    public function setAutoSync($enabled, $interval = 300) {
        try {
            $stmt = $this->db->prepare("
                UPDATE system_status 
                SET auto_sync_enabled = ?, 
                    sync_interval = ?,
                    updated_at = NOW() 
                WHERE id = 1
            ");
            $stmt->execute([$enabled ? 1 : 0, $interval]);
            return true;
        } catch (Exception $e) {
            error_log("Failed to update auto sync settings: " . $e->getMessage());
            return false;
        }
    }
    
    public function getSyncStats() {
        $stmt = $this->db->query("
            SELECT 
                COUNT(*) as total_syncs,
                SUM(success) as successful_syncs,
                COUNT(DISTINCT vehicle_name) as vehicles_synced,
                MAX(created_at) as last_sync
            FROM sync_log
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Also get system status for more complete stats
        $systemStatus = $this->getSystemStatus();
        if ($systemStatus) {
            $stats['system_last_sync'] = $systemStatus['last_sync'];
            $stats['last_sync_count'] = $systemStatus['last_sync_count'];
            $stats['total_syncs_all_time'] = $systemStatus['total_syncs'];
            $stats['auto_sync_enabled'] = $systemStatus['auto_sync_enabled'];
            $stats['sync_interval'] = $systemStatus['sync_interval'];
        }
        
        return $stats;
    }
    
    public function getRecentSyncs($limit = 50) {
        $stmt = $this->db->prepare("
            SELECT * FROM sync_log 
            ORDER BY created_at DESC 
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getVehicleHistory($vehicleName, $days = 7) {
        $stmt = $this->db->prepare("
            SELECT 
                created_at,
                sync_direction,
                fields_synced,
                success,
                error_message
            FROM sync_log 
            WHERE vehicle_name = ?
                AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            ORDER BY created_at DESC
        ");
        $stmt->execute([$vehicleName, $days]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getSyncConflicts() {
        // Find vehicles that had sync issues in the last 24 hours
        $stmt = $this->db->query("
            SELECT 
                vehicle_name,
                COUNT(*) as failure_count,
                MAX(error_message) as last_error,
                MAX(created_at) as last_attempt
            FROM sync_log 
            WHERE success = 0
                AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            GROUP BY vehicle_name
            ORDER BY failure_count DESC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function manualSyncVehicle($vehicleName, $direction = 'both') {
        // Get data from both systems
        $diveraData = $this->diveraApi->getVehicleStatus();
        $steinData = $this->steinApi->getAssets();
        
        $diveraAsset = null;
        $steinAsset = null;
        
        // Find the specific vehicle
        foreach ($diveraData as $asset) {
            if ($asset['number'] === $vehicleName || $asset['name'] === $vehicleName) {
                $diveraAsset = $asset;
                break;
            }
        }
        
        foreach ($steinData as $asset) {
            if ($asset['name'] === $vehicleName) {
                $steinAsset = $asset;
                break;
            }
        }
        
        if (!$diveraAsset || !$steinAsset) {
            throw new Exception("Vehicle not found in both systems: " . $vehicleName);
        }
        
        // Perform sync for this specific vehicle
        $result = $this->performSync($diveraAsset, $steinAsset, $direction);
        
        if ($result) {
            $this->logSync($result);
            
            // Use Europe/Berlin timezone for update
            $timezone = new DateTimeZone('Europe/Berlin');
            $syncTime = new DateTime('now', $timezone);
            $this->updateSystemStatus($syncTime->format('Y-m-d H:i:s'), 1);
        }
        
        return $result;
    }
    
    public function cleanOldLogs($days = 30) {
        try {
            $stmt = $this->db->prepare("
                DELETE FROM sync_log 
                WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            ");
            $stmt->execute([$days]);
            
            return $stmt->rowCount();
        } catch (Exception $e) {
            error_log("Failed to clean old logs: " . $e->getMessage());
            return 0;
        }
    }
}