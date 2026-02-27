# Propunere Optimizare Sistem Google Vision - Scalabilitate

## Arhitectură Actuală (Problematică)
- Tabele per utilizator: `user_1_coordonate_vision`, `user_2_coordonate_vision`, etc.
- Date duplicate între tabele
- Sincronizare prin string matching O(n²)

## Arhitectură Propusă (Scalabilă)

### 1. Tabel Centralizat pentru Coordonate
```sql
CREATE TABLE vision_coordonate_centrale (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    collection_id INT NOT NULL,
    object_id INT NOT NULL,
    image_name VARCHAR(255) NOT NULL,
    detection_id VARCHAR(64) NOT NULL, -- Hash unic pentru deduplicare
    original_label VARCHAR(255) NOT NULL,
    translated_label VARCHAR(255),
    x DECIMAL(5,2) NOT NULL,
    y DECIMAL(5,2) NOT NULL,
    width DECIMAL(5,2) NOT NULL,
    height DECIMAL(5,2) NOT NULL,
    confidence DECIMAL(3,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_user_object (user_id, object_id),
    INDEX idx_collection_object (collection_id, object_id),
    INDEX idx_detection (detection_id),
    UNIQUE KEY unique_detection (user_id, object_id, image_name, detection_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 2. Tabel pentru Mapare Obiecte-Detecții
```sql
CREATE TABLE vision_object_mapping (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    object_id INT NOT NULL,
    detection_label VARCHAR(255) NOT NULL,
    manual_label VARCHAR(255),
    is_active BOOLEAN DEFAULT TRUE,
    
    INDEX idx_user_detection (user_id, detection_label),
    UNIQUE KEY unique_mapping (user_id, object_id, detection_label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 3. Avantaje ale Arhitecturii Propuse

#### Scalabilitate
- **1 tabel** în loc de mii de tabele
- Partitionare posibilă pe `user_id` pentru milioane de înregistrări
- Indexare eficientă pentru query-uri rapide

#### Performanță
- Query-uri O(log n) cu indexare adecvată
- JOIN-uri eficiente între tabele
- Posibilitate de cache la nivel de query

#### Deduplicare Inteligentă
```php
// Generare detection_id unic
$detection_id = md5($image_name . $original_label . $x . $y);

// Verificare rapidă duplicat
$sql = "INSERT IGNORE INTO vision_coordonate_centrale 
        (user_id, detection_id, ...) VALUES (?, ?, ...)";
```

#### Sincronizare Eficientă
```sql
-- Ștergere coordonate pentru obiecte șterse
DELETE vc FROM vision_coordonate_centrale vc
LEFT JOIN user_objects uo ON vc.object_id = uo.id
WHERE uo.id IS NULL AND vc.user_id = ?;

-- Actualizare status active/inactive
UPDATE vision_object_mapping 
SET is_active = FALSE 
WHERE object_id NOT IN (
    SELECT id FROM user_objects WHERE user_id = ?
);
```

### 4. Migrare Progresivă

#### Faza 1: Coexistență
- Păstrăm sistemul actual funcțional
- Implementăm noul sistem în paralel
- Scriem date în ambele sisteme

#### Faza 2: Migrare Date
```php
// Script migrare per utilizator
function migreazaDateUtilizator($user_id, $prefix) {
    $old_table = $prefix . 'coordonate_vision';
    
    $sql = "SELECT * FROM $old_table";
    $result = mysqli_query($conn, $sql);
    
    while ($row = mysqli_fetch_assoc($result)) {
        $coords = json_decode($row['coordonate_vizuale'], true);
        foreach ($coords as $coord) {
            // Insert în tabel centralizat
            insertCentralizedCoordinate($user_id, $row['id_obiect'], 
                                       $row['imagine'], $coord);
        }
    }
}
```

#### Faza 3: Tranziție
- Switchuire graduală citire către noul sistem
- Monitorizare performanță
- Rollback rapid dacă e necesar

### 5. API Optimizat

```php
class VisionCoordinateService {
    private $cache;
    
    public function getCoordinatesForObject($user_id, $object_id) {
        $cache_key = "coords_{$user_id}_{$object_id}";
        
        // Check cache first
        if ($cached = $this->cache->get($cache_key)) {
            return $cached;
        }
        
        // Efficient query with proper indexing
        $sql = "SELECT * FROM vision_coordonate_centrale 
                WHERE user_id = ? AND object_id = ? 
                AND is_active = TRUE";
        
        $result = $this->db->query($sql, [$user_id, $object_id]);
        
        // Cache for 5 minutes
        $this->cache->set($cache_key, $result, 300);
        
        return $result;
    }
    
    public function syncWithObjects($user_id, $object_id, $object_labels) {
        // Efficient batch update
        $sql = "UPDATE vision_object_mapping 
                SET is_active = CASE 
                    WHEN detection_label IN (?) THEN TRUE 
                    ELSE FALSE 
                END 
                WHERE user_id = ? AND object_id = ?";
        
        $this->db->query($sql, [$object_labels, $user_id, $object_id]);
    }
}
```

### 6. Beneficii Estimate

| Metrică | Sistem Actual | Sistem Optimizat | Îmbunătățire |
|---------|---------------|------------------|--------------|
| Tabele pentru 1000 users | 1000+ | 2 | 99.8% reducere |
| Timp query coordonate | O(n) | O(log n) | 10-100x mai rapid |
| Storage duplicat | ~30% | 0% | 30% economie |
| Timp sincronizare | O(n²) | O(n log n) | 100x mai rapid la 1000 obiecte |
| Scalare la 1M users | Imposibil | Fezabil | ✓ |

### 7. Pași de Implementare

1. **Săptămâna 1**: Creare tabele noi + indexuri
2. **Săptămâna 2**: Implementare API nou + teste
3. **Săptămâna 3**: Dual-write (scrie în ambele sisteme)
4. **Săptămâna 4**: Migrare date existente
5. **Săptămâna 5**: Switch citire către sistem nou
6. **Săptămâna 6**: Monitorizare + optimizări
7. **Luna 2**: Decomisionare sistem vechi

### 8. Considerații Suplimentare

#### Partitionare pentru Scale Masiv
```sql
ALTER TABLE vision_coordonate_centrale 
PARTITION BY HASH(user_id) PARTITIONS 100;
```

#### Arhivare Date Vechi
```sql
-- Mutare detecții mai vechi de 1 an în tabel arhivă
INSERT INTO vision_coordonate_archive 
SELECT * FROM vision_coordonate_centrale 
WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR);
```

#### Rate Limiting per User
```php
if ($this->rateLimiter->tooManyAttempts($user_id, 100)) {
    throw new TooManyRequestsException();
}
```

## Concluzie
Arhitectura propusă oferă:
- **Scalabilitate** la milioane de utilizatori
- **Performanță** cu query-uri 10-100x mai rapide
- **Consistență** datelor prin design
- **Costuri reduse** de întreținere și storage
- **Flexibilitate** pentru features viitoare