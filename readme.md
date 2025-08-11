# ===================================
// README.md - Installationsanleitung
// ===================================
# Divera-Stein Sync System

## Installation

### 1. Voraussetzungen
- PHP 7.4 oder höher
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

## Sicherheit

- Alle Konfigurationsdateien sind durch .htaccess geschützt
- API verwendet Prepared Statements gegen SQL Injection
- Rate Limiting für externe APIs implementiert

## Support

Bei Fragen oder Problemen erstellen Sie bitte ein Issue im Repository.