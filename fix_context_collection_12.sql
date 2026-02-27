-- Creează un context specific pentru colecția 12
-- Aceasta va avea prioritate față de contextul generic (NULL)

INSERT INTO context_locatii 
(id_colectie, locatie, cutie, tip_context, obiecte_comune, obiecte_excluse, incredere, numar_exemple)
VALUES 
(12, 'camera', '1', 'general', '', '', 0.5, 0);

-- Dacă există deja, resetează excluderile
UPDATE context_locatii 
SET obiecte_excluse = '', 
    incredere = 0.5,
    ultima_actualizare = NOW()
WHERE id_colectie = 12 
  AND locatie = 'camera' 
  AND cutie = '1';