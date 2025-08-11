# Docker Setup für Divera-Stein Sync System

## 🚀 Schnellstart

### Voraussetzungen
- Docker & Docker Compose installiert
- Make (optional, für einfachere Befehle)

### Technologie-Stack
- **PHP 8.4** (mit JIT-Compiler und OPCache)
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

# 3. .env Datei bearbeiten und API-Keys eintragen
nano .env

# 4. Container neu starten
make restart
```

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
| Web App (PHP 8.4) | 8080 | Hauptanwendung mit Dashboard |
| MariaDB 11.2 | 3306 | Datenbank |
| phpMyAdmin | 8081 | Datenbank-Verwaltung |

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
1. Ändern Sie alle Passwörter in `.env`
2. Verwenden Sie Docker Secrets für sensible Daten
3. Aktivieren Sie SSL/TLS mit einem Reverse Proxy
4. Beschränken Sie Port-Zugriffe

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
# Code aktualisieren
git pull

# Container neu bauen
docker-compose build

# Mit neuer Version starten
docker-compose up -d
```

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

## 🗄️ MariaDB Vorteile

MariaDB 11.2 bietet gegenüber MySQL:

- **Bessere Performance**: Optimierte Query-Ausführung
- **Mehr Storage Engines**: Aria, ColumnStore, etc.
- **Erweiterte Features**: Window Functions, CTEs
- **Kompatibilität**: 100% Drop-in Replacement für MySQL
- **Open Source**: Echte Open-Source-Lizenz

## 📝 Lizenz

MIT License - siehe LICENSE Datei