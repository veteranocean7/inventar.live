-- Script pentru adăugarea tabelelor centrale în baza de date existentă
-- Folosește baza de date existentă în loc să creeze una nouă

USE inventar_atelier;

-- Adaugă prefix "central_" pentru a evita conflicte

-- Tabelă utilizatori
CREATE TABLE IF NOT EXISTS central_utilizatori (
    id_utilizator INT AUTO_INCREMENT PRIMARY KEY,
    nume VARCHAR(100) NOT NULL,
    prenume VARCHAR(100) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    telefon VARCHAR(20),
    parola_hash VARCHAR(255) NOT NULL,
    data_inregistrare DATETIME DEFAULT CURRENT_TIMESTAMP,
    data_ultima_logare DATETIME,
    activ BOOLEAN DEFAULT TRUE,
    db_name VARCHAR(100),
    table_prefix VARCHAR(50), -- în loc de db_name separat
    INDEX idx_email (email),
    INDEX idx_telefon (telefon)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabelă pentru sesiuni
CREATE TABLE IF NOT EXISTS central_sesiuni (
    id_sesiune INT AUTO_INCREMENT PRIMARY KEY,
    id_utilizator INT NOT NULL,
    token_sesiune VARCHAR(255) UNIQUE NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    data_creare DATETIME DEFAULT CURRENT_TIMESTAMP,
    data_expirare DATETIME NOT NULL,
    activa BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (id_utilizator) REFERENCES central_utilizatori(id_utilizator) ON DELETE CASCADE,
    INDEX idx_token (token_sesiune),
    INDEX idx_expirare (data_expirare)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabelă pentru loguri
CREATE TABLE IF NOT EXISTS central_log_autentificare (
    id_log INT AUTO_INCREMENT PRIMARY KEY,
    id_utilizator INT,
    tip_eveniment ENUM('login', 'logout', 'failed_login', 'register') NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    data_eveniment DATETIME DEFAULT CURRENT_TIMESTAMP,
    detalii TEXT,
    FOREIGN KEY (id_utilizator) REFERENCES central_utilizatori(id_utilizator) ON DELETE SET NULL,
    INDEX idx_eveniment (tip_eveniment, data_eveniment)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migrează utilizatorul existent
INSERT INTO central_utilizatori (nume, prenume, email, telefon, parola_hash, table_prefix) 
VALUES ('Utilizator', 'Test', 'test@inventar.live', '0700000000', '$2y$10$YourHashHere', 'user1_')
ON DUPLICATE KEY UPDATE email=email;