# Alpha Version - Nutzung auf eigene Gefahr

# ===================================
// README.md - Installationsanleitung
// ===================================
# Divera-Stein Sync System

## Installation

### 1. Voraussetzungen
- PHP 8.2.35 / 8.3.36 / 8.4.27 / 8.5.12 oder neuer (Docker-Image: 8.4.27)
  - Ältere Versionen sind von den kritischen RCE-Lücken CVE-2026-109618 und
    CVE-2026-109619 betroffen (Security Advisory vom 21.10.2026):
    https://www.php.net/archive/2026.php#2026-10-21-5
  - PHP 7.4 / 8.0 / 8.1 erhalten keine Upstream-Updates mehr und sollten nicht
    mehr eingesetzt werden
- MySQL 5.7 oder höher
- Webserver (Apache/Nginx)
- curl PHP Extension
- PDO MySQL Extension

### 2. Installation

1. Dateien auf den Server kopieren
2. Datenbank einrichten:
   ```bash
   mysql -u root -p < install.sql
   ```

3. Konfiguration anpassen:
   - `config/config.php` kopieren und anpassen
   - Divera Access Key eintragen
   - Stein.app API Key und Business Unit ID eintragen
   - Datenbank-Zugangsdaten eintragen
   - Unter `auth` Benutzer und Passwort für Dashboard/API setzen (bzw. die
     Umgebungsvariablen `DASHBOARD_USER` / `DASHBOARD_PASSWORD`). Ohne Passwort
     ist die API für jeden erreichbar, der den Server erreicht.

4. Berechtigungen setzen:
   ```bash
   chmod 755 .
   chmod 644 *.php
   chmod 755 src/ config/
   ```

### 3. Cronjob einrichten (optional)

Für automatische Synchronisation alle 5 Minuten:
```bash
*/5 * * * * /usr/bin/php /pfad/zu/cron.php >> /var/log/divera-sync.log 2>&1
```

### 4. Dashboard aufrufen

Öffnen Sie `index.html` im Browser für das Dashboard.

## Update

### Docker-Installation

```bash
git pull
make build    # baut mit --pull, zieht also auch das aktuelle PHP-Base-Image
make up
```

Ohne Make:

```bash
git pull
docker compose build --pull
docker compose up -d
```

Die Datenbank bleibt im Volume `mariadb-data` erhalten. Vorher sichern:

```bash
make backup
```

### Manuelle Installation

1. Datenbank sichern (`mysqldump`)
2. Neue Dateien auf den Server kopieren, `config/config.php` dabei nicht überschreiben
3. PHP auf dem Server auf eine gepatchte Version bringen (siehe Voraussetzungen)
4. Prüfen, ob `install.sql` neue Tabellen oder Spalten enthält, und diese
   bei Bedarf nachziehen

### Sicherheitsupdate PHP (21.10.2026)

Bestehende Installationen, die vor dem 21.10.2026 aufgesetzt wurden, laufen
auf einer verwundbaren PHP-Version. Ein `git pull` allein genügt nicht, der
Container muss neu gebaut und neu gestartet werden:

```bash
git pull
make build
make up
```

Anschließend prüfen:

```bash
docker compose exec web php -v
```

Die Ausgabe muss `PHP 8.4.27` oder neuer zeigen.

## Verwendung

### Manuelle Synchronisation
1. Dashboard öffnen
2. Sync-Richtung wählen
3. "Jetzt synchronisieren" klicken

### Feld-Konfiguration
Im Dashboard können Sie auswählen, welche Felder synchronisiert werden sollen.

### API Endpoints

- `GET api.php?action=stats` - Statistiken abrufen
- `GET api.php?action=logs&limit=50` - Logs abrufen
- `POST api.php?action=sync` - Synchronisation starten
- `GET api.php?action=fieldConfig` - Feld-Konfiguration abrufen
- `POST api.php?action=updateField` - Feld-Konfiguration aktualisieren
- `POST api.php?action=updateAutoSync` - Auto-Sync ein/aus und Intervall (60–3600 s)
- `GET api.php?action=systemStatus` - Status des Auto-Sync
- `GET api.php?action=vehicles` - Fahrzeugliste aus beiden Systemen
- `GET api.php?action=health` - Health-Check inkl. `version`

Alle Endpoints verlangen HTTP-Basic-Auth, sobald ein Passwort konfiguriert ist.
POST-Endpoints erwarten `Content-Type: application/json`.

```bash
curl -u admin:geheim "http://localhost:8080/api.php?action=health"
```

## Home Assistant

Der Health-Endpoint liefert die installierte Version. Beispiel für einen
REST-Sensor in der `configuration.yaml`, der Version und letzten Sync anzeigt:

```yaml
rest:
  - resource: "http://sync.example.local:8080/api.php?action=health"
    authentication: basic
    username: admin
    password: !secret divera_stein_sync
    scan_interval: 300
    sensor:
      - name: "Divera-Stein Sync Version"
        value_template: "{{ value_json.version }}"
      - name: "Divera-Stein Sync letzter Lauf"
        value_template: "{{ value_json.last_sync }}"
```

Die aktuelle Version steht in `CHANGELOG.md`; ein Vergleich mit dem Sensorwert
zeigt, ob ein Update ansteht.

## Sicherheit

- API und Dashboard sind per HTTP-Basic-Auth geschützt (`DASHBOARD_USER` /
  `DASHBOARD_PASSWORD`). Ohne gesetztes Passwort ist die API offen, der
  Container warnt beim Start.
- Kein CORS-Wildcard mehr; POST nur mit `Content-Type: application/json`
  (schützt vor CSRF trotz Basic-Auth).
- Alle Eingaben werden validiert (Sync-Richtung, Feldnamen, Intervall, Limit)
- API verwendet Prepared Statements gegen SQL Injection
- Werte aus Divera/Stein werden im Dashboard HTML-escaped (XSS)
- `.htaccess` blockiert Konfigurations-, Log-, Shell-, SQL- und `.env`-Dateien;
  `cron.php` / `cleanup.php` laufen nur per CLI
- TLS-Zertifikate der Stein- und Divera-API werden geprüft
- Datenbank- und interne Fehler gehen nur ins Server-Log, nicht an den Client
- Rate Limiting für externe APIs implementiert
- Docker-Image läuft auf PHP 8.4.27 (Fix für CVE-2026-109618 / CVE-2026-109619);
  Datei-Uploads und `session.upload_progress` sind im Container deaktiviert,
  da die API ausschließlich JSON verarbeitet
- MariaDB und phpMyAdmin sind im Docker-Setup nur auf `127.0.0.1` erreichbar
- Nach PHP-Sicherheitsupdates immer `make build` (nutzt `--pull`) und
  `make up` ausführen, damit das gepatchte Base-Image tatsächlich gezogen wird

Für den Betrieb außerhalb des eigenen Netzes gehört zusätzlich ein Reverse Proxy
mit TLS davor. Die Basic-Auth hat keine Sperre gegen Brute-Force, ein starkes
Passwort ist Pflicht.

## Support


Bei Fragen oder Problemen erstellen Sie bitte ein Issue im Repository.
