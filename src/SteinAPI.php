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
        // TLS-Zertifikat wird geprueft (curl-Default). Vorher stand hier
        // CURLOPT_SSL_VERIFYPEER=false, womit der API-Key per MITM abgreifbar war.
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        if ($method === 'PATCH') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }
        
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new Exception("Stein API request failed: $curlError");
        }

        if ($httpCode !== 200) {
            // Antwort kuerzen: sie landet im sync_log und im Dashboard
            $snippet = mb_substr((string)$response, 0, 200);
            throw new Exception("Stein API request failed with code $httpCode: $snippet");
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
