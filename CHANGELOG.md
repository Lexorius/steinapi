# Changelog

Die Version wird in `api.php` (`APP_VERSION`) gepflegt und über
`api.php?action=health` ausgeliefert.

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
