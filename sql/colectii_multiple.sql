-- Script pentru implementarea sistemului de colecții multiple
-- Inventar.live - 5 August 2025
-- Se rulează în baza de date inventar_central

-- 1. Tabel pentru colecțiile utilizatorilor
CREATE TABLE IF NOT EXISTS colectii_utilizatori (
    id_colectie INT AUTO_INCREMENT PRIMARY KEY,
    id_utilizator INT NOT NULL,
    nume_colectie VARCHAR(255) NOT NULL,
    prefix_tabele VARCHAR(50) UNIQUE NOT NULL,
    este_principala BOOLEAN DEFAULT 0,
    este_publica BOOLEAN DEFAULT 0,
    data_creare TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    data_modificare TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (id_utilizator) REFERENCES utilizatori(id_utilizator) ON DELETE CASCADE,
    INDEX idx_utilizator (id_utilizator),
    INDEX idx_publica (este_publica)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Actualizare tabel partajări pentru a referi colecții în loc de utilizatori
DROP TABLE IF EXISTS partajari;
CREATE TABLE partajari (
    id_partajare INT AUTO_INCREMENT PRIMARY KEY,
    id_colectie INT NOT NULL,
    id_utilizator_partajat INT NOT NULL,
    tip_acces ENUM('citire','scriere') DEFAULT 'citire',
    activ BOOLEAN DEFAULT 1,
    data_partajare TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    data_modificare TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (id_colectie) REFERENCES colectii_utilizatori(id_colectie) ON DELETE CASCADE,
    FOREIGN KEY (id_utilizator_partajat) REFERENCES utilizatori(id_utilizator) ON DELETE CASCADE,
    UNIQUE KEY unique_partajare (id_colectie, id_utilizator_partajat),
    INDEX idx_colectie (id_colectie),
    INDEX idx_partajat (id_utilizator_partajat)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Tabel pentru obiectele marcate pentru partajare (cutia virtuală)
CREATE TABLE IF NOT EXISTS obiecte_partajate (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_colectie INT NOT NULL,
    id_obiect_original INT NOT NULL,
    nume_cutie VARCHAR(255),
    data_marcare TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_colectie) REFERENCES colectii_utilizatori(id_colectie) ON DELETE CASCADE,
    UNIQUE KEY unique_obiect (id_colectie, id_obiect_original),
    INDEX idx_colectie (id_colectie)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Tabel pentru notificări despre activitate în colecții partajate
CREATE TABLE IF NOT EXISTS notificari_partajare (
    id_notificare INT AUTO_INCREMENT PRIMARY KEY,
    id_colectie INT NOT NULL,
    id_utilizator_destinatar INT NOT NULL,
    tip_notificare ENUM('obiect_adaugat','obiect_modificat','obiect_sters','acces_acordat','acces_revocat') NOT NULL,
    id_obiect INT NULL,
    mesaj TEXT,
    citita BOOLEAN DEFAULT 0,
    data_notificare TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_colectie) REFERENCES colectii_utilizatori(id_colectie) ON DELETE CASCADE,
    FOREIGN KEY (id_utilizator_destinatar) REFERENCES utilizatori(id_utilizator) ON DELETE CASCADE,
    INDEX idx_destinatar_necitite (id_utilizator_destinatar, citita),
    INDEX idx_colectie (id_colectie)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Actualizare tabel utilizatori pentru a lega de colecția principală
ALTER TABLE utilizatori 
ADD COLUMN IF NOT EXISTS id_colectie_principala INT,
ADD FOREIGN KEY (id_colectie_principala) REFERENCES colectii_utilizatori(id_colectie);

-- 6. Migrare date existente - creează colecții principale pentru utilizatorii existenți
INSERT INTO colectii_utilizatori (id_utilizator, nume_colectie, prefix_tabele, este_principala)
SELECT 
    u.id_utilizator,
    CONCAT('Inventarul lui ', u.prenume),
    u.prefix_tabele,
    1
FROM utilizatori u
WHERE u.prefix_tabele IS NOT NULL
AND NOT EXISTS (
    SELECT 1 FROM colectii_utilizatori c 
    WHERE c.id_utilizator = u.id_utilizator 
    AND c.este_principala = 1
);

-- 7. Actualizare referințe la colecția principală
UPDATE utilizatori u
SET id_colectie_principala = (
    SELECT id_colectie 
    FROM colectii_utilizatori c 
    WHERE c.id_utilizator = u.id_utilizator 
    AND c.este_principala = 1
    LIMIT 1
)
WHERE u.id_colectie_principala IS NULL;

-- 8. Pentru tabelele existente, adaugă câmpul partajat (se va rula manual pentru fiecare user)
-- Exemplu pentru user_1:
-- ALTER TABLE user_1_obiecte ADD COLUMN IF NOT EXISTS partajat BOOLEAN DEFAULT 0 AFTER imagine_obiect;
-- ALTER TABLE user_1_obiecte ADD INDEX IF NOT EXISTS idx_partajat (partajat);

-- Notă: Scriptul pentru adăugarea câmpului 'partajat' în tabelele existente
-- va fi generat dinamic în PHP bazat pe colecțiile existente