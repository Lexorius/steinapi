<?php
// tests/sync_simulation.php
// Spielt die Synchronisationslogik ohne echte APIs und ohne Datenbank durch.
// Echter SyncManager + echte Status-Zuordnung aus DiveraAPI; Divera, Stein
// und die Datenbank sind In-Memory-Gegenstellen.
//
// Aufruf:  php tests/sync_simulation.php
// Exit-Code 0 = alle Erwartungen erfuellt, 1 = mindestens eine nicht.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

error_reporting(E_ALL);
set_error_handler(function ($no, $str) { echo "      [PHP-Warnung] $str\n"; $GLOBALS['warnings']++; return true; });
$GLOBALS['warnings'] = 0;
$GLOBALS['fail'] = 0;

$root = $argv[1] ?? dirname(__DIR__);

class FakeStmt {
    public function __construct(private string $sql = '', private array $rows = [], private $column = 1) {}
    public function execute($p = []) {
        if (str_contains($this->sql, 'INSERT INTO sync_state')) {
            FakePdo::$state[$p[0]] = ['vehicle_name' => $p[0], 'last_status' => $p[1], 'last_comment' => $p[2]];
        }
        return true;
    }
    public function bindValue(...$a) { return true; }
    public function fetchAll($m = null) { return $this->rows; }
    public function fetch($m = null) { return $this->rows[0] ?? false; }
    public function fetchColumn() { return $this->column; }
    public function rowCount() { return 0; }
}
class FakePdo {
    public static array $fields = ['status' => 1, 'comment' => 1, 'position' => 0, 'crew' => 0];
    public static array $state = [];
    public static bool $lockFree = true;
    public function exec($s) { return 0; }
    public function query($s) {
        if (str_contains($s, 'FROM sync_config')) {
            $rows = [];
            foreach (self::$fields as $f => $a) { $rows[] = ['field_name' => $f, 'active' => $a]; }
            return new FakeStmt($s, $rows);
        }
        if (str_contains($s, 'FROM sync_state')) { return new FakeStmt($s, array_values(self::$state)); }
        if (str_contains($s, 'GET_LOCK')) { return new FakeStmt($s, [], self::$lockFree ? 1 : 0); }
        return new FakeStmt($s);
    }
    public function prepare($s) { return new FakeStmt($s); }
}
class Database {
    public static function getInstance() { return new self(); }
    public function getConnection() { return new FakePdo(); }
}

require "$root/src/DiveraAPI.php";
require "$root/src/SyncManager.php";

$GLOBALS['now'] = 1794650400;

class FakeDivera extends DiveraAPI {
    public array $v;
    public int $calls = 0;
    public function __construct(array $v) { parent::__construct('x'); $this->v = $v; }
    public function getVehicleStatus() { return [$this->v]; }
    public function setVehicleStatus($vehicleId, $fmsStatus, $note = '') {
        // wie src/DiveraAPI.php: Status pruefen, Notiz einzeilig, immer mitsenden
        if (!is_numeric($fmsStatus)) { throw new Exception('Divera API request not sent: unknown status'); }
        $note = trim((string)preg_replace('/\s*\R\s*/', ' ', (string)$note));
        $this->calls++;
        $this->v['fmsstatus'] = (int)$fmsStatus;
        $this->v['fmsstatus_note'] = $note;
        $this->v['fmsstatus_ts'] = $GLOBALS['now'];
        return ['success' => true];
    }
}
class FakeStein {
    public array $a;
    public int $calls = 0;
    public bool $failNext = false;
    private $err = null;
    public function __construct(array $a) { $this->a = $a; }
    public function getAssets() { return [$this->a]; }
    public function getLastError() { return $this->err; }
    public function updateAsset($assetId, $updateData, $notify = false) {
        $this->err = null;
        if ($this->failNext) { $this->failNext = false; $this->err = 'Stein API request failed with code 500: boom'; return false; }
        $this->calls++;
        $this->a = array_merge($this->a, $updateData);
        $this->a['lastModified'] = gmdate('Y-m-d\TH:i:s\Z', $GLOBALS['now']);
        return true;
    }
}

function iso(int $ts): string { return gmdate('Y-m-d\TH:i:s\Z', $ts); }
function j($s) { return $s === null ? 'null' : json_encode($s, JSON_UNESCAPED_UNICODE); }

class Sim {
    public FakeDivera $d; public FakeStein $s;
    public function __construct(string $title, array $divera, array $stein, ?array $base = null) {
        echo "\n=== $title\n";
        FakePdo::$fields = ['status' => 1, 'comment' => 1, 'position' => 0, 'crew' => 0];
        FakePdo::$state = [];
        FakePdo::$lockFree = true;
        if ($base) { FakePdo::$state['GKW'] = ['vehicle_name' => 'GKW', 'last_status' => $base[0], 'last_comment' => $base[1]]; }
        $this->d = new FakeDivera($divera + ['id' => 11, 'name' => 'GKW', 'number' => 'GKW']);
        $this->s = new FakeStein($stein + ['id' => 22, 'name' => 'GKW', 'groupId' => 1]);
        echo "  Start  : " . $this->state() . "\n";
    }
    public function state(): string {
        return sprintf("Divera %s / %s | Stein %s / %s", j($this->d->v['fmsstatus']), j($this->d->v['fmsstatus_note']), j($this->s->a['status']), j($this->s->a['comment']));
    }
    public function run(string $dir = 'both'): ?array {
        $GLOBALS['now'] += 300;
        $m = new SyncManager($this->s, $this->d);
        try { $r = $m->sync($dir); } catch (SyncBusyException $e) { echo "  Lauf   : uebersprungen (Sperre belegt)\n"; return null; }
        if ($r) {
            $x = $r[0];
            $what = $x['action'] . ' ' . j($x['fields_synced']) . ', success=' . j($x['success']) . (isset($x['error']) ? ', error=' . j($x['error']) : '');
        } else { $what = 'nichts zu tun'; }
        echo "  Lauf   : $what\n           " . $this->state() . "\n";
        return $r[0] ?? null;
    }
    public function steinEdit(array $c): void { $GLOBALS['now'] += 10; $this->s->a = array_merge($this->s->a, $c); $this->s->a['lastModified'] = iso($GLOBALS['now']); echo "  Aenderung in Stein : " . j($c) . "\n"; }
    public function diveraEdit(array $c): void { $GLOBALS['now'] += 10; $this->d->v = array_merge($this->d->v, $c); $this->d->v['fmsstatus_ts'] = $GLOBALS['now']; echo "  Aenderung in Divera: " . j($c) . "\n"; }
    public function expect(string $what, bool $ok): void { echo "  " . ($ok ? 'OK  ' : 'FEHL') . " : $what\n"; if (!$ok) { $GLOBALS['fail']++; } }
}

$t = $GLOBALS['now'];

// --- Die acht Fehlerfaelle aus der Pruefung ---

$x = new Sim('1) Divera-Status 4, Kommentare identisch',
    ['fmsstatus' => 4, 'fmsstatus_note' => 'Einsatz Hochwasser', 'fmsstatus_ts' => $t],
    ['status' => 'inuse', 'comment' => 'Einsatz Hochwasser', 'lastModified' => iso($t - 600)]);
$x->run(); $x->run();
$x->expect('kein Schreibzugriff, Divera bleibt auf 4', $x->d->calls === 0 && $x->s->calls === 0 && $x->d->v['fmsstatus'] === 4);

$x = new Sim('2) Mehrzeiliger Kommentar in Stein (neuer)',
    ['fmsstatus' => 2, 'fmsstatus_note' => '', 'fmsstatus_ts' => $t - 600],
    ['status' => 'ready', 'comment' => "Zeile 1\r\nZeile 2", 'lastModified' => iso($t)]);
$x->run(); $x->run(); $x->run();
$x->expect('Divera einzeilig, Stein behaelt Umbruch, genau ein Schreibzugriff',
    $x->d->v['fmsstatus_note'] === 'Zeile 1 Zeile 2' && $x->s->a['comment'] === "Zeile 1\r\nZeile 2" && $x->d->calls === 1 && $x->s->calls === 0);

$x = new Sim('3) Kommentar in Stein geloescht, Divera hat noch alte Notiz',
    ['fmsstatus' => 2, 'fmsstatus_note' => 'TUEV faellig', 'fmsstatus_ts' => $t - 600],
    ['status' => 'ready', 'comment' => 'TUEV faellig', 'lastModified' => iso($t - 600)]);
$x->run(); $x->steinEdit(['comment' => '']); $x->run(); $x->run();
$x->expect('Notiz in Divera geloescht, Stein bleibt leer', $x->d->v['fmsstatus_note'] === '' && $x->s->a['comment'] === '');

$x = new Sim('4) Stein "maint", neue Notiz in Divera',
    ['fmsstatus' => 6, 'fmsstatus_note' => 'Werkstatt', 'fmsstatus_ts' => $t - 600],
    ['status' => 'maint', 'comment' => 'Werkstatt', 'lastModified' => iso($t - 600)]);
$x->run(); $x->diveraEdit(['fmsstatus_note' => 'Werkstatt bis Freitag']); $x->run(); $x->run();
$x->expect('Kommentar uebernommen, Stein bleibt "maint"', $x->s->a['status'] === 'maint' && $x->s->a['comment'] === 'Werkstatt bis Freitag');

$x = new Sim('5) Divera-Status 5 (ohne Entsprechung), neue Notiz in Divera',
    ['fmsstatus' => 3, 'fmsstatus_note' => '', 'fmsstatus_ts' => $t - 600],
    ['status' => 'inuse', 'comment' => '', 'lastModified' => iso($t - 600)]);
$x->run(); $x->diveraEdit(['fmsstatus' => 5, 'fmsstatus_note' => 'Sprechwunsch']); $r = $x->run(); $x->run();
$x->expect('Notiz kommt in Stein an, Stein-Status unveraendert, keine PHP-Warnung',
    $x->s->a['comment'] === 'Sprechwunsch' && $x->s->a['status'] === 'inuse' && $GLOBALS['warnings'] === 0);

$x = new Sim('5b) Divera-Status 5, Kommentar in Stein geaendert',
    ['fmsstatus' => 5, 'fmsstatus_note' => '', 'fmsstatus_ts' => $t - 600],
    ['status' => 'inuse', 'comment' => '', 'lastModified' => iso($t - 600)]);
$x->run(); $x->steinEdit(['comment' => 'Hinweis']); $x->run();
$x->expect('Status 5 wird nicht erneut an Divera gesendet (zurueckgestellt)', $x->d->calls === 0);
$x->diveraEdit(['fmsstatus' => 3]); $x->run();
$x->expect('sobald Divera wieder auf 3 steht, kommt der Kommentar an', $x->d->v['fmsstatus_note'] === 'Hinweis' && $x->d->v['fmsstatus'] === 3);

$x = new Sim('6) Kommentar "007" in Stein (neuer), "7" in Divera',
    ['fmsstatus' => 2, 'fmsstatus_note' => '7', 'fmsstatus_ts' => $t - 600],
    ['status' => 'ready', 'comment' => '007', 'lastModified' => iso($t)]);
$x->run();
$x->expect('Unterschied erkannt und uebertragen', $x->d->v['fmsstatus_note'] === '007');

$x = new Sim('7) Feld "comment" deaktiviert, Kommentar in Stein geaendert',
    ['fmsstatus' => 2, 'fmsstatus_note' => 'alt', 'fmsstatus_ts' => $t - 600],
    ['status' => 'ready', 'comment' => 'neu', 'lastModified' => iso($t)]);
FakePdo::$fields['comment'] = 0;
$x->run();
$x->expect('Kommentar wird nicht uebertragen', $x->d->v['fmsstatus_note'] === 'alt' && $x->s->a['comment'] === 'neu');

$x = new Sim('7b) Feld "status" deaktiviert, Status in Divera geaendert, Kommentar in Stein geaendert',
    ['fmsstatus' => 2, 'fmsstatus_note' => '', 'fmsstatus_ts' => $t - 600],
    ['status' => 'ready', 'comment' => '', 'lastModified' => iso($t - 600)]);
FakePdo::$fields['status'] = 0;
$x->run(); $x->diveraEdit(['fmsstatus' => 3]); $x->steinEdit(['comment' => 'Notiz']); $x->run();
$x->expect('nur Kommentar uebertragen, Status beider Seiten unveraendert',
    $x->d->v['fmsstatus_note'] === 'Notiz' && $x->d->v['fmsstatus'] === 3 && $x->s->a['status'] === 'ready');

$x = new Sim('8) Kommentar in Stein geaendert, DANACH Status in Divera geaendert',
    ['fmsstatus' => 2, 'fmsstatus_note' => 'alt', 'fmsstatus_ts' => $t - 600],
    ['status' => 'ready', 'comment' => 'alt', 'lastModified' => iso($t - 600)]);
$x->run(); $x->steinEdit(['comment' => 'Neuer Hinweis aus Stein']); $x->diveraEdit(['fmsstatus' => 3]); $r = $x->run(); $x->run();
$x->expect('beide Aenderungen bleiben erhalten (Kommentar -> Divera, Status -> Stein)',
    $x->d->v['fmsstatus_note'] === 'Neuer Hinweis aus Stein' && $x->s->a['comment'] === 'Neuer Hinweis aus Stein'
    && $x->s->a['status'] === 'inuse' && $x->d->v['fmsstatus'] === 3 && ($r['action'] ?? '') === 'both');

// --- Weitere Faelle ---

$x = new Sim('9) Echter Konflikt: Kommentar auf beiden Seiten geaendert, Divera zuletzt',
    ['fmsstatus' => 2, 'fmsstatus_note' => 'alt', 'fmsstatus_ts' => $t - 600],
    ['status' => 'ready', 'comment' => 'alt', 'lastModified' => iso($t - 600)]);
$x->run(); $x->steinEdit(['comment' => 'aus Stein']); $x->diveraEdit(['fmsstatus_note' => 'aus Divera']); $x->run();
$x->expect('juengere Seite (Divera) gewinnt', $x->s->a['comment'] === 'aus Divera' && $x->d->v['fmsstatus_note'] === 'aus Divera');

$x = new Sim('10) Stein-Schreibfehler wird nicht mehr als Erfolg gemeldet und wiederholt',
    ['fmsstatus' => 2, 'fmsstatus_note' => 'alt', 'fmsstatus_ts' => $t - 600],
    ['status' => 'ready', 'comment' => 'alt', 'lastModified' => iso($t - 600)]);
$x->run(); $x->diveraEdit(['fmsstatus_note' => 'neu']); $x->s->failNext = true; $r = $x->run();
$x->expect('success=false mit Fehlermeldung', $r && $r['success'] === false && str_contains($r['error'], 'Stein:'));
$r = $x->run();
$x->expect('naechster Lauf holt es nach, Richtung bleibt Divera -> Stein', $x->s->a['comment'] === 'neu' && $x->d->v['fmsstatus_note'] === 'neu');

$x = new Sim('11) Sperre belegt (paralleler Lauf)',
    ['fmsstatus' => 2, 'fmsstatus_note' => 'a', 'fmsstatus_ts' => $t],
    ['status' => 'ready', 'comment' => 'b', 'lastModified' => iso($t - 600)]);
FakePdo::$lockFree = false;
$x->run();
$x->expect('kein Schreibzugriff waehrend ein anderer Lauf aktiv ist', $x->d->calls === 0 && $x->s->calls === 0);

$x = new Sim('12) Statuswechsel Stein ready -> maint, danach Divera 6 -> 2',
    ['fmsstatus' => 2, 'fmsstatus_note' => '', 'fmsstatus_ts' => $t - 600],
    ['status' => 'ready', 'comment' => '', 'lastModified' => iso($t - 600)]);
$x->run(); $x->steinEdit(['status' => 'maint']); $x->run();
$x->expect('Divera auf 6', $x->d->v['fmsstatus'] === 6 && $x->s->a['status'] === 'maint');
$x->diveraEdit(['fmsstatus' => 2]); $x->run();
$x->expect('Stein auf ready', $x->s->a['status'] === 'ready');

$x = new Sim('13) Erster Lauf nach Update ohne gespeicherten Stand: Zeitstempel entscheidet wie bisher',
    ['fmsstatus' => 2, 'fmsstatus_note' => 'aus Divera', 'fmsstatus_ts' => $t - 600],
    ['status' => 'ready', 'comment' => 'aus Stein', 'lastModified' => iso($t)]);
$x->run(); $x->run();
$x->expect('Stein (juenger) gewinnt, danach Ruhe', $x->d->v['fmsstatus_note'] === 'aus Stein' && $x->d->calls === 1 && $x->s->calls === 0);

$x = new Sim('14) Manuelle Richtung "Nur nach Stein" erzwingt Divera -> Stein',
    ['fmsstatus' => 3, 'fmsstatus_note' => 'D', 'fmsstatus_ts' => $t - 600],
    ['status' => 'ready', 'comment' => 'S', 'lastModified' => iso($t)]);
$x->run('stein');
$x->expect('Stein uebernimmt Status und Kommentar aus Divera', $x->s->a['status'] === 'inuse' && $x->s->a['comment'] === 'D' && $x->d->calls === 0);

echo "\n=== Ergebnis: " . ($GLOBALS['fail'] === 0 ? 'alle Erwartungen erfuellt' : $GLOBALS['fail'] . ' Erwartung(en) NICHT erfuellt') . ", PHP-Warnungen: {$GLOBALS['warnings']}\n";
exit($GLOBALS['fail'] === 0 ? 0 : 1);
