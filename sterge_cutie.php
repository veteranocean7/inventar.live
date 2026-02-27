<?php
// sterge_cutie.php - Gestionează ștergerea întregii cutii cu toate imaginile și obiectele asociate
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
    // error_log("sterge_cutie.php - ID colecție din POST: " . ($_POST['id_colectie'] ?? 'null'));
    // error_log("sterge_cutie.php - ID colecție din sesiune selectată: " . ($_SESSION['id_colectie_selectata'] ?? 'null'));
    // error_log("sterge_cutie.php - ID colecție din sesiune curentă: " . ($_SESSION['id_colectie_curenta'] ?? 'null'));
    // error_log("sterge_cutie.php - ID colecție final folosit: " . ($id_colectie ?? 'null'));
    
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
if (!isset($_POST['action']) || $_POST['action'] !== 'sterge_cutie') {
    echo json_encode(['success' => false, 'error' => 'Acțiune invalidă']);
    exit;
}

$id_obiect = isset($_POST['id_obiect']) ? (int)$_POST['id_obiect'] : 0;

if ($id_obiect <= 0) {
    echo json_encode(['success' => false, 'error' => 'ID obiect invalid']);
    exit;
}

try {
    // 1. Obținem informațiile despre cutie
    // error_log("sterge_cutie.php - Caut obiect cu ID=$id_obiect în tabela {$table_prefix}obiecte");
    $sql = "SELECT * FROM {$table_prefix}obiecte WHERE id_obiect = ?";
    $stmt = mysqli_prepare($conn, $sql);
    
    if (!$stmt) {
        // error_log("sterge_cutie.php - Eroare la pregătirea query: " . mysqli_error($conn));
        throw new Exception('Eroare la pregătirea interogării: ' . mysqli_error($conn));
    }
    
    mysqli_stmt_bind_param($stmt, 'i', $id_obiect);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $obiect = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    
    if (!$obiect) {
        // error_log("sterge_cutie.php - Nu am găsit obiectul cu ID=$id_obiect în {$table_prefix}obiecte");
        
        // Verificăm dacă există în tabela principală
        $sql_check = "SELECT id_obiect FROM obiecte WHERE id_obiect = ?";
        $stmt_check = mysqli_prepare($conn, $sql_check);
        if ($stmt_check) {
            mysqli_stmt_bind_param($stmt_check, 'i', $id_obiect);
            mysqli_stmt_execute($stmt_check);
            $result_check = mysqli_stmt_get_result($stmt_check);
            if (mysqli_fetch_assoc($result_check)) {
                // error_log("sterge_cutie.php - ATENȚIE: Obiectul există în tabela 'obiecte' dar căutăm în '{$table_prefix}obiecte'");
            }
            mysqli_stmt_close($stmt_check);
        }
        
        throw new Exception("Cutia nu a fost găsită în tabela {$table_prefix}obiecte");
    }
    
    // error_log("sterge_cutie.php - Am găsit obiectul: cutie=" . $obiect['cutie'] . ", locatie=" . $obiect['locatie']);
    
    // 2. Ștergem toate imaginile fizice asociate
    if (!empty($obiect['imagine'])) {
        $imagini = array_map('trim', explode(',', $obiect['imagine']));
        foreach ($imagini as $imagine) {
            $cale_imagine = 'imagini_obiecte/user_' . $user_id . '/' . $imagine;
            if (file_exists($cale_imagine)) {
                unlink($cale_imagine);
            }
        }
    }
    
    // 3. Ștergem imaginile decupate asociate obiectelor
    if (!empty($obiect['imagine_obiect'])) {
        $imagini_obiecte = array_map('trim', explode(',', $obiect['imagine_obiect']));
        foreach ($imagini_obiecte as $imagine_obiect) {
            if (!empty($imagine_obiect)) {
                $cale_imagine_obiect = 'imagini_decupate/user_' . $user_id . '/' . $imagine_obiect;
                if (file_exists($cale_imagine_obiect)) {
                    unlink($cale_imagine_obiect);
                }
            }
        }
    }
    
    // 4. Ștergem din tabela de detecții
    $sql = "DELETE FROM {$table_prefix}detectii_obiecte WHERE id_obiect = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'i', $id_obiect);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    
    // 5. Ștergem înregistrarea din tabela principală
    $sql = "DELETE FROM {$table_prefix}obiecte WHERE id_obiect = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'i', $id_obiect);
    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception('Eroare la ștergerea din baza de date: ' . mysqli_error($conn));
    }
    mysqli_stmt_close($stmt);
    
    echo json_encode([
        'success' => true, 
        'message' => 'Cutia a fost ștearsă cu succes'
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

mysqli_close($conn);
?>