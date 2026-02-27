<?php
/**
 * Backend pentru funcționalitatea "Împarte cu ceilalți"
 * Inventar.live - August 2025
 */

// Previne output-ul accidental care poate strica JSON-ul
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Start output buffering pentru a captura orice erori
ob_start();

require_once 'includes/auth_functions.php';
require_once 'config.php';
require_once 'includes/email_notifications.php';

// Verifică autentificarea
$user = checkSession();
if (!$user) {
    ob_clean();
    http_response_code(401);
    die(json_encode(['success' => false, 'message' => 'Neautentificat']));
}

// Curăță buffer-ul înainte de a trimite JSON
ob_clean();

// Setează header pentru JSON
header('Content-Type: application/json');

// Obține conexiunea la baza centrală
$conn_central = getCentralDbConnection();

// Obține acțiunea din request
$data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$actiune = $data['actiune'] ?? $_GET['actiune'] ?? '';

try {
    switch ($actiune) {
        case 'salveaza_partajare':
            salveazaPartajare($conn_central, $user, $data);
            break;
            
        case 'obtine_obiecte_cutie':
            obtineObiecteCutie($conn_central, $user, $data);
            break;
            
        case 'obtine_toate_obiectele':
            obtineToateObiectele($conn_central, $user, $data);
            break;
            
        case 'invita_membru':
            invitaMembru($conn_central, $user, $data);
            break;
            
        case 'lista_membri':
            listaMembri($conn_central, $user);
            break;
            
        case 'revoca_acces':
            revocaAcces($conn_central, $user, $data);
            break;
            
        case 'obtine_cutii_colectie':
            obtineCutiiColectie($conn_central, $user, $data);
            break;
            
        case 'obtine_cutii_cu_stare':
            obtineCutiiCuStare($conn_central, $user, $data);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Acțiune necunoscută: ' . $actiune]);
    }
} catch (Exception $e) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Eroare server: ' . $e->getMessage()]);
} finally {
    if (isset($conn_central) && $conn_central) {
        mysqli_close($conn_central);
    }
}

/**
 * Salvează setările de partajare pentru o cutie sau întreaga colecție
 * NOUĂ LOGICĂ: Salvăm obiectele selectate în coloana obiecte_partajate
 */
function salveazaPartajare($conn, $user, $data) {
    $cutie = $data['cutie'] ?? '';
    $locatie = $data['locatie'] ?? '';
    $isPublic = $data['isPublic'] ?? false;
    $obiecte = $data['obiecte'] ?? [];  // Array cu obiectele selectate (denumire + index)
    $id_colectie_primit = $data['id_colectie'] ?? null;
    
    // Obține colecția curentă
    $id_colectie = $id_colectie_primit ?? $_SESSION['id_colectie_selectata'] ?? $_SESSION['id_colectie_curenta'] ?? $user['id_colectie_principala'];
    
    // Determină prefixul corect și verifică dacă e colecție partajată
    if ($id_colectie) {
        // Verificăm dacă e colecție proprie sau partajată
        $sql_prefix = "SELECT c.prefix_tabele, c.id_utilizator as proprietar_id, u.db_name,
                              p.tip_acces
                       FROM colectii_utilizatori c
                       JOIN utilizatori u ON c.id_utilizator = u.id_utilizator
                       LEFT JOIN partajari p ON c.id_colectie = p.id_colectie 
                            AND p.id_utilizator_partajat = ? AND p.activ = 1
                       WHERE c.id_colectie = ?";
        
        $stmt_prefix = mysqli_prepare($conn, $sql_prefix);
        mysqli_stmt_bind_param($stmt_prefix, "ii", $user['id_utilizator'], $id_colectie);
        mysqli_stmt_execute($stmt_prefix);
        $result_prefix = mysqli_stmt_get_result($stmt_prefix);
        
        if ($row_prefix = mysqli_fetch_assoc($result_prefix)) {
            $prefix = $row_prefix['prefix_tabele'];
            $proprietar_id = $row_prefix['proprietar_id'];
            $db_proprietar = $row_prefix['db_name'];
            $tip_acces = $row_prefix['tip_acces'] ?? 'proprietar';
            
            // Verificăm dacă utilizatorul are drept de scriere
            if ($proprietar_id != $user['id_utilizator']) {
                if ($tip_acces != 'scriere') {
                    echo json_encode(['success' => false, 'message' => 'Nu aveți permisiunea de a modifica această colecție']);
                    return;
                }
                // Folosim conexiunea centrală existentă - toate tabelele sunt în inventar_central
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Colecție invalidă sau fără acces']);
            return;
        }
        mysqli_stmt_close($stmt_prefix);
    } else {
        $prefix = $_SESSION['prefix_tabele'] ?? $user['prefix_tabele'];
    }
    
    if (!$id_colectie || !$prefix) {
        echo json_encode(['success' => false, 'message' => 'Colecție invalidă']);
        return;
    }
    
    mysqli_begin_transaction($conn);
    
    try {
        $table_obiecte = $prefix . 'obiecte';
        
        if ($cutie === '__global__') {
            // Pentru partajare globală - copiază toate obiectele în coloana obiecte_partajate
            if ($isPublic) {
                // Copiază denumire_obiect în obiecte_partajate pentru toate rândurile
                $update_all = "UPDATE `$table_obiecte` SET obiecte_partajate = denumire_obiect";
                $stmt = mysqli_prepare($conn, $update_all);
            } else {
                // Șterge toate partajările
                $update_all = "UPDATE `$table_obiecte` SET obiecte_partajate = NULL";
                $stmt = mysqli_prepare($conn, $update_all);
            }
            
            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception("Eroare la actualizarea globală a partajărilor");
            }
            mysqli_stmt_close($stmt);
            
            // Marchează colecția ca publică/privată
            $update_col = "UPDATE colectii_utilizatori SET este_publica = ? WHERE id_colectie = ? AND id_utilizator = ?";
            $stmt = mysqli_prepare($conn, $update_col);
            $este_publica = $isPublic ? 1 : 0;
            mysqli_stmt_bind_param($stmt, "iii", $este_publica, $id_colectie, $user['id_utilizator']);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            
        } else {
            // Pentru o cutie specifică - actualizăm coloana obiecte_partajate
            
            // Mai întâi obținem înregistrarea curentă
            if (!empty($locatie)) {
                $get_sql = "SELECT id_obiect, denumire_obiect FROM `$table_obiecte` 
                           WHERE cutie = ? AND locatie = ? LIMIT 1";
                $stmt = mysqli_prepare($conn, $get_sql);
                mysqli_stmt_bind_param($stmt, "ss", $cutie, $locatie);
            } else {
                $get_sql = "SELECT id_obiect, denumire_obiect FROM `$table_obiecte` 
                           WHERE cutie = ? LIMIT 1";
                $stmt = mysqli_prepare($conn, $get_sql);
                mysqli_stmt_bind_param($stmt, "s", $cutie);
            }
            
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            
            if ($row = mysqli_fetch_assoc($result)) {
                $id_obiect = $row['id_obiect'];
                $denumire_completa = $row['denumire_obiect'];
                mysqli_stmt_close($stmt);
                
                // Construim string-ul pentru obiecte_partajate
                $obiecte_partajate_str = NULL;
                
                if ($isPublic && count($obiecte) > 0) {
                    // Acum primim deja obiectele cu index (ex: "car seat capac(1)")
                    // Doar le filtrăm și le concatenăm
                    $obiecte_pentru_partajare = [];
                    
                    foreach ($obiecte as $obiect_selectat) {
                        $obiect_selectat = trim($obiect_selectat);
                        // Verificăm că obiectul selectat există în lista originală
                        if (strpos($denumire_completa, $obiect_selectat) !== false) {
                            $obiecte_pentru_partajare[] = $obiect_selectat;
                        }
                    }
                    
                    if (count($obiecte_pentru_partajare) > 0) {
                        $obiecte_partajate_str = implode(', ', $obiecte_pentru_partajare);
                    }
                }
                
                // Actualizăm înregistrarea cu noua valoare pentru obiecte_partajate
                $update_sql = "UPDATE `$table_obiecte` SET obiecte_partajate = ? WHERE id_obiect = ?";
                $stmt = mysqli_prepare($conn, $update_sql);
                mysqli_stmt_bind_param($stmt, "si", $obiecte_partajate_str, $id_obiect);
                
                if (!mysqli_stmt_execute($stmt)) {
                    throw new Exception("Eroare la actualizarea obiectelor partajate");
                }
                mysqli_stmt_close($stmt);
                
            } else {
                mysqli_stmt_close($stmt);
                throw new Exception("Nu s-a găsit cutia specificată");
            }
        }
        
        mysqli_commit($conn);
        echo json_encode(['success' => true, 'message' => 'Setările de partajare au fost salvate']);
        
    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

/**
 * Obține obiectele dintr-o cutie specifică
 */
function obtineObiecteCutie($conn, $user, $data) {
    $cutie = $data['cutie'] ?? '';
    $locatie = $data['locatie'] ?? '';
    $id_colectie_primit = $data['id_colectie'] ?? null;
    
    if (empty($cutie)) {
        echo json_encode(['success' => false, 'message' => 'Cutie nespecificată']);
        return;
    }
    
    // Determină prefixul corect bazat pe colecția curentă
    // Prioritate: id_colectie din request > sesiune > principal
    $id_colectie = $id_colectie_primit ?? $_SESSION['id_colectie_selectata'] ?? $_SESSION['id_colectie_curenta'] ?? $user['id_colectie_principala'];
    
    // Log pentru debug - TEMPORAR ACTIVAT
    error_log("[Partajare Debug] ID Colectie determinat: $id_colectie");
    error_log("[Partajare Debug] Cutie: $cutie, Locatie: $locatie");
    
    if ($id_colectie) {
        // Verificăm dacă e colecție proprie sau partajată (fără restricția id_utilizator)
        $sql_prefix = "SELECT c.prefix_tabele, c.id_utilizator as proprietar_id, u.db_name
                       FROM colectii_utilizatori c
                       JOIN utilizatori u ON c.id_utilizator = u.id_utilizator
                       LEFT JOIN partajari p ON c.id_colectie = p.id_colectie 
                            AND p.id_utilizator_partajat = ? AND p.activ = 1
                       WHERE c.id_colectie = ?";
        
        $stmt_prefix = mysqli_prepare($conn, $sql_prefix);
        mysqli_stmt_bind_param($stmt_prefix, "ii", $user['id_utilizator'], $id_colectie);
        mysqli_stmt_execute($stmt_prefix);
        $result_prefix = mysqli_stmt_get_result($stmt_prefix);
        
        if ($row_prefix = mysqli_fetch_assoc($result_prefix)) {
            $prefix = $row_prefix['prefix_tabele'];
            $proprietar_id = $row_prefix['proprietar_id'];
            $db_proprietar = $row_prefix['db_name'];
            
            // Folosim conexiunea centrală existentă - toate tabelele sunt în inventar_central
            error_log("[Partajare Debug] Prefix găsit: $prefix, Proprietar: $proprietar_id");
        } else {
            $prefix = $_SESSION['prefix_tabele'] ?? $user['prefix_tabele'];
            error_log("[Partajare Debug] Folosesc prefix din sesiune: $prefix");
        }
        mysqli_stmt_close($stmt_prefix);
    } else {
        $prefix = $_SESSION['prefix_tabele'] ?? $user['prefix_tabele'];
        // error_log("[Partajare Debug] Prefix implicit: $prefix");
    }
    
    $table_obiecte = $prefix . 'obiecte';
    // error_log("[Partajare Debug] Tabel folosit: $table_obiecte");
    
    // Obține obiectele din cutie - filtrăm și după locație dacă este disponibilă
    if (!empty($locatie)) {
        $sql = "SELECT id_obiect, denumire_obiect, obiecte_partajate, locatie 
                FROM `$table_obiecte` 
                WHERE cutie = ? AND locatie = ?
                ORDER BY denumire_obiect";
        
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "ss", $cutie, $locatie);
    } else {
        $sql = "SELECT id_obiect, denumire_obiect, obiecte_partajate, locatie 
                FROM `$table_obiecte` 
                WHERE cutie = ? 
                ORDER BY denumire_obiect";
        
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "s", $cutie);
    }
    
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $obiecte = [];
    while ($row = mysqli_fetch_assoc($result)) {
        // Verificăm dacă toate obiectele sunt partajate (partajare globală)
        $toate_partajate = false;
        if (!empty($row['obiecte_partajate']) && $row['obiecte_partajate'] === $row['denumire_obiect']) {
            $toate_partajate = true;
        }
        
        // Creăm un array cu obiectele partajate pentru verificare rapidă
        $obiecte_partajate_array = [];
        if (!$toate_partajate && !empty($row['obiecte_partajate'])) {
            $partajate_temp = explode(',', $row['obiecte_partajate']);
            foreach ($partajate_temp as $p) {
                $p_clean = trim(preg_replace('/\s*\(\d+\)$/', '', trim($p)));
                $obiecte_partajate_array[] = $p_clean;
            }
        }
        
        // Procesăm denumirile multiple separate prin virgulă
        $denumiri = explode(',', $row['denumire_obiect']);
        
        foreach ($denumiri as $denumire_cu_index) {
            // Extrage denumirea curată (fără index-ul dintre paranteze)
            $denumire_cu_index = trim($denumire_cu_index);
            $denumire_curata = trim(preg_replace('/\s*\(\d+\)$/', '', $denumire_cu_index));
            
            if (!empty($denumire_curata)) {
                // Verificăm dacă acest obiect specific este partajat
                $este_partajat = $toate_partajate || in_array($denumire_curata, $obiecte_partajate_array);
                
                $obiecte[] = [
                    'id' => $row['id_obiect'],
                    'denumire' => $denumire_curata,
                    'denumire_completa' => $denumire_cu_index,
                    'partajat' => $este_partajat,
                    'locatie' => $row['locatie'] ?? ''
                ];
            }
        }
    }
    mysqli_stmt_close($stmt);
    
    // error_log("[Partajare Debug] Obiecte găsite: " . count($obiecte));
    
    echo json_encode(['success' => true, 'obiecte' => $obiecte, 'debug_info' => [
        'table' => $table_obiecte,
        'cutie' => $cutie,
        'locatie' => $locatie,
        'count' => count($obiecte)
    ]]);
}

/**
 * Obține toate obiectele din colecția curentă
 */
function obtineToateObiectele($conn, $user, $data = null) {
    // Determină prefixul corect bazat pe colecția curentă
    // Prioritate: id_colectie din request > sesiune > principal
    $id_colectie = $data['id_colectie'] ?? $_SESSION['id_colectie_selectata'] ?? $_SESSION['id_colectie_curenta'] ?? $user['id_colectie_principala'];
    
    $proprietar_id = $user['id_utilizator'];
    
    if ($id_colectie) {
        // Verificăm dacă e colecție proprie sau partajată
        $sql_prefix = "SELECT c.prefix_tabele, c.id_utilizator as proprietar_id, u.db_name, c.este_publica
                       FROM colectii_utilizatori c
                       JOIN utilizatori u ON c.id_utilizator = u.id_utilizator
                       LEFT JOIN partajari p ON c.id_colectie = p.id_colectie 
                            AND p.id_utilizator_partajat = ? AND p.activ = 1
                       WHERE c.id_colectie = ?";
        
        $stmt_prefix = mysqli_prepare($conn, $sql_prefix);
        mysqli_stmt_bind_param($stmt_prefix, "ii", $user['id_utilizator'], $id_colectie);
        mysqli_stmt_execute($stmt_prefix);
        $result_prefix = mysqli_stmt_get_result($stmt_prefix);
        
        if ($row_prefix = mysqli_fetch_assoc($result_prefix)) {
            $prefix = $row_prefix['prefix_tabele'];
            $proprietar_id = $row_prefix['proprietar_id'];
            $db_proprietar = $row_prefix['db_name'];
            $este_publica = $row_prefix['este_publica'] == 1;
            
            // Folosim conexiunea centrală existentă - toate tabelele sunt în inventar_central
        } else {
            $prefix = $_SESSION['prefix_tabele'] ?? $user['prefix_tabele'];
            $este_publica = false;
        }
        mysqli_stmt_close($stmt_prefix);
    } else {
        $prefix = $_SESSION['prefix_tabele'] ?? $user['prefix_tabele'];
        $este_publica = false;
    }
    
    $table_obiecte = $prefix . 'obiecte';
    
    // Debug info - poate fi comentat în producție
    // error_log("[Partajare Debug] obtineToateObiectele - Prefix: $prefix, Tabel: $table_obiecte, ID Colecție: $id_colectie");
    
    // Obține toate obiectele grupate pe cutii
    $sql = "SELECT id_obiect, denumire_obiect, cutie, locatie, obiecte_partajate 
            FROM `$table_obiecte` 
            ORDER BY locatie, cutie, denumire_obiect";
    
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Eroare la pregătirea query: ' . mysqli_error($conn)]);
        return;
    }
    
    if (!mysqli_stmt_execute($stmt)) {
        echo json_encode(['success' => false, 'message' => 'Eroare la execuția query: ' . mysqli_stmt_error($stmt)]);
        mysqli_stmt_close($stmt);
        return;
    }
    
    $result = mysqli_stmt_get_result($stmt);
    
    $obiecte = [];
    while ($row = mysqli_fetch_assoc($result)) {
        // Extrage doar denumirea fără cantitate
        $denumire_curata = trim(preg_replace('/\(\d+\)$/', '', $row['denumire_obiect']));
        
        // Verificăm dacă acest obiect specific este partajat
        $este_partajat = false;
        if (!empty($row['obiecte_partajate'])) {
            // Dacă obiecte_partajate == denumire_obiect, înseamnă că TOATE obiectele din rând sunt partajate (partajare globală)
            if ($row['obiecte_partajate'] === $row['denumire_obiect']) {
                $este_partajat = true;
            } else {
                // Altfel, parsăm obiectele partajate și verificăm dacă acest obiect este în listă
                $obiecte_partajate_array = explode(',', $row['obiecte_partajate']);
                foreach ($obiecte_partajate_array as $obj_partajat) {
                    $obj_curat = trim(preg_replace('/\s*\(\d+\)$/', '', trim($obj_partajat)));
                    if ($obj_curat === $denumire_curata) {
                        $este_partajat = true;
                        break;
                    }
                }
            }
        }
        
        $obiecte[] = [
            'id' => $row['id_obiect'],
            'denumire' => $denumire_curata,
            'denumire_completa' => $row['denumire_obiect'],
            'cutie' => $row['cutie'],
            'locatie' => $row['locatie'],
            'partajat' => $este_partajat
        ];
    }
    mysqli_stmt_close($stmt);
    
    // $este_publica a fost deja determinată mai sus când am obținut prefixul
    
    echo json_encode([
        'success' => true, 
        'obiecte' => $obiecte,
        'colectie_publica' => $este_publica  // Folosim valoarea determinată corect mai sus
    ]);
}

/**
 * Invită un membru de familie pentru acces la colecție
 */
function invitaMembru($conn, $user, $data) {
    $email = filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL);
    $tip_acces = $data['tip_acces'] ?? 'citire';
    $id_colectie = $data['id_colectie'] ?? ($_SESSION['id_colectie_curenta'] ?? $user['id_colectie_principala']);
    $tip_partajare = $data['tip_partajare'] ?? 'completa';
    $cutii_selectate = $data['cutii_selectate'] ?? null;
    
    if (!$email) {
        echo json_encode(['success' => false, 'message' => 'Email invalid']);
        return;
    }
    
    if (!in_array($tip_acces, ['citire', 'scriere'])) {
        echo json_encode(['success' => false, 'message' => 'Tip acces invalid']);
        return;
    }
    
    if (!in_array($tip_partajare, ['completa', 'selectiva'])) {
        echo json_encode(['success' => false, 'message' => 'Tip partajare invalid']);
        return;
    }
    
    // Pentru partajare selectivă, verifică că sunt cutii selectate
    if ($tip_partajare == 'selectiva' && empty($cutii_selectate)) {
        echo json_encode(['success' => false, 'message' => 'Selectează cel puțin o cutie pentru partajare selectivă']);
        return;
    }
    
    // Verifică dacă utilizatorul invitat există
    $sql_user = "SELECT id_utilizator, prenume FROM utilizatori WHERE email = ?";
    $stmt = mysqli_prepare($conn, $sql_user);
    mysqli_stmt_bind_param($stmt, "s", $email);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($row = mysqli_fetch_assoc($result)) {
        $id_invitat = $row['id_utilizator'];
        $nume_invitat = $row['prenume'];
        
        // Verifică că nu se invită pe sine
        if ($id_invitat == $user['id_utilizator']) {
            echo json_encode(['success' => false, 'message' => 'Nu te poți invita pe tine însuți']);
            mysqli_stmt_close($stmt);
            return;
        }
        
        mysqli_stmt_close($stmt);
        
        // Pregătește JSON pentru cutii selectate (dacă e cazul)
        $cutii_json = null;
        if ($tip_partajare == 'selectiva' && !empty($cutii_selectate)) {
            $cutii_json = json_encode($cutii_selectate);
        }
        
        // Inserează sau actualizează partajarea cu noile câmpuri
        $sql_partajare = "INSERT INTO partajari (id_colectie, id_utilizator_partajat, tip_acces, tip_partajare, cutii_partajate, activ) 
                          VALUES (?, ?, ?, ?, ?, 1) 
                          ON DUPLICATE KEY UPDATE 
                              tip_acces = VALUES(tip_acces), 
                              tip_partajare = VALUES(tip_partajare),
                              cutii_partajate = VALUES(cutii_partajate),
                              activ = 1";
        
        $stmt = mysqli_prepare($conn, $sql_partajare);
        mysqli_stmt_bind_param($stmt, "iisss", $id_colectie, $id_invitat, $tip_acces, $tip_partajare, $cutii_json);
        
        if (mysqli_stmt_execute($stmt)) {
            // Obține numele colecției
            $nume_colectie = getNumeColectie($conn, $id_colectie);

            // Creează notificare în baza de date
            $mesaj = "Ai primit acces la colecția '" . $nume_colectie . "' de la " . $user['prenume'];
            $sql_notif = "INSERT INTO notificari_partajare
                          (id_colectie, id_utilizator_destinatar, tip_notificare, mesaj)
                          VALUES (?, ?, 'acces_acordat', ?)";

            $stmt_notif = mysqli_prepare($conn, $sql_notif);
            mysqli_stmt_bind_param($stmt_notif, "iis", $id_colectie, $id_invitat, $mesaj);
            mysqli_stmt_execute($stmt_notif);
            mysqli_stmt_close($stmt_notif);

            // Trimite notificare email
            try {
                $nume_proprietar = $user['prenume'] . ' ' . $user['nume'];
                @trimiteEmailPartajareNoua($id_invitat, $nume_colectie, $tip_acces, $nume_proprietar);
            } catch (Exception $e) {
                error_log("Eroare trimitere email partajare: " . $e->getMessage());
            }

            echo json_encode([
                'success' => true,
                'message' => "Invitația a fost trimisă către $nume_invitat"
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Eroare la salvarea partajării']);
        }
        mysqli_stmt_close($stmt);
        
    } else {
        mysqli_stmt_close($stmt);
        echo json_encode([
            'success' => false, 
            'message' => 'Utilizatorul cu acest email nu există în sistem. Invită-l mai întâi să se înregistreze la inventar.live'
        ]);
    }
}

/**
 * Listează membrii cu care este partajată colecția curentă
 */
function listaMembri($conn, $user) {
    $id_colectie = $_SESSION['id_colectie_curenta'] ?? $user['id_colectie_principala'];
    
    $sql = "SELECT p.*, u.nume, u.prenume, u.email 
            FROM partajari p 
            JOIN utilizatori u ON p.id_utilizator_partajat = u.id_utilizator 
            WHERE p.id_colectie = ? AND p.activ = 1 
            ORDER BY p.data_partajare DESC";
    
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $id_colectie);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $membri = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $membri[] = [
            'id_partajare' => $row['id_partajare'],
            'nume_complet' => $row['prenume'] . ' ' . $row['nume'],
            'email' => $row['email'],
            'tip_acces' => $row['tip_acces'],
            'data_partajare' => $row['data_partajare']
        ];
    }
    mysqli_stmt_close($stmt);
    
    echo json_encode(['success' => true, 'membri' => $membri]);
}

/**
 * Revocă accesul unui membru
 */
function revocaAcces($conn, $user, $data) {
    $id_partajare = intval($data['id_partajare'] ?? 0);
    
    if (!$id_partajare) {
        echo json_encode(['success' => false, 'message' => 'ID partajare invalid']);
        return;
    }
    
    // Verifică că utilizatorul deține colecția
    $sql_verif = "SELECT p.id_utilizator_partajat, c.nume_colectie 
                  FROM partajari p 
                  JOIN colectii_utilizatori c ON p.id_colectie = c.id_colectie 
                  WHERE p.id_partajare = ? AND c.id_utilizator = ?";
    
    $stmt = mysqli_prepare($conn, $sql_verif);
    mysqli_stmt_bind_param($stmt, "ii", $id_partajare, $user['id_utilizator']);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($row = mysqli_fetch_assoc($result)) {
        $id_revocat = $row['id_utilizator_partajat'];
        $nume_colectie = $row['nume_colectie'];
        mysqli_stmt_close($stmt);
        
        // Șterge partajarea
        $sql_delete = "DELETE FROM partajari WHERE id_partajare = ?";
        $stmt = mysqli_prepare($conn, $sql_delete);
        mysqli_stmt_bind_param($stmt, "i", $id_partajare);
        
        if (mysqli_stmt_execute($stmt)) {
            // Obținem ID-ul colecției pentru notificare
            $sql_col = "SELECT id_colectie FROM colectii_utilizatori WHERE nume_colectie = ? AND id_utilizator = ?";
            $stmt_col = mysqli_prepare($conn, $sql_col);
            mysqli_stmt_bind_param($stmt_col, "si", $nume_colectie, $user['id_utilizator']);
            mysqli_stmt_execute($stmt_col);
            $result_col = mysqli_stmt_get_result($stmt_col);
            $col_data = mysqli_fetch_assoc($result_col);
            $id_colectie = $col_data['id_colectie'] ?? 0;
            mysqli_stmt_close($stmt_col);

            // Creează notificare în baza de date
            $mesaj = "Accesul tău la colecția '$nume_colectie' a fost revocat de " . $user['prenume'];
            $sql_notif = "INSERT INTO notificari_partajare
                          (id_colectie, id_utilizator_destinatar, tip_notificare, mesaj)
                          VALUES (?, ?, 'acces_revocat', ?)";

            $stmt_notif = mysqli_prepare($conn, $sql_notif);
            mysqli_stmt_bind_param($stmt_notif, "iis", $id_colectie, $id_revocat, $mesaj);
            mysqli_stmt_execute($stmt_notif);
            mysqli_stmt_close($stmt_notif);

            // Trimite notificare email pentru revocare
            try {
                $nume_proprietar = $user['prenume'] . ' ' . $user['nume'];
                @trimiteEmailRevocareAcces($id_revocat, $nume_colectie, $nume_proprietar);
            } catch (Exception $e) {
                error_log("Eroare trimitere email revocare: " . $e->getMessage());
            }

            echo json_encode(['success' => true, 'message' => 'Accesul a fost revocat']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Eroare la revocarea accesului']);
        }
        mysqli_stmt_close($stmt);
        
    } else {
        mysqli_stmt_close($stmt);
        echo json_encode(['success' => false, 'message' => 'Nu aveți permisiunea de a revoca acest acces']);
    }
}

/**
 * Obține lista de cutii din colecție pentru selectare în partajare
 */
function obtineCutiiColectie($conn_central, $user, $data) {
    $id_colectie = $data['id_colectie'] ?? $user['id_colectie_principala'];
    
    // Obține informații despre colecție - verificăm și dacă e proprietar
    $sql_col = "SELECT c.prefix_tabele, c.db_name, c.id_utilizator 
                FROM colectii_utilizatori c 
                WHERE c.id_colectie = ? 
                AND (c.id_utilizator = ? OR EXISTS (
                    SELECT 1 FROM partajari p 
                    WHERE p.id_colectie = c.id_colectie 
                    AND p.id_utilizator_partajat = ? 
                    AND p.activ = 1
                ))";
    $stmt = mysqli_prepare($conn_central, $sql_col);
    mysqli_stmt_bind_param($stmt, "iii", $id_colectie, $user['id_utilizator'], $user['id_utilizator']);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if (!($row = mysqli_fetch_assoc($result))) {
        mysqli_stmt_close($stmt);
        echo json_encode(['success' => false, 'message' => 'Colecție invalidă sau fără acces']);
        return;
    }
    
    $prefix = $row['prefix_tabele'];
    $db_name = $row['db_name'];
    $proprietar_id = $row['id_utilizator'];
    mysqli_stmt_close($stmt);
    
    // Folosim conexiunea centrală - toate tabelele sunt în inventar_central
    $conn_user = $conn_central;
    
    // Obține lista de cutii unice
    $table_obiecte = $prefix . 'obiecte';
    
    // Verificăm mai întâi dacă tabela există
    $check_table = "SHOW TABLES LIKE '$table_obiecte'";
    $table_exists = mysqli_query($conn_user, $check_table);
    if (!$table_exists || mysqli_num_rows($table_exists) == 0) {
        echo json_encode(['success' => false, 'message' => 'Tabela ' . $table_obiecte . ' nu există în ' . $db_name]);
        return;
    }
    
    $sql_cutii = "SELECT DISTINCT cutie, locatie 
                  FROM `$table_obiecte` 
                  WHERE cutie IS NOT NULL AND cutie != ''
                  ORDER BY locatie, cutie";
    
    $stmt = mysqli_prepare($conn_user, $sql_cutii);
    if (!$stmt) {
        $error = mysqli_error($conn_user);
        // Nu închidem conexiunea centrală
        echo json_encode(['success' => false, 'message' => 'Eroare la pregătirea query: ' . $error]);
        return;
    }
    
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $cutii = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $cutii[] = [
            'id' => $row['cutie'] . '|' . $row['locatie'],
            'cutie' => $row['cutie'],
            'locatie' => $row['locatie'] ?? '',
            'display' => $row['cutie'] . (!empty($row['locatie']) ? ' (' . $row['locatie'] . ')' : '')
        ];
    }
    
    mysqli_stmt_close($stmt);
    // Nu închidem conexiunea centrală
    
    echo json_encode(['success' => true, 'cutii' => $cutii]);
}

/**
 * Obține lista de cutii cu starea actuală de partajare pentru un utilizator
 */
function obtineCutiiCuStare($conn_central, $user, $data) {
    $id_colectie = $data['id_colectie'] ?? $user['id_colectie_principala'];
    $email = $data['email'] ?? '';
    
    // Mai întâi verificăm dacă există o partajare existentă
    $partajare_existenta = null;
    $cutii_partajate_array = [];
    
    if (!empty($email)) {
        // Obține ID-ul utilizatorului după email
        $sql_user = "SELECT id_utilizator FROM utilizatori WHERE email = ?";
        $stmt = mysqli_prepare($conn_central, $sql_user);
        mysqli_stmt_bind_param($stmt, "s", $email);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if ($row = mysqli_fetch_assoc($result)) {
            $id_utilizator_partajat = $row['id_utilizator'];
            mysqli_stmt_close($stmt);
            
            // Verifică dacă există deja o partajare
            $sql_partajare = "SELECT tip_acces, tip_partajare, cutii_partajate 
                              FROM partajari 
                              WHERE id_colectie = ? AND id_utilizator_partajat = ? AND activ = 1";
            $stmt = mysqli_prepare($conn_central, $sql_partajare);
            mysqli_stmt_bind_param($stmt, "ii", $id_colectie, $id_utilizator_partajat);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            
            if ($row = mysqli_fetch_assoc($result)) {
                $partajare_existenta = [
                    'tip_acces' => $row['tip_acces'],
                    'tip_partajare' => $row['tip_partajare']
                ];
                
                if (!empty($row['cutii_partajate'])) {
                    $cutii_partajate_array = json_decode($row['cutii_partajate'], true) ?? [];
                }
            }
            mysqli_stmt_close($stmt);
        } else {
            mysqli_stmt_close($stmt);
        }
    }
    
    // Obține informații despre colecție
    $sql_col = "SELECT c.prefix_tabele, c.db_name, c.id_utilizator 
                FROM colectii_utilizatori c 
                WHERE c.id_colectie = ? 
                AND (c.id_utilizator = ? OR EXISTS (
                    SELECT 1 FROM partajari p 
                    WHERE p.id_colectie = c.id_colectie 
                    AND p.id_utilizator_partajat = ? 
                    AND p.activ = 1
                ))";
    $stmt = mysqli_prepare($conn_central, $sql_col);
    mysqli_stmt_bind_param($stmt, "iii", $id_colectie, $user['id_utilizator'], $user['id_utilizator']);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if (!($row = mysqli_fetch_assoc($result))) {
        mysqli_stmt_close($stmt);
        echo json_encode(['success' => false, 'message' => 'Colecție invalidă sau fără acces']);
        return;
    }
    
    $prefix = $row['prefix_tabele'];
    $db_name = $row['db_name'];
    mysqli_stmt_close($stmt);
    
    // Folosim conexiunea centrală - toate tabelele sunt în inventar_central
    $conn_user = $conn_central;
    
    // Obține lista de cutii și verifică dacă sunt marcate cu obiecte_partajate
    $table_obiecte = $prefix . 'obiecte';
    $sql_cutii = "SELECT DISTINCT cutie, locatie, obiecte_partajate 
                  FROM `$table_obiecte` 
                  WHERE cutie IS NOT NULL AND cutie != ''
                  ORDER BY locatie, cutie";
    
    $stmt = mysqli_prepare($conn_user, $sql_cutii);
    if (!$stmt) {
        // Nu închidem conexiunea centrală
        echo json_encode(['success' => false, 'message' => 'Eroare la obținerea cutiilor']);
        return;
    }
    
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $cutii = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $cutie_id = $row['cutie'] . '|' . $row['locatie'];
        
        // Verifică două surse de partajare:
        // 1. Din tabela partajari (pentru membri familie)
        $selectat_partajari = in_array($cutie_id, $cutii_partajate_array);
        
        // 2. Din coloana obiecte_partajate (pentru marcarea cu purple)
        $selectat_obiecte = !empty($row['obiecte_partajate']);
        
        $cutii[] = [
            'id' => $cutie_id,
            'cutie' => $row['cutie'],
            'locatie' => $row['locatie'] ?? '',
            'display' => $row['cutie'] . (!empty($row['locatie']) ? ' (' . $row['locatie'] . ')' : ''),
            'selectat' => $selectat_partajari || $selectat_obiecte // Selectat dacă e marcat în oricare din cele două
        ];
    }
    
    mysqli_stmt_close($stmt);
    // Nu închidem conexiunea centrală
    
    echo json_encode([
        'success' => true, 
        'cutii' => $cutii,
        'partajare_existenta' => $partajare_existenta
    ]);
}

/**
 * Funcție helper pentru a obține numele unei colecții
 */
function getNumeColectie($conn, $id_colectie) {
    $sql = "SELECT nume_colectie FROM colectii_utilizatori WHERE id_colectie = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $id_colectie);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($row = mysqli_fetch_assoc($result)) {
        $nume = $row['nume_colectie'];
        mysqli_stmt_close($stmt);
        return $nume;
    }
    mysqli_stmt_close($stmt);
    return 'Necunoscută';
}
?>