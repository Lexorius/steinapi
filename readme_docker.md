# Docker Setup für Divera-Stein Sync System

## 🚀 Schnellstart

### Voraussetzungen
- Docker & Docker Compose installiert
- Make (optional, für einfachere Befehle)

### Technologie-Stack
- **PHP 8.4.27** (mit JIT-Compiler und OPCache; gepinnt, enthält den Fix für CVE-2026-109618 / CVE-2026-109619)
- **MariaDB 11.2** (optimiert für Performance)
- **Apache 2.4** (mit mod_rewrite)
- **Supervisor** (Process Management)
- **Cron** (für automatische Synchronisation)

### Installation mit Make
```bash
# 1. Repository klonen
git clone <your-repo>
cd divera-stein-sync

# 2. Installation starten
make install

# 3. .env Datei bearbeiten: API-Keys und DASHBOARD_PASSWORD eintragen
nano .env

# 4. Container neu starten
make restart
```

Ohne `DASHBOARD_PASSWORD` ist die API ohne Anmeldung erreichbar; der Container
gibt beim Start eine Warnung aus.

### Installation ohne Make
```bash
# 1. Environment-Datei erstellen
cp .env.example .env

# 2. .env bearbeiten und API-Keys eintragen
nano .env

# 3. Container bauen und starten
docker-compose up -d --build

# 4. Logs prüfen
docker-compose logs -f
```

## 📦 Services

| Service | Port | Beschreibung |
|---------|------|-------------|
| Web App (PHP 8.4.27) | 8080 | Hauptanwendung mit Dashboard (Basic-Auth) |
| MariaDB 11.2 | 127.0.0.1:3306 | Datenbank, nur vom Docker-Host erreichbar |
| phpMyAdmin | 127.0.0.1:8081 | Datenbank-Verwaltung, nur vom Docker-Host erreichbar |

MariaDB und phpMyAdmin sind bewusst nur an `127.0.0.1` gebunden (phpMyAdmin
meldet sich als DB-root an). Für Zugriff von anderen Rechnern einen SSH-Tunnel
nutzen oder die Port-Bindung in `compose.yml` bewusst ändern.

## 🔧 Befehle

### Mit Make
```bash
make up        # Container starten
make down      # Container stoppen
make restart   # Container neu starten
make logs      # Logs anzeigen
make shell     # Web-Container Shell
make db-shell  # MariaDB Shell
make backup    # Datenbank sichern
make restore   # Datenbank wiederherstellen
make clean     # Alles löschen (Vorsicht!)
```

### Mit Docker Compose
```bash
docker-compose up -d        # Starten
docker-compose down         # Stoppen
docker-compose logs -f      # Logs
docker-compose exec web bash   # Shell
docker-compose exec db mariadb -u root -prootpassword
```

## 🔐 Sicherheit

### Produktion
1. Ändern Sie alle Passwörter in `.env`, insbesondere `DASHBOARD_PASSWORD`
2. Verwenden Sie Docker Secrets für sensible Daten
3. Aktivieren Sie SSL/TLS mit einem Reverse Proxy (Basic-Auth überträgt
   Zugangsdaten sonst im Klartext)
4. Beschränken Sie Port-Zugriffe
5. Die `.env` nie committen (steht in `.gitignore`); Vorlage ist `.env.example`

### SSL mit Traefik (Beispiel)
```yaml
services:
  web:
    labels:
      - traefik.enable=true
      - traefik.http.routers.sync.rule=Host(`sync.example.com`)
      - traefik.http.routers.sync.tls.certresolver=letsencrypt
```

## 📊 Monitoring

### Health Check
```bash
# Status prüfen
curl http://localhost:8080/api.php?action=stats

# Container Health
docker-compose ps
```

### Logs
```bash
# Alle Logs
docker-compose logs -f

# Nur Web-Logs
docker-compose logs -f web

# Cron-Logs
docker-compose exec web tail -f /var/www/html/logs/cron.log
```

## 🔄 Updates

```bash
# Optional: Datenbank sichern
make backup

# Code aktualisieren
git pull

# Container neu bauen (--pull zieht auch das aktuelle PHP-Base-Image)
make build
# oder ohne Make:
docker compose build --pull

# Mit neuer Version starten
make up
# oder ohne Make:
docker compose up -d
```

Die Datenbank liegt im Volume `mariadb-data` und bleibt beim Update erhalten.

### PHP-Sicherheitsupdate (21.10.2026)

Container, die vor dem 21.10.2026 gebaut wurden, laufen auf einer PHP-Version
mit den kritischen RCE-Lücken CVE-2026-109618 / CVE-2026-109619. Ein `git pull`
allein reicht nicht, das Image muss neu gebaut werden (Befehle wie oben).
Danach die PHP-Version im Container prüfen:

```bash
docker compose exec web php -v
```

Erwartet wird `PHP 8.4.27` oder neuer.

## 🐛 Troubleshooting

### Container startet nicht
```bash
# Logs prüfen
docker-compose logs web

# Permissions reparieren
docker-compose exec web chown -R www-data:www-data /var/www/html
```

### Datenbank-Verbindung fehlgeschlagen
```bash
# MariaDB Status prüfen
docker-compose exec db mariadb-admin -u root -prootpassword ping

# Netzwerk prüfen
docker network ls
docker network inspect divera-stein-sync_sync-network
```

### Cron läuft nicht
```bash
# Cron Status
docker-compose exec web service cron status

# Cron manuell starten
docker-compose exec web service cron start
```

## 🏗️ Entwicklung

### Lokale Entwicklung
```yaml
# docker-compose.override.yml
services:
  web:
    volumes:
      - ./src:/var/www/html/src
      - ./config:/var/www/html/config
    environment:
      - PHP_DISPLAY_ERRORS=1
```

### Debugging
```bash
# PHP Fehler anzeigen
docker-compose exec web bash
tail -f /var/log/apache2/error.log
```

## 📁 Backup & Restore

### Automatisches Backup
```bash
# Cronjob auf Host einrichten
0 2 * * * cd /path/to/project && make backup
```

### Manuelles Backup
```bash
make backup
# oder
docker-compose exec db mariadb-dump -u root -prootpassword divera_stein_sync > backup.sql
```

### Restore
```bash
make restore
# oder
docker-compose exec -T db mariadb -u root -prootpassword divera_stein_sync < backup.sql
```

## 🚢 Deployment

### Docker Swarm
```bash
docker stack deploy -c docker-compose.yml divera-sync
```

### Kubernetes
Siehe `k8s/` Verzeichnis für Kubernetes Manifeste.

## 🎯 PHP 8.4 Features

Diese Docker-Konfiguration nutzt die neuesten PHP 8.4 Features:

- **JIT Compiler**: Aktiviert für bessere Performance
- **OPCache**: Optimiert mit JIT-Buffer
- **Property Hooks**: Unterstützung für moderne PHP-Syntax
- **Improved Type System**: Vollständige Typ-Unterstützung
- **Performance**: ~15% schneller als PHP 8.1

### Sicherheit
Das Base-Image ist auf `php:8.4.27-apache` gepinnt. Diese Version behebt die am
21.10.2026 veröffentlichten kritischen RCE-Lücken CVE-2026-109618 und
CVE-2026-109619 (betroffen u. a. Apache mod_php):
https://www.php.net/archive/2026.php#2026-10-21-5

Zusätzlich sind im Container Datei-Uploads und `session.upload_progress`
deaktiviert und `post_max_size` ist auf 2M begrenzt
(`/usr/local/etc/php/conf.d/zz-security-hardening.ini`), da die API nur JSON
über `php://input` entgegennimmt.

Bei künftigen PHP-Updates: Version im `Dockerfile` anheben und mit
`make build` (verwendet `--pull`) neu bauen.

## 🗄️ MariaDB Vorteile

MariaDB 11.2 bietet gegenüber MySQL:

- **Bessere Performance**: Optimierte Query-Ausführung
- **Mehr Storage Engines**: Aria, ColumnStore, etc.
- **Erweiterte Features**: Window Functions, CTEs
- **Kompatibilität**: 100% Drop-in Replacement für MySQL
- **Open Source**: Echte Open-Source-Lizenz

## 📝 Lizenz

MIT License - siehe LICENSE Datei