<?php
// adauga_obiect.php
session_start();
include 'config.php';

// Dezactivăm afișarea erorilor pentru a nu strica JSON-ul
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Creăm un fișier de log - DEZACTIVAT PENTRU PRODUCȚIE
$debug_log = "debug_cautare.txt"; // Păstrăm variabila definită pentru a evita erori
// file_put_contents($debug_log, "--- Sesiune nouă: " . date('Y-m-d H:i:s') . " ---\n", FILE_APPEND);

// CRITICAL FIX: Păstrăm colecția selectată persistent
if (isset($_POST['id_colectie']) && $_POST['id_colectie'] > 0) {
    $_SESSION['id_colectie_selectata'] = intval($_POST['id_colectie']);
    $_SESSION['id_colectie_curenta'] = intval($_POST['id_colectie']);
    $_SESSION['upload_colectie_id'] = intval($_POST['id_colectie']);
    // file_put_contents($debug_log, "SALVEZ în sesiune: id_colectie = " . $_POST['id_colectie'] . "\n", FILE_APPEND);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Metodă nepermisă.');
}

$cutie = trim($_POST['cutie'] ?? '');
$locatie = trim($_POST['locatie'] ?? '');
// Preluăm sau setăm valori default pentru categorie și descriere_categorie
$categorie = trim($_POST['categorie'] ?? 'obiecte');
$descriere_categorie = trim($_POST['descriere_categorie'] ?? 'descrie obiecte');
$eticheta = trim($_POST['eticheta'] ?? '#4CAF50'); // Verde pentru categoria implicită 'obiecte'

// Log pentru valorile primite - DEZACTIVAT PENTRU PRODUCȚIE
// file_put_contents($debug_log, "Valori primite:\n", FILE_APPEND);
// file_put_contents($debug_log, "Cutie: '$cutie'\n", FILE_APPEND);
// file_put_contents($debug_log, "Locație: '$locatie'\n", FILE_APPEND);
// file_put_contents($debug_log, "ID colecție din POST: '" . ($_POST['id_colectie'] ?? 'nedefinit') . "'\n", FILE_APPEND);
// file_put_contents($debug_log, "ID colecție din sesiune: '" . ($_SESSION['id_colectie_curenta'] ?? 'nedefinit') . "'\n", FILE_APPEND);
// file_put_contents($debug_log, "ID colecție determinat: '\n", FILE_APPEND);

if (empty($cutie) || empty($locatie) || empty($_FILES['imagini'])) {
    http_response_code(400);
    exit('Date insuficiente.');
}

// Verifică autentificarea și determină prefixul corect
if (file_exists('includes/auth_functions.php')) {
    require_once 'includes/auth_functions.php';
    
    $user = checkSession();
    if (!$user) {
        http_response_code(401);
        exit('Neautorizat');
    }
    
    // Reconectare la baza de date a utilizatorului
    mysqli_close($conn);
    $conn = getUserDbConnection($user['db_name']);
    
    // Determinăm colecția - FOLOSIM sesiunea salvată mai sus
    $id_colectie = $_SESSION['upload_colectie_id'] ?? $_SESSION['id_colectie_selectata'] ?? $_SESSION['id_colectie_curenta'] ?? 0;
    
    // file_put_contents($debug_log, "=== DETERMINARE COLECȚIE ===\n", FILE_APPEND);
    // file_put_contents($debug_log, "POST id_colectie: " . ($_POST['id_colectie'] ?? 'null') . "\n", FILE_APPEND);
    // file_put_contents($debug_log, "SESSION upload_colectie_id: " . ($_SESSION['upload_colectie_id'] ?? 'null') . "\n", FILE_APPEND);
    // file_put_contents($debug_log, "SESSION selectata: " . ($_SESSION['id_colectie_selectata'] ?? 'null') . "\n", FILE_APPEND);
    // file_put_contents($debug_log, "SESSION curenta: " . ($_SESSION['id_colectie_curenta'] ?? 'null') . "\n", FILE_APPEND);
    // file_put_contents($debug_log, "ID colecție final: $id_colectie\n", FILE_APPEND);
    
    $user_id = $user['id_utilizator'];
    $table_prefix = $user['prefix_tabele'] ?? 'user_' . $user_id . '_';
    
    // Dacă avem un id_colectie, determinăm prefixul corect
    if ($id_colectie > 0) {
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
                http_response_code(403);
                exit('Nu aveți drepturi de editare pentru această colecție');
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
                }
                mysqli_stmt_close($stmt_owner);
            } else {
                // IMPORTANT: Pentru colecțiile proprii (principale sau secundare), 
                // folosim ID-ul proprietarului care este același cu ID-ul utilizatorului
                $user_id = $colectie_proprietar_id;
            }
            // file_put_contents($debug_log, "Prefix găsit în BD pentru colecția $id_colectie: '$table_prefix'\n", FILE_APPEND);
        } else {
            // file_put_contents($debug_log, "Nu s-a găsit prefix pentru colecția $id_colectie, folosesc implicit: '$table_prefix'\n", FILE_APPEND);
        }
        mysqli_stmt_close($stmt_prefix);
        mysqli_close($conn_central);
    }
} else {
    // Context non multi-tenant
    $user_id = getCurrentUserId();
    $table_prefix = $GLOBALS['table_prefix'] ?? 'user_' . $user_id . '_';
    $id_colectie = $_SESSION['upload_colectie_id'] ?? $_SESSION['id_colectie_selectata'] ?? 0;
}

// Ne asigurăm că folderul de imagini există pentru utilizatorul curent
$folder_upload = 'imagini_obiecte/user_' . $user_id;
// Debug: verifică calea de upload - DEZACTIVAT pentru producție
// error_log("adauga_obiect.php - Folder upload: $folder_upload pentru colecția $id_colectie");
if (!is_dir($folder_upload)) {
    mkdir($folder_upload, 0777, true);
}

// Funcție pentru sanitizarea numelor de fișiere
function sanitizeFileName($filename) {
    // Păstrăm extensia
    $path_info = pathinfo($filename);
    $extension = isset($path_info['extension']) ? '.' . $path_info['extension'] : '';
    $name = $path_info['filename'];
    
    // Înlocuim caracterele problematice cu underscore
    // Eliminăm: # % & { } \ < > * ? / $ ! ' " : @ + ` | =
    $name = preg_replace('/[#%&{}\\<>*?\/\$!\'":@+`|=]/', '_', $name);
    
    // Înlocuim spațiile multiple cu un singur spațiu
    $name = preg_replace('/\s+/', ' ', $name);
    
    // Trim spațiile de la început și sfârșit
    $name = trim($name);
    
    // Dacă numele devine gol, folosim un timestamp
    if (empty($name)) {
        $name = 'img_' . time() . '_' . rand(1000, 9999);
    }
    
    return $name . $extension;
}

$imagini_incarcate = [];
foreach ($_FILES['imagini']['tmp_name'] as $key => $tmp_name) {
    if (is_uploaded_file($tmp_name)) {
        $nume_original = basename($_FILES['imagini']['name'][$key]);
        $nume_fisier = sanitizeFileName($nume_original);
        
        // Asigurăm unicitatea dacă fișierul există deja
        $cale_finala = $folder_upload . '/' . $nume_fisier;
        $counter = 1;
        while (file_exists($cale_finala)) {
            $path_info = pathinfo($nume_fisier);
            $extension = isset($path_info['extension']) ? '.' . $path_info['extension'] : '';
            $name = $path_info['filename'];
            $nume_fisier_nou = $name . '_' . $counter . $extension;
            $cale_finala = $folder_upload . '/' . $nume_fisier_nou;
            $counter++;
            if ($counter > 100) break; // Evităm bucle infinite
        }
        
        if ($counter <= 100) {
            $nume_fisier = basename($cale_finala);
        }

        if (move_uploaded_file($tmp_name, $cale_finala)) {
            $imagini_incarcate[] = $nume_fisier;
        }
    }
}

if (empty($imagini_incarcate)) {
    http_response_code(500);
    exit('Eroare la salvarea imaginilor.');
}

// Verificăm dacă există deja o cutie + locație
// Folosește tabelele cu prefix determinat mai sus
file_put_contents($debug_log, "=== VERIFICARE EXISTENȚĂ ===\n", FILE_APPEND);
file_put_contents($debug_log, "Prefix final folosit: '$table_prefix'\n", FILE_APPEND);
file_put_contents($debug_log, "Tabela folosită pentru verificare: '{$table_prefix}obiecte'\n", FILE_APPEND);
file_put_contents($debug_log, "Cutie: '$cutie', Locație: '$locatie'\n", FILE_APPEND);
$sql = "SELECT id_obiect, imagine FROM `{$table_prefix}obiecte` WHERE cutie = ? AND locatie = ? LIMIT 1";
file_put_contents($debug_log, "SQL: $sql\n", FILE_APPEND);
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, 'ss', $cutie, $locatie);
mysqli_stmt_execute($stmt);
$rezultat = mysqli_stmt_get_result($stmt);
$exista = mysqli_fetch_assoc($rezultat);
mysqli_stmt_close($stmt);

if ($exista) {
    file_put_contents($debug_log, "Găsit obiect existent cu ID: " . $exista['id_obiect'] . "\n", FILE_APPEND);
} else {
    file_put_contents($debug_log, "Nu există obiect pentru această combinație cutie+locație\n", FILE_APPEND);
}

// Dacă există deja înregistrare
if ($exista) {
    // Actualizăm imaginile existente
    $imagini_existente = [];
    if (!empty($exista['imagine'])) {
        $imagini_existente = array_map('trim', explode(',', $exista['imagine']));
        file_put_contents($debug_log, "Imagini existente: " . implode(", ", $imagini_existente) . "\n", FILE_APPEND);
    } else {
        file_put_contents($debug_log, "Nu există imagini anterioare\n", FILE_APPEND);
    }

    $toate_imaginile = array_merge($imagini_existente, $imagini_incarcate);
    $sir_final_imagini = implode(', ', $toate_imaginile);

    // Actualizăm și categoria/descrierea/eticheta dacă lipsesc
    $sql_update = "UPDATE `{$table_prefix}obiecte` SET 
                   imagine = ?,
                   categorie = COALESCE(NULLIF(categorie, ''), ?),
                   descriere_categorie = COALESCE(NULLIF(descriere_categorie, ''), ?),
                   eticheta = COALESCE(NULLIF(eticheta, ''), ?)
                   WHERE id_obiect = ?";
    $stmt = mysqli_prepare($conn, $sql_update);
    mysqli_stmt_bind_param($stmt, 'ssssi', $sir_final_imagini, $categorie, $descriere_categorie, $eticheta, $exista['id_obiect']);
    $rezultat_update = mysqli_stmt_execute($stmt);

    if ($rezultat_update) {
        file_put_contents($debug_log, "Actualizare reușită\n", FILE_APPEND);
    } else {
        file_put_contents($debug_log, "Eroare la actualizare: " . mysqli_error($conn) . "\n", FILE_APPEND);
    }

    mysqli_stmt_close($stmt);
} else {
    // Inserăm un nou rând
    $sir_final_imagini = implode(', ', $imagini_incarcate);

    // Folosește tabelele cu prefix determinat mai sus
    $sql_insert = "INSERT INTO `{$table_prefix}obiecte` (cutie, locatie, imagine, categorie, descriere_categorie, eticheta) VALUES (?, ?, ?, ?, ?, ?)";
    $stmt = mysqli_prepare($conn, $sql_insert);

    if (!$stmt) {
        file_put_contents($debug_log, "Eroare la pregătirea interogării: " . mysqli_error($conn) . "\n", FILE_APPEND);
    } else {
        mysqli_stmt_bind_param($stmt, 'ssssss', $cutie, $locatie, $sir_final_imagini, $categorie, $descriere_categorie, $eticheta);
        $rezultat_inserare = mysqli_stmt_execute($stmt);

        if ($rezultat_inserare) {
            file_put_contents($debug_log, "Inserare reușită cu ID: " . mysqli_insert_id($conn) . "\n", FILE_APPEND);
        } else {
            file_put_contents($debug_log, "Eroare la inserare: " . mysqli_stmt_error($stmt) . "\n", FILE_APPEND);
        }

        mysqli_stmt_close($stmt);
    }
}

file_put_contents($debug_log, "--- Sfârșitul sesiunii ---\n\n", FILE_APPEND);

// Determinăm ID-ul obiectului și prima imagine
$id_obiect = $exista ? $exista['id_obiect'] : mysqli_insert_id($conn);
$prima_imagine = $imagini_incarcate[0]; // Prima imagine din cele noi adăugate

mysqli_close($conn);

// Returnăm JSON cu informațiile necesare pentru redirect
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'id_obiect' => $id_obiect,
    'imagine' => $prima_imagine,
    'message' => 'Imaginile au fost adăugate cu succes!'
]);
exit();