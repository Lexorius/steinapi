-- Erstelle Datenbank
CREATE DATABASE IF NOT EXISTS divera_stein_sync 
CHARACTER SET utf8mb4 
COLLATE utf8mb4_unicode_ci;

USE divera_stein_sync;

-- Tabelle für Synchronisations-Log
CREATE TABLE IF NOT EXISTS sync_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vehicle_name VARCHAR(100) NOT NULL,
    divera_id INT,
    stein_id INT,
    sync_direction VARCHAR(50),
    fields_synced JSON,
    success BOOLEAN DEFAULT FALSE,
    error_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created (created_at),
    INDEX idx_vehicle (vehicle_name),
    INDEX idx_success (success)
) ENGINE=InnoDB;

-- Tabelle für Feld-Konfiguration
CREATE TABLE IF NOT EXISTS sync_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    field_name VARCHAR(50) UNIQUE NOT NULL,
    active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Standard-Felder einfügen
INSERT INTO sync_config (field_name, active) VALUES 
    ('status', 1),
    ('comment', 1),
    ('position', 0),
    ('crew', 0)
ON DUPLICATE KEY UPDATE field_name=field_name;

-- Tabelle für System-Status (optional)
CREATE TABLE IF NOT EXISTS system_status (
    id INT AUTO_INCREMENT PRIMARY KEY,
    last_sync TIMESTAMP NULL,
    auto_sync_enabled BOOLEAN DEFAULT FALSE,
    sync_interval INT DEFAULT 300,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Initial System Status
INSERT INTO system_status (auto_sync_enabled, sync_interval) 
VALUES (0, 300)
ON DUPLICATE KEY UPDATE id=id;