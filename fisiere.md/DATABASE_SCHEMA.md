# Schema Bază de Date - Inventar.live

## Structura Tabelelor

### 1. Tabela `obiecte`
Tabela principală care stochează toate informațiile despre obiectele din inventar.

```sql
CREATE TABLE obiecte (
    id_obiect INT AUTO_INCREMENT PRIMARY KEY,
    denumire_obiect TEXT,              -- Ex: "creion (1), pix (2), marker (3)"
    cantitate_obiect TEXT,             -- Ex: "2, 5, 1" - cantități corespunzătoare
    cutie VARCHAR(255),                -- Numele cutiei
    locatie VARCHAR(255),              -- Locația fizică
    categorie TEXT,                    -- Categorii (separate prin virgulă)
    eticheta TEXT,                     -- Etichete descriptive
    descriere_categorie TEXT,          -- Descrieri pentru categorii
    eticheta_obiect TEXT,              -- Culori pentru fiecare obiect (format: "#hex;#hex")
    imagine TEXT,                      -- Imagini cutie (separate prin virgulă)
    imagine_obiect TEXT,               -- Imagini decupate obiecte
    data_adaugare TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Indecși pentru performanță
    INDEX idx_cutie_locatie (cutie, locatie),
    INDEX idx_categorie (categorie(255))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 2. Tabela `detectii_obiecte`
Tracking pentru sursa fiecărui obiect detectat (manual sau automat).

```sql
CREATE TABLE detectii_obiecte (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_obiect INT NOT NULL,            -- Referință către obiecte.id_obiect
    denumire VARCHAR(255) NOT NULL,    -- Denumirea obiectului (fără index)
    sursa ENUM('manual', 'google_vision') DEFAULT 'manual',
    data_detectie TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Constrângeri
    FOREIGN KEY (id_obiect) REFERENCES obiecte(id_obiect) ON DELETE CASCADE,
    
    -- Indecși
    INDEX idx_obiect_denumire (id_obiect, denumire),
    INDEX idx_sursa (sursa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## Relații între Tabele

```
obiecte (1) -----> (N) detectii_obiecte
   |
   +-- id_obiect (PK) <---- id_obiect (FK)
```

## Convenții de Stocare

### Format Denumiri Obiecte:
- Stocare: `"creion (1), pix (2), marker (3)"`
- Fiecare obiect are index între paranteze
- Indexul reprezintă poziția în imagine

### Format Cantități:
- Stocare: `"2, 5, 1"`
- Ordine sincronizată cu denumirile
- Valoare implicită: 1

### Format Imagini:
- Stocare: `"img1.jpg, img2.png, img3.jpg"`
- Separare prin virgulă
- Căi relative la directorul de upload

### Format Culori Etichete:
- Stocare: `"#ff0000;#00ff00;#0000ff"`
- Separare prin punct și virgulă
- Ordine sincronizată cu obiectele

## Interogări Comune

### Obține toate obiectele dintr-o cutie:
```sql
SELECT * FROM obiecte 
WHERE cutie = 'Cutie 1' AND locatie = 'Garaj'
ORDER BY id_obiect DESC;
```

### Găsește obiecte detectate automat:
```sql
SELECT o.*, d.sursa 
FROM obiecte o
JOIN detectii_obiecte d ON o.id_obiect = d.id_obiect
WHERE d.sursa = 'google_vision';
```

### Statistici pe categorii:
```sql
SELECT categorie, COUNT(*) as total 
FROM obiecte 
WHERE categorie IS NOT NULL 
GROUP BY categorie;
```

### Caută obiecte după nume:
```sql
SELECT * FROM obiecte 
WHERE LOWER(denumire_obiect) LIKE '%creion%'
OR LOWER(eticheta) LIKE '%creion%';
```

## Maintenance și Optimizare

### Curățare date orfane:
```sql
-- Șterge detecții pentru obiecte inexistente
DELETE d FROM detectii_obiecte d
LEFT JOIN obiecte o ON d.id_obiect = o.id_obiect
WHERE o.id_obiect IS NULL;
```

### Verificare integritate:
```sql
-- Verifică sincronizare denumiri-cantități
SELECT id_obiect, 
       LENGTH(denumire_obiect) - LENGTH(REPLACE(denumire_obiect, ',', '')) + 1 as nr_obiecte,
       LENGTH(cantitate_obiect) - LENGTH(REPLACE(cantitate_obiect, ',', '')) + 1 as nr_cantitati
FROM obiecte
HAVING nr_obiecte != nr_cantitati;
```

### Backup recomandat:
```bash
# Backup zilnic
mysqldump -u user -p inventar_live > backup_$(date +%Y%m%d).sql

# Backup doar structură
mysqldump -u user -p --no-data inventar_live > schema.sql
```

## Note de Performanță

1. **Indecși**: Optimizați pentru căutări frecvente pe cutie/locație
2. **TEXT vs VARCHAR**: TEXT pentru câmpuri cu lungime variabilă mare
3. **utf8mb4**: Suport complet Unicode (inclusiv emoji)
4. **InnoDB**: Suport tranzacții și foreign keys

---
*Schema versiune 2.0 - Actualizat 29 iulie 2025*