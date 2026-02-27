-- =========================================================
-- Sistem Complet de Partajare pentru Inventar.live
-- Versiune: 2.0 - August 2025
-- =========================================================

-- Actualizare tabel partajari pentru a include nivelul de partajare
ALTER TABLE partajari 
ADD COLUMN IF NOT EXISTS nivel_partajare ENUM('familie', 'public') DEFAULT 'familie' AFTER tip_acces,
ADD COLUMN IF NOT EXISTS permite_imprumut BOOLEAN DEFAULT FALSE AFTER nivel_partajare,
ADD INDEX idx_nivel_partajare (nivel_partajare);

-- Tabel pentru colecții publice (vizibile pentru căutare)
CREATE TABLE IF NOT EXISTS colectii_publice (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_colectie INT NOT NULL,
    id_utilizator INT NOT NULL,
    titlu_public VARCHAR(255) NOT NULL,
    descriere TEXT,
    cuvinte_cheie TEXT,
    permite_imprumut BOOLEAN DEFAULT TRUE,
    data_publicare TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ultima_actualizare TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    vizualizari INT DEFAULT 0,
    activ BOOLEAN DEFAULT TRUE,
    
    FOREIGN KEY (id_colectie) REFERENCES colectii_utilizatori(id_colectie) ON DELETE CASCADE,
    FOREIGN KEY (id_utilizator) REFERENCES utilizatori(id_utilizator) ON DELETE CASCADE,
    UNIQUE KEY unique_colectie_public (id_colectie),
    INDEX idx_cuvinte_cheie (cuvinte_cheie(255)),
    INDEX idx_activ (activ)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabel pentru cereri de împrumut
CREATE TABLE IF NOT EXISTS cereri_imprumut (
    id_cerere INT AUTO_INCREMENT PRIMARY KEY,
    id_solicitant INT NOT NULL,
    id_proprietar INT NOT NULL,
    id_colectie INT NOT NULL,
    id_obiect INT NOT NULL,
    denumire_obiect VARCHAR(255) NOT NULL,
    mesaj_cerere TEXT,
    data_cerere TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    data_raspuns TIMESTAMP NULL,
    status_cerere ENUM('in_asteptare', 'aprobata', 'respinsa', 'anulata', 'returnata') DEFAULT 'in_asteptare',
    mesaj_raspuns TEXT,
    data_estimata_returnare DATE NULL,
    data_efectiva_returnare DATE NULL,
    notite_returnare TEXT,
    
    FOREIGN KEY (id_solicitant) REFERENCES utilizatori(id_utilizator) ON DELETE CASCADE,
    FOREIGN KEY (id_proprietar) REFERENCES utilizatori(id_utilizator) ON DELETE CASCADE,
    FOREIGN KEY (id_colectie) REFERENCES colectii_utilizatori(id_colectie) ON DELETE CASCADE,
    
    INDEX idx_status (status_cerere),
    INDEX idx_solicitant (id_solicitant),
    INDEX idx_proprietar (id_proprietar),
    INDEX idx_data_cerere (data_cerere)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabel pentru mesaje între utilizatori (comunicare anonimă)
CREATE TABLE IF NOT EXISTS mesaje_utilizatori (
    id_mesaj INT AUTO_INCREMENT PRIMARY KEY,
    id_cerere_imprumut INT NOT NULL,
    id_expeditor INT NOT NULL,
    id_destinatar INT NOT NULL,
    mesaj TEXT NOT NULL,
    data_trimitere TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    citit BOOLEAN DEFAULT FALSE,
    data_citire TIMESTAMP NULL,
    
    FOREIGN KEY (id_cerere_imprumut) REFERENCES cereri_imprumut(id_cerere) ON DELETE CASCADE,
    FOREIGN KEY (id_expeditor) REFERENCES utilizatori(id_utilizator) ON DELETE CASCADE,
    FOREIGN KEY (id_destinatar) REFERENCES utilizatori(id_utilizator) ON DELETE CASCADE,
    
    INDEX idx_cerere (id_cerere_imprumut),
    INDEX idx_destinatar_citit (id_destinatar, citit),
    INDEX idx_data_trimitere (data_trimitere)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Extindere tabel notificări pentru a include tipuri noi
ALTER TABLE notificari_partajare
ADD COLUMN IF NOT EXISTS tip_notificare ENUM('partajare', 'cerere_imprumut', 'raspuns_cerere', 'mesaj_nou', 'reminder_returnare') DEFAULT 'partajare' AFTER id_notificare,
ADD COLUMN IF NOT EXISTS id_referinta INT NULL COMMENT 'ID-ul cererii sau mesajului asociat' AFTER tip_notificare,
ADD INDEX idx_tip_notificare (tip_notificare);

-- Tabel pentru istoricul împrumuturilor
CREATE TABLE IF NOT EXISTS istoric_imprumuturi (
    id_istoric INT AUTO_INCREMENT PRIMARY KEY,
    id_cerere INT NOT NULL,
    id_solicitant INT NOT NULL,
    id_proprietar INT NOT NULL,
    denumire_obiect VARCHAR(255) NOT NULL,
    data_imprumut DATE NOT NULL,
    data_returnare DATE NULL,
    stare_returnare ENUM('excelenta', 'buna', 'uzata', 'deteriorata') NULL,
    observatii TEXT,
    rating_solicitant TINYINT NULL CHECK (rating_solicitant >= 1 AND rating_solicitant <= 5),
    rating_proprietar TINYINT NULL CHECK (rating_proprietar >= 1 AND rating_proprietar <= 5),
    
    FOREIGN KEY (id_cerere) REFERENCES cereri_imprumut(id_cerere) ON DELETE CASCADE,
    FOREIGN KEY (id_solicitant) REFERENCES utilizatori(id_utilizator) ON DELETE CASCADE,
    FOREIGN KEY (id_proprietar) REFERENCES utilizatori(id_utilizator) ON DELETE CASCADE,
    
    INDEX idx_solicitant_istoric (id_solicitant),
    INDEX idx_proprietar_istoric (id_proprietar),
    INDEX idx_data_imprumut (data_imprumut)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabel pentru setări de confidențialitate
CREATE TABLE IF NOT EXISTS setari_confidentialitate (
    id_utilizator INT PRIMARY KEY,
    permite_cereri_imprumut BOOLEAN DEFAULT TRUE,
    afiseaza_nume_complet BOOLEAN DEFAULT FALSE,
    permite_mesaje BOOLEAN DEFAULT TRUE,
    notificari_email BOOLEAN DEFAULT TRUE,
    vizibilitate_profil ENUM('privat', 'comunitate', 'public') DEFAULT 'comunitate',
    
    FOREIGN KEY (id_utilizator) REFERENCES utilizatori(id_utilizator) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- View pentru căutare în colecții publice (fără date personale)
CREATE OR REPLACE VIEW cautare_colectii_publice AS
SELECT 
    cp.id_colectie,
    cp.titlu_public,
    cp.descriere,
    cp.cuvinte_cheie,
    cp.permite_imprumut,
    cp.vizualizari,
    -- Nu expunem date personale, doar un ID anonim
    CONCAT('Utilizator_', LPAD(cp.id_utilizator, 6, '0')) as proprietar_anonim,
    cu.nume_colectie,
    cu.db_name,
    cu.prefix_tabele
FROM colectii_publice cp
JOIN colectii_utilizatori cu ON cp.id_colectie = cu.id_colectie
WHERE cp.activ = TRUE;

-- Funcție pentru verificare acces la colecție
DELIMITER $$
CREATE FUNCTION IF NOT EXISTS verifica_acces_colectie(
    p_id_utilizator INT,
    p_id_colectie INT
) RETURNS VARCHAR(20)
DETERMINISTIC
READS SQL DATA
BEGIN
    DECLARE v_acces VARCHAR(20) DEFAULT 'fara_acces';
    DECLARE v_proprietar INT;
    DECLARE v_nivel VARCHAR(20);
    DECLARE v_tip_acces VARCHAR(20);
    
    -- Verifică dacă este proprietar
    SELECT id_utilizator INTO v_proprietar
    FROM colectii_utilizatori
    WHERE id_colectie = p_id_colectie;
    
    IF v_proprietar = p_id_utilizator THEN
        RETURN 'proprietar';
    END IF;
    
    -- Verifică partajare directă
    SELECT nivel_partajare, tip_acces INTO v_nivel, v_tip_acces
    FROM partajari
    WHERE id_colectie = p_id_colectie 
    AND id_utilizator_partajat = p_id_utilizator
    AND activ = TRUE
    LIMIT 1;
    
    IF v_nivel IS NOT NULL THEN
        IF v_nivel = 'familie' THEN
            RETURN v_tip_acces; -- 'citire' sau 'scriere'
        ELSE
            RETURN 'cautare'; -- nivel public, doar căutare
        END IF;
    END IF;
    
    -- Verifică dacă colecția este publică
    SELECT COUNT(*) INTO @is_public
    FROM colectii_publice
    WHERE id_colectie = p_id_colectie AND activ = TRUE;
    
    IF @is_public > 0 THEN
        RETURN 'cautare';
    END IF;
    
    RETURN v_acces;
END$$
DELIMITER ;

-- Procedură pentru creare notificare
DELIMITER $$
CREATE PROCEDURE IF NOT EXISTS creeaza_notificare(
    IN p_tip VARCHAR(20),
    IN p_id_utilizator INT,
    IN p_mesaj TEXT,
    IN p_id_referinta INT
)
BEGIN
    INSERT INTO notificari_partajare (
        tip_notificare,
        id_utilizator,
        mesaj,
        id_referinta,
        citita
    ) VALUES (
        p_tip,
        p_id_utilizator,
        p_mesaj,
        p_id_referinta,
        FALSE
    );
    
    -- Trimite email dacă utilizatorul are activată opțiunea
    SELECT notificari_email INTO @send_email
    FROM setari_confidentialitate
    WHERE id_utilizator = p_id_utilizator;
    
    IF @send_email = TRUE THEN
        -- Aici ar trebui să apelăm o funcție PHP pentru trimitere email
        -- Momentan doar marcăm pentru procesare ulterioară
        INSERT INTO email_queue (id_utilizator, tip_email, continut, status)
        VALUES (p_id_utilizator, p_tip, p_mesaj, 'pending');
    END IF;
END$$
DELIMITER ;

-- Inițializare setări confidențialitate pentru utilizatorii existenți
INSERT IGNORE INTO setari_confidentialitate (id_utilizator)
SELECT id_utilizator FROM utilizatori;

-- Adăugare câmpuri pentru statistici utilizatori
ALTER TABLE utilizatori
ADD COLUMN IF NOT EXISTS total_imprumuturi_date INT DEFAULT 0,
ADD COLUMN IF NOT EXISTS total_imprumuturi_primite INT DEFAULT 0,
ADD COLUMN IF NOT EXISTS rating_mediu DECIMAL(3,2) DEFAULT NULL;

-- Tabel pentru coadă email (procesare asincronă)
CREATE TABLE IF NOT EXISTS email_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_utilizator INT NOT NULL,
    tip_email VARCHAR(50),
    continut TEXT,
    status ENUM('pending', 'sent', 'failed') DEFAULT 'pending',
    data_creare TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    data_procesare TIMESTAMP NULL,
    
    FOREIGN KEY (id_utilizator) REFERENCES utilizatori(id_utilizator) ON DELETE CASCADE,
    INDEX idx_status_email (status),
    INDEX idx_data_creare (data_creare)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;