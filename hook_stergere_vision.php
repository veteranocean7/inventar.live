<?php
/**
 * Hook pentru capturarea ștergerilor de obiecte Vision
 * Se integrează cu actualizeaza_obiect.php pentru învățare automată
 */

function inregistreazaStergereVision($conn, $conn_central, $id_obiect, $denumire_stearsa, $locatie, $cutie, $user_id, $table_prefix, $id_colectie = null) {
    // Curățăm denumirea de index
    $denumire_curata = preg_replace('/\s*\(\d+\)\s*/', '', trim($denumire_stearsa));
    
    // Colectăm mesaje de debug pentru JavaScript
    $debug_messages = [];
    $debug_messages[] = "Analizez ștergere: '$denumire_stearsa' -> '$denumire_curata'";
    $debug_messages[] = "Locație: '$locatie', Cutie: '$cutie', Table: '{$table_prefix}obiecte'";
    
    // SIMPLU - verificăm DOAR culoarea #ff6600 în tabela obiecte
    $este_vision = false;
    
    $sql_check = "SELECT eticheta_obiect, denumire_obiect FROM `{$table_prefix}obiecte` 
                  WHERE id_obiect = ?";
    $stmt = mysqli_prepare($conn, $sql_check);
    mysqli_stmt_bind_param($stmt, "i", $id_obiect);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    
    if ($row) {
        error_log("[Hook Vision DEBUG] Etichete găsite: " . substr($row['eticheta_obiect'], 0, 200));
        if (strpos($row['eticheta_obiect'], '#ff6600') !== false) {
            $este_vision = true;
            error_log("[Hook Vision DEBUG] ✓ CONFIRMAT ca obiect Vision (#ff6600 găsit)");
        } else {
            error_log("[Hook Vision DEBUG] ✗ NU e Vision (lipsește #ff6600)");
        }
    } else {
        error_log("[Hook Vision DEBUG] ✗ Nu găsesc obiectul ID $id_obiect în BD");
    }
    mysqli_stmt_close($stmt);
    
    // Dacă e obiect Vision, îl procesăm
    if ($este_vision) {
        // Era obiect Vision, îl adăugăm în excluderi
        error_log("[Hook Vision] PROCESEZ excludere: '$denumire_curata' din $locatie/$cutie");
        
        // 1. Înregistrăm în context_corectii pentru istoric
        $sql_corectie = "INSERT INTO context_corectii 
                        (id_utilizator, locatie, cutie, obiect_original, obiect_corectat, actiune, data_corectie) 
                        VALUES (?, ?, ?, ?, '', 'sters', NOW())";
        $stmt_corectie = mysqli_prepare($conn_central, $sql_corectie);
        mysqli_stmt_bind_param($stmt_corectie, "isss", $user_id, $locatie, $cutie, $denumire_curata);
        mysqli_stmt_execute($stmt_corectie);
        mysqli_stmt_close($stmt_corectie);
        
        // 2. Actualizăm direct context_locatii pentru efect imediat
        actualizareContextExcluderi($conn_central, $locatie, $cutie, $denumire_curata, $id_colectie);
        
        // 3. Nu mai ștergem din detectii_obiecte că poate nici nu există tabela
        
        error_log("[Hook Vision] Context actualizat pentru excludere: '$denumire_curata'");
        return true;
    }
    
    mysqli_stmt_close($stmt);
    return false;
}

function actualizareContextExcluderi($conn_central, $locatie, $cutie, $obiect_exclus, $id_colectie = null) {
    // Convertim la lowercase pentru consistență
    $obiect_exclus = strtolower(trim($obiect_exclus));
    
    error_log("[Context Update] Adaug '$obiect_exclus' la excluderi pentru $locatie/$cutie (colectie: $id_colectie)");
    
    // Verificăm dacă există context pentru această colecție SAU generic
    // Prioritizăm contextul specific colecției
    $sql_check = "SELECT id, obiecte_excluse, obiecte_comune, id_colectie FROM context_locatii 
                  WHERE locatie = ? AND cutie = ? 
                  AND (id_colectie = ? OR (id_colectie IS NULL AND ? IS NULL))
                  ORDER BY (id_colectie IS NOT NULL) DESC
                  LIMIT 1";
    $stmt = mysqli_prepare($conn_central, $sql_check);
    mysqli_stmt_bind_param($stmt, "ssii", $locatie, $cutie, $id_colectie, $id_colectie);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    
    // Nu mai verificăm contextul generic separat - l-am inclus în query-ul principal
    
    if ($row) {
        // Context există, actualizăm
        error_log("[Context Update] Context găsit ID: " . $row['id'] . ", excluderi actuale: " . $row['obiecte_excluse']);
        $obiecte_excluse_array = !empty($row['obiecte_excluse']) 
            ? array_map('trim', explode(',', $row['obiecte_excluse'])) 
            : [];
        
        // Adăugăm dacă nu există deja
        if (!in_array($obiect_exclus, $obiecte_excluse_array)) {
            $obiecte_excluse_array[] = $obiect_exclus;
            $obiecte_excluse_nou = implode(',', $obiecte_excluse_array);
            
            // Îl eliminăm și din obiecte_comune dacă era acolo
            $obiecte_comune_array = !empty($row['obiecte_comune']) 
                ? array_map('trim', explode(',', $row['obiecte_comune'])) 
                : [];
            $obiecte_comune_array = array_diff($obiecte_comune_array, [$obiect_exclus]);
            $obiecte_comune_nou = implode(',', $obiecte_comune_array);
            
            $sql_update = "UPDATE context_locatii 
                          SET obiecte_excluse = ?, 
                              obiecte_comune = ?,
                              incredere = GREATEST(0.3, incredere - 0.05),
                              ultima_actualizare = NOW()
                          WHERE id = ?";
            $stmt_update = mysqli_prepare($conn_central, $sql_update);
            mysqli_stmt_bind_param($stmt_update, "ssi", 
                $obiecte_excluse_nou, 
                $obiecte_comune_nou,
                $row['id']
            );
            mysqli_stmt_execute($stmt_update);
            mysqli_stmt_close($stmt_update);
            
            error_log("[Context Update] Adăugat '$obiect_exclus' în excluderi pentru $locatie/$cutie");
        }
    } else {
        // Nu există context, creăm unul nou cu excluderea
        error_log("[Context Create] Nu există context pentru $locatie/$cutie/col:$id_colectie - cream cu excludere: '$obiect_exclus'");
        
        $sql_insert = "INSERT INTO context_locatii 
                      (locatie, cutie, id_colectie, tip_context, obiecte_excluse, obiecte_comune, incredere, numar_exemple, ultima_actualizare) 
                      VALUES (?, ?, ?, 'general', ?, '', 0.5, 1, NOW())";
        $stmt_insert = mysqli_prepare($conn_central, $sql_insert);
        mysqli_stmt_bind_param($stmt_insert, "ssis", $locatie, $cutie, $id_colectie, $obiect_exclus);
        if (mysqli_stmt_execute($stmt_insert)) {
            error_log("[Context Create] ✓ Creat context nou pentru colecția $id_colectie cu excludere '$obiect_exclus'");
        } else {
            error_log("[Context Create] ✗ EROARE la creare: " . mysqli_error($conn_central));
        }
        mysqli_stmt_close($stmt_insert);
    }
    
    mysqli_stmt_close($stmt);
}

// Funcție pentru procesare batch a corecțiilor acumulate
function procesareCorectiiAcumulate($conn_central) {
    // Găsim pattern-uri din corecții repetate (minim 3 apariții)
    $sql = "SELECT 
            locatie, cutie, obiect_original, actiune,
            COUNT(*) as frecventa
            FROM context_corectii
            WHERE procesat = FALSE AND actiune = 'sters'
            GROUP BY locatie, cutie, obiect_original
            HAVING frecventa >= 2";
    
    $result = mysqli_query($conn_central, $sql);
    $procesate = 0;
    
    while ($row = mysqli_fetch_assoc($result)) {
        // Actualizăm contextul pentru această locație/cutie
        actualizareContextExcluderi($conn_central, 
            $row['locatie'], 
            $row['cutie'], 
            $row['obiect_original']
        );
        $procesate++;
    }
    
    // Marcăm corecțiile ca procesate
    if ($procesate > 0) {
        mysqli_query($conn_central, "UPDATE context_corectii SET procesat = TRUE WHERE procesat = FALSE");
        error_log("[Batch Process] Procesate $procesate pattern-uri de excludere");
    }
    
    return $procesate;
}
?>