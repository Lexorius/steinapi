<?php 
class DiveraAPI {
    private $baseUrl = "https://app.divera247.com/api/v2/";
    private $accessKey;
    
    const FMS_STEIN_MAP = [
        1 => 'semiready',
        2 => 'ready',
        3 => 'inuse',
        4 => 'inuse',
        6 => 'notready',
        'ready' => 2,
        'semiready' => 1,
        'notready' => 6,
        'inuse' => 3,
        'maint' => 6
    ];
    
    public function __construct($accessKey) {
        $this->accessKey = $accessKey;
    }
    
    private function makeRequest($method, $endpoint, $data = null) {
        $url = $this->baseUrl . $endpoint;
        if (strpos($url, '?') === false) {
            $url .= '?accesskey=' . $this->accessKey;
        } else {
            $url .= '&accesskey=' . $this->accessKey;
        }
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        if ($method === 'POST' && $data) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception("Divera API request failed with code $httpCode");
        }
        
        return json_decode($response, true);
    }
    
    public function getVehicleStatus() {
        $response = $this->makeRequest('GET', 'pull/vehicle-status');
        return $response['data'] ?? [];
    }
    
    public function setVehicleStatus($vehicleId, $data) {
        $payload = [
            'status' => self::FMS_STEIN_MAP[$data['status']],
            'status_id' => self::FMS_STEIN_MAP[$data['status']]
        ];
        
        if (!empty($data['comment'])) {
            $payload['status_note'] = str_replace("\n", " ", $data['comment']);
        }
        
        return $this->makeRequest('POST', "using-vehicles/set-status/{$vehicleId}", $payload);
    }
}
?>