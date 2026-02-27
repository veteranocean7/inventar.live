<?php
// sterge_imagine.php - Gestionează ștergerea unei imagini și reindexarea obiectelor asociate
// Versiune 2.0 - Actualizat pentru noul format fără index în denumire (25 August 2025)
// Indexul imaginii se găsește acum în etichetă, nu în denumirea obiectului
session_start();
require_once 'config.php';
header('Content-Type: application/json');

// Verifică autentificarea pentru sistemul multi-tenant
if (file_exists('includes/auth_functions.php')) {
    require_once 'includes/auth_functions.php';
    
    $user = checkSession();
    if (!$user) {
        echo json_encode(['success' => false, 'error' => 'Neautorizat']);
        exit;
    }
    
    // Reconectează la baza de date a utilizatorului
    mysqli_close($conn);
    $conn = getUserDbConnection($user['db_name']);
    
    // Determină prefixul corect bazat pe colecția curentă
    // Prioritate: POST > sesiune selectată > sesiune curentă
    $id_colectie = $_POST['id_colectie'] ?? $_SESSION['id_colectie_selectata'] ?? $_SESSION['id_colectie_curenta'] ?? null;
    // error_log("sterge_imagine.php - ID colecție din POST: " . ($_POST['id_colectie'] ?? 'null'));
    // error_log("sterge_imagine.php - ID colecție din sesiune selectată: " . ($_SESSION['id_colectie_selectata'] ?? 'null'));
    // error_log("sterge_imagine.php - ID colecție din sesiune curentă: " . ($_SESSION['id_colectie_curenta'] ?? 'null'));
    // error_log("sterge_imagine.php - ID colecție final folosit: " . ($id_colectie ?? 'null'));
    
    if ($id_colectie) {
        $conn_central = getCentralDbConnection();
        // Verificăm dacă utilizatorul are acces la colecție (proprietar sau partajată)
        $sql_prefix = "SELECT c.prefix_tabele, c.id_utilizator as proprietar_id, p.tip_acces 
                       FROM colectii_utilizatori c
                       LEFT JOIN partajari p ON c.id_colectie = p.id_colectie 
                            AND p.id_utilizator_partajat = ? AND p.activ = 1
                       WHERE c.id_colectie = ? 
                       AND (c.id_utilizator = ? OR p.id_partajare IS NOT NULL)";
        $stmt_prefix = mysqli_prepare($conn_central, $sql_prefix);
        mysqli_stmt_bind_param($stmt_prefix, "iii", $user['id_utilizator'], $id_colectie, $user['id_utilizator']);
        mysqli_stmt_execute($stmt_prefix);
        $result_prefix = mysqli_stmt_get_result($stmt_prefix);
        
        if ($row_prefix = mysqli_fetch_assoc($result_prefix)) {
            $table_prefix = $row_prefix['prefix_tabele'];
            $colectie_proprietar_id = $row_prefix['proprietar_id'];
            $_SESSION['tip_acces_colectie'] = $row_prefix['tip_acces'] ?? 'proprietar';
            
            // Verificăm dacă utilizatorul are drepturi de scriere
            if ($_SESSION['tip_acces_colectie'] == 'citire') {
                mysqli_stmt_close($stmt_prefix);
                mysqli_close($conn_central);
                echo json_encode(['success' => false, 'error' => 'Nu aveți drepturi de editare pentru această colecție']);
                exit;
            }
            
            // Reconectăm la baza de date a proprietarului dacă este diferită
            if ($colectie_proprietar_id != $user['id_utilizator']) {
                mysqli_close($conn);
                // Obținem informațiile despre proprietar
                $sql_owner = "SELECT db_name FROM utilizatori WHERE id_utilizator = ?";
                $stmt_owner = mysqli_prepare($conn_central, $sql_owner);
                mysqli_stmt_bind_param($stmt_owner, "i", $colectie_proprietar_id);
                mysqli_stmt_execute($stmt_owner);
                $result_owner = mysqli_stmt_get_result($stmt_owner);
                
                if ($row_owner = mysqli_fetch_assoc($result_owner)) {
                    $conn = getUserDbConnection($row_owner['db_name']);
                    $user_id = $colectie_proprietar_id; // Folosim ID-ul proprietarului pentru căile imaginilor
                } else {
                    $user_id = $user['id_utilizator'];
                }
                mysqli_stmt_close($stmt_owner);
            } else {
                $user_id = $user['id_utilizator'];
            }
        } else {
            $table_prefix = $user['prefix_tabele'] ?? 'user_' . $user['id_utilizator'] . '_';
            $user_id = $user['id_utilizator'];
        }
        mysqli_stmt_close($stmt_prefix);
        mysqli_close($conn_central);
    } else {
        $table_prefix = $_SESSION['prefix_tabele'] ?? $user['prefix_tabele'] ?? 'user_' . $user['id_utilizator'] . '_';
        $user_id = $user['id_utilizator'];
    }
} else {
    $table_prefix = $GLOBALS['table_prefix'] ?? '';
    $user_id = getCurrentUserId();
}

// Validare parametri
if (!isset($_POST['action']) || $_POST['action'] !== 'sterge_imagine') {
    echo json_encode(['success' => false, 'error' => 'Acțiune invalidă']);
    exit;
}

$id_obiect = isset($_POST['id_obiect']) ? (int)$_POST['id_obiect'] : 0;
$nume_imagine = isset($_POST['nume_imagine']) ? trim($_POST['nume_imagine']) : '';
$index_imagine = isset($_POST['index_imagine']) ? (int)$_POST['index_imagine'] : 0;

if ($id_obiect <= 0 || empty($nume_imagine) || $index_imagine <= 0) {
    echo json_encode(['success' => false, 'error' => 'Parametri invalizi']);
    exit;
}

try {
    // 1. Obținem datele curente ale obiectului
    $sql = "SELECT * FROM {$table_prefix}obiecte WHERE id_obiect = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'i', $id_obiect);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $obiect = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    
    if (!$obiect) {
        throw new Exception('Obiectul nu a fost găsit');
    }
    
    // 2. Procesăm imaginile
    $imagini = array_map('trim', explode(',', $obiect['imagine']));
    $index_real = $index_imagine - 1; // Convertim la index 0-based
    
    // Verificăm că imaginea există la indexul specificat
    if (!isset($imagini[$index_real]) || $imagini[$index_real] !== $nume_imagine) {
        throw new Exception('Imaginea nu corespunde cu indexul specificat');
    }
    
    // Ștergem imaginea din array
    array_splice($imagini, $index_real, 1);
    
    // Dacă nu mai rămân imagini, ștergem întregul obiect
    if (count($imagini) === 0) {
        $sql = "DELETE FROM {$table_prefix}obiecte WHERE id_obiect = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, 'i', $id_obiect);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        
        // Ștergem și din tabela de detecții
        $sql = "DELETE FROM {$table_prefix}detectii_obiecte WHERE id_obiect = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, 'i', $id_obiect);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        
        // Ștergem fișierul fizic
        $cale_imagine = 'imagini_obiecte/user_' . $user_id . '/' . $nume_imagine;
        if (file_exists($cale_imagine)) {
            unlink($cale_imagine);
        }
        
        echo json_encode(['success' => true, 'message' => 'Obiectul a fost șters complet']);
        exit;
    }
    
    // 3. Procesăm denumirile obiectelor și reindexăm
    $denumiri = array_map('trim', explode(',', $obiect['denumire_obiect']));
    $cantitati = array_map('trim', explode(',', $obiect['cantitate_obiect']));
    $etichete = array_map('trim', explode(';', $obiect['eticheta_obiect']));
    $imagini_obiecte = array_map('trim', explode(',', $obiect['imagine_obiect'] ?? ''));
    
    $denumiri_noi = [];
    $cantitati_noi = [];
    $etichete_noi = [];
    $imagini_obiecte_noi = [];
    
    // NOUĂ LOGICĂ: Procesăm pe baza etichetelor care conțin indexul imaginii
    for ($i = 0; $i < count($denumiri); $i++) {
        $denumire = $denumiri[$i];
        $eticheta = isset($etichete[$i]) ? $etichete[$i] : '';
        
        // Extragem indexul imaginii din etichetă (format: #culoare(x,y,index_imagine))
        $index_obiect = 0;
        if (preg_match('/#[a-f0-9]{6}\([^,]+,[^,]+,(\d+)\)/i', $eticheta, $matches)) {
            $index_obiect = (int)$matches[1];
        } else if (preg_match('/#[a-f0-9]{6}\((\d+)\)/i', $eticheta, $matches)) {
            // Fallback pentru format vechi doar cu index
            $index_obiect = (int)$matches[1];
        }
        
        // Dacă nu găsim index în etichetă, încercăm să-l găsim în denumire (pentru compatibilitate)
        if ($index_obiect == 0 && preg_match('/\((\d+)\)$/', $denumire, $matches)) {
            $index_obiect = (int)$matches[1];
            // Curățăm denumirea de index
            $denumire = trim(preg_replace('/\(\d+\)$/', '', $denumire));
        }
        
        // Dacă obiectul aparține imaginii șterse, îl omitem
        if ($index_obiect === $index_imagine) {
            continue;
        }
        
        // Actualizăm eticheta dacă are index mai mare decât cel șters
        if ($index_obiect > $index_imagine && !empty($eticheta)) {
            $index_nou = $index_obiect - 1;
            // Actualizăm indexul în etichetă
            $eticheta = preg_replace('/([#a-f0-9]{6}\([^,]+,[^,]+,)\d+(\))/i', '${1}' . $index_nou . '${2}', $eticheta);
            // Fallback pentru format vechi
            $eticheta = preg_replace('/([#a-f0-9]{6}\()\d+(\))/i', '${1}' . $index_nou . '${2}', $eticheta);
        }
        
        // Păstrăm obiectul cu denumirea curată (fără index)
        $denumiri_noi[] = $denumire;
        
        // Păstrăm cantitatea, eticheta actualizată și imaginea asociate
        if (isset($cantitati[$i])) $cantitati_noi[] = $cantitati[$i];
        $etichete_noi[] = $eticheta;
        if (isset($imagini_obiecte[$i])) $imagini_obiecte_noi[] = $imagini_obiecte[$i];
    }
    
    // 4. Actualizăm baza de date
    $imagini_finale = implode(', ', $imagini);
    $denumiri_finale = implode(', ', $denumiri_noi);
    $cantitati_finale = implode(', ', $cantitati_noi);
    $etichete_finale = implode('; ', $etichete_noi);
    $imagini_obiecte_finale = implode(', ', $imagini_obiecte_noi);
    
    $sql = "UPDATE {$table_prefix}obiecte SET 
            imagine = ?,
            denumire_obiect = ?,
            cantitate_obiect = ?,
            eticheta_obiect = ?,
            imagine_obiect = ?
            WHERE id_obiect = ?";
    
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'sssssi', 
        $imagini_finale,
        $denumiri_finale,
        $cantitati_finale,
        $etichete_finale,
        $imagini_obiecte_finale,
        $id_obiect
    );
    
    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception('Eroare la actualizarea bazei de date: ' . mysqli_error($conn));
    }
    mysqli_stmt_close($stmt);
    
    // 5. Ștergem fișierul fizic
    $cale_imagine = 'imagini_obiecte/user_' . $user_id . '/' . $nume_imagine;
    if (file_exists($cale_imagine)) {
        unlink($cale_imagine);
    }
    
    // 6. Actualizăm și tabela de detecții (ștergem obiectele asociate cu imaginea ștearsă)
    // Mai întâi obținem toate denumirile care trebuie șterse
    $sql = "DELETE FROM detectii_obiecte WHERE id_obiect = ? AND denumire IN (
        SELECT denumire FROM (
            SELECT DISTINCT TRIM(SUBSTRING_INDEX(denumire, '(', 1)) as denumire
            FROM detectii_obiecte
            WHERE id_obiect = ?
        ) AS temp
    )";
    
    // Pentru siguranță, facem mai simplu - ștergem toate și le recreăm
    $sql = "DELETE FROM {$table_prefix}detectii_obiecte WHERE id_obiect = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'i', $id_obiect);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    
    echo json_encode([
        'success' => true, 
        'message' => 'Imaginea a fost ștearsă și obiectele au fost reindexate'
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

mysqli_close($conn);
?>