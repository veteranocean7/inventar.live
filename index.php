<?php
// lista_obiecte.php
include 'config.php';

// Inițializăm sesiunea dacă nu este deja inițializată - IMPORTANT: trebuie să fie prima
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verifică autentificarea pentru sistemul multi-tenant
if (file_exists('includes/auth_functions.php')) {
    require_once 'includes/auth_functions.php';

    // Verifică dacă utilizatorul este autentificat
    $user = checkSession();
    if (!$user) {
        // Utilizatorul nu este autentificat, redirecționează la login
        header('Location: login.php');
        exit;
    }
    
    // Dacă vine după un setup nou, forțează reîncărcarea datelor utilizatorului
    if (isset($_GET['new_setup'])) {
        $conn_refresh = getCentralDbConnection();
        $sql_refresh = "SELECT * FROM utilizatori WHERE id_utilizator = ?";
        $stmt_refresh = mysqli_prepare($conn_refresh, $sql_refresh);
        mysqli_stmt_bind_param($stmt_refresh, "i", $user['id_utilizator']);
        mysqli_stmt_execute($stmt_refresh);
        $result_refresh = mysqli_stmt_get_result($stmt_refresh);
        if ($row_refresh = mysqli_fetch_assoc($result_refresh)) {
            $user = $row_refresh;
            $_SESSION['user_data'] = $row_refresh;
        }
        mysqli_stmt_close($stmt_refresh);
        mysqli_close($conn_refresh);
        
        // Curăță sesiunea de date vechi
        unset($_SESSION['colectie_proprietar_id']);
        unset($_SESSION['tip_acces_colectie']);
        unset($_SESSION['tip_partajare']);
        unset($_SESSION['cutii_partajate']);
        
        // Setează colecția principală ca activă
        if ($user['id_colectie_principala']) {
            $_SESSION['id_colectie_curenta'] = $user['id_colectie_principala'];
            $_SESSION['id_colectie_selectata'] = $user['id_colectie_principala'];
            $_SESSION['prefix_tabele'] = $user['prefix_tabele'];
        }
    }

    // Verifică dacă utilizatorul are baza de date configurată
    if (empty($user['db_name'])) {
        header('Location: setup_user_db.php');
        exit;
    }

    // TEST: Schimbare manuală colecție prin URL
    if (isset($_GET['test_colectie'])) {
        $_SESSION['id_colectie_curenta'] = intval($_GET['test_colectie']);
        // Obține prefixul pentru noua colecție
        $conn_test = getCentralDbConnection();
        $sql_test = "SELECT prefix_tabele FROM colectii_utilizatori WHERE id_colectie = ? AND id_utilizator = ?";
        $stmt_test = mysqli_prepare($conn_test, $sql_test);
        mysqli_stmt_bind_param($stmt_test, "ii", $_SESSION['id_colectie_curenta'], $user['id_utilizator']);
        mysqli_stmt_execute($stmt_test);
        $result_test = mysqli_stmt_get_result($stmt_test);
        if ($row_test = mysqli_fetch_assoc($result_test)) {
            $_SESSION['prefix_tabele'] = $row_test['prefix_tabele'];
            echo "<!-- TEST: Colecție schimbată la ID " . $_SESSION['id_colectie_curenta'] . " cu prefix " . $_SESSION['prefix_tabele'] . " -->";
        }
        mysqli_stmt_close($stmt_test);
        mysqli_close($conn_test);
    }

    // Schimbare colecție prin URL
    if (isset($_GET['c'])) {
        $id_colectie_noua = intval($_GET['c']);
        // Verifică că utilizatorul are acces la această colecție
        $conn_check = getCentralDbConnection();
        $sql_check = "SELECT c.id_colectie, c.prefix_tabele, p.tip_acces
                      FROM colectii_utilizatori c
                      LEFT JOIN partajari p ON c.id_colectie = p.id_colectie 
                           AND p.id_utilizator_partajat = ? AND p.activ = 1
                      WHERE c.id_colectie = ? 
                      AND (c.id_utilizator = ? OR p.id_partajare IS NOT NULL)";

        $stmt_check = mysqli_prepare($conn_check, $sql_check);
        mysqli_stmt_bind_param($stmt_check, "iii", $user['id_utilizator'], $id_colectie_noua, $user['id_utilizator']);
        mysqli_stmt_execute($stmt_check);
        $result_check = mysqli_stmt_get_result($stmt_check);

        if ($row_check = mysqli_fetch_assoc($result_check)) {
            // Utilizatorul are acces - actualizează sesiunea
            $_SESSION['id_colectie_curenta'] = $id_colectie_noua;
            $_SESSION['id_colectie_selectata'] = $id_colectie_noua; // Adăugăm și aceasta pentru persistență
            $_SESSION['prefix_tabele'] = $row_check['prefix_tabele'];
            $_SESSION['tip_acces_colectie'] = $row_check['tip_acces'] ?? 'proprietar';

            // LOG pentru debug
            error_log("Schimbare colecție: ID=$id_colectie_noua, Prefix={$row_check['prefix_tabele']}");

            // Redirecționează pentru a elimina parametrul din URL
            mysqli_stmt_close($stmt_check);
            mysqli_close($conn_check);
            header('Location: index.php');
            exit;
        }
        mysqli_stmt_close($stmt_check);
        mysqli_close($conn_check);
    }

    // Reconectează la baza de date a utilizatorului
    mysqli_close($conn);
    $conn = getUserDbConnection($user['db_name']);
    if (!$conn) {
        die("Eroare la conectarea la baza de date personală.");
    }

    // Setează prefix pentru tabele
    $GLOBALS['table_prefix'] = $user['prefix_tabele'] ?? 'user_' . $user['id_utilizator'] . '_';
}

// Verificăm dacă există informații în parametrii URL pentru a le transfera în sesiune
if (isset($_GET['id_obiect']) && isset($_GET['imagine'])) {
    // Salvăm selecția în sesiune
    $_SESSION['ultima_imagine_' . $_GET['id_obiect']] = $_GET['imagine'];
}

// Determină colecția curentă și prefixul de tabele
$conn_central_temp = getCentralDbConnection();
// Prioritate: sesiune selectată > sesiune curentă > colecția principală
$id_colectie_curenta = $_SESSION['id_colectie_selectata'] ?? $_SESSION['id_colectie_curenta'] ?? $user['id_colectie_principala'] ?? null;
// error_log("Index.php - Colecție curentă: $id_colectie_curenta, din sesiune selectată: " . ($_SESSION['id_colectie_selectata'] ?? 'null') . ", din sesiune curentă: " . ($_SESSION['id_colectie_curenta'] ?? 'null'));

if ($id_colectie_curenta) {
    // Obține prefixul și proprietarul pentru colecția selectată, inclusiv info despre partajare selectivă
    // IMPORTANT: Includem și colecțiile proprii (nu doar partajate)
    $sql_prefix = "SELECT c.prefix_tabele, c.id_utilizator as proprietar_id, 
                          p.tip_acces, p.tip_partajare, p.cutii_partajate 
                   FROM colectii_utilizatori c
                   LEFT JOIN partajari p ON c.id_colectie = p.id_colectie 
                        AND p.id_utilizator_partajat = ? AND p.activ = 1
                   WHERE c.id_colectie = ? 
                   AND (c.id_utilizator = ? OR p.id_partajare IS NOT NULL)";
    $stmt_prefix = mysqli_prepare($conn_central_temp, $sql_prefix);
    mysqli_stmt_bind_param($stmt_prefix, "iii", $user['id_utilizator'], $id_colectie_curenta, $user['id_utilizator']);
    mysqli_stmt_execute($stmt_prefix);
    $result_prefix = mysqli_stmt_get_result($stmt_prefix);

    if ($row_prefix = mysqli_fetch_assoc($result_prefix)) {
        $table_prefix = $row_prefix['prefix_tabele'];
        $_SESSION['prefix_tabele'] = $table_prefix;
        // IMPORTANT: Actualizează și variabila globală
        $GLOBALS['table_prefix'] = $table_prefix;
        
        // Debug: Verifică prefixul obținut - doar când e activat debug
        if (isset($_GET['debug'])) {
            error_log("Index.php - Prefix găsit pentru colecția $id_colectie_curenta: $table_prefix");
        }
        
        // Salvăm ID-ul proprietarului colecției pentru calea imaginilor
        $colectie_proprietar_id = $row_prefix['proprietar_id'];
        $_SESSION['colectie_proprietar_id'] = $colectie_proprietar_id;
        
        // Salvăm tipul de acces pentru această colecție
        $_SESSION['tip_acces_colectie'] = $row_prefix['tip_acces'] ?? 'proprietar';
        
        // Salvăm informații despre partajare selectivă
        $_SESSION['tip_partajare'] = $row_prefix['tip_partajare'] ?? 'completa';
        $_SESSION['cutii_partajate'] = $row_prefix['cutii_partajate'] ?? null;
    } else {
        // Nu s-a găsit colecția - log pentru debug
        error_log("Index.php - ATENȚIE: Nu s-a găsit prefix pentru colecția $id_colectie_curenta");
        error_log("Index.php - User ID: " . $user['id_utilizator']);
        
        // Folosește prefixul implicit pentru utilizator
        $table_prefix = $user['prefix_tabele'] ?? 'user_' . $user['id_utilizator'] . '_';
        $GLOBALS['table_prefix'] = $table_prefix;
        $_SESSION['prefix_tabele'] = $table_prefix;
        
        // IMPORTANT: Setăm și proprietarul pentru colecțiile proprii
        $_SESSION['colectie_proprietar_id'] = $user['id_utilizator'];
        $_SESSION['tip_acces_colectie'] = 'proprietar';
    }
    mysqli_stmt_close($stmt_prefix);
} else {
    // Dacă nu avem prefix în sesiune, folosește cel implicit
    $table_prefix = $_SESSION['prefix_tabele'] ?? $user['prefix_tabele'] ?? 'user_' . $user['id_utilizator'] . '_';
    $GLOBALS['table_prefix'] = $table_prefix;
    
    // Pentru colecția proprie, proprietarul este utilizatorul curent
    $_SESSION['colectie_proprietar_id'] = $user['id_utilizator'];
    $_SESSION['tip_acces_colectie'] = 'proprietar';
}
mysqli_close($conn_central_temp);

// Folosește ID-ul proprietarului colecției pentru calea imaginilor
// Dacă vizualizăm o colecție partajată, folosim ID-ul proprietarului colecției
// Altfel, folosim ID-ul utilizatorului curent
$user_id = $_SESSION['colectie_proprietar_id'] ?? getCurrentUserId();

// Debug complet înainte de query - TEMPORAR pentru debugging
if (isset($_GET['debug'])) {
    error_log("Index.php - Debug înainte de query principal:");
    error_log("  - ID Colecție curentă: $id_colectie_curenta");
    error_log("  - Prefix tabel folosit: $table_prefix");
    error_log("  - User ID pentru imagini: $user_id");
    error_log("  - Proprietar colecție: " . ($_SESSION['colectie_proprietar_id'] ?? 'null'));
}

$sql = "SELECT * FROM `{$table_prefix}obiecte` ORDER BY data_adaugare DESC, locatie, cutie";
// error_log("Index.php - Query pentru obiecte: $sql");
$rezultat = mysqli_query($conn, $sql);

if (!$rezultat) {
    error_log("Index.php - EROARE Query: " . mysqli_error($conn));
}

// Funcție pentru a verifica sursa unui obiect
function getSursaObiect($conn, $idObiect, $denumire) {
    // Eliminăm numerotarea (1), (2) etc pentru comparație
    $denumireCurata = trim(preg_replace('/\(\d+\)/', '', $denumire));

    $table_prefix = $GLOBALS['table_prefix'] ?? '';
    $sql = "SELECT sursa FROM `{$table_prefix}detectii_obiecte` 
            WHERE id_obiect = ? AND LOWER(denumire) = LOWER(?) 
            LIMIT 1";

    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'is', $idObiect, $denumireCurata);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if ($row = mysqli_fetch_assoc($result)) {
        mysqli_stmt_close($stmt);
        return $row['sursa'];
    }
    mysqli_stmt_close($stmt);

    // Dacă nu găsim în tabel, presupunem că e manual
    return 'manual';
}

// Pregătim array cu cutiile permise pentru partajare selectivă
$cutii_permise = [];
$este_partajare_selectiva = false;

// Verificăm dacă este o colecție partajată selectiv
if ($_SESSION['tip_acces_colectie'] != 'proprietar' && 
    $_SESSION['tip_partajare'] == 'selectiva' && 
    !empty($_SESSION['cutii_partajate'])) {
    
    $este_partajare_selectiva = true;
    $cutii_array = json_decode($_SESSION['cutii_partajate'], true);
    if (is_array($cutii_array)) {
        $cutii_permise = $cutii_array;
    }
}

$grupuri = [];
$totalObiecte = 0;
while ($rand = mysqli_fetch_assoc($rezultat)) {
    // Pentru partajare selectivă, verificăm dacă cutia este permisă
    if ($este_partajare_selectiva) {
        $cutie_id = $rand['cutie'] . '|' . $rand['locatie'];
        if (!in_array($cutie_id, $cutii_permise)) {
            // Skip această cutie dacă nu este în lista cutiilor permise
            continue;
        }
    }
    
    // Numărăm obiectele din fiecare grup (separate prin virgulă)
    if (!empty($rand['denumire_obiect'])) {
        $obiecteArray = explode(',', $rand['denumire_obiect']);
        $totalObiecte += count($obiecteArray);
    }
    $cheie = $rand['locatie'] . '||' . $rand['cutie'];

    if (!isset($grupuri[$cheie])) {
        $grupuri[$cheie] = [
            'info' => [
                'id_obiect' => $rand['id_obiect'],
                'locatie'   => $rand['locatie'],
                'cutie'     => $rand['cutie'],
                'imagine'   => $rand['imagine'],
                'categorie' => $rand['categorie'],
                'eticheta'  => $rand['eticheta'],
                'descriere' => $rand['descriere_categorie'] ?? '',
                'cantitate' => $rand['cantitate_obiect'] ?? '',
                'eticheta_obiect' => $rand['eticheta_obiect'] ?? '',
                'imagine_obiect' => $rand['imagine_obiect'] ?? '',
                'obiecte_partajate' => $rand['obiecte_partajate'] ?? ''
            ],
            'obiecte' => []
        ];
    }

    if (!empty($rand['denumire_obiect'])) {
        $grupuri[$cheie]['obiecte'][] = $rand['denumire_obiect'];
    }
}
?>

<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <title>Lista Obiecte</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- PWA Meta Tags -->
    <meta name="theme-color" content="#007BFF">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Inventar">
    <meta name="application-name" content="Inventar.live">
    <meta name="description" content="Gestionare inventar cu detectare automată Google Vision AI">

    <!-- PWA Manifest -->
    <link rel="manifest" href="manifest.json">

    <!-- PWA Icons - Cutie cu Grid -->
    <link rel="icon" href="favicon.ico" sizes="any">
    <link rel="icon" type="image/svg+xml" href="icons/logo-inventar.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="icons/icon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="icons/icon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="icons/icon-180x180.png">

    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/style-telefon.css">
    <link rel="stylesheet" href="css/notifications.css">

    <!-- PWA Offline Scripts -->
    <script src="js/idb-manager.js" defer></script>
    <script src="js/offline-sync.js" defer></script>
    <script src="js/offline-operations.js" defer></script>
    <script src="js/pending-operations-ui.js" defer></script>
    <script src="js/pwa-install-assistant.js" defer></script>

    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <style>
        .grup-obiecte.hidden {
            display: none !important;
        }

        /* Stil pentru fereastra modală */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.7);
        }

        .modal-content {
            background-color: #f4f4f4;
            margin: 15% auto;
            padding: 20px;
            border-radius: 8px;
            max-width: 500px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
            position: relative;
        }

        .modal-titlu {
            margin-top: 0;
            color: #333;
        }

        .modal-text {
            margin-bottom: 20px;
        }

        .modal-butoane {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .buton-modal {
            padding: 8px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }

        .buton-confirma {
            background-color: #4CAF50;
            color: white;
        }

        .buton-infirma {
            background-color: #f44336;
            color: white;
        }

        /* Stil pentru evidențierea termenilor căutați */
        .highlight-text {
            background-color: #FFFF00;
            font-weight: bold;
            padding: 0 2px;
            border-radius: 3px;
            cursor: pointer;
        }

        .highlight-badge {
            box-shadow: 0 0 5px #FFFF00;
            text-decoration: underline;
        }

        .highlight-title {
            text-decoration: underline;
            background-color: #FFFF99;
            padding: 0 3px;
        }

        /* Stil pentru tooltip-ul cu imagine */
        .imagine-tooltip {
            position: absolute;
            z-index: 1050;
            background-color: white;
            padding: 5px;
            border-radius: 4px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.3);
            pointer-events: none;
            max-width: 120px;
            max-height: 120px;
            display: none;
            border: 1px solid #ccc;
        }

        .imagine-tooltip img {
            max-width: 100%;
            max-height: 100%;
            display: block;
            object-fit: contain;
        }
        /* Stiluri pentru zona editabilă goală */
        .obiecte-text-gol {
            display: inline-block;
            min-width: 250px;
            min-height: 20px;
            padding: 5px 10px;
            border: 1px dashed #ccc;
            border-radius: 4px;
            background-color: #f9f9f9;
            color: #999;
            font-style: italic;
            cursor: text;
            transition: all 0.3s ease;
        }

        .obiecte-text-gol:hover, .obiecte-text-gol:focus {
            border-color: #999;
            background-color: #fff;
            color: #333;
        }

        /* Stiluri pentru zona editabilă cu conținut */
        .obiecte-text:not(.obiecte-text-gol) {
            display: inline-block;
            min-width: 100px;
            padding: 3px 5px;
            border: 1px solid transparent;
            border-radius: 4px;
            transition: all 0.3s ease;
        }

        .obiecte-text:not(.obiecte-text-gol):hover, .obiecte-text:not(.obiecte-text-gol):focus {
            border-color: #ddd;
            background-color: #f5f5f5;
        }

        /* Stiluri pentru obiectele detectate cu Google Vision */
        .obiect-gv {
            color: #ff6600 !important;
            font-weight: 500 !important;
        }
        .obiecte-vision-text {
            color: #2196F3;
            font-style: italic;
            background-color: #e3f2fd;
            padding: 2px 6px;
            border-radius: 4px;
            display: inline-block;
            margin-top: 4px;
        }

        /* Stiluri pentru ștergere imagine */
        .imagini-container {
            position: relative;
        }

        .thumb {
            position: relative;
        }

        .delete-image-btn {
            position: absolute;
            top: 2px;
            right: 5px;
            width: 24px;
            height: 24px;
            background-color: rgba(255, 0, 0, 0.8);
            color: white;
            border: none;
            border-radius: 50%;
            cursor: pointer;
            display: none;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            font-weight: bold;
            z-index: 10;
            transition: all 0.2s ease;
        }

        .delete-image-btn:hover {
            background-color: rgba(255, 0, 0, 1);
            transform: scale(1.1);
        }

        .thumb-container {
            position: relative;
            display: inline-block;
        }

        .thumb-container:hover .delete-image-btn {
            display: flex;
        }

        /* Stiluri pentru ștergere cutie */
        .grup-obiecte {
            position: relative;
        }

        .delete-cutie-btn {
            position: absolute;
            top: 10px;
            right: 10px;
            width: 30px;
            height: 30px;
            background-color: rgba(255, 0, 0, 0.8);
            color: white;
            border: none;
            border-radius: 50%;
            cursor: pointer;
            display: none;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            font-weight: bold;
            z-index: 10;
            transition: all 0.2s ease;
        }

        .delete-cutie-btn:hover {
            background-color: rgba(255, 0, 0, 1);
            transform: scale(1.1);
        }

        .grup-obiecte:hover .delete-cutie-btn {
            display: flex;
        }

        /* Modal pentru confirmare ștergere - similar cu etichete_imagine.php */
        .sterge-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            animation: fadeIn 0.3s ease;
        }

        .sterge-modal-content {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            max-width: 500px;
            width: 90%;
            text-align: center;
            animation: slideIn 0.3s ease;
        }

        .sterge-modal-icon {
            font-size: 60px;
            margin-bottom: 20px;
            color: #f44336;
        }

        .sterge-modal h3 {
            margin: 0 0 20px 0;
            color: #333;
            font-size: 24px;
        }

        .sterge-modal-message {
            color: #666;
            margin-bottom: 20px;
            line-height: 1.5;
        }

        .sterge-modal-buttons {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-top: 20px;
        }

        .sterge-modal-btn {
            border: none;
            padding: 12px 30px;
            border-radius: 25px;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s ease;
            min-width: 120px;
        }

        .sterge-modal-btn-danger {
            background: #f44336;
            color: white;
        }

        .sterge-modal-btn-danger:hover {
            background: #da190b;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(244, 67, 54, 0.3);
        }

        .sterge-modal-btn-secondary {
            background: #e0e0e0;
            color: #333;
        }

        .sterge-modal-btn-secondary:hover {
            background: #d0d0d0;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        /* Modal pentru alerte */
        .alert-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            animation: fadeIn 0.3s ease;
        }

        .alert-modal-content {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            max-width: 500px;
            width: 90%;
            text-align: center;
            animation: slideIn 0.3s ease;
        }

        .alert-modal-icon {
            font-size: 60px;
            margin-bottom: 20px;
            color: #f44336;
        }

        .alert-modal h3 {
            margin: 0 0 20px 0;
            color: #333;
            font-size: 24px;
        }

        .alert-modal-message {
            color: #666;
            margin-bottom: 20px;
            line-height: 1.5;
        }

        .alert-modal-btn {
            background: #f44336;
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 25px;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s ease;
            min-width: 120px;
        }

        .alert-modal-btn:hover {
            background: #da190b;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(244, 67, 54, 0.3);
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideIn {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }


        /* Stiluri pentru butonul Donate - simplificat */
        .donate-btn {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background-color: #ff6600;
            color: white;
            padding: 14px 10px;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(255, 102, 0, 0.3);
            transition: all 0.3s ease;
            z-index: 998; /* Mai mic decât footer pentru a fi în spate */
            display: inline-flex;
            align-items: center;
            gap: 8px;
            width: auto !important;
        }

        .donate-btn:hover {
            background-color: #e55500;
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(255, 102, 0, 0.4);
        }


        /* Container pentru avatar și slogan */
        .user-section {
            position: fixed;
            top: 20px;
            right: 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 16px;
            z-index: 9999;
        }

        /* Stiluri pentru avatar utilizator */
        .user-avatar {
            width: 55px;
            height: 35px;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }

        .user-avatar:hover {
            transform: scale(1.05);
        }

        .user-avatar-box {
            width: 100%;
            height: 100%;
            background-color: #e0e0e0;
            background-image:
                    linear-gradient(rgba(160, 160, 160, 0.4) 1px, transparent 1px),
                    linear-gradient(90deg, rgba(160, 160, 160, 0.4) 1px, transparent 1px);
            background-size: 8px 8px;
            border-radius: 3px;
            border: 2px solid #555;
            border-top-width: 4px;
            box-shadow:
                    0 2px 5px rgba(0,0,0,0.2),
                    0 0 20px rgba(255,255,255,0.9),
                    0 0 40px rgba(255,255,255,0.6),
                    inset 0 -1px 0 rgba(0,0,0,0.1),
                    inset 0 1px 0 rgba(255,255,255,0.6);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            padding: 4px;
            position: relative;
            z-index: 10000;
        }

        .user-avatar-name {
            font-size: 10px;
            color: #333;
            font-weight: 600;
            text-align: center;
            line-height: 1;
        }

        /* Dropdown menu pentru user */
        .user-dropdown {
            position: absolute;
            top: 40px;
            right: 0;
            background-color: #e0e0e0;
            background-image:
                    linear-gradient(rgba(160, 160, 160, 0.4) 1px, transparent 1px),
                    linear-gradient(90deg, rgba(160, 160, 160, 0.4) 1px, transparent 1px);
            background-size: 10px 10px;
            border-radius: 3px;
            border: 2px solid #555;
            border-top-width: 5px;
            box-shadow:
                    0 2px 5px rgba(0,0,0,0.2),
                    inset 0 -1px 0 rgba(0,0,0,0.1),
                    inset 0 1px 0 rgba(255,255,255,0.6);
            min-width: 200px;
            display: none;
            overflow: hidden;
            animation: slideIn 0.3s ease;
        }

        .user-avatar.active .user-dropdown {
            display: block;
        }

        .user-dropdown-item {
            padding: 12px 16px;
            border-bottom: 1px solid rgba(85, 85, 85, 0.3);
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 10px;
            color: #333;
            text-decoration: none;
            background-color: rgba(255, 255, 255, 0.5);
            position: relative;
        }

        .user-dropdown-item:last-child {
            border-bottom: none;
        }

        .user-dropdown-item:hover {
            background-color: rgba(255, 102, 0, 0.1);
            padding-left: 20px;
        }

        .user-dropdown-item.logout {
            color: #d32f2f;
            font-weight: 500;
        }

        .user-dropdown-item.logout:hover {
            background-color: rgba(211, 47, 47, 0.1);
        }

        /* Slogan sub avatar */
        .user-slogan {
            font-size: 11px;
            color: #666;
            text-align: center;
            line-height: 1.3;
            font-weight: 600;
            text-shadow:
                    0 1px 0 rgba(255,255,255,1),
                    0 0 3px rgba(255,255,255,1),
                    0 0 10px rgba(255,255,255,0.8),
                    0 0 20px rgba(255,255,255,0.6);
        }

        /* Modal donate */
        .donate-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            animation: fadeIn 0.3s ease;
        }

        .donate-modal-content {
            background: white;
            padding: 40px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            max-width: 400px;
            width: 90%;
            text-align: center;
            animation: slideIn 0.3s ease;
        }

        .donate-amount-input {
            width: 100%;
            padding: 12px;
            font-size: 24px;
            text-align: center;
            border: 2px solid #ddd;
            border-radius: 8px;
            margin: 20px 0;
        }

        .donate-amount-input:focus {
            outline: none;
            border-color: #ff6600;
        }

        .donate-modal h3 {
            color: #333;
            margin-bottom: 20px;
        }

        .donate-modal-buttons {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-top: 20px;
        }

        .donate-modal-btn {
            padding: 12px 30px;
            border: none;
            border-radius: 25px;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .donate-modal-btn-primary {
            background: #ff6600;
            color: white;
        }

        .donate-modal-btn-primary:hover {
            background: #e55500;
        }

        .donate-modal-btn-secondary {
            background: #ddd;
            color: #333;
        }

        .donate-modal-btn-secondary:hover {
            background: #ccc;
        }

        /* Ajustare container pentru avatar */
        .container {
            padding-top: 80px;
            padding-bottom: 20px;
        }

        /* Footer informativ - apare doar la final */
        .info-footer {
            width: 730px;
            max-width: calc(100% - 20px);
            margin: 40px auto 0;
            background-color: #ff6600;
            color: white;
            padding: 6.5px 20px;
            text-align: center;
            box-shadow: 0 -2px 5px rgba(0,0,0,0.2);
            font-family: Arial, sans-serif;
            border-radius: 5px 5px 0 0;
            position: relative;
            z-index: 1000; /* Deasupra butonului Donează */
        }

        .info-footer .info-text {
            font-size: 14px;
            margin-bottom: 5px;
        }

        .info-footer .copyright {
            font-size: 12px;
            opacity: 0.9;
        }

        /* SISTEM DE TAB-URI */
        .tabs-container {
            background-color: transparent;
            padding: 0 0 10px 0;
            display: flex;
            align-items: center;
            gap: 5px;
            overflow-x: auto;
            white-space: nowrap;
            margin-bottom: 0;
            border-bottom: 2px solid #ddd;
        }

        .tab {
            background-color: #e0e0e0;
            background-image:
                    linear-gradient(rgba(160, 160, 160, 0.3) 1px, transparent 1px),
                    linear-gradient(90deg, rgba(160, 160, 160, 0.3) 1px, transparent 1px);
            background-size: 10px 10px;
            padding: 10px 20px;
            border-radius: 8px 8px 0 0;
            cursor: pointer;
            user-select: none;
            transition: all 0.3s;
            position: relative;
            border: 2px solid #ccc;
            border-bottom: none;
            position: relative;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            color: #555;
            min-width: 120px;
            justify-content: center;
        }

        .tab:hover {
            background-color: #d5d5d5;
        }

        .tab.active {
            background-color: white;
            color: #333;
            font-weight: 600;
            border-color: #ff6600;
            border-top-width: 3px;
        }

        /* Tab cu cel puțin un obiect partajat */
        .tab.has-shared {
            border-color: #9370DB;
            border-width: 2px;
            box-shadow: inset 0 0 5px rgba(147, 112, 219, 0.2);
        }

        /* Tab cu toată colecția publică */
        .tab.all-public {
            background-color: #e6ccff;
            border-color: purple;
            border-width: 3px;
            background-image:
                    linear-gradient(rgba(128, 0, 128, 0.2) 1px, transparent 1px),
                    linear-gradient(90deg, rgba(128, 0, 128, 0.2) 1px, transparent 1px);
            box-shadow: inset 0 0 10px rgba(128, 0, 128, 0.3);
        }

        /* Iconițe pentru tab-uri */
        .tab-icon {
            font-size: 16px;
        }

        .tab.shared {
            background-color: #e8f4f8;
        }

        .tab.shared:hover {
            background-color: #d0e8f0;
        }

        .tab.shared.active {
            background-color: white;
        }

        /* Tab pentru adăugare */
        .tab-add {
            background-color: transparent;
            border: 2px dashed #999;
            color: #666;
            min-width: 50px;
            padding: 10px 15px;
        }

        .tab-add:hover {
            background-color: rgba(255, 102, 0, 0.1);
            border-color: #ff6600;
            color: #ff6600;
        }

        .delete-tab-btn {
            position: absolute;
            right: 5px;
            top: 5px;
            width: 20px;
            height: 20px;
            background-color: rgba(255, 0, 0, 0.8);
            color: white;
            border: none;
            border-radius: 50%;
            cursor: pointer;
            display: none;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            font-weight: bold;
            transition: all 0.3s;
            z-index: 10;
        }

        .delete-tab-btn:hover {
            background-color: rgba(255, 0, 0, 1);
            transform: scale(1.1);
        }

        .tab:hover .delete-tab-btn {
            display: flex;
        }

        /* Badge pentru notificări */
        .tab-badge {
            background-color: #ff6600;
            color: white;
            font-size: 11px;
            padding: 2px 6px;
            border-radius: 10px;
            position: absolute;
            top: -5px;
            right: -5px;
        }

        /* Ajustare search-bar pentru tab-uri */
        .search-bar {
            margin-bottom: 0;
            border-radius: 5px 5px 0 0;
        }


        /* Stiluri pentru simbolul grid clickable */
        .indicator {
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .indicator:hover {
            transform: scale(1.1);
        }

        .indicator.public .grid {
            background-image:
                    repeating-linear-gradient(to right, transparent, transparent 2px, purple 2px, purple 3px),
                    repeating-linear-gradient(to bottom, transparent, transparent 2px, purple 2px, purple 3px) !important;
            opacity: 0.9 !important;
        }

        /* Simbol grid clickable */
        .indicator .grid {
            cursor: pointer;
            transition: opacity 0.3s;
        }

        .indicator .grid:hover {
            opacity: 0.8;
        }

        /* Simbol cutie inventar.live global */
        .global-grid-box {
            display: inline-block;
            width: 35px;
            height: 20px;
            background-color: #e0e0e0;
            background-image:
                    linear-gradient(rgba(160, 160, 160, 0.4) 1px, transparent 1px),
                    linear-gradient(90deg, rgba(160, 160, 160, 0.4) 1px, transparent 1px);
            background-size: 5px 5px;
            border-radius: 2px;
            border: 1px solid #555;
            border-top-width: 3px;
            box-shadow:
                    0 1px 3px rgba(0,0,0,0.2),
                    inset 0 -1px 0 rgba(0,0,0,0.1),
                    inset 0 1px 0 rgba(255,255,255,0.6);
            cursor: pointer;
            margin-right: 10px;
            vertical-align: middle;
            transition: all 0.3s;
        }

        .global-grid-box:hover {
            transform: scale(1.1);
            box-shadow: 0 2px 5px rgba(0,0,0,0.3);
        }

        .global-grid-box.public {
            background-color: #e6ccff;
            border-color: purple;
            background-image:
                    linear-gradient(rgba(128, 0, 128, 0.3) 1px, transparent 1px),
                    linear-gradient(90deg, rgba(128, 0, 128, 0.3) 1px, transparent 1px);
        }

        /* Stiluri pentru butoane modal */
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s;
        }

        .btn-primary {
            background-color: #ff6600;
            color: white;
        }

        .btn-primary:hover {
            background-color: #e55500;
        }

        .btn-secondary {
            background-color: #666;
            color: white;
        }

        .btn-secondary:hover {
            background-color: #555;
        }

        /* Modal pentru partajare */
        .modal-partajare {
            display: none;
            position: fixed;
            z-index: 3000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            align-items: center;
            justify-content: center;
        }

        .modal-partajare-content {
            background-color: #e0e0e0;
            background-image:
                    linear-gradient(rgba(160, 160, 160, 0.4) 1px, transparent 1px),
                    linear-gradient(90deg, rgba(160, 160, 160, 0.4) 1px, transparent 1px);
            background-size: 15px 15px;
            padding: 30px;
            border-radius: 3px;
            border: 2px solid #555;
            border-top-width: 7px;
            box-shadow:
                    0 2px 5px rgba(0,0,0,0.2),
                    inset 0 -1px 0 rgba(0,0,0,0.1),
                    inset 0 1px 0 rgba(255,255,255,0.6);
            width: 90%;
            max-width: 500px;
            max-height: 80vh;
            overflow-y: auto;
            position: relative;
        }

        .modal-partajare-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #ff6600;
        }

        .modal-partajare-close {
            cursor: pointer;
            font-size: 28px;
            color: #666;
            transition: color 0.3s;
        }

        .modal-partajare-close:hover {
            color: #ff6600;
        }

        .checkbox-publica {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
            padding: 10px;
            background-color: rgba(255, 255, 255, 0.5);
            border-radius: 5px;
        }

        .checkbox-publica input[type="checkbox"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
        }

        .lista-obiecte-partajare {
            margin-top: 20px;
        }

        .obiect-partajare {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px;
            margin-bottom: 5px;
            background-color: rgba(255, 255, 255, 0.3);
            border-radius: 3px;
            transition: background-color 0.2s;
        }

        .obiect-partajare:hover {
            background-color: rgba(255, 255, 255, 0.5);
        }

        .obiect-partajare input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .btn-selecteaza-toate {
            margin: 10px 0;
            padding: 8px 16px;
            background-color: #666;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            transition: background-color 0.3s;
        }

        .btn-selecteaza-toate:hover {
            background-color: #555;
        }

        /* Simbol grid global */
        .grid-global {
            display: inline-block;
            width: 25px;
            height: 25px;
            position: relative;
            margin-right: 10px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .grid-global .bar-top {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background-color: #333;
        }

        .grid-global .grid {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            opacity: 0.7;
            background-image:
                    repeating-linear-gradient(to right, transparent, transparent 2px, #ccc 2px, #ccc 3px),
                    repeating-linear-gradient(to bottom, transparent, transparent 2px, #ccc 2px, #ccc 3px);
        }

        .grid-global.public .grid {
            background-image:
                    repeating-linear-gradient(to right, transparent, transparent 2px, purple 2px, purple 3px),
                    repeating-linear-gradient(to bottom, transparent, transparent 2px, purple 2px, purple 3px) !important;
            opacity: 0.9 !important;
        }

        /* Modal universal stilizat ca Vision modal */
        .universal-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            animation: fadeIn 0.3s ease;
        }

        .universal-modal-content {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            max-width: 500px;
            width: 90%;
            text-align: center;
            animation: slideIn 0.3s ease;
        }

        .universal-modal-icon {
            font-size: 60px;
            margin-bottom: 20px;
        }

        .universal-modal-icon.warning {
            color: #ff9800;
        }

        .universal-modal-icon.error {
            color: #f44336;
        }

        .universal-modal-icon.success {
            color: #4CAF50;
        }

        .universal-modal-icon.info {
            color: #2196F3;
        }

        .universal-modal-icon.question {
            color: #ff6600;
        }

        .universal-modal h3 {
            margin: 0 0 20px 0;
            color: #333;
            font-size: 24px;
        }

        .universal-modal p {
            color: #666;
            margin-bottom: 20px;
            line-height: 1.5;
        }

        .universal-modal-buttons {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-top: 20px;
        }

        .universal-modal-button {
            background: #ff6600;
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 25px;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s ease;
            min-width: 100px;
        }

        .universal-modal-button:hover {
            background: #e55a00;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(255, 102, 0, 0.3);
        }

        .universal-modal-button.secondary {
            background: #ccc;
            color: #333;
        }

        .universal-modal-button.secondary:hover {
            background: #bbb;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .universal-modal-button.danger {
            background: #f44336;
        }

        .universal-modal-button.danger:hover {
            background: #d32f2f;
            box-shadow: 0 4px 12px rgba(244, 67, 54, 0.3);
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideIn {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        @keyframes pulsare {
            0% {
                transform: scale(1);
                box-shadow: 0 2px 4px rgba(0,0,0,0.3);
            }
            50% {
                transform: scale(1.1);
                box-shadow: 0 4px 8px rgba(0,0,0,0.5);
            }
            100% {
                transform: scale(1);
                box-shadow: 0 2px 4px rgba(0,0,0,0.3);
            }
        }
        
        .timer-pulsare {
            animation: pulsare 1.5s ease-in-out infinite;
        }
    </style>
    <script>
        // Funcții globale pentru Donate
        function openDonateModal() {
            document.getElementById('donateModal').style.display = 'flex';
        }

        function closeDonateModal() {
            document.getElementById('donateModal').style.display = 'none';
        }

        function processDonation() {
            const amount = document.getElementById('donateAmount').value;
            if (amount < 10) {
                showError('Suma minimă pentru donație este €10');
                return;
            }
            showSuccess('Vă direcționăm către plată...');
            setTimeout(() => {
                // Folosim Ko-fi - acceptă card și PayPal
                const url = `https://ko-fi.com/inventarlive`;
                window.open(url, '_blank');
                closeDonateModal();
            }, 1000);
        }
    </script>
</head>
<body>
<!-- Secțiune utilizator -->
<div class="user-section">
    <div class="user-avatar" id="userAvatar" style="position: relative;">
        <div class="user-avatar-box">
            <div class="user-avatar-name"><?php echo htmlspecialchars($user['prenume'] ?? 'User'); ?></div>
        </div>
        <span id="badgeNotificariAvatar" style="display: none; position: absolute; top: -8px; right: -8px; 
                                                 background: #ff0000; color: white; border-radius: 50%; 
                                                 width: 20px; height: 20px; line-height: 20px; 
                                                 font-size: 11px; text-align: center; font-weight: bold; 
                                                 z-index: 10000; box-shadow: 0 2px 4px rgba(0,0,0,0.3);
                                                 border: 2px solid white;">0</span>
        <!-- Timer împrumut cu clepsidră -->
        <div id="timerImprumut" style="display: none; position: absolute; top: -8px; left: -8px; 
                                       padding: 2px 5px; border-radius: 8px; 
                                       align-items: center; justify-content: center; 
                                       font-size: 11px; color: white; font-weight: bold; 
                                       z-index: 10000; box-shadow: 0 2px 4px rgba(0,0,0,0.3);
                                       background: #ff9800; cursor: pointer;
                                       white-space: nowrap; border: 1px solid white;" 
             onclick="afiseazaDetaliiImprumuturi()">
            <span id="timerImprumutText">⏳ 0</span>
        </div>
        <div class="user-dropdown">
            <a href="profil.php" class="user-dropdown-item">
                <span>👤</span> Profil și Setări
            </a>
            <a href="export_import.php" class="user-dropdown-item">
                <span>💾</span> Export/Import Date
            </a>
            <a href="impartasiri.php" class="user-dropdown-item">
                <span>👥</span> Împarte cu ceilalți
            </a>
            <a href="logout.php" class="user-dropdown-item logout">
                <span>🚪</span> Delogare
            </a>
        </div>
    </div>
    <div class="user-slogan">Organizează simplu,<br>găsește rapid.</div>
</div>

<!-- Buton Donate -->
<button class="donate-btn" onclick="openDonateModal()">
    <span class="heart">❤️</span> Donează
</button>


<!-- Modal Donate -->
<div class="donate-modal" id="donateModal">
    <div class="donate-modal-content">
        <h3>Susține Inventar.live</h3>
        <p style="color: #666; margin-bottom: 10px; font-style: italic;">
            Proiect susținut cu efort personal, pentru că m-am săturat să nu-mi găsesc lucrurile când am nevoie.
        </p>
        <p style="color: #666; margin-bottom: 20px;">
            Ajută-ne să menținem și să îmbunătățim această platformă gratuită.
        </p>
        <div style="display: flex; align-items: center; justify-content: center; margin-bottom: 10px;">
            <span style="font-size: 24px; margin-right: 10px;">€</span>
            <input type="number" class="donate-amount-input" id="donateAmount" value="20" min="10" step="5">
        </div>
        <p style="color: #999; font-size: 14px; margin-bottom: 10px;">
            Suma minimă: €10
        </p>
        <p style="color: #999; font-size: 12px; margin-bottom: 20px;">
            Acceptăm PayPal și majoritatea cardurilor bancare.<br>
            <small>Pentru Revolut: revolut.me/corneljt88</small>
        </p>
        <div class="donate-modal-buttons">
            <button class="donate-modal-btn donate-modal-btn-primary" onclick="processDonation()">
                Continuă către plată
            </button>
            <button class="donate-modal-btn donate-modal-btn-secondary" onclick="closeDonateModal()">
                Anulează
            </button>
        </div>
    </div>
</div>

<!-- Container pentru tooltip-ul cu imagine -->
<div id="imagine-tooltip" class="imagine-tooltip">
    <img id="imagine-tooltip-img" src="" alt="Imagine obiect">
</div>

<!-- Modal pentru confirmare ștergere -->
<div class="sterge-modal" id="stergeModal">
    <div class="sterge-modal-content">
        <div class="sterge-modal-icon">🗑️</div>
        <h3>Confirmare ștergere</h3>
        <div class="sterge-modal-message">
            Sigur doriți să ștergeți această imagine?<br><br>
            <strong style="color: #f44336;">ATENȚIE:</strong> Se vor șterge și toate obiectele asociate cu această imagine!
        </div>
        <div class="sterge-modal-buttons">
            <button class="sterge-modal-btn sterge-modal-btn-danger" onclick="confirmaSterge()">Șterge</button>
            <button class="sterge-modal-btn sterge-modal-btn-secondary" onclick="anuleazaSterge()">Anulează</button>
        </div>
    </div>
</div>

<!-- Modal pentru alerte -->
<div class="alert-modal" id="alertModal">
    <div class="alert-modal-content">
        <div class="alert-modal-icon">⚠️</div>
        <h3 id="alertTitle">Eroare</h3>
        <div class="alert-modal-message" id="alertMessage"></div>
        <button class="alert-modal-btn" onclick="inchideAlert()">OK</button>
    </div>
</div>

<nav class="navbar">
    <a href="adauga_imagini.php" id="link-adauga-imagini" class="modern-link">➕ Adaugă Imagini</a>
</nav>

<?php 
// Eliminăm mesajul complet - nu este necesar să informăm utilizatorul despre limitări
// El vede doar ce are voie să vadă
?>

<script>
    // Actualizează link-ul pentru adăugare imagini cu colecția curentă
    function actualizeazaLinkAdaugaImagini() {
        const link = document.getElementById('link-adauga-imagini');
        const tabActiv = document.querySelector('.tab.active');
        if (link && tabActiv) {
            const idColectie = tabActiv.getAttribute('data-colectie');
            if (idColectie) {
                link.href = `adauga_imagini.php?colectie=${idColectie}`;
            }
        }
    }

    // Actualizează la încărcare
    document.addEventListener('DOMContentLoaded', actualizeazaLinkAdaugaImagini);
    
    // Verifică și aplică stilurile pentru tab-uri și simbolul global bazat pe starea de partajare
    document.addEventListener('DOMContentLoaded', function() {
        // Verifică dacă există indicatori cu clasa 'public' pentru a marca tab-ul curent
        const indicators = document.querySelectorAll('.indicator.public');
        const tabActiv = document.querySelector('.tab.active');
        
        if (indicators.length > 0 && tabActiv) {
            // Dacă există cel puțin un indicator public, adaugă clasa has-shared la tab
            tabActiv.classList.add('has-shared');
        }
        
        // Pentru simbolul global și tab-ul all-public, facem un request să verificăm starea colecției
        const tabActivId = tabActiv ? tabActiv.getAttribute('data-colectie') : null;
        if (tabActivId) {
            // Verificăm prin AJAX dacă colecția este publică
            fetch('ajax_partajare.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    actiune: 'obtine_toate_obiectele',
                    id_colectie: tabActivId
                })
            })
            .then(response => response.json())
            .then(data => {
                // Mai întâi resetăm clasa public
                const globalBox = document.querySelector('.global-grid-box');
                if (globalBox) {
                    globalBox.classList.remove('public');
                }
                
                if (data.success && data.colectie_publica) {
                    // Marchează simbolul global și tab-ul ca public DOAR dacă colecția curentă e publică
                    if (globalBox) {
                        globalBox.classList.add('public');
                    }
                    if (tabActiv) {
                        tabActiv.classList.remove('has-shared');
                        tabActiv.classList.add('all-public');
                    }
                }
            })
            .catch(error => console.log('Eroare verificare stare colecție:', error));
        }
    });

    // Actualizează la schimbarea tab-ului
    document.addEventListener('click', function(e) {
        if (e.target.closest('.tab')) {
            setTimeout(actualizeazaLinkAdaugaImagini, 100);
        }
    });
</script>

<div class="search-bar" style="position: relative;">
    <input
            type="text"
            id="campCautare"
            placeholder="Caută după locație, cutie, categorie sau obiecte..."
            oninput="filtreazaObiecte(this.value)"
    >
    <button id="btnCautareGlobala" 
            onclick="cautareGlobala()" 
            style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); 
                   background-color: #ff6600;
                   color: white; 
                   border: none; 
                   padding: 6px 12px; 
                   border-radius: 5px;
                   font-size: 12px; 
                   font-weight: 600; 
                   cursor: pointer;
                   transition: all 0.3s ease; 
                   display: none;
                   width: auto;
                   white-space: nowrap;
                   box-shadow: 0 3px 10px rgba(255, 102, 0, 0.3);">
        Caută în toate colecțiile
    </button>
</div>

<!-- Container pentru rezultatele căutării globale -->
<div id="rezultateGlobale" style="display: none;"></div>

<style>
#btnCautareGlobala:hover {
    transform: translateY(-50%) translateY(-2px);
    background-color: #e55500;
    box-shadow: 0 6px 16px rgba(255, 102, 0, 0.4);
}

#rezultateGlobale .container {
    max-width: 730px;
    margin: 0 auto;
    padding: 20px 10px;
}
</style>

<!-- Modal pentru confirmarea salvării cu virgule -->
<div id="modalConfirmareVirgule" class="modal">
    <div class="modal-content">
        <h3 class="modal-titlu">Atenție!</h3>
        <p class="modal-text">Ai introdus text cu virgule. Virgula este folosită pentru a separa mai multe obiecte.</p>
        <p class="modal-text">Conținut detectat: <strong id="continutCuVirgule"></strong></p>
        <p class="modal-text">Dorești să continui cu mai multe obiecte sau ai vrut să introduci un singur obiect?</p>
        <div class="modal-butoane">
            <button class="buton-modal buton-confirma" id="butonConfirmaMultipleObiecte">Mai multe obiecte (păstrează virgulele)</button>
            <button class="buton-modal buton-infirma" id="butonInfirmaMultipleObiecte">Un singur obiect (înlocuiește virgulele)</button>
        </div>
    </div>
</div>

<div class="container">
    <!-- SISTEM DE TAB-URI -->
    <div class="tabs-container">
        <?php
        // Obține conexiunea la baza centrală pentru a citi colecțiile
        $conn_central = getCentralDbConnection();

        // Determină colecția curentă
        $id_colectie_curenta = $_SESSION['id_colectie_curenta'] ?? $user['id_colectie_principala'] ?? null;

        // Array pentru a stoca toate tab-urile
        $toate_taburile = [];
        $tab_activ = null;

        // 1. Obține colecțiile proprii ale utilizatorului
        // IMPORTANT: Nu folosim subquery pentru COUNT deoarece fiecare colecție are propriul prefix
        $sql_proprii = "SELECT c.*
                        FROM colectii_utilizatori c 
                        WHERE c.id_utilizator = ? 
                        ORDER BY c.este_principala DESC, c.data_creare";

        $stmt = mysqli_prepare($conn_central, $sql_proprii);
        mysqli_stmt_bind_param($stmt, "i", $user['id_utilizator']);
        mysqli_stmt_execute($stmt);
        $result_proprii = mysqli_stmt_get_result($stmt);

        // Array asociativ pentru iconuri bazate pe cuvinte cheie din numele colecției
        // Această mapare ajută la atribuirea inteligentă a iconurilor
        $iconuri_cuvinte_cheie = [
            // Locații casnice
            'biblioteca' => '📚',
            'carti' => '📚',
            'carte' => '📚',
            'lectura' => '📚',
            'birou' => '🏢',
            'office' => '🏢',
            'munca' => '💼',
            'job' => '💼',
            'garaj' => '🚗',
            'masina' => '🚗',
            'auto' => '🚗',
            'casa' => '🏠',
            'acasa' => '🏠',
            'home' => '🏠',
            'bucatarie' => '🍳',
            'kitchen' => '🍳',
            'dormitor' => '🛏️',
            'bedroom' => '🛏️',
            'baie' => '🚿',
            'bathroom' => '🚿',
            'living' => '🛋️',
            'sufragerie' => '🛋️',
            // Locații exterioare
            'gradina' => '🌳',
            'garden' => '🌳',
            'curte' => '🌳',
            'terasa' => '🌳',
            'balcon' => '🌳',
            // Depozitare
            'pod' => '📦',
            'mansarda' => '📦',
            'attic' => '📦',
            'pivnita' => '📦',
            'beci' => '📦',
            'depozit' => '📦',
            'magazie' => '📦',
            'storage' => '📦',
            // Hobby și activități
            'atelier' => '🔧',
            'workshop' => '🔧',
            'unelte' => '🔧',
            'scule' => '🔧',
            'tools' => '🔧',
            'sport' => '⚽',
            'gym' => '💪',
            'muzica' => '🎵',
            'music' => '🎵',
            'arta' => '🎨',
            'art' => '🎨',
            'pictura' => '🎨',
            // Locații speciale
            'scoala' => '🏫',
            'school' => '🏫',
            'facultate' => '🎓',
            'universitate' => '🎓',
            'spital' => '🏥',
            'hospital' => '🏥',
            'magazin' => '🏪',
            'shop' => '🏪',
            // Vacanță și călătorii
            'vacanta' => '🏖️',
            'vacation' => '🏖️',
            'holiday' => '🏖️',
            'munte' => '🏔️',
            'mountain' => '🏔️',
            'mare' => '🏖️',
            'plaja' => '🏖️',
            'beach' => '🏖️',
            'cabana' => '🏡',
            'cabin' => '🏡',
            'camping' => '⛺',
            // Tehnologie
            'computer' => '💻',
            'laptop' => '💻',
            'pc' => '💻',
            'electronice' => '📱',
            'electronics' => '📱',
            'gadget' => '📱'
        ];
        
        // Array cu iconuri generale pentru când nu găsim cuvânt cheie potrivit
        // Acestea vor fi folosite în ordine pentru colecțiile fără cuvinte cheie specifice
        $iconite_colectii = ['📦', '📁', '🗂️', '📋', '🗃️', '📑', '🏷️', '📌'];
        $index_icon = 0;

        while ($colectie = mysqli_fetch_assoc($result_proprii)) {
            // Obține numărul de obiecte pentru această colecție folosind prefixul ei specific
            $prefix_colectie = $colectie['prefix_tabele'];
            $table_name = "`{$prefix_colectie}obiecte`";
            
            // Verifică dacă tabela există și obține numărul de obiecte
            $sql_count = "SELECT COUNT(*) as nr_obiecte FROM $table_name";
            $result_count = mysqli_query($conn, $sql_count);
            $nr_obiecte = 0;
            if ($result_count) {
                $row_count = mysqli_fetch_assoc($result_count);
                $nr_obiecte = $row_count['nr_obiecte'];
                mysqli_free_result($result_count);
            }
            $colectie['nr_obiecte'] = $nr_obiecte;
            
            $is_active = ($colectie['id_colectie'] == $id_colectie_curenta);
            
            // Logică inteligentă pentru atribuirea iconurilor
            if ($colectie['este_principala']) {
                // Colecția principală primește întotdeauna iconul casă
                $icon = '🏠';
            } else {
                // Pentru colecțiile secundare, căutăm cuvinte cheie în numele colecției
                $nume_lower = mb_strtolower($colectie['nume_colectie'], 'UTF-8');
                $icon_gasit = false;
                
                // Parcurgem array-ul de cuvinte cheie pentru a găsi o potrivire
                foreach ($iconuri_cuvinte_cheie as $cuvant => $icon_cuvant) {
                    // Verificăm dacă numele conține cuvântul cheie
                    if (mb_strpos($nume_lower, $cuvant) !== false) {
                        $icon = $icon_cuvant;
                        $icon_gasit = true;
                        break;
                    }
                }
                
                // Dacă nu s-a găsit niciun cuvânt cheie, folosim un icon din lista generală
                if (!$icon_gasit) {
                    // Folosim modulo pentru a cicla prin array-ul de iconuri generale
                    $icon = $iconite_colectii[$index_icon % count($iconite_colectii)];
                    $index_icon++;
                }
            }
            
            ob_start();
            ?>
            <div class="tab <?php echo $is_active ? 'active' : ''; ?>" data-colectie="<?php echo $colectie['id_colectie']; ?>">
                <span class="tab-icon"><?php echo $icon; ?></span>
                <span><?php echo htmlspecialchars($colectie['nume_colectie']); ?></span>
                <?php if (!$colectie['este_principala']): ?>
                    <button class="delete-tab-btn"
                            onclick="stergeColectie(event, <?php echo $colectie['id_colectie']; ?>, '<?php echo htmlspecialchars($colectie['nume_colectie'], ENT_QUOTES); ?>')"
                            title="Șterge colecția">
                        ×
                    </button>
                <?php endif; ?>
            </div>
            <?php
            $tab_html = ob_get_clean();
            
            if ($is_active) {
                $tab_activ = $tab_html;
            } else {
                $toate_taburile[] = $tab_html;
            }
        }
        mysqli_stmt_close($stmt);

        // 2. Obține colecțiile partajate cu utilizatorul
        $sql_partajate = "SELECT c.*, p.tip_acces, u.prenume,
                          (SELECT COUNT(*) FROM notificari_partajare n 
                           WHERE n.id_colectie = c.id_colectie 
                           AND n.id_utilizator_destinatar = ? 
                           AND n.citita = 0) as notificari_necitite
                          FROM partajari p
                          JOIN colectii_utilizatori c ON p.id_colectie = c.id_colectie
                          JOIN utilizatori u ON c.id_utilizator = u.id_utilizator
                          WHERE p.id_utilizator_partajat = ? AND p.activ = 1
                          ORDER BY p.data_partajare DESC";

        $stmt = mysqli_prepare($conn_central, $sql_partajate);
        mysqli_stmt_bind_param($stmt, "ii", $user['id_utilizator'], $user['id_utilizator']);
        mysqli_stmt_execute($stmt);
        $result_partajate = mysqli_stmt_get_result($stmt);

        while ($colectie = mysqli_fetch_assoc($result_partajate)) {
            $is_active = ($colectie['id_colectie'] == $id_colectie_curenta);
            // Pentru moment, considerăm că toate colecțiile partajate sunt complete
            // (nu avem încă sistem de partajare parțială pe cutii)
            $partajare_completa = true;
            
            ob_start();
            ?>
            <div class="tab shared <?php echo $is_active ? 'active' : ''; ?> <?php echo $partajare_completa ? 'all-public' : ''; ?>" 
                 data-colectie="<?php echo $colectie['id_colectie']; ?>">
                <span class="tab-icon">👥</span>
                <span>Colecția lui <?php echo htmlspecialchars($colectie['prenume']); ?></span>
                <?php if ($colectie['notificari_necitite'] > 0): ?>
                    <span class="tab-badge"><?php echo $colectie['notificari_necitite']; ?></span>
                <?php endif; ?>
            </div>
            <?php
            $tab_html = ob_get_clean();
            
            if ($is_active) {
                $tab_activ = $tab_html;
            } else {
                $toate_taburile[] = $tab_html;
            }
        }
        mysqli_stmt_close($stmt);

        // Închidem conexiunea la baza centrală
        mysqli_close($conn_central);

        // Afișăm tab-urile: mai întâi cel activ, apoi restul
        if ($tab_activ) {
            echo $tab_activ;
        }
        foreach ($toate_taburile as $tab) {
            echo $tab;
        }
        ?>

        <!-- Buton pentru tab nou -->
        <div class="tab tab-add" onclick="deschideModalColectieNoua()">
            <span style="font-size: 20px;">+</span>
        </div>
    </div>

    <!-- Info despre baza de date curentă -->
    <?php
    // Obține numele colecției curente
    $nume_colectie_curenta = "Inventarul meu";
    $tip_acces = "proprietar";

    if ($id_colectie_curenta) {
        $conn_central_info = getCentralDbConnection();
        $sql_info = "SELECT c.nume_colectie, p.tip_acces, u.prenume 
                     FROM colectii_utilizatori c
                     LEFT JOIN partajari p ON c.id_colectie = p.id_colectie 
                          AND p.id_utilizator_partajat = ? AND p.activ = 1
                     LEFT JOIN utilizatori u ON c.id_utilizator = u.id_utilizator
                     WHERE c.id_colectie = ?";

        $stmt_info = mysqli_prepare($conn_central_info, $sql_info);
        mysqli_stmt_bind_param($stmt_info, "ii", $user['id_utilizator'], $id_colectie_curenta);
        mysqli_stmt_execute($stmt_info);
        $result_info = mysqli_stmt_get_result($stmt_info);

        if ($row_info = mysqli_fetch_assoc($result_info)) {
            $nume_colectie_curenta = $row_info['nume_colectie'];
            if ($row_info['tip_acces']) {
                $tip_acces = $row_info['tip_acces'];
                $nume_colectie_curenta = "Colecția lui " . $row_info['prenume'] . " - " . $nume_colectie_curenta;
            }
        }
        mysqli_stmt_close($stmt_info);
        mysqli_close($conn_central_info);
    }
    ?>
    <div style="margin-bottom: 20px; margin-top: 15px; padding: 15px; background-color: #f0f0f0; border-radius: 5px; font-size: 14px;">
        <strong>Colecție curentă:</strong>
        <span contenteditable="<?php echo ($tip_acces == 'proprietar') ? 'true' : 'false'; ?>"
              style="display: inline-block; padding: 2px 8px; background: white; border: 1px solid #ccc; border-radius: 3px; min-width: 200px;"
              onblur="salveazaNumeBazaDate(this)"
              data-id-colectie="<?php echo $id_colectie_curenta; ?>"><?php echo htmlspecialchars($nume_colectie_curenta); ?></span>
        <?php if ($tip_acces != 'proprietar'): ?>
            • <strong>Acces:</strong> <?php echo ($tip_acces == 'scriere') ? 'Citire și scriere' : 'Doar citire'; ?>
        <?php endif; ?>
        • <strong>Cutii:</strong> <?php echo count($grupuri); ?>
        • <strong>Obiecte:</strong> <?php echo $totalObiecte; ?>
    </div>

    <h1><span class="global-grid-box" onclick="openGlobalPartajare()" title="Partajează întreaga bază de date"></span>Listă obiecte înregistrate</h1>
    <div id="statusCautare" style="text-align: center; margin-bottom: 20px; font-weight: bold;"></div>

    <?php if (!empty($grupuri)): ?>
        <?php foreach ($grupuri as $grup): ?>
            <?php $info = $grup['info']; ?>
            <div class="grup-obiecte" id="grup-<?php echo $info['id_obiect']; ?>" data-locatie="<?php echo htmlspecialchars($info['locatie']); ?>"
                 data-cutie="<?php echo htmlspecialchars($info['cutie']); ?>"
                 data-categorie="<?php echo htmlspecialchars($info['categorie']); ?>"
                 data-obiecte="<?php echo htmlspecialchars(implode(', ', $grup['obiecte'])); ?>">

                <!-- Buton ștergere cutie -->
                <?php if ($_SESSION['tip_acces_colectie'] == 'proprietar' || $_SESSION['tip_acces_colectie'] == 'scriere'): ?>
                <button class="delete-cutie-btn"
                        onclick="stergeCutie(event, <?php echo $info['id_obiect']; ?>, '<?php echo addslashes($info['locatie']); ?>', '<?php echo addslashes($info['cutie']); ?>')"
                        title="Șterge întreaga cutie">
                    ×
                </button>
                <?php endif; ?>

                <style>
                    .grup-titlu {
                        text-align: left;
                    }
                    .grup-titlu .linie-principala,
                    .grup-titlu .linie-secundara {
                        display: block;
                        margin-left: 15px;
                        font-size: 0.8em;
                        font-weight: normal;
                    }
                    .grup-titlu .indicator {
                        display: inline-block;
                        position: relative;
                        width: 16px;
                        height: 12px;
                        border: 1px solid #333;
                        border-radius: 2px;
                        background-color: #f5f5f5;
                        vertical-align: middle;
                        margin-right: 3px;
                        overflow: hidden;
                    }
                    .grup-titlu .indicator .bar-top {
                        position: absolute;
                        top: 0;
                        left: 0;
                        right: 0;
                        height: 2px;
                        background-color: #333;
                    }
                    .grup-titlu .indicator .grid {
                        position: absolute;
                        top: 0;
                        left: 0;
                        right: 0;
                        bottom: 0;
                        opacity: 0.7;
                        background-image:
                                repeating-linear-gradient(to right, transparent, transparent 2px, #ccc 2px, #ccc 3px),
                                repeating-linear-gradient(to bottom, transparent, transparent 2px, #ccc 2px, #ccc 3px);
                    }
                    .grup-titlu .linie-secundara strong {
                        color: #666666;
                    }
                    /* Stiluri comune pentru toate zonele cu placeholder */
                    .obiecte-text-gol, .descriere-text-gol, .categorii-placeholder {
                        display: inline-block;
                        min-width: 250px;
                        min-height: 20px;
                        padding: 5px 10px;
                        border: 1px dashed #ccc;
                        border-radius: 4px;
                        background-color: #f9f9f9;
                        color: #999;
                        font-style: italic;
                        cursor: text;
                        transition: all 0.3s ease;
                    }

                    .obiecte-text-gol:hover, .descriere-text-gol:hover, .categorii-placeholder:hover,
                    .obiecte-text-gol:focus, .descriere-text-gol:focus {
                        border-color: #999;
                        background-color: #fff;
                        color: #333;
                    }

                    /* Stiluri pentru categorii placeholder */
                    .categorii-placeholder {
                        margin: 5px 0;
                        cursor: pointer; /* Cursor pointer pentru a indica că e clickabil */
                    }

                    /* Stiluri pentru zonele editabile cu conținut */
                    .obiecte-text:not(.obiecte-text-gol), .descriere-text:not(.descriere-text-gol) {
                        display: inline-block;
                        min-width: 100px;
                        padding: 3px 5px;
                        border: 1px solid transparent;
                        border-radius: 4px;
                        transition: all 0.3s ease;
                    }

                    .obiecte-text:not(.obiecte-text-gol):hover, .descriere-text:not(.descriere-text-gol):hover,
                    .obiecte-text:not(.obiecte-text-gol):focus, .descriere-text:not(.descriere-text-gol):focus {
                        border-color: #ddd;
                        background-color: #f5f5f5;
                    }

                    /* Stiluri pentru containerul de badge-uri când este gol */
                    .badge-container:empty {
                        display: inline-block;
                        min-width: 250px;
                        min-height: 30px;
                        padding: 5px;
                        border: 1px dashed #ccc;
                        border-radius: 4px;
                        background-color: #f9f9f9;
                        cursor: pointer;
                    }

                    /* Stil pentru inputul de editare categorii */
                    .edit-categorii {
                        display: none;
                        width: 100%;
                        max-width: 500px;
                        padding: 5px;
                        border: 1px solid #ccc;
                        border-radius: 4px;
                    }

                    /* Stil pentru obiecte detectate de AI (Vision) */
                    .obiect-vision {
                        color: #ff6600 !important; /* Portocaliu */
                        font-weight: 500;
                    }

                    /* Stil pentru separarea vizuală între obiecte manual și AI */
                    .separator-sursa {
                        color: #ccc;
                        margin: 0 5px;
                    }

                    /* Stiluri pentru editare locație și cutie */
                    .locatie-editabila:hover,
                    .cutie-editabila:hover {
                        background-color: rgba(255, 255, 255, 0.1);
                        border-radius: 4px;
                        padding: 2px 4px;
                        margin: -2px -4px;
                    }

                    .locatie-editabila:focus,
                    .cutie-editabila:focus {
                        background-color: rgba(255, 255, 255, 0.15);
                        outline: 1px dashed rgba(255, 255, 255, 0.5);
                        border-radius: 4px;
                        padding: 2px 4px;
                        margin: -2px -4px;
                    }
                </style>

                <h2 class="grup-titlu">
                    <!-- Prima linie: locație -->
                    <span class="linie-principala">
    <span contenteditable="true"
          onblur="actualizeazaObiect(<?php echo $info['id_obiect']; ?>, 'locatie', this)"
          class="locatie-editabila"
          style="cursor: text; display: inline-block;">
        <?php echo htmlspecialchars($info['locatie']); ?>
    </span>
  </span>

                    <!-- A doua linie: indicator + Cutie -->
                    <span class="linie-secundara">
    <span class="indicator <?php 
        // Evidențiere purple doar când TOATE obiectele sunt partajate
        echo (!empty($grup['info']['obiecte_partajate']) && 
              trim($grup['info']['obiecte_partajate']) === trim($grup['info']['denumire_obiect'])) ? 'public' : ''; 
    ?>" data-cutie="<?php echo htmlspecialchars($grup['info']['cutie']); ?>" data-locatie="<?php echo htmlspecialchars($grup['info']['locatie']); ?>">
      <span class="bar-top"></span>
      <span class="grid" onclick="openPartajareModal(this)" title="Configurează partajarea pentru această cutie"></span>
    </span>
    <strong>Cutie</strong>: <span contenteditable="true"
                                  onblur="actualizeazaObiect(<?php echo $info['id_obiect']; ?>, 'cutie', this)"
                                  class="cutie-editabila"
                                  style="cursor: text; display: inline-block;">
        <?php echo htmlspecialchars($info['cutie']); ?>
    </span>
  </span>
                </h2>


                <div class="imagini-container" id="imagini-<?php echo $info['id_obiect']; ?>" data-id="<?php echo $info['id_obiect']; ?>">
                    <?php
                    if (!empty($info['imagine'])) {
                        $imagini = array_map('trim', explode(',', $info['imagine']));

                        // Verificăm dacă există o imagine salvată în sesiune pentru acest grup
                        $ultimaImagine = isset($_SESSION['ultima_imagine_' . $info['id_obiect']])
                            ? $_SESSION['ultima_imagine_' . $info['id_obiect']]
                            : '';

                        foreach ($imagini as $index => $imagine):
                            // Determinăm dacă această imagine trebuie marcată ca selectată
                            $isSelected = '';
                            if ($ultimaImagine === $imagine) {
                                // Dacă avem o selecție salvată și este această imagine
                                $isSelected = 'selected';
                            } else if ($ultimaImagine === '' && $index === 0) {
                                // Dacă nu avem o selecție salvată, aplicăm la prima imagine
                                $isSelected = 'selected';
                            }
                            ?>
                            <div class="thumb-container">
                                <img src="imagini_obiecte/user_<?php echo $user_id; ?>/<?php echo str_replace(' ', '%20', $imagine); ?>"
                                     alt="Imagine obiect"
                                     class="thumb <?php echo $isSelected; ?>"
                                     data-id="<?php echo $info['id_obiect']; ?>"
                                     data-nume="<?php echo htmlspecialchars($imagine); ?>"
                                     data-index="<?php echo $index + 1; ?>"
                                     onclick="selecteazaImagine(this)">
                                <?php if ($_SESSION['tip_acces_colectie'] == 'proprietar' || $_SESSION['tip_acces_colectie'] == 'scriere'): ?>
                                <button class="delete-image-btn"
                                        onclick="stergeImagine(event, <?php echo $info['id_obiect']; ?>, '<?php echo addslashes(htmlspecialchars($imagine)); ?>', <?php echo $index + 1; ?>)"
                                        title="Șterge imaginea">
                                    ×
                                </button>
                                <?php endif; ?>
                            </div>
                        <?php endforeach;
                    } else { ?>
                        <img src="imagini_obiecte/placeholder.png"
                             alt="Imagine indisponibilă"
                             class="thumb selected"
                             data-id="<?php echo $info['id_obiect']; ?>"
                             data-nume="placeholder.png"
                             onclick="selecteazaImagine(this)">
                    <?php } ?>
                </div>

                <p><strong>Categorii:</strong>
                <div class="badge-container" onclick="startEditCategorii(this, <?php echo $info['id_obiect']; ?>)">
                    <?php
                    $categorii = explode(',', $info['categorie']);
                    $culori = explode(',', $info['eticheta']);

                    if (empty($categorii) || (count($categorii) === 1 && trim($categorii[0]) === '')) {
                        // Afișăm placeholder când nu există categorii
                        echo '<span class="categorii-placeholder">Clic aici pentru a adăuga categorii...</span>';
                    } else {
                        // Afișăm categoriile existente
                        foreach ($categorii as $i => $cat) {
                            $catTrim = trim($cat);
                            $culoare = trim($culori[$i] ?? '#ccc');
                            $r = hexdec(substr($culoare,1,2));
                            $g = hexdec(substr($culoare,3,2));
                            $b = hexdec(substr($culoare,5,2));
                            $lum = ($r*299 + $g*587 + $b*114) / 1000;
                            $textColor = $lum > 150 ? '#000' : '#fff';

                            if (strtolower($catTrim) === 'obiecte') {
                                $textColor = '#000';
                            }

                            echo "<span class='badge-categorie' style='background-color: {$culoare}; color: {$textColor};' data-text='" . htmlspecialchars($catTrim) . "'>" . htmlspecialchars($catTrim) . "</span> ";
                        }
                    }
                    ?>
                </div>
                <input type="text" class="edit-categorii" onblur="salveazaCategorii(this)">
                </p>

                <p><strong>Descriere categorie:</strong>
                    <span contenteditable="true"
                          onblur="actualizeazaObiect(<?php echo $info['id_obiect']; ?>, 'descriere_categorie', this)"
                          class="descriere-text <?php echo empty(trim($info['descriere'])) ? 'descriere-text-gol' : ''; ?>">
        <?php
        if (empty(trim($info['descriere']))) {
            echo "Clic aici pentru a adăuga o descriere...";
        } else {
            echo htmlspecialchars($info['descriere']);
        }
        ?>
    </span>
                </p>

                <p><strong>Obiecte:</strong>
                    <?php
                    // Pregătim informațiile despre obiecte pentru editare
                    $denumiriObiecte = [];
                    foreach ($grup['obiecte'] as $obiectString) {
                        $denumiriObiecte = array_merge($denumiriObiecte, array_map('trim', explode(',', $obiectString)));
                    }

                    // Obținem etichetele și cantitățile
                    $eticheteObiecte = array_map('trim', explode(';', $info['eticheta_obiect'] ?? ''));
                    $cantitatiObiecte = array_map('trim', explode(',', $info['cantitate'] ?? ''));
                    // Obținem imaginile obiectelor
                    $imaginiObiecte = array_map('trim', explode(',', $info['imagine_obiect'] ?? ''));

                    // Construim un obiect JSON cu informațiile despre fiecare obiect
                    $obiecteInfo = [];
                    foreach ($denumiriObiecte as $index => $denumire) {
                        $denumireCurata = $denumire;
                        $indexImagine = '1';

                        // Extragem indexul imaginii
                        if (preg_match('/^(.*?)\((\d+)\)$/', $denumire, $matches)) {
                            $denumireCurata = trim($matches[1]);
                            $indexImagine = $matches[2];
                        }

                        $obiecteInfo[] = [
                            'denumireOriginala' => $denumire,
                            'denumireCurata' => $denumireCurata,
                            'indexImagine' => $indexImagine,
                            'eticheta' => isset($eticheteObiecte[$index]) ? $eticheteObiecte[$index] : '#ccc',
                            'cantitate' => isset($cantitatiObiecte[$index]) ? $cantitatiObiecte[$index] : '1',
                            'imagine' => isset($imaginiObiecte[$index]) ? $imaginiObiecte[$index] : ''
                        ];
                    }

                    $obiecteInfoJSON = htmlspecialchars(json_encode($obiecteInfo));
                    ?>
                    <span contenteditable="true"
                          id="lista-obiecte-<?php echo $info['id_obiect']; ?>"
                          oninput="detecteazaVirgula(this, <?php echo $info['id_obiect']; ?>)"
                          onblur="verificaVirguleInObiect(<?php echo $info['id_obiect']; ?>, this)"
                          data-obiecte-info="<?php echo $obiecteInfoJSON; ?>"
                          data-continut-temporar=""
                          class="obiecte-text <?php echo empty($obiecteInfo) ? 'obiecte-text-gol' : ''; ?>">
    <?php
    // Afișăm obiectele cu cantitățile lor între paranteze
    $obiecteAfisate = [];

    // Obținem lista de obiecte detectate cu Google Vision pentru acest id_obiect
    $obiecteGV = [];
    $table_prefix = $GLOBALS['table_prefix'] ?? '';
    $sql_gv = "SELECT denumire FROM `{$table_prefix}detectii_obiecte` WHERE id_obiect = ? AND sursa = 'google_vision'";
    if ($stmt_gv = mysqli_prepare($conn, $sql_gv)) {
        mysqli_stmt_bind_param($stmt_gv, 'i', $info['id_obiect']);
        mysqli_stmt_execute($stmt_gv);
        $result_gv = mysqli_stmt_get_result($stmt_gv);
        while ($row_gv = mysqli_fetch_assoc($result_gv)) {
            // Stocăm denumirea fără paranteze pentru comparație
            $denumireCurata = preg_replace('/\s*\(\d+\)$/', '', $row_gv['denumire']);
            $obiecteGV[] = $denumireCurata;
        }
        mysqli_stmt_close($stmt_gv);
    }

    foreach ($obiecteInfo as $obiect) {
        $textObiect = $obiect['denumireCurata'] . '(' . $obiect['cantitate'] . ')';

        // Verificăm dacă această denumire (fără index) este detectată cu Google Vision
        $denumireFaraIndex = preg_replace('/\s*\(\d+\)$/', '', $obiect['denumireCurata']);
        $esteGV = in_array($denumireFaraIndex, $obiecteGV);

        if ($esteGV) {
            $obiecteAfisate[] = '<span class="obiect-gv">' . htmlspecialchars($textObiect) . '</span>';
        } else {
            $obiecteAfisate[] = htmlspecialchars($textObiect);
        }
    }

    // Adăugăm mesaj placeholder doar dacă lista e goală
    if (empty($obiecteAfisate)) {
        echo "Clic aici pentru a adăuga obiecte sau clic pe imagine...";
    } else {
        echo implode(', ', $obiecteAfisate);
    }
    ?>
</span>
                </p>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <p>Nu există obiecte înregistrate.</p>
    <?php endif; ?>
</div>

<script>
    // Funcții globale care trebuie să fie accesibile din HTML
    function selecteazaImagine(element) {
        const idObiect = element.dataset.id;
        const numeImagine = element.dataset.nume;

        // Salvăm informații despre imaginea selectată în localStorage
        localStorage.setItem('ultimulIdObiectVizualizat', idObiect);
        localStorage.setItem('ultimulNumeImagineVizualizat', numeImagine);

        // De asemenea, salvăm id-ul div-ului container al grupului pentru a putea derula la el
        const grupContainer = element.closest('.grup-obiecte');
        if (grupContainer) {
            localStorage.setItem('ultimulGrupObiectVizualizat', grupContainer.id || `grup-${idObiect}`);
        }

        // Actualizăm vizual selecția în UI
        const container = element.closest('.imagini-container');
        if (container) {
            // Eliminăm clasa selected de pe toate imaginile din acest container
            container.querySelectorAll('.thumb').forEach(img => {
                img.classList.remove('selected');
            });

            // Adăugăm clasa selected la imaginea curentă
            element.classList.add('selected');
        }

        // Redirectăm către pagina de editare imediat după click
        // Luăm ID-ul colecției din tab-ul activ
        const tabActiv = document.querySelector('.tab.active');
        let urlEtichete = `etichete_imagine.php?id=${idObiect}&imagine=${encodeURIComponent(numeImagine)}`;
        if (tabActiv) {
            const idColectieCurenta = tabActiv.getAttribute('data-colectie');
            if (idColectieCurenta) {
                urlEtichete += `&colectie=${idColectieCurenta}`;
            }
        }
        window.location.href = urlEtichete;
    }

    // Funcție pentru ștergerea întregii cutii
    function stergeCutie(event, idObiect, locatie, cutie) {
        event.stopPropagation(); // Previne alte evenimente

        // Salvăm referința la buton înainte de modal
        const btn = event.target;

        // Modificăm mesajul modal-ului pentru ștergerea cutiei
        const modalContent = document.querySelector('.sterge-modal-message');
        const originalMessage = modalContent.innerHTML;

        modalContent.innerHTML = `Sigur doriți să ștergeți întreaga cutie <strong>"${cutie}"</strong> din locația <strong>"${locatie}"</strong>?<br><br>
            <strong style="color: #f44336;">ATENȚIE:</strong> Se vor șterge TOATE imaginile și obiectele din această cutie!`;

        // Afișăm modal-ul de confirmare
        arataStergeModal(function(confirmed) {
            // Restaurăm mesajul original
            modalContent.innerHTML = originalMessage;

            if (!confirmed) return;

            // Afișăm un indicator de încărcare
            btn.innerHTML = '⟳';
            btn.disabled = true;

            // Trimitem cererea de ștergere
            const formData = new FormData();
            formData.append('id_obiect', idObiect);
            formData.append('action', 'sterge_cutie');

            // Adăugăm ID-ul colecției curente - luăm din tab-ul activ
            const tabActiv = document.querySelector('.tab.active');
            if (tabActiv) {
                const idColectieCurenta = tabActiv.getAttribute('data-colectie');
                if (idColectieCurenta) {
                    formData.append('id_colectie', idColectieCurenta);
                    console.log('Trimit date pentru colecția:', idColectieCurenta);
                }
            }

            fetch('sterge_cutie.php', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Reîncărcăm pagina pentru a reflecta modificările
                        location.reload();
                    } else {
                        arataAlert('Eroare la ștergerea cutiei: ' + (data.error || 'Eroare necunoscută'));
                        btn.innerHTML = '×';
                        btn.disabled = false;
                    }
                })
                .catch(error => {
                    console.error('Eroare:', error);
                    arataAlert('Eroare la comunicarea cu serverul');
                    btn.innerHTML = '×';
                    btn.disabled = false;
                });
        });
    }

    // Funcție pentru ștergerea unei imagini
    function stergeImagine(event, idObiect, numeImagine, indexImagine) {
        event.stopPropagation(); // Previne selectarea imaginii

        // Salvăm referința la buton înainte de modal
        const btn = event.target;

        // Afișăm modal-ul de confirmare
        arataStergeModal(function(confirmed) {
            if (!confirmed) return;

            // Afișăm un indicator de încărcare
            btn.innerHTML = '⟳';
            btn.disabled = true;

            // Trimitem cererea de ștergere
            const formData = new FormData();
            formData.append('action', 'sterge_imagine');
            formData.append('id_obiect', idObiect);
            formData.append('nume_imagine', numeImagine);
            formData.append('index_imagine', indexImagine);

            // Adăugăm ID-ul colecției curente - luăm din tab-ul activ
            const tabActiv = document.querySelector('.tab.active');
            if (tabActiv) {
                const idColectieCurenta = tabActiv.getAttribute('data-colectie');
                if (idColectieCurenta) {
                    formData.append('id_colectie', idColectieCurenta);
                    console.log('Trimit date pentru colecția:', idColectieCurenta);
                }
            }

            fetch('sterge_imagine.php', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // IMPORTANT: După ștergerea unei imagini, trebuie să reîncărcăm
                        // containerul cutiei pentru a actualiza indexurile celorlalte imagini
                        // Altfel, următoarea ștergere va folosi indexuri greșite
                        
                        // Găsim cutia părinte și o reîncărcăm
                        const cutieDiv = btn.closest('.cutie');
                        if (cutieDiv) {
                            // Găsim butonul de expandare al cutiei
                            const expandBtn = cutieDiv.querySelector('.expand-btn');
                            if (expandBtn) {
                                // Simulăm închiderea și redeschiderea cutiei pentru a reîncărca conținutul
                                expandBtn.click(); // Închide
                                setTimeout(() => {
                                    expandBtn.click(); // Redeschide cu date actualizate
                                }, 100);
                            } else {
                                // Fallback: reîncărcăm pagina dacă nu găsim butonul
                                location.reload();
                            }
                        } else {
                            // Fallback: reîncărcăm pagina dacă nu găsim cutia
                            location.reload();
                        }
                    } else {
                        arataAlert('Eroare la ștergerea imaginii: ' + (data.error || 'Eroare necunoscută'));
                        btn.innerHTML = '×';
                        btn.disabled = false;
                    }
                })
                .catch(error => {
                    console.error('Eroare:', error);
                    arataAlert('Eroare la comunicarea cu serverul');
                    btn.innerHTML = '×';
                    btn.disabled = false;
                });
        });
    }

    // Funcție pentru editarea categoriilor
    function startEditCategorii(container, id) {
        const input = container.nextElementSibling;
        const currentText = Array.from(container.querySelectorAll('.badge-categorie')).map(e => e.innerText).join(', ');
        input.value = currentText;
        input.dataset.id = id;
        container.style.display = 'none';
        input.style.display = 'inline-block';
        input.focus();
    }

    function salveazaCategorii(input) {
        const id = input.dataset.id;
        const valoare = input.value;
        const formData = new URLSearchParams();
        formData.append('id', id);
        formData.append('camp', 'categorie');
        formData.append('valoare', valoare);
        formData.append('pastrare_virgule', 'true');

        // Adăugăm ID-ul colecției curente - luăm din tab-ul activ
        const tabActiv = document.querySelector('.tab.active');
        if (tabActiv) {
            const idColectieCurenta = tabActiv.getAttribute('data-colectie');
            if (idColectieCurenta) {
                formData.append('id_colectie', idColectieCurenta);
                console.log('Actualizez obiect pentru colecția:', idColectieCurenta);
            }
        }

        fetch('actualizeaza_obiect.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData.toString()
        })
            .then(r => r.json()) // Modificat pentru a primi JSON înapoi
            .then(response => {
                const container = input.parentElement;
                const badgeContainer = container.querySelector('.badge-container');

                // Actualizăm grupul cu noile categorii
                const grup = container.closest('.grup-obiecte');
                if (grup) {
                    grup.setAttribute('data-categorie', valoare);

                    // Utilizăm categoriile și culorile returnate de server
                    const categorii = response.categorii || [];
                    const culori = response.culori || [];

                    // Generăm HTML pentru badge-uri folosind culorile returnate de server
                    let badgeHTML = '';

                    // Verificăm dacă avem categorii sau lista este goală
                    if (categorii.length === 0 || (categorii.length === 1 && categorii[0].trim() === '')) {
                        // Afișăm placeholder când nu există categorii
                        badgeHTML = '<span class="categorii-placeholder">Clic aici pentru a adăuga categorii...</span>';
                    } else {
                        // Generăm badge-urile pentru categorii
                        for (let i = 0; i < categorii.length; i++) {
                            const catTrim = categorii[i];
                            const culoare = culori[i] || '#ccc';

                            // Calculăm culoarea textului bazată pe luminozitatea culorii
                            const r = parseInt(culoare.slice(1, 3), 16);
                            const g = parseInt(culoare.slice(3, 5), 16);
                            const b = parseInt(culoare.slice(5, 7), 16);
                            const lum = (r * 299 + g * 587 + b * 114) / 1000;
                            const textColor = lum > 150 ? '#000' : '#fff';

                            // Adăugăm badge-ul la HTML
                            badgeHTML += `<span class='badge-categorie' style='background-color: ${culoare}; color: ${textColor};' data-text='${catTrim}'>${catTrim}</span> `;
                        }
                    }

                    // Actualizăm containerul de badge-uri
                    badgeContainer.innerHTML = badgeHTML;

                    // Reaplică evidențierea termenilor dacă e cazul
                    if (typeof termenCautareCurent !== 'undefined' && termenCautareCurent && termenCautareCurent !== '') {
                        evidentiazaTermeni(grup, termenCautareCurent);
                    }

                    // Afișăm bifa de confirmare
                    const bifa = document.createElement('span');
                    bifa.textContent = ' ✔';
                    bifa.style.color = 'green';
                    bifa.style.marginLeft = '5px';
                    container.appendChild(bifa);
                    setTimeout(() => bifa.remove(), 2000);

                    // Ascundem inputul și arătăm badgeContainer
                    badgeContainer.style.display = 'inline-block';
                    input.style.display = 'none';
                } else {
                    // Dacă nu găsim grupul, reîncărcăm pagina
                    location.reload();
                }
            })
            .catch(err => {
                console.error('Eroare salvare categorii:', err);
                input.style.display = 'none';
                input.previousElementSibling.style.display = 'inline-block';
            });
    }

    // Funcție pentru actualizarea obiectelor
    function actualizeazaObiect(id, camp, element, eObiect = false) {
        const valoare = element.innerText.trim();
        const formData = new URLSearchParams();
        formData.append('id', id);
        formData.append('camp', camp);
        formData.append('valoare', valoare);

        // Adăugăm ID-ul colecției curente - luăm din tab-ul activ
        const tabActiv = document.querySelector('.tab.active');
        if (tabActiv) {
            const idColectieCurenta = tabActiv.getAttribute('data-colectie');
            if (idColectieCurenta) {
                formData.append('id_colectie', idColectieCurenta);
                console.log('Actualizez obiect pentru colecția:', idColectieCurenta);
            }
        }

        if (eObiect) {
            try {
                // Obținem informațiile originale despre obiecte
                const obiecteOriginale = JSON.parse(element.getAttribute('data-obiecte-info') || '[]');
                console.log("Obiecte Originale Complete:", obiecteOriginale);

                // Extragem denumirile și cantitățile din textul editat
                const obiecteEditate = valoare.split(',').map(item => item.trim()).filter(item => item);
                const denumiriNoi = [];
                const cantitatiNoi = [];
                const eticheteNoi = [];
                const imaginiNoi = [];

                // LOGICA CORECTATĂ: Păstrăm exact indexul imaginii din obiectul original
                // Cream un tracking array pentru a ține evidența obiectelor deja potrivite
                const obiecteUtilizate = Array(obiecteOriginale.length).fill(false);

                for (let i = 0; i < obiecteEditate.length; i++) {
                    // Extragem denumirea și cantitatea din textul editat
                    let numeBaza, cantitate;
                    const match = obiecteEditate[i].match(/^(.*?)\((\d+)\)$/);
                    if (match) {
                        numeBaza = match[1].trim();
                        cantitate = match[2];
                    } else {
                        numeBaza = obiecteEditate[i].trim();
                        cantitate = '1';
                    }

                    // Căutăm obiectul EXACT în lista originală după denumire și index
                    let obiectGasitExact = null;
                    let indexObiectGasit = -1;

                    // Prima încercare: căutare exactă cu același index imagine (dacă avem un index în obiectul editat)
                    if (match && match[2]) {
                        const indexCautat = match[2];
                        for (let j = 0; j < obiecteOriginale.length; j++) {
                            if (!obiecteUtilizate[j] &&
                                obiecteOriginale[j].denumireCurata.toLowerCase() === numeBaza.toLowerCase() &&
                                obiecteOriginale[j].indexImagine === indexCautat) {
                                indexObiectGasit = j;
                                obiectGasitExact = obiecteOriginale[j];
                                obiecteUtilizate[j] = true;
                                console.log(`Potrivire exactă cu index pentru: ${numeBaza}(${indexCautat})`, obiectGasitExact);
                                break;
                            }
                        }
                    }

                    // A doua încercare: căutare doar după nume dacă nu am găsit o potrivire exactă cu index
                    if (indexObiectGasit === -1) {
                        for (let j = 0; j < obiecteOriginale.length; j++) {
                            if (!obiecteUtilizate[j] &&
                                obiecteOriginale[j].denumireCurata.toLowerCase() === numeBaza.toLowerCase()) {
                                indexObiectGasit = j;
                                obiectGasitExact = obiecteOriginale[j];
                                obiecteUtilizate[j] = true;
                                console.log(`Potrivire doar după nume pentru: ${numeBaza}`, obiectGasitExact);
                                break;
                            }
                        }
                    }

                    // Determinăm valorile pentru acest obiect
                    let indexImagine = '0';  // Valoare implicită
                    let eticheta = '#ccc';   // Valoare implicită
                    let imagine = '';        // Valoare implicită pentru imagine
                    let denumireOriginala = numeBaza; // Păstrăm numele așa cum a fost editat inițial

                    // Dacă am găsit obiectul în lista originală, folosim EXACT valorile lui
                    if (obiectGasitExact) {
                        // IMPORTANT: Păstrăm indexul EXACT al imaginii din obiectul original
                        indexImagine = obiectGasitExact.indexImagine;
                        eticheta = obiectGasitExact.eticheta;
                        imagine = obiectGasitExact.imagine;
                        denumireOriginala = obiectGasitExact.denumireCurata; // Folosim denumirea originală (cu majuscule/minuscule)

                        console.log(`Păstrăm valorile EXACTE pentru: ${denumireOriginala}(${indexImagine})`, {
                            eticheta: eticheta,
                            imagine: imagine
                        });
                    }

                    // Păstrăm valorile obținute pentru acest obiect
                    denumiriNoi.push(`${denumireOriginala}(${indexImagine})`);
                    cantitatiNoi.push(cantitate);
                    eticheteNoi.push(eticheta);
                    imaginiNoi.push(imagine);
                }

                // Pregătim datele pentru trimitere
                formData.append('denumiri', denumiriNoi.join(', '));
                formData.append('cantitati', cantitatiNoi.join(', '));
                formData.append('etichete_obiect', eticheteNoi.join('; '));
                formData.append('imagini_obiect', imaginiNoi.join(', '));
                formData.append('pastrare_asocieri', 'true');
                formData.append('pastrare_ordine', 'true');
                formData.append('actualizeaza_imagini', 'true');

                // Adăugăm informații pentru debugging
                console.group('Debugging - Date trimise pentru actualizare:');
                console.log('ID:', id);
                console.log('Camp:', camp);
                console.log('Obiecte Originale:', obiecteOriginale);
                console.log('Denumiri Noi:', denumiriNoi);
                console.log('Cantități Noi:', cantitatiNoi);
                console.log('Etichete Noi:', eticheteNoi);
                console.log('Imagini Noi:', imaginiNoi);
                console.groupEnd();

            } catch (error) {
                console.error('Eroare la procesarea obiectelor:', error);
            }
        }

        // Adăugăm un flag pentru a cere informații de debugging
        formData.append('debug_mode', 'true');

        fetch('actualizeaza_obiect.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData.toString()
        })
            .then(r => r.json())
            .then(jsonData => {
                try {

                    // Afișăm informațiile de debugging în consolă
                    if (jsonData.debug) {
                        console.group('=== DEBUGGING RĂSPUNS ACTUALIZARE_OBIECT ===');
                        console.log('Rezultat:', jsonData.message || jsonData.error);

                        // Afișăm date detaliate din debugging
                        if (jsonData.debug.obiecte_eliminate && jsonData.debug.obiecte_eliminate.length > 0) {
                            console.group('Obiecte care lipsesc (au fost șterse):');
                            jsonData.debug.obiecte_eliminate.forEach((obj, idx) => {
                                console.log(`Obiect #${idx}:`, obj);
                            });
                            console.groupEnd();
                        }

                        if (jsonData.debug.siruri_finale) {
                            console.group('Date finale pentru update:');
                            console.log('Denumiri:', jsonData.debug.siruri_finale.denumiri);
                            console.log('Cantități:', jsonData.debug.siruri_finale.cantitati);
                            console.log('Etichete:', jsonData.debug.siruri_finale.etichete);
                            console.log('Imagini:', jsonData.debug.siruri_finale.imagini);
                            console.groupEnd();
                        }

                        console.groupEnd();
                    }

                    // Afișăm bifa normală
                    afiseazaBifa(element);

                } catch (e) {
                    // Dacă nu este JSON, afișăm răspunsul text și eroarea
                    console.log('Răspuns server:', jsonData);
                    console.error('Eroare parsare JSON:', e);
                    afiseazaBifa(element);
                }

                // Partea originală pentru reaplicare evidențiere
                if (typeof termenCautareCurent !== 'undefined' && termenCautareCurent && termenCautareCurent !== '') {
                    const grup = element.closest('.grup-obiecte');
                    if (grup) {
                        // Actualizăm și atributul data-obiecte pentru căutare
                        if (eObiect) {
                            grup.setAttribute('data-obiecte', valoare);
                        }

                        // Reaplică evidențierea
                        setTimeout(() => {
                            // Salvăm textul original pentru evidențiere
                            element.setAttribute('data-text-original', element.textContent);
                            if (typeof evidentiazaTermeni !== 'undefined') {
                                evidentiazaTermeni(grup, termenCautareCurent);
                            }
                        }, 100);
                    }
                }
            })
            .catch(err => {
                console.error('Eroare actualizare:', err);
                afiseazaBifa(element);
            });
    }

    // Funcție pentru afișarea bifei de confirmare
    function afiseazaBifa(element) {
        const bifa = document.createElement('span');
        bifa.textContent = ' ✔';
        bifa.style.color = 'green';
        bifa.style.fontSize = '20px';
        bifa.style.marginLeft = '5px';
        bifa.style.display = 'inline-block';
        element.appendChild(bifa);

        // Eliminare după 1.5 secunde cu animație
        setTimeout(() => {
            bifa.style.transition = 'opacity 0.3s';
            bifa.style.opacity = '0';
            setTimeout(() => {
                if (bifa.parentNode) {
                    bifa.parentNode.removeChild(bifa);
                }
            }, 300);
        }, 1700);
    }

    document.addEventListener('DOMContentLoaded', function() {
        const testGrup = document.querySelector('.grup-obiecte');
        if (testGrup) {
            testGrup.classList.add('hidden');
            console.log("Test hidden - display:", window.getComputedStyle(testGrup).display);
            testGrup.classList.remove('hidden');
        }

        // Adăugăm listeners pentru butoanele din modal
        document.getElementById('butonConfirmaMultipleObiecte').addEventListener('click', confirmaMultipleObiecte);
        document.getElementById('butonInfirmaMultipleObiecte').addEventListener('click', infirmaMultipleObiecte);

        // Adăugăm event listener pentru a curăța complet evidențierile când câmpul de căutare devine gol
        const campCautare = document.getElementById('campCautare');

        // Adăugăm un event listener pentru 'input' care să verifice explicit când câmpul devine gol
        campCautare.addEventListener('input', function() {
            if (this.value.trim() === '') {
                curataToateEvidentierile();
            }
        });

        // Adăugăm și un event listener pentru 'focus' care să curețe evidențierile când câmpul primește focus
        campCautare.addEventListener('focus', function() {
            if (this.value.trim() === '') {
                curataToateEvidentierile();
            }
        });

        // Adăugăm event listener pentru click pe câmpul de căutare pentru resetarea căutării
        campCautare.addEventListener('click', function() {
            // Dacă există text în câmpul de căutare
            if (this.value.trim() !== '') {
                // Resetăm căutarea
                this.value = '';
                filtreazaObiecte('');
                curataToateEvidentierile();

                // Selectăm tot textul pentru ștergere rapidă (în caz că utilizatorul vrea să tasteze direct)
                this.select();
            }
        });

        // Adăugăm event listener pentru click pe header (navbar, user section) pentru resetarea căutării
        const headerElements = document.querySelectorAll('.navbar, .user-section');
        headerElements.forEach(element => {
            element.addEventListener('click', function(event) {
                // Verificăm dacă există o căutare activă
                if (campCautare.value.trim() !== '') {
                    // Nu resetăm dacă click-ul este pe un link sau buton din header
                    if (!event.target.closest('a, button, .user-avatar')) {
                        // Resetăm căutarea
                        campCautare.value = '';
                        filtreazaObiecte('');
                        curataToateEvidentierile();
                    }
                }
            });
        });

        // Event delegation pentru hover - doar primul obiect
        document.body.addEventListener('mouseover', function(event) {
            const target = event.target.closest('.highlight-text[data-first-obiect]');
            if (target) {
                afiseazaImagineTooltip(event, target);
            }
        });

        // Event delegation pentru ieșirea din hover
        document.body.addEventListener('mouseout', function(event) {
            const target = event.target;
            if (target.classList.contains('highlight-text')) {
                ascundeImagineTooltip();
            }
        });

        // Actualizăm poziția tooltip-ului la mișcarea mouse-ului
        document.body.addEventListener('mousemove', function(event) {
            const target = event.target;
            if (target.classList.contains('highlight-text') &&
                document.getElementById('imagine-tooltip').style.display === 'block') {
                const tooltip = document.getElementById('imagine-tooltip');
                tooltip.style.left = (event.pageX + 15) + 'px';
                tooltip.style.top = (event.pageY + 15) + 'px';
            }
        });
    });

    // Variabile pentru gestionarea editării și modala de confirmare
    let elemCurent = null;
    let idObiectCurent = null;
    let virgulaDetectata = false;
    let continutInainteDePrimaVirgula = '';
    let continutOriginalComplet = '';
    let termenCautareCurent = ''; // Pentru reținerea termenului curent de căutare

    // Funcție ajutătoare pentru evidențierea unui text
    function evidentiazaText(text, termen, imaginiJSON = '', indexStart = 0) {
        if (!text) return '';

        // Creăm un RegExp care va fi insensibil la diacritice
        // Ne folosim de o funcție ajutătoare care va elimina diacriticele din termen
        const termenFaraDiacritice = eliminaDiacritice(termen);
        const regex = new RegExp('(' + escapeRegex(termenFaraDiacritice) + ')', 'gi');

        // Facem o copie a textului original pentru a păstra evidențierile corect poziționate
        const textOriginal = text;
        const textFaraDiacritice = eliminaDiacritice(text);

        // Ținem evidența pozițiilor unde avem potriviri
        const potriviri = [];
        let match;

        // Găsim toate potrivirile în textul fără diacritice
        while ((match = regex.exec(textFaraDiacritice)) !== null) {
            potriviri.push({
                start: match.index,
                end: match.index + match[0].length,
                text: textOriginal.substring(match.index, match.index + match[0].length)
            });
        }

        // Dacă nu există potriviri, returnăm textul original
        if (potriviri.length === 0) {
            return text;
        }

        // Construim textul evidențiat pornind de la final pentru a nu afecta offset-urile
        let rezultat = textOriginal;
        for (let i = potriviri.length - 1; i >= 0; i--) {
            const p = potriviri[i];
            const fragmentEvidential = `<span class="highlight-text"
    data-index-obiect="${indexStart}"
    data-imagini-json='${encodeURIComponent(imaginiJSON)}'
>${p.text}</span>`;

            rezultat = rezultat.substring(0, p.start) + fragmentEvidential + rezultat.substring(p.end);
        }

        return rezultat;
    }

    // Funcție îmbunătățită pentru evidențierea (highlight) termenilor căutați
    function evidentiazaTermeni(container, termen) {
        if (!termen || termen.trim() === '') return;

        // 1. Evidențiem titlurile (locație și cutie) - MODIFICAT pentru a evita codul CSS
        const titluriLocatie = container.querySelectorAll('.linie-principala');
        const titluriCutie = container.querySelectorAll('.linie-secundara');

        titluriLocatie.forEach(titlu => {
            // Text original fără evidențiere
            if (!titlu.getAttribute('data-text-original')) {
                titlu.setAttribute('data-text-original', titlu.textContent);
            }

            const textOriginal = titlu.getAttribute('data-text-original');
            // Creare element nou pentru text evidențiat
            titlu.innerHTML = evidentiazaText(textOriginal, termen);
        });

        titluriCutie.forEach(titlu => {
            const textCutie = titlu.querySelector('strong');
            if (textCutie) {
                const textDupaCutie = textCutie.nextSibling;
                if (textDupaCutie && textDupaCutie.nodeType === Node.TEXT_NODE) {
                    // Salvăm textul original
                    if (!titlu.getAttribute('data-cutie-original')) {
                        titlu.setAttribute('data-cutie-original', textDupaCutie.textContent);
                    }

                    const textOriginal = titlu.getAttribute('data-cutie-original');

                    // Creăm un container pentru textul evidențiat
                    const wrapper = document.createElement('span');
                    wrapper.innerHTML = evidentiazaText(textOriginal, termen);

                    // Înlocuim textul vechi cu cel nou evidențiat
                    const parentNode = textDupaCutie.parentNode;
                    parentNode.insertBefore(wrapper, textDupaCutie);
                    parentNode.removeChild(textDupaCutie);
                }
            }
        });

        // 2. Evidențiem categoriile - MODIFICAT pentru a folosi abordarea prin atribute data
        const badgeCategorii = container.querySelectorAll('.badge-categorie');
        badgeCategorii.forEach(badge => {
            // Resetăm clasa de highlight
            badge.classList.remove('highlight-badge');

            // Obținem textul original din atributul specific
            const textOriginal = badge.getAttribute('data-text')?.toLowerCase() || '';

            // Verificăm dacă termenul de căutare există în text
            if (textOriginal.includes(termen.toLowerCase())) {
                badge.classList.add('highlight-badge');
            }
        });

        // 3. Evidențiem obiectele - MODIFICAT pentru a evidenția corect și a adăuga informații despre imagini
        const obiecteElemente = container.querySelectorAll('.obiecte-text');
        obiecteElemente.forEach(elem => {
            // Salvăm conținutul original dacă nu există deja
            if (!elem.getAttribute('data-text-original')) {
                elem.setAttribute('data-text-original', elem.textContent);
            }

            // Restaurăm conținutul original pentru a elimina orice evidențiere anterioară
            let textOriginal = elem.getAttribute('data-text-original');

            // Obținem informațiile despre obiecte din JSON
            let obiecteInfo = [];
            try {
                const infoJSON = elem.getAttribute('data-obiecte-info');
                if (infoJSON) {
                    obiecteInfo = JSON.parse(infoJSON);
                }
            } catch (e) {
                console.error('Eroare la parsarea datelor despre obiecte:', e);
            }

            // Procesăm fiecare obiect separat pentru a nu evidenția în interiorul parantezelor
            const obiecte = textOriginal.split(', ');
            const obiecteEvidential = obiecte.map((obiect, index) => {
                // Separăm numele obiectului de cantitate (ex: "Vopsea(1)")
                const match = obiect.match(/^(.*?)(\(\d+\))$/);
                if (match) {
                    const numeObiect = match[1];
                    const cantitate = match[2];

                    // Obținem informațiile despre imagine pentru acest obiect - MODIFICARE AICI
                    let imagineJSON = '';
                    if (obiecteInfo) { // Schimbare 1: verificăm întregul array
                        imagineJSON = JSON.stringify(obiecteInfo); // Schimbare 2: trimitem tot array-ul
                    }

                    // Evidențiem doar numele obiectului, nu și cantitatea - MODIFICARE AICI
                    const numeEvidential = evidentiazaText(
                        numeObiect,
                        termen,
                        imagineJSON,
                        index // Schimbare 3: trimitem indexul curent
                    );
                    return numeEvidential + cantitate;
                }

                // Dacă nu are format cu cantitate - PĂSTRAT NESCHIMBAT
                return evidentiazaText(obiect, termen);
            });

            // Reconstruim lista de obiecte cu evidențieri
            elem.innerHTML = obiecteEvidential.join(', ');
            // Adăugăm atribut special pentru primul obiect evidențiat
            const firstHighlight = elem.querySelector('.highlight-text');
            if (firstHighlight) {
                firstHighlight.setAttribute('data-first-obiect', 'true');
                firstHighlight.setAttribute('data-imagini-json', elem.getAttribute('data-obiecte-info'));
            }
        });

        // 4. Evidențiem descrierea - MODIFICAT pentru a evidenția corect
        const descriereElemente = container.querySelectorAll('.descriere-text');
        descriereElemente.forEach(elem => {
            // Salvăm conținutul original dacă nu există deja
            if (!elem.getAttribute('data-text-original')) {
                elem.setAttribute('data-text-original', elem.textContent.trim());
            }

            // Restaurăm conținutul original
            let textOriginal = elem.getAttribute('data-text-original');

            // Evidențiem textul
            elem.innerHTML = evidentiazaText(textOriginal, termen);
        });
    }

    // Funcție pentru curățarea tuturor evidențierilor
    function curataToateEvidentierile() {
        // 1. Curățăm evidențierile din titluri (locație și cutie)
        document.querySelectorAll('.linie-principala, .linie-secundara').forEach(titlu => {
            if (titlu.getAttribute('data-text-original')) {
                if (titlu.classList.contains('linie-principala')) {
                    titlu.textContent = titlu.getAttribute('data-text-original');
                } else {
                    // Pentru linie-secundara, trebuie să tratăm separat "Cutie: X"
                    const textCutie = titlu.querySelector('strong');
                    if (textCutie) {
                        const textOriginal = titlu.getAttribute('data-cutie-original');
                        if (textOriginal) {
                            // Înlocuim orice conținut evidențiat cu textul original
                            const texteEvidențiate = titlu.querySelectorAll('span:not(strong)');
                            texteEvidențiate.forEach(span => {
                                if (span !== textCutie) {
                                    span.parentNode.removeChild(span);
                                }
                            });
                            // Adăugăm textul original după elementul strong
                            const textNode = document.createTextNode(textOriginal);
                            if (textCutie.nextSibling) {
                                textCutie.parentNode.replaceChild(textNode, textCutie.nextSibling);
                            } else {
                                textCutie.parentNode.appendChild(textNode);
                            }
                        }
                    }
                }
            }
        });

        // 2. Resetăm evidențierile din badge-uri
        document.querySelectorAll('.badge-categorie').forEach(badge => {
            badge.classList.remove('highlight-badge');
        });

        // 3. Resetăm evidențierile din obiecte
        document.querySelectorAll('.obiecte-text').forEach(elem => {
            if (elem.getAttribute('data-text-original')) {
                elem.textContent = elem.getAttribute('data-text-original');
            }
        });

        // 4. Resetăm evidențierile din descrieri + curățăm atribute speciale
        document.querySelectorAll('.descriere-text').forEach(elem => {
            if (elem.getAttribute('data-text-original')) {
                elem.textContent = elem.getAttribute('data-text-original');
            }
        });
    }

    // Funcție pentru a escapa caracterele speciale într-un șir pentru utilizarea în regex
    function escapeRegex(string) {
        return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    // Funcție pentru detectarea virgulelor în timpul editării - ÎMBUNĂTĂȚITĂ
    function detecteazaVirgula(element, idObiect) {
        const continut = element.innerText;

        // La prima editare, salvăm conținutul original complet
        if (!element.getAttribute('data-continut-original')) {
            element.setAttribute('data-continut-original', continut);
            continutOriginalComplet = continut;
        }

        // Analizăm lista de obiecte pentru a detecta corect virgulele
        const obiecte = continut.split(', ');

        // Verificăm fiecare obiect din listă dacă conține virgule interne
        let contineVirguleNepermise = false;
        let obiectProblematic = '';

        // Verificăm doar obiectele care sunt în curs de editare (au fost modificate)
        // Nu verificăm cantitățile între paranteze - ele pot conține virgule legitim
        for (let i = 0; i < obiecte.length; i++) {
            const obiectCurent = obiecte[i];

            // Extragem numele obiectului (fără cantitatea din paranteze)
            const numeMatch = obiectCurent.match(/^(.*?)(\(\d+\))?$/);
            if (numeMatch && numeMatch[1]) {
                const numeObiect = numeMatch[1].trim();

                // Verificăm dacă numele obiectului (nu cantitatea) conține virgule
                if (numeObiect.includes(',')) {
                    contineVirguleNepermise = true;
                    obiectProblematic = obiectCurent;
                    break;
                }
            }
        }

        // Memorăm elementul și id-ul pentru referință ulterioară
        elemCurent = element;
        idObiectCurent = idObiect;

        // Dacă avem virgule nepermise, setăm flag-ul
        if (contineVirguleNepermise) {
            virgulaDetectata = true;
            element.setAttribute('data-obiect-problematic', obiectProblematic);
        }
    }

    // Funcție pentru verificarea și gestionarea virgulelor la pierderea focusului - ÎMBUNĂTĂȚITĂ
    function verificaVirguleInObiect(idObiect, element) {
        const continut = element.innerText.trim();
        const obiectProblematic = element.getAttribute('data-obiect-problematic');

        // Resetăm starea de detectare a virgulei pentru editări viitoare
        virgulaDetectata = false;

        // Verificăm dacă există virgule în obiecte (nu în separatori)
        if (obiectProblematic) {
            // Afișăm modalul de confirmare doar pentru obiectul problematic
            afiseazaModalConfirmareVirgule(obiectProblematic, idObiect, element);
            // Resetăm atributul pentru viitoare verificări
            element.removeAttribute('data-obiect-problematic');
        } else {
            // Nu avem virgule în interiorul numelor obiectelor, continuăm cu salvarea normală
            actualizeazaObiect(idObiect, 'denumire_obiect', element, true);
        }
    }

    /// Funcție pentru afișarea modalului de confirmare - ACTUALIZATĂ
    function afiseazaModalConfirmareVirgule(obiectProblematic, idObiect, element) {
        const modal = document.getElementById('modalConfirmareVirgule');
        const continutElement = document.getElementById('continutCuVirgule');

        if (!modal || !continutElement) {
            console.error('Modal sau conținut lipsă pentru confirmarea virgulelor');
            return;
        }

        // Afișăm doar obiectul problematic în modal
        continutElement.textContent = obiectProblematic;

        // Memorăm elementul și id-ul pentru referință în callback-uri
        elemCurent = element;
        idObiectCurent = idObiect;

        // Afișăm modalul
        modal.style.display = 'block';
    }

    // Callback pentru butonul de confirmare (păstrăm virgulele) - ACTUALIZAT
    function confirmaMultipleObiecte() {
        // Ascundem modalul
        const modal = document.getElementById('modalConfirmareVirgule');
        if (modal) {
            modal.style.display = 'none';
        }

        // Continuăm cu salvarea normală fără modificări
        if (elemCurent && idObiectCurent) {
            actualizeazaObiect(idObiectCurent, 'denumire_obiect', elemCurent, true);
        }

        // Resetăm variabilele
        resetVariabileSalvare();
    }

    // Callback pentru butonul de infirmare (înlocuim virgulele cu -) - ACTUALIZAT
    function infirmaMultipleObiecte() {
        // Ascundem modalul
        const modal = document.getElementById('modalConfirmareVirgule');
        if (modal) {
            modal.style.display = 'none';
        }

        if (elemCurent) {
            // Obținem conținutul curent pentru procesare
            const continutActual = elemCurent.innerText;

            // Împărțim textul în obiecte individuale
            const obiecte = continutActual.split(', ');

            // Procesăm fiecare obiect, înlocuind virgulele cu liniuțe doar în numele obiectelor
            const obiecteCorectate = obiecte.map(obiect => {
                const match = obiect.match(/^(.*?)(\(\d+\))?$/);
                if (match) {
                    const nume = match[1].trim();
                    const cantitate = match[2] || '';

                    // Înlocuim virgulele din nume cu liniuțe
                    const numeCuratat = nume.replace(/,/g, '-');
                    return numeCuratat + cantitate;
                }
                return obiect;
            });

            // Reconstruim textul complet
            elemCurent.innerText = obiecteCorectate.join(', ');

            // Salvăm conținutul modificat
            if (idObiectCurent) {
                actualizeazaObiect(idObiectCurent, 'denumire_obiect', elemCurent, true);
            }
        }

        // Resetăm variabilele
        resetVariabileSalvare();
    }

    // Funcție pentru resetarea variabilelor după salvare
    function resetVariabileSalvare() {
        elemCurent = null;
        idObiectCurent = null;
        virgulaDetectata = false;
        continutInainteDePrimaVirgula = '';
        continutOriginalComplet = '';
    }

    // Funcție pentru eliminarea diacriticelor din text
    function eliminaDiacritice(text) {
        if (!text) return text;

        const mapDiacritice = {
            'ă': 'a', 'â': 'a', 'î': 'i', 'ș': 's', 'ş': 's', 'ț': 't', 'ţ': 't',
            'Ă': 'A', 'Â': 'A', 'Î': 'I', 'Ș': 'S', 'Ş': 'S', 'Ț': 'T', 'Ţ': 'T',
            'é': 'e', 'è': 'e', 'ê': 'e', 'ë': 'e', 'É': 'E', 'È': 'E', 'Ê': 'E', 'Ë': 'E',
            'á': 'a', 'à': 'a', 'ä': 'a', 'Á': 'A', 'À': 'A', 'Ä': 'A',
            'í': 'i', 'ì': 'i', 'ï': 'i', 'Í': 'I', 'Ì': 'I', 'Ï': 'I',
            'ó': 'o', 'ò': 'o', 'ô': 'o', 'ö': 'o', 'Ó': 'O', 'Ò': 'O', 'Ô': 'O', 'Ö': 'O',
            'ú': 'u', 'ù': 'u', 'û': 'u', 'ü': 'u', 'Ú': 'U', 'Ù': 'U', 'Û': 'U', 'Ü': 'U',
            'ç': 'c', 'Ç': 'C', 'ñ': 'n', 'Ñ': 'N'
        };

        return text.replace(/[ăâîșşțţĂÂÎȘŞȚŢéèêëÉÈÊËáàâäÁÀÂÄíìîïÍÌÎÏóòôöÓÒÔÖúùûüÚÙÛÜçÇñÑ]/g,
            function(match) {
                return mapDiacritice[match] || match;
            });
    }
    
    // Funcție simplă pentru căutare globală
    function cautareGlobala() {
        const termen = document.getElementById('campCautare').value.trim();
        if (termen.length < 3) {
            alert('Introduceți minim 3 caractere pentru căutare globală');
            return;
        }
        
        // Ascundem butonul de căutare globală
        const btnGlobal = document.getElementById('btnCautareGlobala');
        if (btnGlobal) {
            btnGlobal.style.display = 'none';
        }
        
        // Ascundem rezultatele locale
        const containerPrincipal = document.querySelector('.container-principal');
        if (containerPrincipal) {
            containerPrincipal.style.display = 'none';
        }
        
        const container = document.getElementById('rezultateGlobale');
        if (!container) {
            console.error('Container rezultate globale nu există');
            return;
        }
        container.style.display = 'block';
        container.innerHTML = '<div class="container"><div class="grup-obiecte" style="text-align: center; padding: 20px;">🔍 Se caută în toate colecțiile...</div></div>';
        
        fetch(`cautare_simpla.php?q=${encodeURIComponent(termen)}`)
            .then(response => response.json())
            .then(data => {
                if (data.success && data.total > 0) {
                    let html = '<div class="container">';
                    html += `<div style="background: #fff3e0; border-left: 4px solid #ff9800; 
                                        padding: 15px; margin-bottom: 20px; border-radius: 4px;">
                               <strong>🔍 Căutare globală activă</strong><br>
                               <span style="color: #666;">Găsite ${data.total} rezultate în ${data.rezultate.length} colecții</span>
                             </div>`;
                    
                    // Afișăm rezultatele exact ca în căutarea standard
                    data.rezultate.forEach(colectie => {
                        // Creează badge-ul de ranking dacă există
                        let rankingBadge = '';
                        if (colectie.tip_acces !== 'proprietar' && colectie.nivel_ranking) {
                            const badges = {
                                'diamond': '💎',
                                'platinum': '🏆',
                                'gold': '🥇',
                                'silver': '🥈',
                                'bronze': '🥉'
                            };
                            const colors = {
                                'diamond': 'linear-gradient(135deg, #b3e5fc, #81d4fa)',
                                'platinum': 'linear-gradient(135deg, #e1bee7, #ba68c8)',
                                'gold': 'linear-gradient(135deg, #fff9c4, #ffd54f)',
                                'silver': 'linear-gradient(135deg, #f5f5f5, #bdbdbd)',
                                'bronze': 'linear-gradient(135deg, #ffccbc, #ff8a65)'
                            };
                            const badge = badges[colectie.nivel_ranking] || '🥉';
                            const color = colors[colectie.nivel_ranking] || colors['bronze'];
                            const scor = Math.round(colectie.ranking_scor || 0);
                            const disponibilitate = Math.round(colectie.scor_disponibilitate || 0);
                            
                            rankingBadge = `<span style="display: inline-flex; align-items: center; gap: 3px; 
                                padding: 2px 6px; border-radius: 12px; font-size: 11px; 
                                background: ${color}; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-left: 8px;">
                                <span style="font-size: 12px;">${badge}</span>
                                <span style="color: #444; font-weight: 500;">${scor}p</span>
                                <span style="color: #666; font-size: 9px;">(📤${disponibilitate}%)</span>
                            </span>`;
                        } else if (colectie.tip_acces !== 'proprietar') {
                            rankingBadge = `<span style="display: inline-flex; align-items: center; gap: 3px; 
                                padding: 2px 6px; border-radius: 12px; font-size: 11px; 
                                background: linear-gradient(135deg, #f5f5f5, #e0e0e0); margin-left: 8px;">
                                <span style="font-size: 12px;">🆕</span>
                                <span style="color: #666; font-size: 10px;">Nou</span>
                            </span>`;
                        }
                        
                        // Pass ranking data to modal
                        const rankingData = colectie.ranking_scor ? 
                            `data-ranking-scor="${colectie.ranking_scor}" data-nivel-ranking="${colectie.nivel_ranking}" data-scor-disponibilitate="${colectie.scor_disponibilitate}"` : '';
                        
                        colectie.obiecte.forEach(ob => {
                            html += `<div class="grup-obiecte">
                                       <div class="lista-obiecte">
                                         <div class="header-grup">
                                           <div class="linie-principala">
                                             <span class="indicator" style="pointer-events: none;">
                                               <span class="bar-top"></span>
                                               <span class="grid"></span>
                                             </span>
                                             <strong>Cutie</strong>: <span class="highlight-title">${ob.cutie}</span> | 
                                             <strong>Locație</strong>: <span class="highlight-title">${ob.locatie}</span>
                                             <span style="float: right; font-size: 12px; color: #666; display: flex; align-items: center;">
                                               din ${colectie.nume_colectie}${rankingBadge}
                                               <button onclick="deschideModalObiect(${colectie.id_colectie}, ${ob.id_obiect}, '${ob.cutie.replace(/'/g, "\\'").replace(/"/g, "&quot;")}', '${ob.locatie.replace(/'/g, "\\'").replace(/"/g, "&quot;")}', '${colectie.nume_colectie.replace(/'/g, "\\'").replace(/"/g, "&quot;")}', '${ob.imagine ? ob.imagine.replace(/'/g, "\\'").replace(/"/g, "&quot;") : ""}', '${colectie.tip_acces}')" ${rankingData} 
                                                       style="margin-left: 10px; background-color: #ff6600; 
                                                              color: white; border: none; padding: 4px 10px; 
                                                              border-radius: 5px; cursor: pointer; font-size: 11px;
                                                              font-weight: 600; transition: all 0.3s ease;
                                                              width: auto; white-space: nowrap;
                                                              box-shadow: 0 2px 5px rgba(255, 102, 0, 0.3);"
                                                       onmouseover="this.style.backgroundColor='#e55500'; this.style.transform='translateY(-1px)'; this.style.boxShadow='0 4px 8px rgba(255, 102, 0, 0.4)';"
                                                       onmouseout="this.style.backgroundColor='#ff6600'; this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 5px rgba(255, 102, 0, 0.3)';">
                                                 Vezi
                                               </button>
                                             </span>
                                           </div>
                                         </div>
                                         <div class="obiecte-container">
                                           <span class="obiecte-text">
                                             ${ob.obiecte.split(',').map(o => 
                                               o.toLowerCase().includes(termen.toLowerCase()) 
                                                 ? `<span class="highlight-text">${o.trim()}</span>`
                                                 : o.trim()
                                             ).join(', ')}
                                           </span>
                                         </div>
                                       </div>
                                     </div>`;
                        });
                    });
                    
                    html += '</div>';
                    container.innerHTML = html;
                } else {
                    container.innerHTML = `<div class="container"><div class="grup-obiecte" style="text-align: center; padding: 40px;">
                                             <div style="font-size: 48px; margin-bottom: 10px;">🔍</div>
                                             <p style="color: #666;">Nu s-au găsit rezultate pentru "${termen}" în nicio colecție</p>
                                           </div></div>`;
                }
            })
            .catch(error => {
                console.error('Eroare căutare:', error);
                container.innerHTML = `<div class="grup-obiecte" style="text-align: center; padding: 20px; color: red;">
                                         ❌ Eroare la căutare. Vă rugăm încercați din nou.
                                       </div>`;
            });
    }
    
    // Funcție pentru închiderea căutării globale
    function inchideCautareGlobala() {
        // Arătăm înapoi containerul principal
        const containerPrincipal = document.querySelector('.container-principal');
        if (containerPrincipal) {
            containerPrincipal.style.display = 'block';
        }
        
        // Ascundem rezultatele globale
        const rezultateGlobale = document.getElementById('rezultateGlobale');
        if (rezultateGlobale) {
            rezultateGlobale.style.display = 'none';
            rezultateGlobale.innerHTML = '';
        }
        
        // Resetăm câmpul de căutare
        const campCautare = document.getElementById('campCautare');
        if (campCautare) {
            campCautare.value = '';
        }
        
        const btnGlobal = document.getElementById('btnCautareGlobala');
        if (btnGlobal) {
            btnGlobal.style.display = 'none';
        }
        
        // Reapelăm funcția de filtrare pentru a reseta afișarea locală
        filtreazaObiecte('');
    }
    
    // Resetare căutare globală la click în afara zonei
    document.addEventListener('click', function(e) {
        const rezultateGlobale = document.getElementById('rezultateGlobale');
        
        // Verifică dacă căutarea globală este activă
        if (rezultateGlobale && rezultateGlobale.style.display === 'block') {
            const searchBar = document.querySelector('.search-bar');
            const isInsideSearch = searchBar && searchBar.contains(e.target);
            const isInsideResults = rezultateGlobale.contains(e.target);
            const isInsideModal = e.target.closest('.modal-partajare');
            
            // Închide căutarea dacă click-ul este în afara zonelor relevante
            if (!isInsideSearch && !isInsideResults && !isInsideModal) {
                inchideCautareGlobala();
            }
        }
    });
    
    // Afișează butonul de căutare globală când există text
    document.addEventListener('DOMContentLoaded', function() {
        const campCautare = document.getElementById('campCautare');
        const btnGlobal = document.getElementById('btnCautareGlobala');
        
        console.log('Inițializare căutare globală - Câmp:', campCautare ? 'găsit' : 'lipsă', ', Buton:', btnGlobal ? 'găsit' : 'lipsă');
        
        if (campCautare && btnGlobal) {
            // Setăm inițial display-ul butonului
            btnGlobal.style.display = 'none';
            console.log('Buton inițializat ca ascuns');
            
            campCautare.addEventListener('input', function() {
                console.log('Text introdus:', this.value, 'Lungime:', this.value.length);
                
                // Afișăm butonul doar dacă avem text și nu suntem în modul căutare globală
                const rezultateGlobale = document.getElementById('rezultateGlobale');
                if (rezultateGlobale && rezultateGlobale.style.display === 'block') {
                    // Dacă suntem în căutare globală și ștergem textul, închidem căutarea globală
                    if (this.value.length < 3) {
                        inchideCautareGlobala();
                    }
                } else {
                    const shouldShow = this.value.length >= 3;
                    btnGlobal.style.display = shouldShow ? 'inline-block' : 'none';
                    console.log('Buton setat la:', btnGlobal.style.display);
                }
            });
            
            // La click pe câmpul de căutare, resetăm căutarea globală
            campCautare.addEventListener('click', function() {
                // Închidem căutarea globală dacă e activă
                const rezultateGlobale = document.getElementById('rezultateGlobale');
                if (rezultateGlobale && rezultateGlobale.style.display === 'block') {
                    inchideCautareGlobala();
                }
                
                // Ascundem butonul dacă câmpul e gol
                if (this.value.trim() === '') {
                    btnGlobal.style.display = 'none';
                }
            });
        }
    });
    
    // Funcție îmbunătățită pentru filtrarea obiectelor
    function filtreazaObiecte(termen) {
        termen = termen.toLowerCase().trim();
        const grupuriObiecte = document.querySelectorAll('.grup-obiecte');
        let contor = 0;

        // Memorăm termenul de căutare curent pentru a-l folosi în alte funcții
        termenCautareCurent = termen;

        // Dezactivăm sau activăm editarea în funcție de prezența termenului de căutare
        const toateElementeleEditabile = document.querySelectorAll('[contenteditable=true]');
        if (termen !== '') {
            // Dezactivăm editarea dacă avem un termen de căutare
            toateElementeleEditabile.forEach(element => {
                // Salvăm starea originală pentru a o putea restaura
                element.setAttribute('data-original-editable', 'true');
                element.setAttribute('contenteditable', 'false');
            });
        } else {
            // Reactivăm editarea dacă nu avem termen de căutare
            document.querySelectorAll('[data-original-editable=true]').forEach(element => {
                element.setAttribute('contenteditable', 'true');
            });
        }

        // Dacă termenul de căutare este gol sau prea scurt (doar 1-2 caractere)
        // curățăm toate evidențierile și afișăm toate grupurile
        if (termen === '' || termen.length < 3) {
            // Resetăm complet toate evidențierile
            curataToateEvidentierile();

            // Afișăm toate grupurile dacă termenul e gol
            if (termen === '') {
                grupuriObiecte.forEach(grup => {
                    grup.classList.remove('hidden');
                });

                const statusElement = document.getElementById('statusCautare');
                statusElement.textContent = '';
                return;
            }
        }

        // Eliminăm diacriticele din termenul de căutare
        const termenFaraDiacritice = eliminaDiacritice(termen);

        grupuriObiecte.forEach(grup => {
            // Folosim doar atributele specificate pentru căutare
            const locatie = grup.getAttribute('data-locatie')?.toLowerCase() || '';
            const cutie = grup.getAttribute('data-cutie')?.toLowerCase() || '';
            const categorie = grup.getAttribute('data-categorie')?.toLowerCase() || '';
            const obiecte = grup.getAttribute('data-obiecte')?.toLowerCase() || '';

            // Construim stringul căutabil și eliminăm diacriticele
            const continutCautabil = `${locatie} ${cutie} ${categorie} ${obiecte}`;
            const continutCautabilFaraDiacritice = eliminaDiacritice(continutCautabil);

            // Căutăm utilizând versiunile fără diacritice
            const gasit = termen === '' || continutCautabilFaraDiacritice.includes(termenFaraDiacritice);

            if (gasit) {
                grup.classList.remove('hidden');
                contor++;

                // Aplicăm evidențierea termenilor pe elementele găsite doar dacă termenul are minim 3 caractere
                if (termen !== '' && termen.length >= 3) {
                    evidentiazaTermeni(grup, termen);
                }
            } else {
                grup.classList.add('hidden');
            }
        });

        const statusElement = document.getElementById('statusCautare');
        statusElement.textContent = termen === '' ? '' : `${contor} rezultate găsite pentru: "${termen}"`;
    }

    // Funcție pentru afișarea tooltip-ului cu imagine
    function afiseazaImagineTooltip(event, element) {
        const tooltip = document.getElementById('imagine-tooltip');
        const imagineTooltip = document.getElementById('imagine-tooltip-img');

        // Resetăm tooltip-ul la fiecare apel
        tooltip.style.display = 'none';
        imagineTooltip.src = '';

        try {
            // Obținem datele corecte
            const indexObiect = parseInt(element.getAttribute('data-index-obiect') || 0, 10);
            const imaginiJSON = element.getAttribute('data-imagini-json') || '[]';
            const toateObiectele = JSON.parse(imaginiJSON);

            // Verificări de validitate
            if (!Array.isArray(toateObiectele) || indexObiect >= toateObiectele.length) return;

            const obiectCurent = toateObiectele[indexObiect];
            if (!obiectCurent?.imagine) return;

            // Setare imagine corectă cu prefix user
            imagineTooltip.src = `imagini_decupate/user_<?php echo $user_id; ?>/${encodeURIComponent(obiectCurent.imagine.trim())}?t=${Date.now()}`;

            // Poziționare
            tooltip.style.display = 'block';
            tooltip.style.left = `${event.pageX + 15}px`;
            tooltip.style.top = `${event.pageY + 15}px`;

            // Gestionare erori
            imagineTooltip.onerror = () => tooltip.style.display = 'none';

        } catch (e) {
            console.error('Eroare tooltip:', e);
        }
    }

    // Funcție pentru ascunderea tooltip-ului
    function ascundeImagineTooltip() {
        const tooltip = document.getElementById('imagine-tooltip');
        tooltip.style.display = 'none';
    }

    function actualizeazaObiect(id, camp, element, eObiect = false) {
        const valoare = element.innerText.trim();
        const formData = new URLSearchParams();
        formData.append('id', id);
        formData.append('camp', camp);
        formData.append('valoare', valoare);

        // Adăugăm ID-ul colecției curente - luăm din tab-ul activ
        const tabActiv = document.querySelector('.tab.active');
        if (tabActiv) {
            const idColectieCurenta = tabActiv.getAttribute('data-colectie');
            if (idColectieCurenta) {
                formData.append('id_colectie', idColectieCurenta);
                console.log('Actualizez obiect pentru colecția:', idColectieCurenta);
            }
        }

        if (eObiect) {
            try {
                // Obținem informațiile originale despre obiecte
                const obiecteOriginale = JSON.parse(element.getAttribute('data-obiecte-info') || '[]');
                console.log("Obiecte Originale Complete:", obiecteOriginale);

                // Extragem denumirile și cantitățile din textul editat
                const obiecteEditate = valoare.split(',').map(item => item.trim()).filter(item => item);
                const denumiriNoi = [];
                const cantitatiNoi = [];
                const eticheteNoi = [];
                const imaginiNoi = [];

                // LOGICA CORECTATĂ: Păstrăm exact indexul imaginii din obiectul original
                // Cream un tracking array pentru a ține evidența obiectelor deja potrivite
                const obiecteUtilizate = Array(obiecteOriginale.length).fill(false);

                for (let i = 0; i < obiecteEditate.length; i++) {
                    // Extragem denumirea și cantitatea din textul editat
                    let numeBaza, cantitate;
                    const match = obiecteEditate[i].match(/^(.*?)\((\d+)\)$/);
                    if (match) {
                        numeBaza = match[1].trim();
                        cantitate = match[2];
                    } else {
                        numeBaza = obiecteEditate[i].trim();
                        cantitate = '1';
                    }

                    // Căutăm obiectul EXACT în lista originală după denumire și index
                    let obiectGasitExact = null;
                    let indexObiectGasit = -1;

                    // Prima încercare: căutare exactă cu același index imagine (dacă avem un index în obiectul editat)
                    if (match && match[2]) {
                        const indexCautat = match[2];
                        for (let j = 0; j < obiecteOriginale.length; j++) {
                            if (!obiecteUtilizate[j] &&
                                obiecteOriginale[j].denumireCurata.toLowerCase() === numeBaza.toLowerCase() &&
                                obiecteOriginale[j].indexImagine === indexCautat) {
                                indexObiectGasit = j;
                                obiectGasitExact = obiecteOriginale[j];
                                obiecteUtilizate[j] = true;
                                console.log(`Potrivire exactă cu index pentru: ${numeBaza}(${indexCautat})`, obiectGasitExact);
                                break;
                            }
                        }
                    }

                    // A doua încercare: căutare doar după nume dacă nu am găsit o potrivire exactă cu index
                    if (indexObiectGasit === -1) {
                        for (let j = 0; j < obiecteOriginale.length; j++) {
                            if (!obiecteUtilizate[j] &&
                                obiecteOriginale[j].denumireCurata.toLowerCase() === numeBaza.toLowerCase()) {
                                indexObiectGasit = j;
                                obiectGasitExact = obiecteOriginale[j];
                                obiecteUtilizate[j] = true;
                                console.log(`Potrivire doar după nume pentru: ${numeBaza}`, obiectGasitExact);
                                break;
                            }
                        }
                    }

                    // Determinăm valorile pentru acest obiect
                    let indexImagine = '0';  // Valoare implicită
                    let eticheta = '#ccc';   // Valoare implicită
                    let imagine = '';        // Valoare implicită pentru imagine
                    let denumireOriginala = numeBaza; // Păstrăm numele așa cum a fost editat inițial

                    // Dacă am găsit obiectul în lista originală, folosim EXACT valorile lui
                    if (obiectGasitExact) {
                        // IMPORTANT: Păstrăm indexul EXACT al imaginii din obiectul original
                        indexImagine = obiectGasitExact.indexImagine;
                        eticheta = obiectGasitExact.eticheta;
                        imagine = obiectGasitExact.imagine;
                        denumireOriginala = obiectGasitExact.denumireCurata; // Folosim denumirea originală (cu majuscule/minuscule)

                        console.log(`Păstrăm valorile EXACTE pentru: ${denumireOriginala}(${indexImagine})`, {
                            eticheta: eticheta,
                            imagine: imagine
                        });
                    }

                    // Păstrăm valorile obținute pentru acest obiect
                    denumiriNoi.push(`${denumireOriginala}(${indexImagine})`);
                    cantitatiNoi.push(cantitate);
                    eticheteNoi.push(eticheta);
                    imaginiNoi.push(imagine);
                }

                // Pregătim datele pentru trimitere
                formData.append('denumiri', denumiriNoi.join(', '));
                formData.append('cantitati', cantitatiNoi.join(', '));
                formData.append('etichete_obiect', eticheteNoi.join('; '));
                formData.append('imagini_obiect', imaginiNoi.join(', '));
                formData.append('pastrare_asocieri', 'true');
                formData.append('pastrare_ordine', 'true');
                formData.append('actualizeaza_imagini', 'true');

                // Adăugăm informații pentru debugging
                console.group('Debugging - Date trimise pentru actualizare:');
                console.log('ID:', id);
                console.log('Camp:', camp);
                console.log('Obiecte Originale:', obiecteOriginale);
                console.log('Denumiri Noi:', denumiriNoi);
                console.log('Cantități Noi:', cantitatiNoi);
                console.log('Etichete Noi:', eticheteNoi);
                console.log('Imagini Noi:', imaginiNoi);
                console.groupEnd();

            } catch (error) {
                console.error('Eroare la procesarea obiectelor:', error);
            }
        }

        // Adăugăm un flag pentru a cere informații de debugging
        formData.append('debug_mode', 'true');

        fetch('actualizeaza_obiect.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData.toString()
        })
            .then(r => r.json())
            .then(jsonData => {
                try {

                    // Afișăm informațiile de debugging în consolă
                    if (jsonData.debug) {
                        console.group('=== DEBUGGING RĂSPUNS ACTUALIZARE_OBIECT ===');
                        console.log('Rezultat:', jsonData.message || jsonData.error);

                        // Afișăm date detaliate din debugging
                        if (jsonData.debug.obiecte_eliminate && jsonData.debug.obiecte_eliminate.length > 0) {
                            console.group('Obiecte care lipsesc (au fost șterse):');
                            jsonData.debug.obiecte_eliminate.forEach((obj, idx) => {
                                console.log(`Obiect #${idx}:`, obj);
                            });
                            console.groupEnd();
                        }

                        if (jsonData.debug.siruri_finale) {
                            console.group('Date finale pentru update:');
                            console.log('Denumiri:', jsonData.debug.siruri_finale.denumiri);
                            console.log('Cantități:', jsonData.debug.siruri_finale.cantitati);
                            console.log('Etichete:', jsonData.debug.siruri_finale.etichete);
                            console.log('Imagini:', jsonData.debug.siruri_finale.imagini);
                            console.groupEnd();
                        }

                        console.groupEnd();
                    }

                    // Afișăm bifa normală
                    afiseazaBifa(element);

                } catch (e) {
                    // Dacă nu este JSON, afișăm răspunsul text și eroarea
                    console.log('Răspuns server:', jsonData);
                    console.error('Eroare parsare JSON:', e);
                    afiseazaBifa(element);
                }

                // Partea originală pentru reaplicare evidențiere
                if (termenCautareCurent && termenCautareCurent !== '') {
                    const grup = element.closest('.grup-obiecte');
                    if (grup) {
                        // Actualizăm și atributul data-obiecte pentru căutare
                        if (eObiect) {
                            grup.setAttribute('data-obiecte', valoare);
                        }

                        // Reaplică evidențierea
                        setTimeout(() => {
                            // Salvăm textul original pentru evidențiere
                            element.setAttribute('data-text-original', element.textContent);
                            evidentiazaTermeni(grup, termenCautareCurent);
                        }, 100);
                    }
                }
            })
            .catch(err => console.error('Eroare:', err));
    }

    function afiseazaBifa(element) {
        // Calculăm poziția elementului pentru a plasa bifa corect
        const rect = element.getBoundingClientRect();
        const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
        const scrollLeft = window.pageXOffset || document.documentElement.scrollLeft;

        // Creăm bifa cu poziționare absolută
        const bifa = document.createElement('div');
        bifa.textContent = '✔';
        bifa.style.position = 'absolute';
        bifa.style.top = (rect.top + scrollTop + rect.height/2 - 12) + 'px'; // Centrat vertical
        bifa.style.left = (rect.right + scrollLeft + 10) + 'px'; // La dreapta elementului
        bifa.style.color = 'green';
        bifa.style.fontWeight = 'bold';
        bifa.style.fontSize = '18px';
        bifa.style.zIndex = '1000';
        bifa.style.pointerEvents = 'none'; // Previne interacțiunea cu bifa
        bifa.className = 'bifa-feedback';

        // Adăugăm animație
        bifa.style.opacity = '0';
        bifa.style.transition = 'opacity 0.2s ease-in-out';

        // Adăugăm bifa la body, complet separată de elementul editat
        document.body.appendChild(bifa);

        // Animație de apariție
        setTimeout(() => bifa.style.opacity = '1', 10);

        // Animație de dispariție și eliminare
        setTimeout(() => {
            bifa.style.opacity = '0';
            setTimeout(() => {
                if (bifa.parentNode) {
                    bifa.parentNode.removeChild(bifa);
                }
            }, 300);
        }, 1700);
    }

    // Variabile globale pentru modal-uri
    let stergeCallback = null;

    // Funcții pentru modal de ștergere
    function arataStergeModal(callback) {
        stergeCallback = callback;
        document.getElementById('stergeModal').style.display = 'flex';
    }

    function confirmaSterge() {
        document.getElementById('stergeModal').style.display = 'none';
        if (stergeCallback) {
            stergeCallback(true);
            stergeCallback = null;
        }
    }

    function anuleazaSterge() {
        document.getElementById('stergeModal').style.display = 'none';
        if (stergeCallback) {
            stergeCallback(false);
            stergeCallback = null;
        }
    }

    // Funcții pentru modal de alertă
    function arataAlert(mesaj, titlu = 'Eroare') {
        document.getElementById('alertTitle').textContent = titlu;
        document.getElementById('alertMessage').innerHTML = mesaj;
        document.getElementById('alertModal').style.display = 'flex';
    }

    function inchideAlert() {
        document.getElementById('alertModal').style.display = 'none';
    }

    // Click pe fundal pentru a închide modal-urile
    document.addEventListener('DOMContentLoaded', function() {
        // Pentru modal ștergere
        document.getElementById('stergeModal').addEventListener('click', function(e) {
            if (e.target === this) {
                anuleazaSterge();
            }
        });

        // Pentru modal alertă
        document.getElementById('alertModal').addEventListener('click', function(e) {
            if (e.target === this) {
                inchideAlert();
            }
        });
    });

    // Funcțiile stergeCutie și stergeImagine au fost mutate în scope-ul global

    function stergeImagineOLD(event, idObiect, numeImagine, indexImagine) {
        event.stopPropagation(); // Previne selectarea imaginii

        // Salvăm referința la buton înainte de modal
        const btn = event.target;

        // Afișăm modal-ul de confirmare
        arataStergeModal(function(confirmed) {
            if (!confirmed) return;

            // Afișăm un indicator de încărcare
            btn.innerHTML = '⟳';
            btn.disabled = true;

            // Trimitem cererea de ștergere
            const formData = new FormData();
            formData.append('action', 'sterge_imagine');
            formData.append('id_obiect', idObiect);
            formData.append('nume_imagine', numeImagine);
            formData.append('index_imagine', indexImagine);

            // Adăugăm ID-ul colecției curente - luăm din tab-ul activ
            const tabActiv = document.querySelector('.tab.active');
            if (tabActiv) {
                const idColectieCurenta = tabActiv.getAttribute('data-colectie');
                if (idColectieCurenta) {
                    formData.append('id_colectie', idColectieCurenta);
                    console.log('Trimit date pentru colecția:', idColectieCurenta);
                }
            }

            fetch('sterge_imagine.php', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Reîncărcăm pagina pentru a reflecta modificările
                        location.reload();
                    } else {
                        arataAlert('Eroare la ștergerea imaginii: ' + (data.error || 'Eroare necunoscută'));
                        btn.innerHTML = '×';
                        btn.disabled = false;
                    }
                })
                .catch(error => {
                    console.error('Eroare:', error);
                    arataAlert('Eroare la comunicarea cu serverul');
                    btn.innerHTML = '×';
                    btn.disabled = false;
                });
        });
    }
</script>
<script>
    // evidentiere la revenire
    document.addEventListener('DOMContentLoaded', function() {
        // Verificăm dacă există informații despre ultima imagine vizualizată
        const ultimulIdObiect = localStorage.getItem('ultimulIdObiectVizualizat');
        const ultimulNumeImagine = localStorage.getItem('ultimulNumeImagineVizualizat');
        const ultimulGrup = localStorage.getItem('ultimulGrupObiectVizualizat');

        if (ultimulIdObiect && ultimulNumeImagine) {
            // Găsim imaginea corespunzătoare
            const imagine = document.querySelector(`img[data-id="${ultimulIdObiect}"][data-nume="${ultimulNumeImagine}"]`);

            if (imagine) {
                // Găsim grupul care conține imaginea
                const grup = imagine.closest('.grup-obiecte');
                const container = imagine.closest('.imagini-container');

                if (container) {
                    // Actualizăm selecția - eliminăm selected de pe toate imaginile din container
                    container.querySelectorAll('.thumb').forEach(img => {
                        img.classList.remove('selected');
                    });

                    // Aplicăm clasa selected la imaginea vizualizată anterior
                    imagine.classList.add('selected');
                }

                if (grup) {
                    // Aplicăm stiluri temporare pentru a evidenția imaginea
                    imagine.style.transition = 'box-shadow 0.3s, transform 0.3s';
                    imagine.style.boxShadow = '0 0 10px 5px #3498db';
                    imagine.style.transform = 'scale(1.05)';

                    // Derulăm la grup
                    setTimeout(() => {
                        setTimeout(() => {
                            // Obținem poziția elementului în pagină
                            const rect = grup.getBoundingClientRect();
                            // Calculăm poziția dorită, luând în considerare un offset de 120px pentru bara de căutare și header
                            const offsetTop = window.pageYOffset + rect.top - 120;
                            // Facem derularea cu offsetul calculat
                            window.scrollTo({
                                top: offsetTop,
                                behavior: 'smooth'
                            });

                            // După două secunde, eliminăm stilurile de evidențiere,
                            // dar păstrăm clasa selected
                            setTimeout(() => {
                                imagine.style.boxShadow = '';
                                imagine.style.transform = '';
                            }, 2000);
                        }, 300);

                        // După două secunde, eliminăm stilurile de evidențiere,
                        // dar păstrăm clasa selected
                        setTimeout(() => {
                            imagine.style.boxShadow = '';
                            imagine.style.transform = '';
                        }, 2000);
                    }, 300);
                }
            }

            // Curățăm datele de localStorage pentru a evita probleme la navigările viitoare
            // dar păstrăm selecția în sesiune PHP
            localStorage.removeItem('ultimulIdObiectVizualizat');
            localStorage.removeItem('ultimulNumeImagineVizualizat');
            localStorage.removeItem('ultimulGrupObiectVizualizat');
        }
    });
</script>
<script>
    // Funcție pentru gestionarea elementelor cu placeholder
    function initializeazaObiecteEditabile() {
        // Selectăm toate elementele cu placeholdere
        document.querySelectorAll('.obiecte-text-gol, .descriere-text-gol').forEach(element => {
            // Salvăm mesajul placeholder
            const placeholder = element.textContent.trim();
            element.setAttribute('data-placeholder', placeholder);

            // Când elementul primește focus, eliminăm placeholder-ul
            element.addEventListener('focus', function() {
                if (this.textContent.trim() === this.getAttribute('data-placeholder')) {
                    this.textContent = '';
                    this.style.fontStyle = 'normal';
                    this.style.color = '#333';
                }
            });

            // Când elementul pierde focus și e gol, resetăm placeholder-ul
            element.addEventListener('blur', function() {
                if (this.textContent.trim() === '') {
                    this.textContent = this.getAttribute('data-placeholder');
                    this.style.fontStyle = 'italic';
                    this.style.color = '#999';
                } else {
                    // Dacă s-a introdus conținut, eliminăm clasa de gol corespunzătoare
                    this.classList.remove('obiecte-text-gol');
                    this.classList.remove('descriere-text-gol');
                }
            });
        });
    }

    // Adăugați la lista evenimentelor la încărcarea paginii
    document.addEventListener('DOMContentLoaded', function() {
        // ... alte inițializări existente ...
        initializeazaObiecteEditabile();

        // Funcții pentru User Avatar Dropdown
        const userAvatar = document.getElementById('userAvatar');

        if (userAvatar) {
            userAvatar.addEventListener('click', function(e) {
                e.stopPropagation();
                this.classList.toggle('active');
            });

            // Previne închiderea când se face click pe dropdown
            const dropdown = userAvatar.querySelector('.user-dropdown');
            if (dropdown) {
                dropdown.addEventListener('click', function(e) {
                    e.stopPropagation();
                });
            }
        }

        // Închide dropdown-ul când se face click în afară
        document.addEventListener('click', function() {
            const userAvatar = document.getElementById('userAvatar');
            if (userAvatar) {
                userAvatar.classList.remove('active');
            }
        });

        // Închide modalul donate când se face click pe fundal
        const donateModal = document.getElementById('donateModal');
        if (donateModal) {
            donateModal.addEventListener('click', function(e) {
                if (e.target === this) {
                    closeDonateModal();
                }
            });
        }

        // Încarcă starea de partajare pentru cutii
        incarcaStarePartajare();

        // Inițializează tab-urile
        initializeazaTaburi();
    });

    // Apelează și după ce pagina e complet încărcată
    window.addEventListener('load', function() {
        initializeazaTaburi();
    });

    // Funcție pentru inițializarea tab-urilor
    function initializeazaTaburi() {
        const tabs = document.querySelectorAll('.tab:not(.tab-add)');
        console.log('Tab-uri găsite:', tabs.length);
        tabs.forEach(tab => {
            tab.addEventListener('click', function(e) {
                e.preventDefault();
                const idColectie = this.getAttribute('data-colectie');
                console.log('Clic pe tabul cu ID colecție:', idColectie);
                if (idColectie) {
                    console.log('Redirecționez către: index.php?c=' + idColectie);
                    // Schimbă colecția prin URL
                    window.location.href = 'index.php?c=' + idColectie;
                } else {
                    console.log('Nu am găsit data-colectie pe acest tab');
                }
            });
        });
    }

    // Funcție pentru a încărca starea de partajare la încărcarea paginii
    function incarcaStarePartajare() {
        // Obținem ID-ul colecției active din tab-ul activ
        const tabActiv = document.querySelector('.tab.active');
        const idColectie = tabActiv ? tabActiv.getAttribute('data-colectie') : null;
        
        // Verificăm dacă colecția globală este publică
        fetch('ajax_partajare.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                actiune: 'obtine_toate_obiectele',
                id_colectie: idColectie  // Trimitem ID-ul colecției curente
            })
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Mai întâi resetăm clasa public
                    const globalBox = document.querySelector('.global-grid-box');
                    if (globalBox) {
                        globalBox.classList.remove('public');
                    }
                    
                    // Marcăm simbolul global DOAR dacă colecția CURENTĂ e publică
                    if (data.colectie_publica) {
                        if (globalBox) {
                            globalBox.classList.add('public');
                        }
                    }

                    // Grupăm obiectele partajate pe cutii
                    const cutiiPartajate = new Set();
                    data.obiecte.forEach(obiect => {
                        if (obiect.partajat) {
                            cutiiPartajate.add(obiect.cutie);
                        }
                    });

                    // Marcăm indicatorii pentru cutiile care au obiecte partajate
                    const indicators = document.querySelectorAll('.indicator');
                    indicators.forEach(ind => {
                        const cutie = ind.getAttribute('data-cutie');
                        if (cutiiPartajate.has(cutie)) {
                            ind.classList.add('public');
                        }
                    });
                }
            })
            .catch(error => {
                console.error('Eroare la încărcarea stării de partajare:', error);
            });
    }

</script>

<!-- Modal pentru partajare -->
<div id="modalPartajare" class="modal-partajare">
    <div class="modal-partajare-content">
        <div class="modal-header">
            <span class="close-modal" onclick="closePartajareModal()">&times;</span>
            <h2>Configurează partajarea pentru cutia: <span id="numeCutieModal"></span></h2>
        </div>

        <div class="checkbox-public">
            <input type="checkbox" id="cutiePublica" onchange="toggleCutiePublica()">
            <label for="cutiePublica">Marchează această cutie ca publică</label>
        </div>

        <div id="obiecteSection" style="display: none;">
            <h3>Selectează obiectele pe care vrei să le partajezi:</h3>
            <div class="obiecte-lista" id="listaObiectePartajare">
                <!-- Va fi populat dinamic cu JavaScript -->
            </div>
        </div>

        <div style="margin-top: 20px; text-align: right;">
            <button class="btn btn-secondary" onclick="closePartajareModal()">Anulează</button>
            <button class="btn btn-primary" onclick="salveazaPartajare()" style="background-color: #ff6600; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer;">Salvează</button>
        </div>
    </div>
</div>

<script>
    // Variabile globale pentru partajare
    let cutieSelectata = null;
    let locatieSelectata = null;
    let obiecteInCutie = {};

    // Funcții pentru partajare
    function openPartajareModal(element) {
        // Verifică dacă suntem într-o colecție partajată
        const tabActiv = document.querySelector('.tab.active');
        if (tabActiv && tabActiv.classList.contains('shared')) {
            // Afișează modal de avertizare pentru colecții partajate
            afiseazaAvertizarePartajare();
            return;
        }
        
        const indicator = element.closest('.indicator');
        const cutie = indicator.getAttribute('data-cutie');
        const locatie = indicator.getAttribute('data-locatie');
        cutieSelectata = cutie;
        locatieSelectata = locatie;

        // Setează numele cutiei în modal
        document.getElementById('numeCutieModal').textContent = cutie + ' (' + locatie + ')';

        // Încarcă obiectele din cutie
        incarcaObiecteDinCutie(cutie, locatie);

        // Afișează modalul
        document.getElementById('modalPartajare').style.display = 'block';
    }
    
    function afiseazaAvertizarePartajare() {
        // Afișează modal-ul de avertizare
        const modalAvertizare = document.getElementById('modalAvertizarePartajare');
        if (modalAvertizare) {
            modalAvertizare.style.display = 'block';
        }
    }
    
    function closeAvertizarePartajare() {
        const modalAvertizare = document.getElementById('modalAvertizarePartajare');
        if (modalAvertizare) {
            modalAvertizare.style.display = 'none';
        }
    }

    function openGlobalPartajare() {
        // Verifică dacă suntem într-o colecție partajată
        const tabActiv = document.querySelector('.tab.active');
        if (tabActiv && tabActiv.classList.contains('shared')) {
            // Afișează modal de avertizare pentru colecții partajate
            afiseazaAvertizarePartajare();
            return;
        }
        
        // Pentru simbolul global, arătăm toate obiectele
        cutieSelectata = '__global__';
        document.getElementById('numeCutieModal').textContent = 'Toate cutiile';

        // Încarcă toate obiectele
        incarcaToateObiectele();

        // Afișează modalul
        document.getElementById('modalPartajare').style.display = 'block';
    }

    function closePartajareModal() {
        document.getElementById('modalPartajare').style.display = 'none';
        cutieSelectata = null;
        obiecteInCutie = {};
    }

    function toggleCutiePublica() {
        const isPublic = document.getElementById('cutiePublica').checked;
        const obiecteSection = document.getElementById('obiecteSection');

        if (isPublic) {
            obiecteSection.style.display = 'block';
        } else {
            obiecteSection.style.display = 'none';
        }
    }

    function incarcaObiecteDinCutie(cutie, locatie) {
        const listaContainer = document.getElementById('listaObiectePartajare');
        listaContainer.innerHTML = '<div style="text-align: center;">Se încarcă...</div>';

        // Obținem ID-ul colecției din tab-ul activ
        const tabActiv = document.querySelector('.tab.active');
        const idColectie = tabActiv ? tabActiv.getAttribute('data-colectie') : null;

        // Facem request AJAX pentru a obține obiectele și statusul lor de partajare
        fetch('ajax_partajare.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                actiune: 'obtine_obiecte_cutie',
                cutie: cutie,
                locatie: locatie || '',
                id_colectie: idColectie
            })
        })
            .then(response => response.json())
            .then(data => {
                console.log('Răspuns partajare cutie:', data); // Debug info
                listaContainer.innerHTML = '';

                if (data.success && data.obiecte.length > 0) {
                    // Buton selectează toate
                    const btnSelectAll = document.createElement('button');
                    btnSelectAll.className = 'btn-selecteaza-toate';
                    btnSelectAll.textContent = 'Selectează toate';
                    btnSelectAll.onclick = function() {
                        const checkboxes = listaContainer.querySelectorAll('input[type="checkbox"]');
                        const allChecked = Array.from(checkboxes).every(cb => cb.checked);
                        checkboxes.forEach(cb => cb.checked = !allChecked);
                        this.textContent = allChecked ? 'Selectează toate' : 'Deselectează toate';
                    };
                    listaContainer.appendChild(btnSelectAll);

                    // Adăugăm obiectele
                    data.obiecte.forEach((obiect, index) => {
                        const item = document.createElement('div');
                        item.className = 'obiect-partajare';
                        item.innerHTML = `
                        <input type="checkbox" id="obiect_${index}" name="obiecte_partajate[]" value="${obiect.denumire_completa}" ${obiect.partajat ? 'checked' : ''}>
                        <label for="obiect_${index}">${obiect.denumire_completa}</label>
                    `;
                        listaContainer.appendChild(item);
                    });

                    // Verificăm dacă cutia e marcată ca publică
                    const isPublic = data.obiecte.some(o => o.partajat);
                    document.getElementById('cutiePublica').checked = isPublic;
                    toggleCutiePublica();
                } else {
                    listaContainer.innerHTML = '<div style="color: #666; text-align: center;">Nu există obiecte în această cutie.</div>';
                }
            })
            .catch(error => {
                console.error('Eroare:', error);
                listaContainer.innerHTML = '<div style="color: red;">Eroare la încărcarea obiectelor.</div>';
            });
    }

    function incarcaToateObiectele() {
        const listaContainer = document.getElementById('listaObiectePartajare');
        listaContainer.innerHTML = '<div style="text-align: center;">Se încarcă...</div>';

        // Obținem ID-ul colecției din tab-ul activ
        const tabActiv = document.querySelector('.tab.active');
        const idColectie = tabActiv ? tabActiv.getAttribute('data-colectie') : null;

        // Facem request AJAX pentru a obține toate obiectele
        fetch('ajax_partajare.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                actiune: 'obtine_toate_obiectele',
                id_colectie: idColectie
            })
        })
            .then(response => response.json())
            .then(data => {
                listaContainer.innerHTML = '';

                if (data.success && data.obiecte.length > 0) {
                    // Verificăm dacă colecția e marcată ca publică
                    document.getElementById('cutiePublica').checked = data.colectie_publica;
                    toggleCutiePublica();

                    // Buton selectează toate
                    const btnSelectAll = document.createElement('button');
                    btnSelectAll.className = 'btn-selecteaza-toate';
                    btnSelectAll.textContent = 'Selectează toate';
                    btnSelectAll.onclick = function() {
                        const checkboxes = listaContainer.querySelectorAll('input[type="checkbox"]');
                        const allChecked = Array.from(checkboxes).every(cb => cb.checked);
                        checkboxes.forEach(cb => cb.checked = !allChecked);
                        this.textContent = allChecked ? 'Selectează toate' : 'Deselectează toate';
                    };
                    listaContainer.appendChild(btnSelectAll);

                    // Grupăm obiectele pe cutii pentru afișare mai clară
                    const obiecteGrupate = {};
                    data.obiecte.forEach(obiect => {
                        const key = `${obiect.locatie} - ${obiect.cutie}`;
                        if (!obiecteGrupate[key]) {
                            obiecteGrupate[key] = [];
                        }
                        obiecteGrupate[key].push(obiect);
                    });

                    // Afișăm obiectele grupate
                    let indexGlobal = 0;
                    Object.keys(obiecteGrupate).forEach(key => {
                        const header = document.createElement('div');
                        header.style.marginTop = '15px';
                        header.style.marginBottom = '5px';
                        header.style.fontWeight = 'bold';
                        header.style.color = '#666';
                        header.textContent = key;
                        listaContainer.appendChild(header);

                        obiecteGrupate[key].forEach(obiect => {
                            const item = document.createElement('div');
                            item.className = 'obiect-partajare';
                            item.innerHTML = `
                            <input type="checkbox" id="obiect_global_${indexGlobal}" name="obiecte_partajate[]" value="${obiect.denumire_completa}" ${obiect.partajat ? 'checked' : ''}>
                            <label for="obiect_global_${indexGlobal}">${obiect.denumire_completa}</label>
                        `;
                            listaContainer.appendChild(item);
                            indexGlobal++;
                        });
                    });
                } else {
                    listaContainer.innerHTML = '<div style="color: #666; text-align: center;">Nu există obiecte în această colecție.</div>';
                }
            })
            .catch(error => {
                console.error('Eroare:', error);
                listaContainer.innerHTML = '<div style="color: red;">Eroare la încărcarea obiectelor.</div>';
            });
    }

    function salveazaPartajare() {
        const isPublic = document.getElementById('cutiePublica').checked;
        const obiecteSelectate = [];

        if (isPublic) {
            // Colectăm obiectele selectate
            const checkboxes = document.querySelectorAll('#listaObiectePartajare input[type="checkbox"]:checked');
            checkboxes.forEach(cb => {
                obiecteSelectate.push(cb.value);
            });
        }

        // Obținem ID-ul colecției din tab-ul activ
        const tabActiv = document.querySelector('.tab.active');
        const idColectie = tabActiv ? tabActiv.getAttribute('data-colectie') : null;

        // Trimitem datele către server
        fetch('ajax_partajare.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                actiune: 'salveaza_partajare',
                cutie: cutieSelectata,
                locatie: locatieSelectata || '',
                isPublic: isPublic,
                obiecte: obiecteSelectate,
                id_colectie: idColectie
            })
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (typeof showSuccess === 'function') {
                        showSuccess(data.message);
                    }

                    // Actualizăm vizual indicatorul
                    if (cutieSelectata === '__global__') {
                        // Pentru global, actualizăm simbolul global și toate cutiile
                        const globalBox = document.querySelector('.global-grid-box');
                        const allIndicators = document.querySelectorAll('.indicator');
                        const tabActiv = document.querySelector('.tab.active');

                        if (isPublic) {
                            globalBox.classList.add('public');
                            allIndicators.forEach(ind => ind.classList.add('public'));
                            // Actualizează și tab-ul activ
                            if (tabActiv) {
                                tabActiv.classList.remove('has-shared');
                                tabActiv.classList.add('all-public');
                            }
                        } else {
                            globalBox.classList.remove('public');
                            allIndicators.forEach(ind => ind.classList.remove('public'));
                            // Elimină stilurile de partajare de pe tab
                            if (tabActiv) {
                                tabActiv.classList.remove('all-public');
                                tabActiv.classList.remove('has-shared');
                            }
                        }
                    } else {
                        // Pentru o cutie specifică
                        const indicators = document.querySelectorAll('.indicator');
                        const tabActiv = document.querySelector('.tab.active');
                        let hasAnyShared = false;
                        
                        indicators.forEach(ind => {
                            const cutieInd = ind.getAttribute('data-cutie');
                            const locatieInd = ind.getAttribute('data-locatie');

                            // Verificăm atât cutia cât și locația
                            if (cutieInd === cutieSelectata &&
                                (!locatieSelectata || locatieInd === locatieSelectata)) {
                                if (isPublic && obiecteSelectate.length > 0) {
                                    ind.classList.add('public');
                                } else {
                                    ind.classList.remove('public');
                                }
                            }
                            // Verifică dacă există vreun indicator public după actualizare
                            if (ind.classList.contains('public')) {
                                hasAnyShared = true;
                            }
                        });
                        
                        // Actualizează tab-ul în funcție de starea de partajare
                        if (tabActiv && !tabActiv.classList.contains('all-public')) {
                            if (hasAnyShared) {
                                tabActiv.classList.add('has-shared');
                            } else {
                                tabActiv.classList.remove('has-shared');
                            }
                        }
                    }

                    closePartajareModal();
                } else {
                    if (typeof showError === 'function') {
                        showError(data.message || 'Eroare la salvarea setărilor de partajare');
                    }
                }
            })
            .catch(error => {
                console.error('Eroare:', error);
                if (typeof showError === 'function') {
                    showError('Eroare la comunicarea cu serverul');
                }
            });
    }

    // Închide modalul când se dă click în afara lui
    window.addEventListener('click', function(event) {
        const modal = document.getElementById('modalPartajare');
        if (event.target === modal) {
            closePartajareModal();
        }
    });

    // FUNCȚII PENTRU SISTEMUL DE TAB-URI
    // Folosim delegare de evenimente pentru tab-uri
    document.addEventListener('click', function(e) {
        // Verifică dacă click-ul a fost pe un tab sau în interiorul unui tab
        const tab = e.target.closest('.tab:not(.tab-add)');

        if (tab) {
            e.preventDefault();
            e.stopPropagation();

            const colectieId = tab.getAttribute('data-colectie');
            console.log('Click pe tab cu ID colecție:', colectieId);

            if (!colectieId) {
                console.error('Tab fără ID colecție!');
                return;
            }

            // Afișează loading
            tab.style.opacity = '0.6';

            // Schimbă colecția curentă prin AJAX
            fetch('ajax_colectii.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'actiune=schimba_colectie&id_colectie=' + colectieId
            })
                .then(response => {
                    console.log('Response status:', response.status);
                    return response.json();
                })
                .then(data => {
                    console.log('Response data:', data);
                    if (data.success) {
                        // Reîncarcă pagina pentru a afișa noua colecție
                        window.location.reload();
                    } else {
                        showError(data.message || 'Eroare la schimbarea colecției');
                        tab.style.opacity = '1';
                    }
                })
                .catch(error => {
                    console.error('Eroare AJAX:', error);
                    showError('Eroare la comunicarea cu serverul');
                    tab.style.opacity = '1';
                });
        }
    });

    // Funcție pentru ștergerea unei colecții
    function stergeColectie(event, idColectie, numeColectie) {
        // Oprește propagarea evenimentului pentru a nu activa tab-ul
        event.stopPropagation();

        // Prima confirmare cu modal stilizat
        showDeleteConfirm(
            `Ești sigur că vrei să ștergi colecția "<strong>${numeColectie}</strong>"?<br><br>
            <span style="color: #f44336;">⚠️ Această acțiune este ireversibilă și va șterge toate obiectele din această colecție!</span>`,
            'Confirmare ștergere colecție',
            function() {
                // A doua confirmare pentru siguranță
                showDeleteConfirm(
                    `<strong style="color: #f44336;">ATENȚIE FINALĂ!</strong><br><br>
                    Toate datele din colecția "<strong>${numeColectie}</strong>" vor fi șterse definitiv.<br><br>
                    Ești absolut sigur că vrei să continui?`,
                    'Ultima confirmare',
                    function() {
                        // Execută ștergerea
                        executeStergeColectie(idColectie, numeColectie);
                    }
                );
            }
        );
    }

    function executeStergeColectie(idColectie, numeColectie) {
        // Trimite cererea de ștergere
        fetch('ajax_colectii.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                actiune: 'stergere_colectie',
                id_colectie: idColectie
            })
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (typeof showSuccess === 'function') {
                        showSuccess('Colecția a fost ștearsă cu succes');
                    }
                    // Reîncarcă pagina pentru a actualiza lista de colecții
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                } else {
                    if (typeof showError === 'function') {
                        showError(data.message || 'Eroare la ștergerea colecției');
                    } else {
                        alert('Eroare: ' + (data.message || 'Nu s-a putut șterge colecția'));
                    }
                }
            })
            .catch(error => {
                console.error('Eroare:', error);
                if (typeof showError === 'function') {
                    showError('Eroare de comunicare cu serverul');
                } else {
                    alert('Eroare de comunicare cu serverul');
                }
            });
    }

    // Funcție pentru deschiderea modalului de colecție nouă
    function deschideModalColectieNoua() {
        // Pentru moment, folosim un prompt simplu
        const numeColectie = prompt('Introdu numele pentru noua colecție:', 'Colecția mea nouă');

        if (numeColectie && numeColectie.trim()) {
            // Aici va fi apelul AJAX pentru a crea noua colecție
            console.log('Se creează colecția:', numeColectie);

            // TODO: Implementare AJAX pentru creare colecție
            fetch('ajax_colectii.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    actiune: 'creare_colectie',
                    nume: numeColectie
                })
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showSuccess('Colecția a fost creată cu succes!');
                        // Reîncarcă pagina pentru a afișa noua colecție
                        setTimeout(() => {
                            window.location.reload();
                        }, 1500);
                    } else {
                        showError(data.message || 'Eroare la crearea colecției');
                    }
                })
                .catch(error => {
                    console.error('Eroare:', error);
                    showError('Eroare la comunicarea cu serverul');
                });
        }
    }

    // Funcție pentru salvarea numelui colecției
    function salveazaNumeBazaDate(element) {
        const numeNou = element.textContent.trim();
        const idColectie = element.getAttribute('data-id-colectie');

        if (numeNou && idColectie) {
            // Salvează prin AJAX
            fetch('ajax_colectii.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'actiune=redenumire_colectie&id_colectie=' + idColectie + '&nume_nou=' + encodeURIComponent(numeNou)
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showSuccess('Numele colecției a fost actualizat!');
                        // Actualizează și în tab
                        document.querySelector('.tab.active span:not(.tab-icon)').textContent = numeNou;
                    } else {
                        showError(data.message || 'Eroare la actualizarea numelui');
                        element.textContent = element.defaultValue;
                    }
                })
                .catch(error => {
                    console.error('Eroare:', error);
                    showError('Eroare la comunicarea cu serverul');
                });
        }
    }
</script>

</div> <!-- închide container -->

<!-- Footer informativ -->
<div class="info-footer">
    <div class="info-text">Proiect susținut cu efort personal, pentru că m-am săturat să nu-mi găsesc lucrurile când am nevoie.</div>
    <div class="copyright">© <?php echo date('Y'); ?> Inventar.live</div>
</div>

<!-- Modal universal stilizat ca Vision -->
<div class="universal-modal" id="universalModal">
    <div class="universal-modal-content">
        <div class="universal-modal-icon" id="modalIcon">ℹ️</div>
        <h3 id="modalTitle">Titlu</h3>
        <p id="modalMessage">Mesaj</p>
        <div class="universal-modal-buttons" id="modalButtons">
            <!-- Butoanele vor fi adăugate dinamic -->
        </div>
    </div>
</div>

<script>
    // Sistem universal de modale stilizate ca Vision
    let modalCallback = null;

    function showModal(options) {
        const modal = document.getElementById('universalModal');
        const icon = document.getElementById('modalIcon');
        const title = document.getElementById('modalTitle');
        const message = document.getElementById('modalMessage');
        const buttons = document.getElementById('modalButtons');

        // Setare icon
        const icons = {
            'success': '✅',
            'error': '❌',
            'warning': '⚠️',
            'info': 'ℹ️',
            'question': '❓',
            'delete': '🗑️'
        };
        icon.innerHTML = icons[options.type] || icons.info;
        icon.className = 'universal-modal-icon ' + (options.type || 'info');

        // Setare text
        title.textContent = options.title || 'Notificare';
        message.innerHTML = options.message || '';

        // Curăță butoanele existente
        buttons.innerHTML = '';

        // Adaugă butoane
        if (options.buttons) {
            options.buttons.forEach(btn => {
                const button = document.createElement('button');
                button.className = 'universal-modal-button ' + (btn.class || '');
                button.textContent = btn.text;
                button.onclick = () => {
                    modal.style.display = 'none';
                    if (btn.callback) btn.callback();
                };
                buttons.appendChild(button);
            });
        } else {
            // Buton implicit OK
            const button = document.createElement('button');
            button.className = 'universal-modal-button';
            button.textContent = 'OK';
            button.onclick = () => {
                modal.style.display = 'none';
            };
            buttons.appendChild(button);
        }

        // Afișează modalul
        modal.style.display = 'flex';
    }

    // Înlocuitori pentru alert() și confirm()
    function showAlert(message, title = 'Atenție', type = 'warning') {
        showModal({
            type: type,
            title: title,
            message: message,
            buttons: [{
                text: 'OK',
                callback: null
            }]
        });
    }

    function showConfirm(message, title = 'Confirmare', onConfirm = null, onCancel = null) {
        showModal({
            type: 'question',
            title: title,
            message: message,
            buttons: [
                {
                    text: 'Da',
                    callback: onConfirm
                },
                {
                    text: 'Nu',
                    class: 'secondary',
                    callback: onCancel
                }
            ]
        });
    }

    function showDeleteConfirm(message, title = 'Confirmare ștergere', onConfirm = null) {
        showModal({
            type: 'delete',
            title: title,
            message: message,
            buttons: [
                {
                    text: 'Șterge',
                    class: 'danger',
                    callback: onConfirm
                },
                {
                    text: 'Anulează',
                    class: 'secondary',
                    callback: null
                }
            ]
        });
    }

    // Închide modalul la click în afara lui
    document.getElementById('universalModal').addEventListener('click', function(e) {
        if (e.target === this) {
            this.style.display = 'none';
        }
    });
</script>

<script src="js/notifications.js"></script>
<!-- Modal de avertizare pentru colecții partajate -->
<div id="modalAvertizarePartajare" class="modal-partajare" style="display: none;">
    <div class="modal-partajare-content" style="max-width: 500px;">
        <div class="modal-header">
            <span class="close-modal" onclick="closeAvertizarePartajare()">&times;</span>
            <h2>⚠️ Acces restricționat</h2>
        </div>
        
        <div style="padding: 30px; text-align: center;">
            <div style="background: #fff3e0; border-left: 4px solid #ff9800; padding: 20px; text-align: left; margin-bottom: 25px; border-radius: 4px;">
                <p style="margin: 0; color: #e65100; font-weight: 600; margin-bottom: 10px;">
                    Nu puteți modifica setările de partajare
                </p>
                <p style="margin: 0; color: #666; font-size: 14px; line-height: 1.5;">
                    Această colecție vă este partajată de alt utilizator. Doar proprietarul colecției poate gestiona permisiunile de partajare și decide ce elemente sunt accesibile.
                </p>
            </div>
            
            <button onclick="closeAvertizarePartajare()" 
                    style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
                           color: white; border: none; padding: 12px 30px; 
                           border-radius: 25px; font-size: 16px; font-weight: 600; 
                           cursor: pointer; transition: all 0.3s ease;">
                Am înțeles
            </button>
        </div>
    </div>
</div>

<!-- Modal Detalii Obiect și Cerere Împrumut -->
<div id="modalDetaliiObiect" class="modal-partajare" style="display: none;">
    <div class="modal-partajare-content" style="max-width: 700px;">
        <div class="modal-header">
            <span class="close-modal" onclick="inchideModalObiect()">&times;</span>
            <h2>📦 Detalii Obiect</h2>
        </div>
        <div id="continutModalObiect" style="padding: 30px;">
            <!-- Conținutul va fi populat dinamic -->
        </div>
    </div>
</div>

<style>
#modalDetaliiObiect .info-localizare {
    background: #f5f5f5;
    border-left: 4px solid #667eea;
    padding: 15px;
    margin-bottom: 20px;
    border-radius: 4px;
}

#modalDetaliiObiect .imagini-container {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 20px;
}

#modalDetaliiObiect .imagine-obiect {
    width: 150px;
    height: 150px;
    object-fit: cover;
    border-radius: 8px;
    cursor: pointer;
    transition: transform 0.3s ease;
}

#modalDetaliiObiect .imagine-obiect:hover {
    transform: scale(1.05);
}

#modalDetaliiObiect .formular-imprumut {
    background: #fff3e0;
    border-left: 4px solid #ff9800;
    padding: 20px;
    border-radius: 4px;
    margin-top: 20px;
}

#modalDetaliiObiect .form-group {
    margin-bottom: 15px;
}

#modalDetaliiObiect .form-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: 600;
    color: #333;
}

#modalDetaliiObiect .form-group input,
#modalDetaliiObiect .form-group textarea {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
}

#modalDetaliiObiect .form-group textarea {
    resize: vertical;
    min-height: 80px;
}

.btn-imprumut {
    background: linear-gradient(135deg, #ff9800 0%, #ff6600 100%);
    color: white;
    border: none;
    padding: 12px 30px;
    border-radius: 5px;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    margin-top: 10px;
}

.btn-imprumut:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(255, 102, 0, 0.3);
}

.btn-imprumut:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
</style>

<script>
// Funcție pentru deschiderea modalului cu detalii obiect
function deschideModalObiect(idColectie, idObiect, cutie, locatie, numeColectie, imagine, tipAcces = null) {
    const modal = document.getElementById('modalDetaliiObiect');
    const continut = document.getElementById('continutModalObiect');
    
    // Afișăm modala cu loading
    modal.style.display = 'flex';
    continut.innerHTML = '<div style="text-align: center; padding: 40px;">⏳ Se încarcă detaliile...</div>';
    
    // Folosim datele pe care le avem deja, inclusiv imaginea
    setTimeout(() => {
        let imagineSectiune = '';
        if (imagine && imagine !== '' && imagine !== 'undefined') {
            imagineSectiune = `
                <div style="margin: 20px 0; text-align: center;">
                    <img src="${imagine}" 
                         style="max-width: 100%; max-height: 300px; border-radius: 8px; 
                                box-shadow: 0 4px 10px rgba(0,0,0,0.1); cursor: pointer;"
                         onclick="window.open('${imagine}', '_blank')"
                         alt="Imagine obiect"
                         onerror="this.style.display='none'">
                </div>`;
        }
        
        // Determinăm culoarea în funcție de tipul de acces
        const culoareHeader = tipAcces === 'proprietar' ? '#4CAF50' : '#764ba2'; // Verde pentru proprietar, purple pentru alte colecții
        const iconita = tipAcces === 'proprietar' ? '🏠' : '🤝'; // Iconițe diferite
        const mesajAcces = tipAcces === 'proprietar' ? 'Obiect din colecția proprie' : 'Obiect din colecție partajată';
        
        let html = `
            <div class="info-localizare" style="border-left: 4px solid ${culoareHeader}; padding-left: 15px;">
                <h3 style="margin-top: 0; color: ${culoareHeader};">${iconita} Localizare Completă</h3>
                <p style="color: ${culoareHeader}; font-size: 12px; font-style: italic; margin-bottom: 10px;">${mesajAcces}</p>
                <p><strong>Colecție:</strong> ${numeColectie}</p>
                <p><strong>Cutie:</strong> ${cutie}</p>
                <p><strong>Locație:</strong> ${locatie}</p>
            </div>
            
            ${imagineSectiune}
            
            ${tipAcces !== 'proprietar' ? `
            <div class="formular-imprumut" style="background: linear-gradient(135deg, #f3e5f5 0%, #e1bee7 100%); border-left: 4px solid #764ba2;">
                <h3 style="margin-top: 0; color: #764ba2;">🤝 Cerere de Împrumut</h3>
                <p style="color: #666; font-size: 14px; margin-bottom: 20px;">
                    Completați formularul pentru a trimite o cerere de împrumut către proprietarul colecției "${numeColectie}".
                </p>
                
                <form id="formImprumut" onsubmit="trimitereCerereImprumut(event, ${idColectie}, ${idObiect})">
                    <div class="form-group">
                        <label for="obiectIdentificare">Identificare obiect specific din cutia "${cutie}":</label>
                        <input type="text" id="obiectIdentificare" name="obiectIdentificare" 
                               placeholder="Ex: Decantă cristal cu model floral" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="perioadaStart">Dată început împrumut:</label>
                        <input type="date" id="perioadaStart" name="perioadaStart" 
                               min="${new Date().toISOString().split('T')[0]}" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="perioadaSfarsit">Dată returnare:</label>
                        <input type="date" id="perioadaSfarsit" name="perioadaSfarsit" 
                               min="${new Date().toISOString().split('T')[0]}" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="mesaj">Mesaj către proprietar (opțional):</label>
                        <textarea id="mesaj" name="mesaj" 
                                  placeholder="Adăugați detalii suplimentare despre motivul împrumutului..."></textarea>
                    </div>
                    
                    <button type="submit" class="btn-imprumut">
                        📨 Trimite Cererea de Împrumut
                    </button>
                </form>
            </div>
            ` : ''}
            
            <div style="text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee;">
                <button onclick="inchideModalObiect()" 
                        style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
                               color: white; border: none; padding: 10px 30px; 
                               border-radius: 5px; cursor: pointer; font-size: 14px;">
                    Închide
                </button>
            </div>
        `;
        
        continut.innerHTML = html;
        
        // Validare date pentru perioada de împrumut
        const perioadaStart = document.getElementById('perioadaStart');
        const perioadaSfarsit = document.getElementById('perioadaSfarsit');
        
        if (perioadaStart && perioadaSfarsit) {
            perioadaStart.addEventListener('change', function() {
                perioadaSfarsit.min = this.value;
                if (perioadaSfarsit.value && perioadaSfarsit.value < this.value) {
                    perioadaSfarsit.value = this.value;
                }
            });
        }
    }, 300);
}

// Funcție pentru închiderea modalului
function inchideModalObiect() {
    document.getElementById('modalDetaliiObiect').style.display = 'none';
    document.getElementById('continutModalObiect').innerHTML = '';
}

// Funcție pentru trimiterea cererii de împrumut
function trimitereCerereImprumut(event, idColectie, idObiect) {
    event.preventDefault();
    
    const formData = new FormData(event.target);
    const data = Object.fromEntries(formData);
    
    // Dezactivăm butonul pentru a preveni dublarea cererii
    const btn = event.target.querySelector('.btn-imprumut');
    btn.disabled = true;
    btn.innerHTML = '⏳ Se trimite...';
    
    // Obținem informațiile despre cutie și locație din modal
    const modal = document.getElementById('continutModalObiect');
    const infoDiv = modal.querySelector('div:first-child');
    const paragraphs = infoDiv.querySelectorAll('p');
    // paragraphs[0] = mesajAcces, paragraphs[1] = Colecție, paragraphs[2] = Cutie, paragraphs[3] = Locație
    const cutieText = paragraphs[2].textContent.replace('Cutie: ', '').trim();
    const locatieText = paragraphs[3].textContent.replace('Locație: ', '').trim();
    
    // Debug - verifică ce date trimitem
    const dataToSend = {
        actiune: 'trimite_cerere',
        id_colectie: idColectie,
        id_obiect: idObiect,
        denumire_obiect: data.obiectIdentificare,
        cutie: cutieText,
        locatie: locatieText,
        data_inceput: data.perioadaStart,
        data_sfarsit: data.perioadaSfarsit,
        mesaj: data.mesaj || ''
    };
    
    console.log('Trimit cerere împrumut cu datele:', dataToSend);
    
    // Trimitem cererea efectivă la server
    fetch('ajax_imprumut.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(dataToSend)
    })
    .then(response => {
        console.log('Răspuns status:', response.status);
        return response.text(); // Folosim text() pentru a vedea răspunsul brut
    })
    .then(text => {
        console.log('Răspuns brut:', text);
        try {
            const result = JSON.parse(text);
            return result;
        } catch (e) {
            console.error('Eroare parsare JSON:', e);
            console.error('Text primit:', text);
            throw e;
        }
    })
    .then(result => {
        console.log('Rezultat parsare:', result);
        if (result.success) {
            // Afișăm confirmare
            const continut = document.getElementById('continutModalObiect');
            continut.innerHTML = `
                <div style="text-align: center; padding: 40px;">
                    <div style="font-size: 64px; margin-bottom: 20px;">✅</div>
                    <h3 style="color: #4CAF50;">Cerere trimisă cu succes!</h3>
                    <p style="color: #666; margin: 20px 0;">
                        Cererea dvs. de împrumut pentru perioada<br>
                        <strong>${data.perioadaStart}</strong> - <strong>${data.perioadaSfarsit}</strong><br>
                        a fost înregistrată.
                    </p>
                    <p style="color: #999; font-size: 14px;">
                        Veți primi o notificare când proprietarul va răspunde la cererea dvs.
                    </p>
                    <button onclick="inchideModalObiect()" 
                            style="margin-top: 20px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
                                   color: white; border: none; padding: 10px 30px; 
                                   border-radius: 5px; cursor: pointer;">
                        Închide
                    </button>
                </div>
            `;
            
            // Închidem automat după 3 secunde
            setTimeout(() => {
                inchideModalObiect();
            }, 3000);
        } else {
            // Reactivăm butonul și afișăm eroarea
            btn.disabled = false;
            btn.innerHTML = '📨 Trimite Cererea de Împrumut';
            alert('Eroare: ' + (result.error || 'Nu s-a putut trimite cererea'));
        }
    })
    .catch(error => {
        console.error('Eroare:', error);
        btn.disabled = false;
        btn.innerHTML = '📨 Trimite Cererea de Împrumut';
        alert('A apărut o eroare la trimiterea cererii');
    });
    
    console.log('Cerere împrumut:', {
        idColectie,
        idObiect,
        ...data
    });
}

// Închide modala când se dă click în afara ei
window.addEventListener('click', function(event) {
    const modal = document.getElementById('modalDetaliiObiect');
    if (event.target === modal) {
        inchideModalObiect();
    }
});

// Funcție pentru verificarea notificărilor de împrumut
function verificaNotificariImprumuturi() {
    // Verifică cereri primite necitite
    Promise.all([
        fetch('ajax_imprumut.php?actiune=numar_cereri_necitite').then(r => r.json()),
        fetch('ajax_imprumut.php?actiune=numar_raspunsuri_necitite').then(r => r.json())
    ])
    .then(([cereriData, raspunsuriData]) => {
        const totalNotificari = (cereriData.numar || 0) + (raspunsuriData.numar || 0);
        
        if (totalNotificari > 0) {
            // Actualizează badge-ul pe avatar
            const badgeAvatar = document.getElementById('badgeNotificariAvatar');
            if (badgeAvatar) {
                badgeAvatar.textContent = totalNotificari;
                badgeAvatar.style.display = 'block';
            }
            
            // Adaugă și indicator pe link-ul către Împarte cu ceilalți
            const linkImpartasiri = document.querySelector('a[href="impartasiri.php"]');
            if (linkImpartasiri) {
                let badge = linkImpartasiri.querySelector('.badge-notificare');
                if (!badge) {
                    badge = document.createElement('span');
                    badge.className = 'badge-notificare';
                    badge.style.cssText = 'background: #ff0000; color: white; border-radius: 10px; padding: 2px 6px; margin-left: 5px; font-size: 11px;';
                    linkImpartasiri.appendChild(badge);
                }
                badge.textContent = totalNotificari;
            }
        } else {
            // Ascunde badge-ul dacă nu sunt notificări
            const badgeAvatar = document.getElementById('badgeNotificariAvatar');
            if (badgeAvatar) {
                badgeAvatar.style.display = 'none';
            }
            
            // Elimină badge-ul de pe link
            const badge = document.querySelector('.badge-notificare');
            if (badge) {
                badge.remove();
            }
        }
    })
    .catch(error => console.error('Eroare la verificarea notificărilor:', error));
}

// Funcție pentru actualizarea timer-ului de împrumut
function actualizeazaTimerImprumut() {
    fetch('ajax_imprumut.php?actiune=obtine_imprumuturi_active')
        .then(response => response.json())
        .then(data => {
            if (data.success && data.total > 0) {
                const timer = document.getElementById('timerImprumut');
                const timerText = document.getElementById('timerImprumutText');
                
                if (timer && timerText) {
                    // Afișează timer-ul doar cu numărul de împrumuturi active
                    timer.style.display = 'flex';
                    timerText.textContent = '⏳ ' + data.total;
                    
                    // Culoare bazată pe urgența celui mai apropiat împrumut
                    if (data.cel_mai_urgent) {
                        // Folosește culoarea doar pentru fundal
                        if (data.cel_mai_urgent.urgenta === 'foarte_urgent') {
                            timer.style.backgroundColor = '#ff0000'; // Roșu pentru urgent
                            if (data.cel_mai_urgent.animatie_pulsare) {
                                timer.classList.add('timer-pulsare');
                            }
                        } else if (data.cel_mai_urgent.urgenta === 'urgent') {
                            timer.style.backgroundColor = '#ff9800'; // Portocaliu
                            timer.classList.remove('timer-pulsare');
                        } else {
                            timer.style.backgroundColor = '#4CAF50'; // Verde
                            timer.classList.remove('timer-pulsare');
                        }
                    }
                    
                    // Salvează toate împrumuturile pentru afișare în modal
                    window.imprumuturiActive = data.imprumuturi;
                }
            } else {
                // Ascunde timer-ul dacă nu sunt împrumuturi active
                const timer = document.getElementById('timerImprumut');
                if (timer) {
                    timer.style.display = 'none';
                }
                window.imprumuturiActive = [];
            }
        })
        .catch(error => console.error('Eroare la actualizarea timer-ului:', error));
}

// Funcție pentru a deschide impartasiri.php la cererea specifică
function deschideImpartasiriLaCerere(idCerere, rolUtilizator) {
    // Închide modalul
    const modal = document.getElementById('modalImprumuturi');
    if (modal) {
        modal.style.display = 'none';
    }
    
    // Determină tab-ul și subtab-ul bazat pe rol
    const tab = 'imprumuturi';
    const subtab = rolUtilizator === 'solicitant' ? 'trimise' : 'primite';
    
    // Redirecționează cu parametri
    window.location.href = `impartasiri.php?tab=${tab}&subtab=${subtab}&highlight=${idCerere}#cerere-${idCerere}`;
}

// Funcție pentru afișarea detaliilor împrumuturilor
function afiseazaDetaliiImprumuturi() {
    if (!window.imprumuturiActive || window.imprumuturiActive.length === 0) {
        return;
    }
    
    // Determină culoarea predominantă bazată pe primul împrumut (cel mai urgent)
    const culoareHeader = window.imprumuturiActive[0].rol_utilizator === 'solicitant' ? '#4CAF50' : '#9C27B0';
    
    // Creează modalul dacă nu există - stil inventar.live
    let modal = document.getElementById('modalImprumuturi');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'modalImprumuturi';
        modal.style.cssText = `
            display: none; position: fixed; z-index: 100000; left: 0; top: 0; 
            width: 100%; height: 100%; background-color: rgba(0,0,0,0.6);
            animation: fadeIn 0.3s ease;
        `;
        modal.innerHTML = `
            <div style="background: white; margin: 50px auto; padding: 0; 
                        border-radius: 15px; max-width: 600px; max-height: 80vh; 
                        overflow: hidden; position: relative; animation: slideIn 0.4s ease;
                        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);">
                <div id="headerModal" style="background: ${culoareHeader}; 
                            padding: 20px 30px; position: relative;">
                    <span onclick="this.parentElement.parentElement.parentElement.style.display='none'" 
                          style="position: absolute; right: 20px; top: 20px; font-size: 32px; 
                                 cursor: pointer; color: white; opacity: 0.8; transition: opacity 0.3s;">&times;</span>
                    <h2 style="margin: 0; color: white; font-size: 24px; font-weight: 600;">
                        ⏰ Împrumuturi Active
                    </h2>
                </div>
                <div id="listaImprumuturi" style="padding: 20px 30px 30px; overflow-y: auto; max-height: calc(80vh - 100px);"></div>
            </div>
        `;
        document.body.appendChild(modal);
    } else {
        // Actualizează culoarea header-ului dacă modalul există deja
        const header = document.getElementById('headerModal');
        if (header) {
            header.style.background = culoareHeader;
        }
    }
    
    // Populează lista de împrumuturi
    const lista = document.getElementById('listaImprumuturi');
    let html = '';
    
    window.imprumuturiActive.forEach(imp => {
        const persoana = imp.rol_utilizator === 'solicitant' ? 
            `Împrumutat de la ${imp.nume_proprietar_format}` : 
            `Împrumutat către ${imp.nume_solicitant_format}`;
        
        // Culoare bordură în funcție de rol
        const culoareBordura = imp.rol_utilizator === 'solicitant' ? '#4CAF50' : '#9C27B0'; // verde sau purple
        
        // Culoare bulină - roșie doar dacă timpul a expirat, altfel culoarea rolului
        const culoareBulina = imp.ore_ramase <= 0 ? '#ff0000' : 
                              (imp.rol_utilizator === 'solicitant' ? '#4CAF50' : '#9C27B0');
        const culoareText = 'white'; // text alb întotdeauna pentru contrast
        
        html += `
            <div style="background: #f9f9f9; border: 2px solid #e0e0e0; 
                        border-radius: 10px; padding: 15px; margin-bottom: 15px;
                        border-left: 5px solid ${culoareBordura}; cursor: pointer;
                        transition: transform 0.2s, box-shadow 0.2s;"
                 onclick="deschideImpartasiriLaCerere(${imp.id_cerere}, '${imp.rol_utilizator}')"
                 onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 8px rgba(0,0,0,0.15)';"
                 onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='none';">
                <div style="display: flex; justify-content: space-between; align-items: start;">
                    <div style="flex: 1;">
                        <h4 style="margin: 0 0 8px 0; color: #333;">
                            ${imp.denumire_obiect}
                        </h4>
                        <p style="margin: 5px 0; color: #666; font-size: 14px;">
                            ${persoana}<br>
                            <strong>Din:</strong> ${imp.nume_colectie}<br>
                            <strong>Cutie:</strong> ${imp.cutie} | <strong>Locație:</strong> ${imp.locatie}<br>
                            <strong>Perioada:</strong> ${imp.data_inceput} - ${imp.data_sfarsit}
                        </p>
                    </div>
                    <div style="text-align: center;">
                        <div style="background: ${culoareBulina}; color: ${culoareText}; 
                                    padding: 10px; border-radius: 50%; width: 50px; height: 50px;
                                    display: flex; align-items: center; justify-content: center;
                                    font-weight: bold; font-size: 16px;
                                    ${imp.ore_ramase <= 0 ? 'animation: pulsare 1.5s ease-in-out infinite;' : ''}">
                            ${imp.timp_ramas_text}
                        </div>
                        <div style="margin-top: 5px; font-size: 11px; color: #999;">
                            ${imp.ore_ramase <= 0 ? 'Termen depășit' : 
                              imp.ore_ramase <= 24 ? 'URGENT!' : 
                              imp.zile_ramase <= 3 ? 'În curând' : 'Timp rămas'}
                        </div>
                    </div>
                </div>
                ${imp.status === 'imprumutat' && imp.data_predare ? 
                    `<div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #e0e0e0; 
                                font-size: 12px; color: #999;">
                        Împrumutat la: ${imp.data_predare}
                    </div>` : ''}
            </div>
        `;
    });
    
    lista.innerHTML = html || '<p style="text-align: center; color: #999;">Nu ai împrumuturi active.</p>';
    modal.style.display = 'block';
}

// Verifică notificările la încărcarea paginii
document.addEventListener('DOMContentLoaded', function() {
    verificaNotificariImprumuturi();
    actualizeazaTimerImprumut();
    
    // Verifică notificările la fiecare 30 de secunde
    setInterval(verificaNotificariImprumuturi, 30000);
    
    // Actualizează timer-ul la fiecare minut
    setInterval(actualizeazaTimerImprumut, 60000);
});
</script>

<!-- PWA Service Worker Registration & Offline Sync -->
<script>
(function() {
    'use strict';

    // Configurare PWA
    const PWA_CONFIG = {
        colectieId: <?php echo json_encode($_SESSION['id_colectie_curenta'] ?? $user['id_colectie_principala'] ?? null); ?>,
        userId: <?php echo json_encode($user['id_utilizator'] ?? null); ?>
    };

    // Indicator vizual status sincronizare
    function createSyncIndicator() {
        const indicator = document.createElement('div');
        indicator.id = 'pwa-sync-indicator';
        indicator.className = 'pwa-status-indicator online';
        indicator.innerHTML = '<span class="status-dot"></span><span class="status-text">Online</span>';
        indicator.style.display = 'none'; // Ascuns implicit
        document.body.appendChild(indicator);
        return indicator;
    }

    // Actualizare indicator
    function updateSyncIndicator(status, message) {
        const indicator = document.getElementById('pwa-sync-indicator');
        if (!indicator) return;

        indicator.className = 'pwa-status-indicator ' + status;
        indicator.querySelector('.status-text').textContent = message;

        // Afișează temporar pentru mesaje importante
        if (status !== 'online') {
            indicator.style.display = 'flex';
            if (status === 'syncing') {
                // Auto-hide după sync
            }
        }
    }

    // Înregistrare Service Worker
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', async function() {
            try {
                // Înregistrează Service Worker
                const registration = await navigator.serviceWorker.register('/sw.js');
                console.log('[PWA] Service Worker înregistrat:', registration.scope);

                // Verifică pentru update-uri
                registration.addEventListener('updatefound', function() {
                    const newWorker = registration.installing;
                    console.log('[PWA] Nou Service Worker găsit...');

                    newWorker.addEventListener('statechange', function() {
                        if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                            console.log('[PWA] Update disponibil!');
                            // Afișează notificare pentru update
                            if (confirm('Este disponibilă o versiune nouă. Reîncarcă pagina?')) {
                                window.location.reload();
                            }
                        }
                    });
                });

                // Inițializează Offline Sync dacă e disponibil
                if (typeof OfflineSync !== 'undefined') {
                    await OfflineSync.init();
                    console.log('[PWA] OfflineSync inițializat');

                    // Setează colecția curentă
                    if (PWA_CONFIG.colectieId) {
                        await IDBManager.Meta.setCurrentColectie(PWA_CONFIG.colectieId);
                    }
                    if (PWA_CONFIG.userId) {
                        await IDBManager.Meta.setCurrentUser(PWA_CONFIG.userId);
                    }

                    // Event listeners pentru sync
                    OfflineSync.on('syncStart', function() {
                        console.log('[PWA] Sincronizare începută...');
                        updateSyncIndicator('syncing', 'Sincronizare...');
                    });

                    OfflineSync.on('syncComplete', function(data) {
                        console.log('[PWA] Sincronizare completă:', data);
                        updateSyncIndicator('online', 'Sincronizat');
                        setTimeout(() => {
                            const indicator = document.getElementById('pwa-sync-indicator');
                            if (indicator) indicator.style.display = 'none';
                        }, 3000);
                    });

                    OfflineSync.on('syncError', function(error) {
                        console.error('[PWA] Eroare sincronizare:', error);
                        updateSyncIndicator('error', 'Eroare sync');
                    });

                    OfflineSync.on('offline', function() {
                        updateSyncIndicator('offline', 'Offline');
                        document.getElementById('pwa-sync-indicator').style.display = 'flex';
                    });

                    OfflineSync.on('online', function() {
                        updateSyncIndicator('online', 'Online');
                        // Sincronizează când revine conexiunea
                        OfflineSync.syncFromServer(PWA_CONFIG.colectieId);
                    });

                    // Afișează status inițial
                    const status = await OfflineSync.getStatus();
                    console.log('[PWA] Status:', status);
                }

                // Faza 3: Inițializează Offline Operations
                if (typeof OfflineOperations !== 'undefined') {
                    OfflineOperations.init();
                    console.log('[PWA] OfflineOperations inițializat');

                    // Ascultă mesaje de la Service Worker (Background Sync)
                    navigator.serviceWorker.addEventListener('message', async function(event) {
                        if (event.data && event.data.type === 'SYNC_REQUESTED') {
                            console.log('[PWA] Background Sync solicitat de SW');
                            // Procesează queue-ul de operații
                            await OfflineOperations.processQueue();
                        }
                    });

                    // Procesează queue când revine online
                    OfflineOperations.on('syncComplete', function(data) {
                        console.log('[PWA] Operații sincronizate:', data);
                        // Reîncarcă datele fresh de pe server
                        if (data.processed > 0 && typeof OfflineSync !== 'undefined') {
                            OfflineSync.syncFromServer(PWA_CONFIG.colectieId);
                        }
                    });
                }

            } catch (error) {
                console.warn('[PWA] Eroare:', error);
            }

            // Indicator status conexiune (backup dacă OfflineSync nu e disponibil)
            function updateOnlineStatus() {
                const isOnline = navigator.onLine;
                document.body.classList.toggle('offline-mode', !isOnline);

                if (!isOnline) {
                    console.log('[PWA] Mod offline activat');
                    updateSyncIndicator('offline', 'Offline');
                    const indicator = document.getElementById('pwa-sync-indicator');
                    if (indicator) indicator.style.display = 'flex';
                }
            }

            window.addEventListener('online', updateOnlineStatus);
            window.addEventListener('offline', updateOnlineStatus);

            // Creează indicator și verifică status
            createSyncIndicator();
            updateOnlineStatus();
        });
    }
})();
</script>

</body>
</html>