# ⚠️ OBSOLET: Divera-Stein Sync System (steinapi)

> **Dieses Projekt ist obsolet und wird nicht mehr gepflegt.**
>
> Nachfolger ist
> **[Stein-Divera-Sync-homeassistant-addon](https://github.com/Lexorius/Stein-Divera-Sync-homeassistant-addon)**.
> Er erledigt dieselbe Aufgabe, läuft als Home-Assistant-Add-on oder als eigenständiger
> Docker-Container und erhält alle Fehlerkorrekturen und Sicherheitsupdates.
>
> Letzter Stand hier: **1.2.0** vom 14.11.2026. Es gibt keine weiteren Versionen.

## Warum obsolet

`steinapi` und der Nachfolger gleichen beide Fahrzeugstatus und Notiz zwischen Divera 24/7 und
Stein.app ab. Zwei getrennte Codebasen bedeuten, dass jede Korrektur zweimal gemacht werden muss.
Gepflegt wird deshalb nur noch der Nachfolger. Er hat automatische Tests, einen Zugriffsschutz und
eine integrierte Datenbank.

In `steinapi` bleiben diese bekannten Fehler **unbehoben**:

- Eine Neuinstallation nach Anleitung startet nicht. `compose.yml` erwartet Variablen mit dem
  Präfix `MARIA_`, die in `.env.example` fehlen.
- `make backup` erzeugt mit einem eigenen Datenbank-Passwort leere Sicherungen.
- Der Schalter „Auto-Sync" im Dashboard stoppt den automatischen Lauf nicht.
- Zeiten im Sync-Log liegen während der Sommerzeit eine Stunde daneben.
- Ein Apostroph oder Dollarzeichen im Passwort verhindert den Start des Containers.

## Umstieg auf den Nachfolger

Der Umstieg dauert rund 15 Minuten. Es werden **keine Daten übernommen**: Die Datenbank von
`steinapi` enthält nur Protokoll und Sync-Stand, und beides baut der Nachfolger selbst neu auf.
Fahrzeuge, Status und Notizen liegen ohnehin in Divera und Stein.app.

**Wichtig:** `steinapi` und der Nachfolger dürfen nie gleichzeitig laufen. Beide würden dieselben
Fahrzeuge beschreiben und sich das Anfragelimit von Stein.app teilen.

### 1. Zugangsdaten bereitlegen

Aus der `.env` von `steinapi` werden drei Werte gebraucht:

| Wert in `steinapi` | Bedeutung |
|---|---|
| `DIVERA_ACCESS_KEY` | Access Key von Divera 24/7 |
| `STEIN_API_KEY` | API Key von Stein.app |
| `STEIN_BU_ID` | Business Unit ID in Stein.app |

Dazu kommt die Adresse der Stein-API. In `steinapi` steht sie fest im Code:

```
https://stein.app/api/api/ext
```

### 2. Beide Systeme auf denselben Stand bringen

Der Nachfolger kennt beim ersten Lauf noch keinen gemeinsamen Stand. Wo Stein.app und Divera
voneinander abweichen, gewinnt dann einmalig die zuletzt geänderte Seite. Wer das vermeiden will,
löst im Dashboard von `steinapi` einen letzten Sync aus und prüft, dass er ohne Fehler endet.

### 3. `steinapi` stoppen

Docker-Installation, im Ordner von `steinapi`:

```bash
docker compose down
```

Das Volume mit der Datenbank bleibt dabei erhalten, falls ein Rückweg nötig wird.

Manuelle Installation: den Cron-Eintrag für `cron.php` entfernen und das Dashboard nicht mehr
verwenden.

### 4. Nachfolger installieren

**Variante A: Home-Assistant-Add-on**

1. In Home Assistant **Einstellungen → Add-ons → Add-on Store** öffnen.
2. Im Drei-Punkte-Menü **Repositories** wählen.
3. Diese Adresse hinzufügen:
   ```
   https://github.com/Lexorius/Stein-Divera-Sync-homeassistant-addon
   ```
4. Das Add-on **SteinAPI - Divera Sync** installieren, aber noch nicht starten.

**Variante B: eigenständiger Docker-Container**

```bash
git clone https://github.com/Lexorius/Stein-Divera-Sync-homeassistant-addon.git
cd Stein-Divera-Sync-homeassistant-addon/steinapi-docker
cp .env.example .env
```

### 5. Einstellungen übertragen

Beim Add-on stehen die Werte im Reiter **Konfiguration**, beim Container in der neuen `.env`.

| `steinapi` | Add-on-Option | Variable im Container | Hinweis |
|---|---|---|---|
| `DIVERA_ACCESS_KEY` | `divera_access_key` | `DIVERA_ACCESS_KEY` | unverändert übernehmen |
| `STEIN_API_KEY` | `stein_api_key` | `STEIN_API_KEY` | unverändert übernehmen |
| `STEIN_BU_ID` | `stein_business_unit_id` | `STEIN_BUSINESS_UNIT_ID` | derselbe Wert, neuer Name |
| fest im Code | `stein_base_url` | `STEIN_BASE_URL` | `https://stein.app/api/api/ext` eintragen, siehe unten |
| fest im Code | `divera_base_url` | `DIVERA_BASE_URL` | Voreinstellung passt |
| Dashboard: Auto-Sync an/aus | `auto_sync_enabled` | `AUTO_SYNC_ENABLED` | `true` oder `false` |
| Dashboard: Intervall in Sekunden | `sync_interval_minutes` | `SYNC_INTERVAL_MINUTES` | jetzt in Minuten, 300 Sekunden sind `5` |
| Richtung „Bidirektional" | `sync_direction: both` | `SYNC_DIRECTION=both` | |
| Richtung „Nur nach Divera" | `sync_direction: stein_to_divera` | `SYNC_DIRECTION=stein_to_divera` | |
| Richtung „Nur nach Stein" | `sync_direction: divera_to_stein` | `SYNC_DIRECTION=divera_to_stein` | |
| `TZ` | `timezone` | `TIMEZONE` | zum Beispiel `Europe/Berlin` |
| `DASHBOARD_USER`, `DASHBOARD_PASSWORD` | entfällt | entfällt | siehe „Was sich ändert" |
| `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` | entfällt | entfällt | Datenbank ist eingebaut, das Passwort entsteht von selbst |
| `STEIN_TIMEZONE` | entfällt | entfällt | |
| `APP_PORT`, `PHPMYADMIN_PORT`, `MYSQL_PORT` | entfällt | entfällt | |

**Zur Adresse der Stein-API:** Die Voreinstellung des Nachfolgers lautet
`https://www.stein.app/api` und weicht damit von der Adresse ab, mit der `steinapi` arbeitet.
Für einen sicheren Umstieg die Adresse aus `steinapi` eintragen: `https://stein.app/api/api/ext`.

### 6. Starten und prüfen

Add-on: **Starten** klicken und das Dashboard über **Im Seitenbereich öffnen** aufrufen.

Container:

```bash
docker compose up -d --build
```

Danach das Dashboard unter `http://localhost:8099` öffnen.

In beiden Varianten prüfen:

1. Das Dashboard lädt, der Status steht auf „Verbunden".
2. **Jetzt synchronisieren** endet mit einer Erfolgsmeldung.
3. Im Aktivitätsprotokoll steht „Sync completed" und kein Eintrag der Stufe error.
4. Die Kachel „Fehler" zeigt 0.

Steht im Protokoll „Stein API request failed", stimmen Adresse, API Key oder Business Unit ID
nicht. Bei „Divera API request failed" ist der Access Key falsch.

Fahrzeuge werden nur abgeglichen, wenn das Kennzeichen in Stein.app (Feld `name`) und in Divera
(Feld `number`) Zeichen für Zeichen gleich ist. Auch ein doppeltes Leerzeichen verhindert die
Zuordnung. Das war in `steinapi` genauso.

### 7. Aufräumen

Wenn der Nachfolger ein paar Tage sauber läuft, im Ordner von `steinapi`:

```bash
docker compose down -v
```

Das löscht auch das Volume mit der alten Datenbank. Danach kann der Ordner weg. Zusätzlich
entfernen, falls eingerichtet:

- den Cron-Eintrag für `make backup` auf dem Host
- Sensoren in Home Assistant, die `api.php?action=health` von `steinapi` abfragen

## Was sich ändert

| Thema | `steinapi` | Nachfolger |
|---|---|---|
| Dashboard | `http://<host>:8080` | Add-on: Seitenleiste von Home Assistant. Container: `http://localhost:8099` |
| Anmeldung | Benutzer und Passwort aus der `.env` | Add-on: Anmeldung von Home Assistant. Container: keine, der Port ist nur lokal erreichbar |
| Datenbank | eigener Container, dazu phpMyAdmin | eingebaut, von außen nicht erreichbar, kein phpMyAdmin |
| Einstellungen | `.env` und Dashboard | Add-on-Konfiguration oder `.env`, Änderungen wirken nach einem Neustart |
| Automatischer Lauf | fest alle 5 Minuten | Intervall einstellbar, 1 bis 60 Minuten |
| Updates | `git pull` und neu bauen | Add-on: Home Assistant bietet sie an. Container: `git pull` und neu bauen |
| Sync-Regeln | feldweiser Abgleich seit 1.2.0 | dieselben Regeln, durch automatische Tests abgesichert |
| Feldauswahl im Dashboard | vorhanden | entfällt, Status und Notiz werden immer abgeglichen |

Wer den Container aus dem Netz erreichen will, setzt einen Reverse Proxy mit Anmeldung davor.
Der Nachfolger hat bewusst kein eigenes Login.

**API-Endpunkte:** `health`, `stats`, `logs` und `sync` gibt es weiterhin unter
`api.php?action=...`. Die Antworten haben ein anderes Format: Jede trägt ein Feld `success`,
die Nutzdaten von `stats`, `logs` und `sync` stehen unter `data`. `health` liefert keine
Versionsnummer mehr. Entfallen sind `fieldConfig`, `updateField`,
`updateAutoSync`, `systemStatus` und `vehicles`.

---

# Alte Dokumentation (Stand 1.2.0)

Die folgenden Abschnitte beschreiben `steinapi` selbst. Sie werden nicht mehr gepflegt und sind
nur noch für bestehende Installationen bis zum Umstieg gedacht.

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

### Synchronisationsregeln

Status und Kommentar werden getrennt abgeglichen. Grundlage ist der zuletzt
synchronisierte Stand je Fahrzeug (Tabelle `sync_state`):

- Hat sich ein Feld nur in Stein geändert, geht es nach Divera.
- Hat es sich nur in Divera geändert, geht es nach Stein.
- Wurde es auf beiden Seiten geändert, gewinnt die jüngere Änderung.

Besonderheiten:

- Divera-Notizen sind einzeilig. Ein mehrzeiliger Stein-Kommentar wird in
  Divera mit Leerzeichen statt Zeilenumbrüchen gespeichert und bleibt in Stein
  unverändert.
- Divera-Status 3 und 4 entsprechen in Stein beide „inuse", Stein „maint" und
  „notready" in Divera beide Status 6. Gleichwertige Status werden nicht
  überschrieben.
- Divera-Status 0, 5, 7, 8 und 9 haben in Stein keine Entsprechung. Der Status
  wird dann nicht übertragen, der Kommentar von Divera nach Stein schon.
- Es läuft immer nur eine Synchronisation gleichzeitig.

Die Logik lässt sich ohne echte APIs durchspielen:

```bash
php tests/sync_simulation.php
```

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
