-- Creare bază de date centrală pentru sistemul multi-tenant
CREATE DATABASE IF NOT EXISTS inventar_central CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE inventar_central;

-- Tabelă utilizatori extinsă cu toate datele necesare
CREATE TABLE IF NOT EXISTS utilizatori (
    id_utilizator INT AUTO_INCREMENT PRIMARY KEY,
    nume VARCHAR(100) NOT NULL,
    prenume VARCHAR(100) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    telefon VARCHAR(20),
    parola_hash VARCHAR(255) NOT NULL,
    data_inregistrare DATETIME DEFAULT CURRENT_TIMESTAMP,
    data_ultima_logare DATETIME,
    activ BOOLEAN DEFAULT TRUE,
    db_name VARCHAR(100), -- numele bazei de date a utilizatorului
    INDEX idx_email (email),
    INDEX idx_telefon (telefon)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabelă pentru sesiuni și cookie-uri
CREATE TABLE IF NOT EXISTS sesiuni (
    id_sesiune INT AUTO_INCREMENT PRIMARY KEY,
    id_utilizator INT NOT NULL,
    token_sesiune VARCHAR(255) UNIQUE NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    data_creare DATETIME DEFAULT CURRENT_TIMESTAMP,
    data_expirare DATETIME NOT NULL,
    activa BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (id_utilizator) REFERENCES utilizatori(id_utilizator) ON DELETE CASCADE,
    INDEX idx_token (token_sesiune),
    INDEX idx_expirare (data_expirare)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabelă pentru loguri de autentificare
CREATE TABLE IF NOT EXISTS log_autentificare (
    id_log INT AUTO_INCREMENT PRIMARY KEY,
    id_utilizator INT,
    tip_eveniment ENUM('login', 'logout', 'failed_login', 'register') NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    data_eveniment DATETIME DEFAULT CURRENT_TIMESTAMP,
    detalii TEXT,
    FOREIGN KEY (id_utilizator) REFERENCES utilizatori(id_utilizator) ON DELETE SET NULL,
    INDEX idx_eveniment (tip_eveniment, data_eveniment)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabelă pentru resetare parolă
CREATE TABLE IF NOT EXISTS resetare_parola (
    id_resetare INT AUTO_INCREMENT PRIMARY KEY,
    id_utilizator INT NOT NULL,
    token_resetare VARCHAR(255) UNIQUE NOT NULL,
    data_creare DATETIME DEFAULT CURRENT_TIMESTAMP,
    data_expirare DATETIME NOT NULL,
    folosit BOOLEAN DEFAULT FALSE,
    FOREIGN KEY (id_utilizator) REFERENCES utilizatori(id_utilizator) ON DELETE CASCADE,
    INDEX idx_token_reset (token_resetare)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;