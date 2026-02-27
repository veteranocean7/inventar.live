<?php
// Script pentru a repara tabela context_locatii
session_start();
include 'config.php';

// Verifică autentificarea
if (file_exists('includes/auth_functions.php')) {
    require_once 'includes/auth_functions.php';
    $user = checkSession();
    if (!$user) {
        die("Neautentificat");
    }
} else {
    $user = getCurrentUser();
    if (!$user) {
        die("Neautentificat");
    }
}

echo "<h2>Verificare și reparare tabela context_locatii</h2>";
echo "<pre>";

// Conectare la BD centrală
$conn_central = getCentralDbConnection();
echo "✓ Conectat la BD centrală\n\n";

// 1. Verificăm structura actuală a tabelei
echo "=== STRUCTURA ACTUALĂ ===\n";
$result = mysqli_query($conn_central, "DESCRIBE context_locatii");
if ($result) {
    $columns = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $columns[] = $row['Field'];
        echo "- {$row['Field']} ({$row['Type']})\n";
    }
    
    // 2. Verificăm dacă lipsește coloana id_colectie
    if (!in_array('id_colectie', $columns)) {
        echo "\n❌ Coloana 'id_colectie' LIPSEȘTE!\n";
        echo "Adaug coloana...\n";
        
        $sql = "ALTER TABLE context_locatii 
                ADD COLUMN id_colectie INT DEFAULT NULL AFTER cutie,
                ADD INDEX idx_colectie (id_colectie)";
        
        if (mysqli_query($conn_central, $sql)) {
            echo "✅ Coloana 'id_colectie' a fost adăugată cu succes!\n";
        } else {
            echo "❌ Eroare la adăugarea coloanei: " . mysqli_error($conn_central) . "\n";
        }
    } else {
        echo "\n✅ Coloana 'id_colectie' există deja.\n";
    }
    
    // 3. Verificăm dacă lipsește coloana context_corectii (pentru istoric)
    echo "\n=== VERIFICARE TABELĂ context_corectii ===\n";
    $check = mysqli_query($conn_central, "SHOW TABLES LIKE 'context_corectii'");
    if (mysqli_num_rows($check) == 0) {
        echo "Tabela 'context_corectii' nu există. O creez...\n";
        
        $sql = "CREATE TABLE context_corectii (
            id INT AUTO_INCREMENT PRIMARY KEY,
            id_utilizator INT NOT NULL,
            locatie VARCHAR(255),
            cutie VARCHAR(255),
            obiect_original VARCHAR(255),
            obiect_corectat VARCHAR(255),
            actiune ENUM('adaugat', 'sters', 'modificat', 'reset') DEFAULT 'sters',
            data_corectie TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            procesat BOOLEAN DEFAULT FALSE,
            INDEX idx_procesat (procesat),
            INDEX idx_locatie_cutie (locatie, cutie),
            INDEX idx_utilizator (id_utilizator)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        if (mysqli_query($conn_central, $sql)) {
            echo "✅ Tabela 'context_corectii' a fost creată cu succes!\n";
        } else {
            echo "❌ Eroare la crearea tabelei: " . mysqli_error($conn_central) . "\n";
        }
    } else {
        echo "✅ Tabela 'context_corectii' există deja.\n";
    }
    
    // 4. Afișăm contextele existente
    echo "\n=== CONTEXTE EXISTENTE ===\n";
    $sql = "SELECT id, locatie, cutie, id_colectie, 
            LENGTH(obiecte_excluse) as len_excluse,
            LENGTH(obiecte_comune) as len_comune,
            incredere
            FROM context_locatii 
            ORDER BY locatie, cutie";
    $result = mysqli_query($conn_central, $sql);
    
    if ($result && mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            echo "ID {$row['id']}: {$row['locatie']} / {$row['cutie']}";
            echo " (Col: " . ($row['id_colectie'] ?: 'NULL') . ")";
            echo " - Excluse: {$row['len_excluse']} chars";
            echo " - Comune: {$row['len_comune']} chars";
            echo " - Încredere: {$row['incredere']}\n";
        }
    } else {
        echo "Nu există contexte salvate.\n";
    }
    
} else {
    echo "❌ Nu pot accesa tabela context_locatii: " . mysqli_error($conn_central) . "\n";
}

echo "\n=== VERIFICARE COMPLETĂ ===\n";
echo "Script executat cu succes!\n";
echo "</pre>";

mysqli_close($conn_central);
?>