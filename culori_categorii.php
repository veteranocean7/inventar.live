<?php
header('Content-Type: application/json');
session_start();
include 'config.php';

// Verifică autentificarea pentru sistemul multi-tenant
if (file_exists('includes/auth_functions.php')) {
    require_once 'includes/auth_functions.php';
    
    $user = checkSession();
    if (!$user) {
        echo json_encode([]);
        exit;
    }
    
    // Reconectează la baza de date a utilizatorului
    mysqli_close($conn);
    $conn = getUserDbConnection($user['db_name']);
    
    // Determină prefixul corect bazat pe colecția curentă
    // Prioritate: GET > sesiune selectată > sesiune curentă
    $id_colectie = $_GET['colectie'] ?? $_SESSION['id_colectie_selectata'] ?? $_SESSION['id_colectie_curenta'] ?? null;
    
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
            // error_log("culori_categorii.php - Folosesc prefix: $table_prefix pentru colecția $id_colectie");
        } else {
            $table_prefix = $user['prefix_tabele'] ?? 'user_' . $user['id_utilizator'] . '_';
            $colectie_proprietar_id = $user['id_utilizator'];
            $_SESSION['tip_acces_colectie'] = 'proprietar';
        }
        mysqli_stmt_close($stmt_prefix);
        mysqli_close($conn_central);
    } else {
        $table_prefix = $_SESSION['prefix_tabele'] ?? $user['prefix_tabele'] ?? 'user_' . $user['id_utilizator'] . '_';
        $colectie_proprietar_id = $user['id_utilizator'];
        $_SESSION['tip_acces_colectie'] = 'proprietar';
    }
    
    $user_id = $colectie_proprietar_id ?? $user['id_utilizator'];
} else {
    $table_prefix = $GLOBALS['table_prefix'] ?? '';
    $user_id = getCurrentUserId();
}

$categorii_culori = [];

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    echo json_encode([]);
    exit;
}

$sql = "SELECT categorie, eticheta FROM {$table_prefix}obiecte WHERE id_obiect = ?";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, 'i', $id);
mysqli_stmt_execute($stmt);
$rez = mysqli_stmt_get_result($stmt);

if ($row = mysqli_fetch_assoc($rez)) {
    $categorii = explode(',', $row['categorie']);
    $culori    = explode(',', $row['eticheta']);

    foreach ($categorii as $i => $cat) {
        $cat = trim($cat);
        $cul = isset($culori[$i]) ? trim($culori[$i]) : null;
        if ($cat !== '' && $cul && !isset($categorii_culori[$cat])) {
            $categorii_culori[$cat] = $cul;
        }
    }
}

mysqli_stmt_close($stmt);
mysqli_close($conn);
echo json_encode($categorii_culori, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
