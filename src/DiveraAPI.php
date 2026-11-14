<?php 
class DiveraAPI {
    private $baseUrl = "https://app.divera247.com/api/v2/";
    private $accessKey; 
    
    // Divera-FMS-Status -> Stein-Status. Nicht umkehrbar: 3 und 4 sind in
    // Stein beide "inuse". Status 0, 5, 7, 8, 9 haben keine Entsprechung.
    const FMS_TO_STEIN = [
        1 => 'semiready',
        2 => 'ready',
        3 => 'inuse',
        4 => 'inuse',
        6 => 'notready'
    ];

    // Stein-Status -> Divera-FMS-Status. "maint" und "notready" werden beide zu 6.
    const STEIN_TO_FMS = [
        'ready' => 2,
        'semiready' => 1,
        'notready' => 6,
        'inuse' => 3,
        'maint' => 6
    ];

    /** Stein-Status zu einem FMS-Status, oder null ohne Entsprechung. */
    public static function fmsToStein($fms) {
        if (!is_numeric($fms)) {
            return null;
        }
        return self::FMS_TO_STEIN[(int)$fms] ?? null;
    }

    /** FMS-Status zu einem Stein-Status, oder null bei unbekanntem Status. */
    public static function steinToFms($steinStatus) {
        if (!is_string($steinStatus)) {
            return null;
        }
        return self::STEIN_TO_FMS[$steinStatus] ?? null;
    }

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
        // TLS-Zertifikat wird geprueft (curl-Default). Vorher stand hier
        // CURLOPT_SSL_VERIFYPEER=false, womit der Access-Key per MITM abgreifbar war.
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        if ($method === 'POST' && $data) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        }
        
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new Exception("Divera API request failed: $curlError");
        }

        if ($httpCode !== 200) {
            throw new Exception("Divera API request failed with code $httpCode");
        }
        
        return json_decode($response, true);
    }
    
    public function getVehicleStatus() {
        $response = $this->makeRequest('GET', 'pull/vehicle-status');
        return $response['data'] ?? [];
    }
    
    /**
     * Setzt FMS-Status und Notiz eines Fahrzeugs.
     * Die Notiz wird immer mitgeschickt, auch leer: nur so wird ein in Stein
     * geloeschter Kommentar auch in Divera geloescht.
     */
    public function setVehicleStatus($vehicleId, $fmsStatus, $note = '') {
        if (!is_numeric($fmsStatus)) {
            throw new Exception("Divera API request not sent: unknown status for vehicle $vehicleId");
        }

        // Divera-Notizen sind einzeilig
        $note = trim((string)preg_replace('/\s*\R\s*/', ' ', (string)$note));

        $payload = [
            'status' => (int)$fmsStatus,
            'status_id' => (int)$fmsStatus,
            'status_note' => $note
        ];

        return $this->makeRequest('POST', "using-vehicles/set-status/{$vehicleId}", $payload);
    }
}
?>
