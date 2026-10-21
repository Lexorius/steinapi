<?php
// api.php - API Endpoint für das Dashboard
require_once 'config/Database.php';
require_once 'src/SteinAPI.php';
require_once 'src/DiveraAPI.php';
require_once 'src/SyncManager.php';

// Anwendungsversion. Wird ueber action=health ausgeliefert, damit z. B. die
// Home-Assistant-Integration ein Update erkennen kann. Bei jedem Release
// anheben und einen Eintrag in CHANGELOG.md ergaenzen.
const APP_VERSION = '1.1.0';

const ALLOWED_SYNC_DIRECTIONS = ['both', 'divera', 'stein'];
const ALLOWED_SYNC_FIELDS = ['status', 'comment', 'position', 'crew'];
const MIN_SYNC_INTERVAL = 60;
const MAX_SYNC_INTERVAL = 3600;
const MAX_LOG_LIMIT = 200;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
// Bewusst KEIN Access-Control-Allow-Origin: Dashboard und API liegen auf demselben
// Origin. Ohne CORS-Freigabe scheitern Cross-Site-JSON-POSTs (CSRF) am Preflight.

$config = require 'config/config.php';

/**
 * HTTP-Basic-Auth fuer alle Endpoints.
 * Aktiv, sobald in config['auth']['password'] ein Passwort steht. Unter mod_php
 * liefert PHP die Zugangsdaten in PHP_AUTH_*, bei CGI/FPM wird der
 * Authorization-Header ausgewertet (siehe RewriteRule in .htaccess).
 */
function requireAuth(array $auth): void
{
    $expectedUser = (string)($auth['user'] ?? '');
    $expectedPass = (string)($auth['password'] ?? '');

    if ($expectedPass === '') {
        // Kein Passwort konfiguriert: API offen. Nur in abgeschotteten Netzen vertretbar.
        return;
    }

    $user = $_SERVER['PHP_AUTH_USER'] ?? null;
    $pass = $_SERVER['PHP_AUTH_PW'] ?? '';

    if ($user === null) {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (stripos($header, 'basic ') === 0) {
            $decoded = base64_decode(substr($header, 6), true);
            if ($decoded !== false && str_contains($decoded, ':')) {
                [$user, $pass] = explode(':', $decoded, 2);
            }
        }
    }

    // Beide Vergleiche immer ausfuehren (kein Kurzschluss), damit das Timing
    // nicht verraet, ob der Benutzername stimmt.
    $userOk = $user !== null && hash_equals($expectedUser, (string)$user);
    $passOk = hash_equals($expectedPass, (string)$pass);

    if (!$userOk || !$passOk) {
        header('WWW-Authenticate: Basic realm="Divera-Stein Sync", charset="UTF-8"');
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Authentication required']);
        exit;
    }
}

/**
 * Liest und validiert den JSON-Body eines POST-Requests.
 * Erzwingt Content-Type application/json: einfache Cross-Site-Formular-POSTs
 * (CSRF ueber <form>) kommen damit nicht durch.
 */
function readJsonBody(): array
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new InvalidArgumentException('Method not allowed', 405);
    }

    $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== 0) {
        throw new InvalidArgumentException('Content-Type must be application/json', 415);
    }

    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) {
        throw new InvalidArgumentException('Invalid JSON body', 400);
    }

    return $input;
}

function createSteinApi(array $config): SteinAPI
{
    return new SteinAPI($config['stein']['buname'], $config['stein']['apikey']);
}

function createDiveraApi(array $config): DiveraAPI
{
    return new DiveraAPI($config['divera']['accesskey']);
}

function createSyncManager(array $config): SyncManager
{
    return new SyncManager(createSteinApi($config), createDiveraApi($config));
}

requireAuth($config['auth'] ?? []);

$action = (string)($_GET['action'] ?? '');

try {
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
            $limit = max(1, min(MAX_LOG_LIMIT, (int)($_GET['limit'] ?? 50)));
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("
                SELECT * FROM sync_log
                ORDER BY created_at DESC
                LIMIT :limit
            ");
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            break;

        case 'sync':
            $input = readJsonBody();
            $direction = $input['direction'] ?? 'both';
            if (!in_array($direction, ALLOWED_SYNC_DIRECTIONS, true)) {
                throw new InvalidArgumentException('Invalid sync direction', 400);
            }

            $syncManager = createSyncManager($config);
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
            $input = readJsonBody();
            $field = $input['field'] ?? '';
            if (!in_array($field, ALLOWED_SYNC_FIELDS, true)) {
                throw new InvalidArgumentException('Unknown field', 400);
            }
            $active = !empty($input['active']) ? 1 : 0;

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
            $input = readJsonBody();
            $enabled = filter_var($input['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $interval = filter_var(
                $input['interval'] ?? 300,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => MIN_SYNC_INTERVAL, 'max_range' => MAX_SYNC_INTERVAL]]
            );
            if ($interval === false) {
                throw new InvalidArgumentException(
                    'Interval must be between ' . MIN_SYNC_INTERVAL . ' and ' . MAX_SYNC_INTERVAL . ' seconds',
                    400
                );
            }

            $syncManager = createSyncManager($config);
            $result = $syncManager->setAutoSync($enabled, $interval);
            echo json_encode(['success' => $result]);
            break;

        case 'systemStatus':
            $syncManager = createSyncManager($config);
            $status = $syncManager->getSystemStatus();
            echo json_encode($status ?: ['error' => 'No system status found']);
            break;

        case 'vehicles':
            // Get list of all vehicles from both systems
            $steinApi = createSteinApi($config);
            $diveraApi = createDiveraApi($config);

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
                'version' => APP_VERSION
            ];

            echo json_encode($health);
            break;

        default:
            throw new InvalidArgumentException('Invalid action', 400);
    }

} catch (InvalidArgumentException $e) {
    // Validierungsfehler: Meldung darf an den Client
    $code = $e->getCode();
    http_response_code(($code >= 400 && $code < 600) ? $code : 400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
} catch (Throwable $e) {
    // Alles andere (PDO, Upstream-APIs, Programmierfehler): Details nur ins
    // Server-Log. Upstream-Fehler von Stein/Divera sind fuer den Betreiber
    // nuetzlich und bereits auf 200 Zeichen gekuerzt, die duerfen durch.
    error_log('api.php [' . $action . '] ' . get_class($e) . ': ' . $e->getMessage());

    $isUpstream = preg_match('/^(Stein|Divera) API /', $e->getMessage()) === 1;
    http_response_code($isUpstream ? 502 : 500);
    echo json_encode([
        'success' => false,
        'error' => $isUpstream ? $e->getMessage() : 'Internal server error',
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}
