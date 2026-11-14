<?php
// src/SyncManager.php

/**
 * Wird geworfen, wenn bereits ein anderer Sync-Lauf aktiv ist
 * (Cron, Dashboard-Timer und manueller Sync teilen sich eine Sperre).
 */
class SyncBusyException extends RuntimeException {}

/**
 * Synchronisiert Status und Kommentar zwischen Divera und Stein.
 *
 * Abgleich je Feld (Status, Kommentar) gegen den zuletzt synchronisierten
 * Stand aus der Tabelle sync_state:
 *   - nur Stein weicht vom letzten Stand ab  -> Stein  -> Divera
 *   - nur Divera weicht vom letzten Stand ab -> Divera -> Stein
 *   - beide weichen ab (echter Konflikt) oder es gibt noch keinen
 *     gespeicherten Stand -> die juengere Seite (Zeitstempel) gewinnt
 *
 * Damit ueberschreibt eine Statusaenderung auf der einen Seite nicht mehr
 * eine Kommentaraenderung auf der anderen, und die vom Sync selbst
 * ausgeloesten Zeitstempel-Aenderungen fuehren nicht mehr zu Rueck-Syncs.
 */
class SyncManager {
    const LOCK_NAME = 'divera_stein_sync';

    private $db;
    private $steinApi;
    private $diveraApi;
    private $syncConfig = [];
    private $steinTimezone;
    private $lockHeld = false;

    public function __construct($steinApi, $diveraApi, $steinTimezone = 'Europe/Berlin') {
        $this->db = Database::getInstance()->getConnection();
        $this->steinApi = $steinApi;
        $this->diveraApi = $diveraApi;
        $this->loadSyncConfig();

        // Zeitzone, in der Stein-Zeitstempel OHNE Zonenangabe gedeutet werden.
        // Zeitstempel mit "Z" oder Offset sind davon unabhaengig.
        try {
            $this->steinTimezone = new DateTimeZone($steinTimezone ?: 'Europe/Berlin');
        } catch (Exception $e) {
            error_log("Invalid Stein timezone '$steinTimezone', falling back to Europe/Berlin");
            $this->steinTimezone = new DateTimeZone('Europe/Berlin');
        }

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

    // ------------------------------------------------------------------
    // Normalisierung
    // ------------------------------------------------------------------

    /**
     * Einheitliche Vergleichsform eines Kommentars: Divera-Notizen sind
     * einzeilig, deshalb werden Zeilenumbrueche (\r\n, \r, \n) zu einem
     * Leerzeichen und der Text getrimmt. null wird zu ''.
     * Ein mehrzeiliger Stein-Kommentar gilt damit als gleich zu seiner
     * einzeiligen Divera-Fassung und wird nicht zurueckgeschrieben.
     */
    public static function normalizeComment($comment) {
        $comment = (string)($comment ?? '');
        $normalized = preg_replace('/\s*\R\s*/u', ' ', $comment);
        if ($normalized === null) {
            // Ungueltiges UTF-8: ohne /u-Modifier ersetzen
            $normalized = str_replace(["\r\n", "\r", "\n"], ' ', $comment);
        }
        return trim($normalized);
    }

    /**
     * Stein kennt "maint" und "notready", Divera fuer beides nur FMS 6.
     * Fuer den Vergleich zaehlt "maint" deshalb als "notready"; so wird ein
     * "maint" in Stein nicht durch den Sync auf "notready" gekippt.
     */
    public static function canonicalStatus($steinStatus) {
        if (!is_string($steinStatus) || $steinStatus === '') {
            return null;
        }
        return $steinStatus === 'maint' ? 'notready' : $steinStatus;
    }

    // ------------------------------------------------------------------
    // Konfiguration, Sperre, gespeicherter Stand
    // ------------------------------------------------------------------

    private function loadSyncConfig() {
        // Alle Felder laden, nicht nur aktive: nur so ist ein deaktiviertes
        // Feld von einem unbekannten (Standard: aktiv) unterscheidbar.
        $stmt = $this->db->query("SELECT field_name, active FROM sync_config");
        $this->syncConfig = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $this->syncConfig[$row['field_name']] = ((int)$row['active'] === 1);
        }
    }

    private function shouldSyncField($field) {
        return $this->syncConfig[$field] ?? true;
    }

    private function acquireLock() {
        try {
            $stmt = $this->db->query("SELECT GET_LOCK('" . self::LOCK_NAME . "', 0)");
            $acquired = (int)$stmt->fetchColumn() === 1;
        } catch (Exception $e) {
            // Sperre nicht verfuegbar: lieber ohne Sperre synchronisieren als gar nicht
            error_log("Could not acquire sync lock: " . $e->getMessage());
            return;
        }

        if (!$acquired) {
            throw new SyncBusyException('Sync already running');
        }
        $this->lockHeld = true;
    }

    private function releaseLock() {
        if (!$this->lockHeld) {
            return;
        }
        $this->lockHeld = false;
        try {
            $this->db->query("SELECT RELEASE_LOCK('" . self::LOCK_NAME . "')");
        } catch (Exception $e) {
            error_log("Could not release sync lock: " . $e->getMessage());
        }
    }

    private function fetchState() {
        $stmt = $this->db->query("SELECT vehicle_name, last_status, last_comment FROM sync_state");
        $state = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $state[$row['vehicle_name']] = $row;
        }
        return $state;
    }

    /**
     * Zuletzt synchronisierter Stand je Fahrzeug. Legt die Tabelle bei
     * Installationen vor 1.2.0 selbst an. Ist sie nicht verfuegbar, laeuft
     * der Sync mit reiner Zeitstempel-Entscheidung weiter.
     */
    private function loadState() {
        try {
            return $this->fetchState();
        } catch (Exception $e) {
            try {
                $this->db->exec("
                    CREATE TABLE IF NOT EXISTS sync_state (
                        vehicle_name VARCHAR(100) NOT NULL PRIMARY KEY,
                        last_status VARCHAR(20) NULL,
                        last_comment TEXT NULL,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB
                ");
                return $this->fetchState();
            } catch (Exception $e2) {
                error_log("sync_state not available, falling back to timestamps: " . $e2->getMessage());
                return [];
            }
        }
    }

    private function saveState($vehicleName, $status, $comment) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO sync_state (vehicle_name, last_status, last_comment)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    last_status = VALUES(last_status),
                    last_comment = VALUES(last_comment)
            ");
            $stmt->execute([$vehicleName, $status, $comment]);
        } catch (Exception $e) {
            error_log("Failed to save sync state for $vehicleName: " . $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // Synchronisation
    // ------------------------------------------------------------------

    public function sync($direction = 'both') {
        $this->acquireLock();

        try {
            $results = [];

            // Use Europe/Berlin timezone
            $timezone = new DateTimeZone('Europe/Berlin');
            $syncStartTime = new DateTime('now', $timezone);
            $syncStartTimeString = $syncStartTime->format('Y-m-d H:i:s');

            // Get data from both systems
            $diveraData = $this->diveraApi->getVehicleStatus();
            $steinData = $this->steinApi->getAssets();
            $state = $this->loadState();

            // Create lookup arrays
            $diveraAssets = [];
            foreach ($diveraData as $asset) {
                if (!empty($asset['number'])) {
                    $diveraAssets[$asset['number']] = $asset;
                }
            }

            $steinAssets = [];
            foreach ($steinData as $asset) {
                if (in_array($asset['groupId'] ?? null, [1, 5])) {
                    $steinAssets[$asset['name']] = $asset;
                }
            }

            foreach ($steinAssets as $name => $steinAsset) {
                if (!isset($diveraAssets[$name])) {
                    continue;
                }

                $syncResult = $this->syncVehicle(
                    $diveraAssets[$name],
                    $steinAsset,
                    $direction,
                    $state[$name] ?? null
                );

                if ($syncResult) {
                    $results[] = $syncResult;
                    $this->logSync($syncResult);
                }
            }

            // Update system_status with last sync time
            $this->updateSystemStatus($syncStartTimeString, count($results));

            return $results;
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Entscheidet fuer ein Feld, wohin geschrieben wird.
     * Rueckgabe: 'divera' (Stein -> Divera) oder 'stein' (Divera -> Stein).
     */
    private function decideDirection($direction, $steinValue, $diveraValue, $baseValue, $steinNewer) {
        if ($direction === 'divera') {
            return 'divera';
        }
        if ($direction === 'stein') {
            return 'stein';
        }

        if ($baseValue !== null) {
            $steinChanged = $steinValue !== $baseValue;
            $diveraChanged = $diveraValue !== $baseValue;

            if ($steinChanged && !$diveraChanged) {
                return 'divera';
            }
            if ($diveraChanged && !$steinChanged) {
                return 'stein';
            }
        }

        // Konflikt (beide geaendert) oder kein gespeicherter Stand
        return $steinNewer ? 'divera' : 'stein';
    }

    private function steinTimestamp($steinAsset) {
        if (empty($steinAsset['lastModified'])) {
            return 0;
        }
        try {
            return (new DateTime($steinAsset['lastModified'], $this->steinTimezone))->getTimestamp();
        } catch (Exception $e) {
            error_log("Could not parse Stein lastModified '{$steinAsset['lastModified']}'");
            return 0;
        }
    }

    /**
     * Gleicht ein Fahrzeug ab. Gibt null zurueck, wenn nichts zu schreiben war.
     */
    private function syncVehicle($diveraAsset, $steinAsset, $direction, $base) {
        $name = (string)$steinAsset['name'];

        $steinStatusRaw = $steinAsset['status'] ?? null;
        $steinStatus = self::canonicalStatus($steinStatusRaw);
        $diveraFms = $diveraAsset['fmsstatus'] ?? null;
        // null, wenn der Divera-Status in Stein keine Entsprechung hat (0, 5, 7, 8, 9)
        $diveraStatus = DiveraAPI::fmsToStein($diveraFms);

        $steinComment = self::normalizeComment($steinAsset['comment'] ?? '');
        $diveraComment = self::normalizeComment($diveraAsset['fmsstatus_note'] ?? '');

        $steinNewer = $this->steinTimestamp($steinAsset) > (int)($diveraAsset['fmsstatus_ts'] ?? 0);

        $baseStatus = $base['last_status'] ?? null;
        $baseComment = $base['last_comment'] ?? null;
        $newBaseStatus = $baseStatus;
        $newBaseComment = $baseComment;

        // --- Status ---
        $statusDir = null;
        $statusSyncable = $this->shouldSyncField('status')
            && $steinStatus !== null
            && $diveraStatus !== null
            && DiveraAPI::steinToFms($steinStatusRaw) !== null;

        if ($statusSyncable) {
            if ($steinStatus === $diveraStatus) {
                $newBaseStatus = $steinStatus;
            } else {
                $statusDir = $this->decideDirection($direction, $steinStatus, $diveraStatus, $baseStatus, $steinNewer);
            }
        }

        // --- Kommentar ---
        $commentDir = null;
        if ($this->shouldSyncField('comment')) {
            if ($steinComment === $diveraComment) {
                $newBaseComment = $steinComment;
            } else {
                $commentDir = $this->decideDirection($direction, $steinComment, $diveraComment, $baseComment, $steinNewer);
            }
        }

        $toDivera = [];
        $toStein = [];
        if ($statusDir === 'divera') { $toDivera[] = 'status'; }
        if ($statusDir === 'stein') { $toStein[] = 'status'; }
        if ($commentDir === 'divera') { $toDivera[] = 'comment'; }
        if ($commentDir === 'stein') { $toStein[] = 'comment'; }

        // Divera nimmt eine Notiz nur zusammen mit einem Status an. Steht das
        // Fahrzeug dort auf einem Status ohne Stein-Entsprechung (z. B. 5 =
        // Sprechwunsch), wird dieser nicht erneut gesendet, um keine
        // Alarmierung auszuloesen. Der Kommentar folgt, sobald der Status
        // wieder zuordenbar ist.
        if ($toDivera === ['comment'] && $diveraStatus === null) {
            $toDivera = [];
        }

        $synced = [];
        $errors = [];

        // --- Stein -> Divera ---
        if ($toDivera) {
            $fms = in_array('status', $toDivera, true)
                ? DiveraAPI::steinToFms($steinStatusRaw)
                : $diveraFms; // Status unveraendert lassen (FMS 4 bleibt 4)
            $note = in_array('comment', $toDivera, true) ? $steinComment : $diveraComment;

            try {
                $this->diveraApi->setVehicleStatus($diveraAsset['id'], $fms, $note);
                $synced = array_merge($synced, $toDivera);
                if (in_array('status', $toDivera, true)) { $newBaseStatus = $steinStatus; }
                if (in_array('comment', $toDivera, true)) { $newBaseComment = $steinComment; }
            } catch (Exception $e) {
                $errors[] = 'Divera: ' . $e->getMessage();
            }
        }

        // --- Divera -> Stein ---
        if ($toStein) {
            $payload = [];
            if (in_array('status', $toStein, true)) { $payload['status'] = $diveraStatus; }
            if (in_array('comment', $toStein, true)) { $payload['comment'] = $diveraComment; }

            if ($this->steinApi->updateAsset($steinAsset['id'], $payload)) {
                $synced = array_merge($synced, $toStein);
                if (in_array('status', $toStein, true)) { $newBaseStatus = $diveraStatus; }
                if (in_array('comment', $toStein, true)) { $newBaseComment = $diveraComment; }
            } else {
                $detail = method_exists($this->steinApi, 'getLastError') ? $this->steinApi->getLastError() : null;
                $errors[] = 'Stein: ' . ($detail ?: 'Update fehlgeschlagen');
            }
        }

        // Gespeicherten Stand nur fuer Felder fortschreiben, die jetzt
        // nachweislich auf beiden Seiten gleich sind.
        if ($newBaseStatus !== $baseStatus || $newBaseComment !== $baseComment || $base === null) {
            $this->saveState($name, $newBaseStatus, $newBaseComment);
        }

        if (!$toDivera && !$toStein) {
            return null;
        }

        if ($toDivera && $toStein) {
            $action = 'both';
        } else {
            $action = $toDivera ? 'stein_to_divera' : 'divera_to_stein';
        }

        $result = [
            'vehicle' => $diveraAsset['name'] ?? $name,
            'divera_id' => $diveraAsset['id'],
            'stein_id' => $steinAsset['id'],
            'action' => $action,
            'success' => empty($errors),
            'fields_synced' => $synced
        ];
        if ($errors) {
            $result['error'] = implode('; ', $errors);
        }

        return $result;
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
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->execute();
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
        $this->acquireLock();

        try {
            // Get data from both systems
            $diveraData = $this->diveraApi->getVehicleStatus();
            $steinData = $this->steinApi->getAssets();

            $diveraAsset = null;
            $steinAsset = null;

            // Find the specific vehicle
            foreach ($diveraData as $asset) {
                if (($asset['number'] ?? null) === $vehicleName || ($asset['name'] ?? null) === $vehicleName) {
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

            $state = $this->loadState();

            // Perform sync for this specific vehicle
            $result = $this->syncVehicle(
                $diveraAsset,
                $steinAsset,
                $direction,
                $state[$steinAsset['name']] ?? null
            );

            if ($result) {
                $this->logSync($result);

                // Use Europe/Berlin timezone for update
                $timezone = new DateTimeZone('Europe/Berlin');
                $syncTime = new DateTime('now', $timezone);
                $this->updateSystemStatus($syncTime->format('Y-m-d H:i:s'), 1);
            }

            return $result;
        } finally {
            $this->releaseLock();
        }
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
