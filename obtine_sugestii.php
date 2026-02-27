<?php // obtine_sugestii.php - cod optimizat
// Dezactivăm afișarea erorilor HTML pentru a returna doar JSON
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Funcție pentru a returna erori în format JSON
function returnError($message) {
    header('Content-Type: application/json');
    echo json_encode(['eroare' => $message]);
    exit;
}

// Handler pentru erori fatale
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && ($error['type'] === E_ERROR || $error['type'] === E_PARSE)) {
        header('Content-Type: application/json');
        echo json_encode(['eroare' => 'Eroare server: ' . $error['message']]);
    }
});

session_start();

// Verificăm dacă config.php există
if (!file_exists('config.php')) {
    returnError('Fișierul config.php nu a fost găsit');
}
include 'config.php';

// Verifică autentificarea pentru sistemul multi-tenant
if (file_exists('includes/auth_functions.php')) {
    require_once 'includes/auth_functions.php';
    
    // Verifică dacă utilizatorul este autentificat
    $user = checkSession();
    if (!$user) {
        http_response_code(401);
        echo json_encode(['eroare' => 'Neautorizat']);
        exit;
    }
    
    // Reconectează la baza de date a utilizatorului
    mysqli_close($conn);
    $conn = getUserDbConnection($user['db_name']);
    if (!$conn) {
        http_response_code(500);
        echo json_encode(['eroare' => 'Eroare la conectarea la baza de date']);
        exit;
    }
    
    // Determină prefixul corect bazat pe colecția curentă
    // Prioritate: parametru GET > sesiune selectată > sesiune curentă
    $id_colectie = intval($_GET['id_colectie'] ?? $_SESSION['id_colectie_selectata'] ?? $_SESSION['id_colectie_curenta'] ?? 0);
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
                    $user_id = $colectie_proprietar_id;
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
    $id_colectie = 0; // Setăm implicit pentru context non multi-tenant
}

// Inițializare variabile și sanitizare
$tip = isset($_GET['tip']) ? $_GET['tip'] : '';
$cautare = isset($_GET['cautare']) ? $_GET['cautare'] : null; // Poate fi null pentru a indica că vrem toate rezultatele
$locatie = isset($_GET['locatie']) ? trim($_GET['locatie']) : '';

// Logare pentru debug
$id_colectie_debug = isset($id_colectie) ? $id_colectie : 'nedefinit';
error_log("obtine_sugestii.php - ID colectie=$id_colectie_debug, Prefix folosit=$table_prefix, Tip=$tip, Locatie='$locatie'");

// Verificăm dacă tipul este valid
if ($tip !== 'cutie' && $tip !== 'locatie') {
    http_response_code(400);
    echo json_encode(['eroare' => 'Tip invalid']);
    exit;
}

// Definim interogarea SQL în funcție de tip
if ($tip === 'locatie') {
    // Interogare pentru locații
    $sql = "SELECT DISTINCT locatie FROM {$table_prefix}obiecte WHERE locatie IS NOT NULL AND locatie != ''";

    // Adăugăm filtrarea după termen de căutare dacă există
    if ($cautare !== null) {
        $sql .= " AND locatie LIKE ?";
        $stmt = mysqli_prepare($conn, $sql);
        $cautare_param = "%$cautare%";
        mysqli_stmt_bind_param($stmt, 's', $cautare_param);
    } else {
        // Returnăm toate locațiile fără filtrare
        $stmt = mysqli_prepare($conn, $sql);
    }

    // Adăugăm limitare și ordonare
    $sql .= " ORDER BY locatie ASC LIMIT 30";

} else {
    // Interogare pentru cutii
    if (!empty($locatie)) {
        // Dacă cautare este null, returnăm TOATE cutiile pentru această locație
        if ($cautare === null) {
            $sql = "SELECT DISTINCT cutie FROM {$table_prefix}obiecte 
                    WHERE cutie IS NOT NULL AND cutie != '' 
                    AND locatie = ? 
                    ORDER BY cutie ASC";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, 's', $locatie);
        } else if (!empty($cautare)) {
            // Filtrare după locație și termen de căutare
            $sql = "SELECT DISTINCT cutie FROM {$table_prefix}obiecte 
                    WHERE cutie IS NOT NULL AND cutie != '' 
                    AND locatie = ? AND cutie LIKE ? 
                    ORDER BY cutie ASC";
            $stmt = mysqli_prepare($conn, $sql);
            $cautare_param = "%$cautare%";
            mysqli_stmt_bind_param($stmt, 'ss', $locatie, $cautare_param);
        } else {
            // Doar filtrare după locație
            $sql = "SELECT DISTINCT cutie FROM {$table_prefix}obiecte 
                    WHERE cutie IS NOT NULL AND cutie != '' 
                    AND locatie = ? 
                    ORDER BY cutie ASC";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, 's', $locatie);
        }
    } else if ($cautare !== null) {
        // Doar filtrare după termen de căutare
        $sql = "SELECT DISTINCT cutie FROM {$table_prefix}obiecte 
                WHERE cutie IS NOT NULL AND cutie != '' 
                AND cutie LIKE ? 
                ORDER BY cutie ASC";
        $stmt = mysqli_prepare($conn, $sql);
        $cautare_param = "%$cautare%";
        mysqli_stmt_bind_param($stmt, 's', $cautare_param);
    } else {
        // Fără filtrare - returnăm toate cutiile
        $sql = "SELECT DISTINCT cutie FROM {$table_prefix}obiecte 
                WHERE cutie IS NOT NULL AND cutie != '' 
                ORDER BY cutie ASC LIMIT 100";
        $stmt = mysqli_prepare($conn, $sql);
    }
}

// Logăm interogarea pentru debug
error_log("SQL: $sql");

// Executăm interogarea
mysqli_stmt_execute($stmt);
$rezultat = mysqli_stmt_get_result($stmt);

// Verificăm dacă avem erori
if (!$rezultat) {
    error_log("Eroare MySQL: " . mysqli_error($conn));
    http_response_code(500);
    echo json_encode(['eroare' => 'Eroare la interogare: ' . mysqli_error($conn)]);
    exit;
}

// Colectăm valorile într-un array
$valori = [];
while ($row = mysqli_fetch_assoc($rezultat)) {
    $valori[] = $row[$tip];
}

// Logăm rezultatele
error_log("Rezultate găsite: " . count($valori) . " - " . json_encode($valori));

// Returnăm valorile ca JSON
header('Content-Type: application/json');
echo json_encode($valori);

// Închidem conexiunea
mysqli_stmt_close($stmt);
mysqli_close($conn);
?>