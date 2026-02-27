-- =====================================================
-- Claude Vision Queue - Tabel pentru procesare automată
-- inventar.live
-- Data: 27 Februarie 2026
-- =====================================================

-- Tabel principal pentru coada de procesare imagini
-- Se adaugă în baza de date CENTRALĂ (inventar_central)

CREATE TABLE IF NOT EXISTS procesare_imagini_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,

    -- Referințe
    id_utilizator INT NOT NULL,
    id_colectie INT NOT NULL,
    id_obiect INT NULL,                    -- NULL dacă obiectul nu e creat încă

    -- Imagine
    cale_imagine VARCHAR(500) NOT NULL,
    nume_original VARCHAR(255),

    -- Context pentru identificare mai bună
    locatie VARCHAR(255),                   -- Ex: "Atelier", "Garaj"
    cutie VARCHAR(255),                     -- Ex: "Cutie unelte vechi"
    context_manual TEXT,                    -- Context adăugat de user

    -- Status procesare
    status ENUM('pending', 'processing', 'completed', 'failed', 'review') DEFAULT 'pending',
    prioritate TINYINT DEFAULT 5,           -- 1=urgent, 5=normal, 10=low

    -- Rezultate
    rezultat_json LONGTEXT,                 -- JSON cu obiectele identificate
    numar_obiecte_gasite INT DEFAULT 0,
    eroare_mesaj TEXT,

    -- Retry logic
    retry_count INT DEFAULT 0,
    max_retries INT DEFAULT 3,

    -- Timestamps
    data_adaugare DATETIME DEFAULT CURRENT_TIMESTAMP,
    data_procesare DATETIME NULL,
    data_completare DATETIME NULL,

    -- Cost tracking
    tokens_utilizate INT DEFAULT 0,
    cost_estimat DECIMAL(10,6) DEFAULT 0,

    -- Indecși pentru performanță
    INDEX idx_status (status),
    INDEX idx_prioritate_status (prioritate, status),
    INDEX idx_utilizator (id_utilizator),
    INDEX idx_data_adaugare (data_adaugare),
    INDEX idx_procesare (status, prioritate, data_adaugare)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Tabel pentru obiectele identificate (rezultate)
-- Se adaugă în baza de date PER-USER (inventar_user_{id})
-- =====================================================

-- NOTĂ: Acest tabel trebuie creat cu prefix dinamic
-- Template pentru creare în PHP:

/*
CREATE TABLE IF NOT EXISTS {prefix}obiecte_identificate (
    id INT AUTO_INCREMENT PRIMARY KEY,

    -- Referințe
    id_queue INT NOT NULL,                  -- FK către procesare_imagini_queue
    id_obiect INT NULL,                     -- FK către {prefix}obiecte (după salvare)

    -- Identificare
    denumire VARCHAR(255) NOT NULL,
    denumire_scurta VARCHAR(100),
    descriere TEXT,
    categorie VARCHAR(100),
    stare VARCHAR(50),                      -- Nouă, Bună, Uzată, Deteriorată
    certitudine VARCHAR(50),                -- Sigur, Probabil, Posibil

    -- Căutare
    cuvinte_cheie TEXT,                     -- JSON array

    -- Poziție în imagine
    pozitie_descriere VARCHAR(100),         -- Ex: "stânga-sus"
    pozitie_x_percent DECIMAL(5,2),         -- Poziție X ca procent (0-100)
    pozitie_y_percent DECIMAL(5,2),         -- Poziție Y ca procent (0-100)

    -- Status
    confirmat_user BOOLEAN DEFAULT FALSE,   -- User a confirmat identificarea
    corectat_user BOOLEAN DEFAULT FALSE,    -- User a corectat denumirea
    denumire_originala VARCHAR(255),        -- Păstrăm ce a zis AI-ul inițial

    -- Timestamps
    data_identificare DATETIME DEFAULT CURRENT_TIMESTAMP,
    data_confirmare DATETIME NULL,

    INDEX idx_id_queue (id_queue),
    INDEX idx_categorie (categorie),
    INDEX idx_confirmat (confirmat_user)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
*/

-- =====================================================
-- Tabel pentru statistici și învățare
-- =====================================================

CREATE TABLE IF NOT EXISTS claude_vision_stats (
    id INT AUTO_INCREMENT PRIMARY KEY,

    -- Perioada
    data_stat DATE NOT NULL,

    -- Statistici procesare
    imagini_procesate INT DEFAULT 0,
    obiecte_identificate INT DEFAULT 0,
    obiecte_confirmate INT DEFAULT 0,
    obiecte_corectate INT DEFAULT 0,

    -- Acuratețe
    rata_confirmare DECIMAL(5,2),           -- % obiecte confirmate fără corecție
    rata_corectare DECIMAL(5,2),            -- % obiecte care au necesitat corecție

    -- Costuri
    tokens_total INT DEFAULT 0,
    cost_total_usd DECIMAL(10,4) DEFAULT 0,

    -- Performanță
    timp_mediu_procesare_sec DECIMAL(10,2),

    UNIQUE KEY unique_data (data_stat)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Tabel pentru corecții (learning din feedback user)
-- =====================================================

CREATE TABLE IF NOT EXISTS claude_vision_corectii (
    id INT AUTO_INCREMENT PRIMARY KEY,

    -- Ce a identificat AI-ul
    identificare_ai VARCHAR(255) NOT NULL,

    -- Ce a corectat user-ul
    corectie_user VARCHAR(255) NOT NULL,

    -- Context
    categorie VARCHAR(100),
    cuvinte_cheie_asociate TEXT,

    -- Frecvență
    numar_aparitii INT DEFAULT 1,

    -- Timestamps
    data_prima_corectie DATETIME DEFAULT CURRENT_TIMESTAMP,
    data_ultima_corectie DATETIME DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY unique_corectie (identificare_ai, corectie_user),
    INDEX idx_ai (identificare_ai)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- View pentru monitorizare queue
-- =====================================================

CREATE OR REPLACE VIEW v_queue_status AS
SELECT
    status,
    COUNT(*) as numar,
    AVG(retry_count) as medie_retries,
    MIN(data_adaugare) as cea_mai_veche,
    MAX(data_adaugare) as cea_mai_recenta
FROM procesare_imagini_queue
GROUP BY status;

-- =====================================================
-- Procedură pentru cleanup periodic
-- =====================================================

DELIMITER //

CREATE PROCEDURE IF NOT EXISTS cleanup_queue_vechi(IN zile_pastrare INT)
BEGIN
    -- Șterge înregistrările procesate mai vechi de X zile
    DELETE FROM procesare_imagini_queue
    WHERE status IN ('completed', 'failed')
    AND data_completare < DATE_SUB(NOW(), INTERVAL zile_pastrare DAY);

    -- Returnează numărul de înregistrări șterse
    SELECT ROW_COUNT() as inregistrari_sterse;
END //

DELIMITER ;

-- =====================================================
-- INSTRUCȚIUNI DE APLICARE
-- =====================================================
--
-- 1. Aplică acest SQL în phpMyAdmin pe baza de date inventar_central
--
-- 2. Pentru cleanup periodic, rulează:
--    CALL cleanup_queue_vechi(30);  -- Păstrează 30 zile
--
-- 3. Pentru monitorizare:
--    SELECT * FROM v_queue_status;
--
-- =====================================================
