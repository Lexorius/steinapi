<?php
// src/SteinAPI.php
class SteinAPI {
    private $baseUrl = "https://stein.app/api/api/ext";
    private $buId; //
    private $apiKey;
    private $lastRequestTime = 0; 
    private $assets = []; 
    
    public function __construct($buId, $apiKey) {
        $this->buId = $buId;
        $this->apiKey = $apiKey;
    }
    
    private function rateLimit() {
        $currentTime = microtime(true);
        $elapsedTime = $currentTime - $this->lastRequestTime;
        if ($elapsedTime < 3) {
            usleep((3 - $elapsedTime) * 1000000);
        }
        $this->lastRequestTime = microtime(true);
    }
    
    private function makeRequest($method, $endpoint, $data = null) {
        $this->rateLimit();
        
        $url = $this->baseUrl . $endpoint;
        $ch = curl_init($url);
        
        $headers = [
            'User-Agent: Mozilla/5.0',
            'Accept: application/json',
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json'
        ];
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        if ($method === 'PATCH') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception("API request failed with code $httpCode: $response");
        }
        
        return json_decode($response, true);
    }
    
    public function getAssets() {
        if (empty($this->assets)) {
            $this->assets = $this->makeRequest('GET', "/assets/?buIds={$this->buId}");
        }
        return $this->assets;
    }
    
    public function updateAsset($assetId, $updateData, $notify = false) {
        $assets = $this->getAssets();
        $assetData = null;
        
        foreach ($assets as $asset) {
            if ($asset['id'] == $assetId) {
                $assetData = $asset;
                break;
            }
        }
        
        if (!$assetData) {
            return false;
        }
        
        unset($updateData['id']);
        $assetData = array_merge($assetData, $updateData);
        
        $endpoint = "/assets/{$assetId}?notifyRadio=" . ($notify ? 'true' : 'false');
        
        try {
            $this->makeRequest('PATCH', $endpoint, $assetData);
            return true;
        } catch (Exception $e) {
            error_log("Failed to update asset $assetId: " . $e->getMessage());
            return false;
        }
    }
}
?>
