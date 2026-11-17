# Changelog

Die Version wird in `api.php` (`APP_VERSION`) gepflegt und über
`api.php?action=health` ausgeliefert.

## Obsolet - 2026-11-17

Das Projekt ist obsolet. Nachfolger ist
[Stein-Divera-Sync-homeassistant-addon](https://github.com/Lexorius/Stein-Divera-Sync-homeassistant-addon),
als Home-Assistant-Add-on oder als eigenständiger Docker-Container. Den Umstieg beschreibt
`readme.md`. Version 1.2.0 ist der letzte Stand, am Code wurde nichts mehr geändert. Die in
`readme.md` aufgeführten bekannten Fehler bleiben unbehoben.

## 1.2.0 - 2026-11-14

Überarbeitung der Synchronisationslogik. Kommentare und Status wurden in
mehreren Situationen nicht sauber übertragen oder gegenseitig überschrieben.

### Behoben

- **Feldweiser Abgleich statt „ganzes Fahrzeug, jüngere Seite gewinnt"**: Status
  und Kommentar werden getrennt gegen den zuletzt synchronisierten Stand
  verglichen (neue Tabelle `sync_state`). Eine Statusänderung in Divera
  überschreibt nicht mehr einen kurz zuvor in Stein geänderten Kommentar.
  Der Zeitstempel entscheidet nur noch bei echten Konflikten.
- Kein Rück-Sync mehr durch die vom Sync selbst verursachten Zeitstempel.
- Ein in Stein gelöschter Kommentar wird jetzt auch in Divera gelöscht
  (leere Notiz wurde bisher nicht gesendet und kam im nächsten Lauf zurück).
- Mehrzeilige Stein-Kommentare: gelten als gleich zu ihrer einzeiligen
  Divera-Fassung und werden in Stein nicht mehr umgeschrieben.
- Divera-Status 4 wurde durch den Sync auf 3 zurückgesetzt, weil beide in Stein
  „inuse" sind. Statusvergleich erfolgt jetzt in Stein-Begriffen.
- Stein-Status „maint" kippte bei einer Kommentaränderung aus Divera auf
  „notready".
- Divera-Status ohne Stein-Entsprechung (0, 5, 7, 8, 9): der Kommentar kommt
  jetzt trotzdem in Stein an, der Status bleibt unangetastet. Vorher schlug
  jeder Lauf still fehl.
- Fehlgeschlagene Schreibzugriffe auf Stein wurden als Erfolg protokolliert.
  Sie erscheinen jetzt mit Fehlermeldung im Log und werden im nächsten Lauf
  wiederholt.
- Feldkonfiguration im Dashboard hatte keine Wirkung. Ein abgewähltes Feld wird
  jetzt in keine Richtung übertragen.
- Kommentarvergleich ist jetzt exakt („007" und „7" galten als gleich).
- Sperre gegen parallele Läufe (Cron, Dashboard-Timer, manueller Sync).

### Neu

- `STEIN_TIMEZONE` (Standard `Europe/Berlin`): Zeitzone für Stein-Zeitstempel
  ohne Zonenangabe. `api.php?action=vehicles` liefert zur Kontrolle
  `stein_last_modified` und `divera_status_ts`.
- `tests/sync_simulation.php`: spielt die Sync-Fälle ohne echte APIs durch.

### Hinweise zum Update

- Die Tabelle `sync_state` wird beim ersten Lauf automatisch angelegt.
- Im ersten Lauf nach dem Update gibt es noch keinen gespeicherten Stand, dort
  entscheidet einmalig der Zeitstempel wie bisher.
- Steht ein Fahrzeug in Divera auf einem Status ohne Stein-Entsprechung, wird
  ein Stein-Kommentar erst übertragen, wenn der Status wieder zuordenbar ist.
  So wird z. B. ein Sprechwunsch nicht erneut ausgelöst.

## 1.1.1 - 2026-10-21

### Behoben

- `docker-entrypoint.sh` wurde nie ausgeführt: das Dockerfile hatte kein
  `ENTRYPOINT`, der Container startete direkt supervisord. Folge: kein
  Cron-Eintrag, der automatische Sync alle 5 Minuten lief nicht, nur der
  Browser-Timer im Dashboard. Jetzt läuft der Entrypoint vor supervisord
  (DB-Wartezeit, Tabellen anlegen, `config.php` und Cron-Jobs aus der
  Umgebung erzeugen) und übergibt per `exec` an supervisord.
- Entrypoint nutzt `mariadb`, wenn kein `mysql`-Wrapper installiert ist
  (Debian trixie).

## 1.1.0 - 2026-10-21

Sicherheits-Release. Nach dem Update Container neu bauen (`make build && make up`)
und `DASHBOARD_PASSWORD` in der `.env` setzen.

### Sicherheit

- PHP-Base-Image auf `php:8.4.27-apache` gepinnt: behebt die kritischen RCE-Lücken
  CVE-2026-109618 und CVE-2026-109619 (PHP-Advisory vom 21.10.2026).
  Zusätzlich Datei-Uploads und `session.upload_progress` deaktiviert.
- **Authentifizierung für die API**: `api.php` verlangt HTTP-Basic-Auth, sobald
  `DASHBOARD_PASSWORD` gesetzt ist. Vorher war die komplette API (Sync auslösen,
  Konfiguration ändern, Logs lesen) ohne Anmeldung erreichbar.
- CORS-Freigabe `Access-Control-Allow-Origin: *` entfernt; POST-Endpoints verlangen
  `Content-Type: application/json` (CSRF-Schutz).
- Eingaben werden validiert: Sync-Richtung, Feldname, Intervall (60–3600 s), Log-Limit.
- TLS-Zertifikatsprüfung für Stein- und Divera-API aktiviert
  (`CURLOPT_SSL_VERIFYPEER=false` entfernt), Timeouts gesetzt.
- Fehlermeldungen: Datenbank- und interne Fehler gehen nur noch ins Server-Log,
  der Client erhält eine generische Meldung. `display_errors` im Container aus,
  `php.ini-production` aktiv.
- Dashboard: Werte aus der API (Fahrzeugnamen, Fehlermeldungen) werden vor dem
  Einfügen ins DOM escaped (Stored XSS über Fahrzeugnamen).
- `cron.php` und `cleanup.php` nur noch per CLI ausführbar, nicht über den Webserver.
- `.htaccess` liefert `.env`, Shell-/SQL-/YAML-Dateien, Logs und Build-Dateien nicht
  mehr aus; `.dockerignore` hält `.env` und Logs aus dem Image.
- Entrypoint: `cron-env.sh` mit allen Zugangsdaten liegt jetzt außerhalb des
  Webroots (vorher unter `/var/www/html/` abrufbar); DB-Passwort nicht mehr auf der
  mysql-Kommandozeile.
- MariaDB (3306) und phpMyAdmin (8081) sind nur noch auf `127.0.0.1` gebunden.
- `.env` aus dem Repository entfernt (jetzt `.env.example`), `.gitignore` ergänzt.

### Geändert

- Versionsnummer zentral als `APP_VERSION` in `api.php`.
- `make build` baut mit `--pull`.
- `.gitattributes`: Shell-Skripte und Dockerfile immer mit LF.

## 1.0.0

- Erste Version.
