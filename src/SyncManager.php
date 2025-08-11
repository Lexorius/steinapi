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
    }
    
    private function loadSyncConfig() {
        $stmt = $this->db->query("SELECT * FROM sync_config WHERE active = 1");
        $this->syncConfig = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function sync($direction = 'both') {
        $results = [];
        
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
        $steinTs = strtotime($steinAsset['lastModified']);
        
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
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
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
}
?>