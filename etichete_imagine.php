<?php
// etichete_imagine.php - Editare interactivă a obiectelor într-o imagine
session_start();
include 'config.php';

// Verifică autentificarea pentru sistemul multi-tenant
if (file_exists('includes/auth_functions.php')) {
    require_once 'includes/auth_functions.php';

    $user = checkSession();
    if (!$user) {
        die("Neautorizat");
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
            // Folosim ID-ul proprietarului colecției pentru calea imaginilor
            $colectie_proprietar_id = $row_prefix['proprietar_id'];
            $_SESSION['tip_acces_colectie'] = $row_prefix['tip_acces'] ?? 'proprietar';

            // Dacă este o colecție partajată, reconectăm la baza de date a proprietarului
            if ($colectie_proprietar_id != $user['id_utilizator']) {
                // Obținem informațiile despre proprietar
                $sql_owner = "SELECT db_name FROM utilizatori WHERE id_utilizator = ?";
                $stmt_owner = mysqli_prepare($conn_central, $sql_owner);
                mysqli_stmt_bind_param($stmt_owner, "i", $colectie_proprietar_id);
                mysqli_stmt_execute($stmt_owner);
                $result_owner = mysqli_stmt_get_result($stmt_owner);

                if ($row_owner = mysqli_fetch_assoc($result_owner)) {
                    mysqli_close($conn);
                    $conn = getUserDbConnection($row_owner['db_name']);
                }
                mysqli_stmt_close($stmt_owner);
            }
            error_log("etichete_imagine.php - Folosesc prefix: $table_prefix pentru colecția $id_colectie");
        } else {
            $table_prefix = $user['prefix_tabele'] ?? 'user_' . $user['id_utilizator'] . '_';
            $colectie_proprietar_id = $user['id_utilizator'];
            $_SESSION['tip_acces_colectie'] = 'proprietar';
        }
        mysqli_stmt_close($stmt_prefix);
        mysqli_close($conn_central);
    } else {
        // Folosim valorile din sesiune dacă există
        $table_prefix = $_SESSION['prefix_tabele'] ?? $user['prefix_tabele'] ?? 'user_' . $user['id_utilizator'] . '_';
        $colectie_proprietar_id = $_SESSION['colectie_proprietar_id'] ?? $user['id_utilizator'];
        $_SESSION['tip_acces_colectie'] = $_SESSION['tip_acces_colectie'] ?? 'proprietar';
    }

    // Folosim ID-ul proprietarului colecției pentru calea imaginilor
    $user_id = $colectie_proprietar_id ?? $user['id_utilizator'];
} else {
    $table_prefix = $GLOBALS['table_prefix'] ?? '';
    $user_id = getCurrentUserId();
}

$id_obiect = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($id_obiect > 0) {
    $sql = "SELECT * FROM {$table_prefix}obiecte WHERE id_obiect = ? AND imagine IS NOT NULL LIMIT 1";
    error_log("DEBUG SQL: $sql cu ID=$id_obiect");
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'i', $id_obiect);
    mysqli_stmt_execute($stmt);
    $rezultat = mysqli_stmt_get_result($stmt);
    $obiect = mysqli_fetch_assoc($rezultat);
    mysqli_stmt_close($stmt);
    
    if ($obiect) {
        error_log("DEBUG: Obiect găsit cu ID=" . $obiect['id_obiect']);
    }
} else {
    $sql = "SELECT * FROM {$table_prefix}obiecte WHERE imagine IS NOT NULL ORDER BY data_upload DESC LIMIT 1";
    $rezultat = mysqli_query($conn, $sql);
    $obiect = mysqli_fetch_assoc($rezultat);
}

if (!$obiect) {
    // Log pentru debug
    error_log("DEBUG: Nu s-a găsit obiectul cu ID=$id_obiect în tabela {$table_prefix}obiecte");
    die("Nu s-a găsit nicio imagine de obiect. ID căutat: $id_obiect, Prefix tabel: $table_prefix");
}

// Verificăm dacă există parametrul imagine în URL pentru afișare
if (isset($_GET['imagine']) && !empty($_GET['imagine'])) {
    // Folosim imaginea specificată în URL pentru afișare
    $imagine_selectata = $_GET['imagine'];
} else {
    // Altfel, folosim prima imagine din baza de date
    $imagini = explode(',', $obiect['imagine']);
    $imagine_selectata = trim($imagini[0]);
}

// Calea către imaginea care va fi afișată - nu aplicăm htmlspecialchars aici
$cale_imagine = 'imagini_obiecte/user_' . $user_id . '/' . $imagine_selectata;

// Determinăm poziția (indexul) imaginii curente în șirul de imagini
$imagini = explode(',', $obiect['imagine']);
$pozitie_imagine_curenta = 0;
foreach ($imagini as $index => $imagine) {
    if (trim($imagine) === trim($imagine_selectata)) {
        $pozitie_imagine_curenta = $index + 1; // Indexăm de la 1, nu de la 0
        break;
    }
}

// Determinăm numărul total de imagini și obținem un array cu toate imaginile pentru navigare
$toate_imaginile = [];
if (!empty($obiect['imagine'])) {
    $toate_imaginile = array_map('trim', explode(',', $obiect['imagine']));
}
$numar_total_imagini = count($toate_imaginile);

// Găsim index-ul curent în array-ul de imagini pentru a ști care este imaginea anterioară/următoare
$index_imagine_curenta = array_search(trim($imagine_selectata), $toate_imaginile);

// Determinăm index-urile pentru imaginea anterioară și următoare
$index_imagine_anterioara = ($index_imagine_curenta > 0) ? $index_imagine_curenta - 1 : $numar_total_imagini - 1;
$index_imagine_urmatoare = ($index_imagine_curenta < $numar_total_imagini - 1) ? $index_imagine_curenta + 1 : 0;

// Obținem numele imaginilor pentru navigare
$imagine_anterioara = $toate_imaginile[$index_imagine_anterioara];
$imagine_urmatoare = $toate_imaginile[$index_imagine_urmatoare];

// Reconstruim corect etichetele, luând în considerare punctul și virgula ca separator între etichete
$etichete_obiect = [];
$denumiri_obiect = [];
if (!empty($obiect['eticheta_obiect']) && !empty($obiect['denumire_obiect'])) {
    // Separate datele brute folosind punct și virgulă pentru etichete și virgulă pentru denumiri
    $etichete_brut = array_map('trim', explode(';', $obiect['eticheta_obiect']));
    $denumiri_brut = array_map('trim', explode(',', $obiect['denumire_obiect']));

    // Nu mai este nevoie de reconstrucția etichetelor, deoarece separatorul ;
    // nu va cauza fragmentarea etichetelor cu coordonate
    $etichete_obiect = $etichete_brut;
    $denumiri_obiect = $denumiri_brut;

    // 3. Asigurăm că avem cel mult atâtea etichete câte denumiri avem
    $etichete_obiect = array_slice($etichete_obiect, 0, count($denumiri_obiect));
}

// Obținem cantitățile obiectelor
$cantitati_obiect = [];
if (!empty($obiect['cantitate_obiect'])) {
    $cantitati_obiect = array_map('trim', explode(',', $obiect['cantitate_obiect']));
    // Asigurăm că avem cel mult atâtea cantități câte denumiri avem
    $cantitati_obiect = array_slice($cantitati_obiect, 0, count($denumiri_obiect));
}
?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <title>Editează Obiecte</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/style-telefon.css" media="only screen and (max-width: 768px)">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.12/cropper.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.12/cropper.min.js"></script>
    <style>
        .eticheta.hidden, .obiect-salvat.hidden {
            display: none !important;
        }

        /* Stilizare pentru containerul imaginii cu poziție relativă pentru plasarea săgeților */
        .container-img {
            position: relative;
            overflow: visible; /* Schimbat din hidden pentru a permite afișarea etichetelor Vision */
        }

        /* Stiluri pentru butoanele de navigare */
        .navigare-img {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            width: 40px;
            height: 40px;
            background-color: rgba(0, 0, 0, 0.4);
            color: white;
            border-radius: 50%;
            display: flex;
            justify-content: center;
            align-items: center;
            cursor: pointer;
            font-size: 20px;
            z-index: 100;
            transition: background-color 0.3s, opacity 0.3s;
            opacity: 0.7;
            border: none;
            outline: none;
        }

        .navigare-img:hover {
            background-color: rgba(0, 0, 0, 0.6);
            opacity: 1;
        }

        .navigare-img-anterior {
            left: 15px;
        }

        .navigare-img-urmator {
            right: 15px;
        }

        /* Stilizare pentru indicator poziție imagine */
        .indicator-pozitie {
            position: absolute;
            bottom: 10px;
            left: 50%;
            transform: translateX(-50%);
            background-color: rgba(0, 0, 0, 0.4);
            color: white;
            border-radius: 15px;
            padding: 5px 10px;
            font-size: 12px;
            z-index: 100;
            opacity: 0.8;
        }

        /* Adaptare pentru dispozitive mobile - butoane mai mari */
        @media only screen and (max-width: 768px) {
            .navigare-img {
                width: 50px;
                height: 50px;
                font-size: 24px;
            }

            .navigare-img-anterior {
                left: 10px;
            }

            .navigare-img-urmator {
                right: 10px;
            }
        }

        /* Stiluri pentru iconul de decupare */
        .crop-toggle {
            position: absolute;
            top: 10px;
            right: 10px;
            width: 30px;
            height: 30px;
            border-radius: 4px;
            background-color: #e0e0e0; /* Gri deschis - inactiv */
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background-color 0.3s ease;
            color: #555;
        }

        .crop-toggle.active {
            background-color: #2196F3; /* Albastru - activ */
            color: white;
        }

        .crop-toggle svg {
            width: 20px;
            height: 20px;
        }

        /* Stiluri pentru butonul Vision */
        .vision-toggle {
            position: absolute;
            top: 10px;
            right: 50px; /* Plasat lângă butonul de decupare */
            width: 30px;
            height: 30px;
            border-radius: 4px;
            background-color: #ff6600; /* Portocaliu ca obiectele GV */
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background-color 0.3s ease;
            color: white;
        }

        .vision-toggle:hover {
            background-color: #e55a00; /* Portocaliu mai închis la hover */
            color: white;
        }

        .vision-toggle.processing {
            background-color: #FF9800; /* Portocaliu în timpul procesării */
            color: white;
            animation: pulse 2s infinite;
        }

        .vision-toggle svg {
            width: 20px;
            height: 20px;
        }

        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
        }

        /* Ajustare pentru titlul paginii pentru a permite poziționarea relativă a iconului */
        .titlu-pagina {
            position: relative;
            padding-right: 40px; /* Spațiu pentru icon */
        }

        /* Stiluri pentru interfața de crop */
        .crop-container {
            position: fixed; /* Rămâne fixed pentru a acoperi tot ecranul */
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.8);
            z-index: 1000;
            display: none;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .crop-area {
            max-width: 90%;
            max-height: 70vh;
            position: relative; /* Adăugat pentru a permite poziționarea relativă */
        }

        .crop-actions {
            display: flex;
            gap: 10px;
        }

        .crop-button {
            width: 40px;
            height: 40px;
            border: none;
            border-radius: 20px;
            cursor: pointer;
            font-weight: bold;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px; /* Mărește dimensiunea fontului */
        }

        .crop-cancel {
            background-color: #f44336;
        }

        .crop-apply {
            background-color: #4CAF50;
        }

        /* Modal pentru mesaje Vision */
        .vision-modal {
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

        .vision-modal-content {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            max-width: 500px;
            width: 90%;
            text-align: center;
            animation: slideIn 0.3s ease;
        }

        .vision-modal-icon {
            font-size: 60px;
            margin-bottom: 20px;
        }

        .vision-modal-icon.success {
            color: #ff6600; /* Portocaliu ca obiectele GV */
        }

        .vision-modal h3 {
            margin: 0 0 20px 0;
            color: #333;
            font-size: 24px;
        }

        .vision-modal-stats {
            display: flex;
            justify-content: space-around;
            margin: 20px 0;
            padding: 20px;
            background: #f5f5f5;
            border-radius: 10px;
        }

        .vision-modal-stat {
            text-align: center;
        }

        .vision-modal-stat-number {
            font-size: 36px;
            font-weight: bold;
            color: #ff6600; /* Portocaliu ca obiectele GV */
            display: block;
        }

        .vision-modal-stat-label {
            font-size: 14px;
            color: #666;
            margin-top: 5px;
        }

        .vision-modal-button {
            background: #ff6600; /* Portocaliu ca obiectele GV */
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 25px;
            font-size: 16px;
            cursor: pointer;
            margin-top: 20px;
            transition: all 0.3s ease;
        }

        .vision-modal-button:hover {
            background: #e55a00; /* Portocaliu mai închis */
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(255, 102, 0, 0.3);
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

        /* Modal pentru confirmare Vision */
        .vision-confirm-modal {
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

        .vision-confirm-content {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            max-width: 450px;
            width: 90%;
            text-align: center;
            animation: slideIn 0.3s ease;
        }

        .vision-confirm-icon {
            font-size: 50px;
            color: #ff6600; /* Portocaliu ca obiectele GV */
            margin-bottom: 20px;
        }

        .vision-confirm-title {
            margin: 0 0 15px 0;
            color: #333;
            font-size: 22px;
        }

        .vision-confirm-message {
            color: #666;
            margin-bottom: 25px;
            line-height: 1.5;
        }

        .vision-confirm-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
        }

        .vision-confirm-btn {
            padding: 12px 30px;
            border: none;
            border-radius: 25px;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
        }

        .vision-confirm-btn-primary {
            background: #ff6600; /* Portocaliu ca obiectele GV */
            color: white;
        }

        .vision-confirm-btn-primary:hover {
            background: #e55a00; /* Portocaliu mai închis */
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(255, 102, 0, 0.3);
        }

        .vision-confirm-btn-secondary {
            background: #e0e0e0;
            color: #666;
        }

        .vision-confirm-btn-secondary:hover {
            background: #d0d0d0;
        }

        .crop-rotate-left, .crop-rotate-right {
            background-color: #2196F3;
        }
    </style>
</head>
<body>
<nav class="navbar">
    <a href="index.php?id_obiect=<?php echo (int)$obiect['id_obiect']; ?>&imagine=<?php echo urlencode($imagine_selectata); ?>" class="modern-link">🔙 Listă Obiecte</a>
</nav>

<!-- Adăugăm bara de căutare -->
<div class="search-bar">
    <input
            type="text"
            id="campCautare"
            placeholder="Caută etichete..."
            oninput="filtreazaEtichete(this.value)"
    >
</div>

<div class="container">
    <style>
        .titlu-pagina {
            text-align: left;
        }
        .titlu-pagina .linie-principala,
        .titlu-pagina .linie-secundara {
            display: block;
            font-size: 0.8em;
            font-weight: normal;
            margin-left: 15px;
        }
        .titlu-pagina .indicator {
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
        .titlu-pagina .indicator .bar-top {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            background-color: #333;
        }
        .titlu-pagina .indicator .grid {
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
        .titlu-pagina .linie-secundara strong {
            color: #666666;
        }
    </style>

    <h2 class="titlu-pagina">
        <!-- prima linie: locaţia -->
        <span class="linie-principala">
            <?php echo htmlspecialchars($obiect['locatie']); ?>,
        </span>

        <!-- a doua linie: indicator + Cutie -->
        <span class="linie-secundara">
            <span class="indicator">
                <span class="bar-top"></span>
                <span class="grid"></span>
            </span>
            <strong>Cutie</strong>: <?php echo htmlspecialchars($obiect['cutie']); ?>
        </span>

        <?php if ($_SESSION['tip_acces_colectie'] == 'proprietar' || $_SESSION['tip_acces_colectie'] == 'scriere'): ?>
            <!-- Adăugăm iconul pentru decupare -->
            <span class="crop-toggle" id="cropToggle" title="Activează/dezactivează decuparea obiectelor">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                <line x1="3" y1="9" x2="21" y2="9"></line>
                <line x1="3" y1="15" x2="21" y2="15"></line>
                <line x1="9" y1="3" x2="9" y2="21"></line>
                <line x1="15" y1="3" x2="15" y2="21"></line>
            </svg>
        </span>

            <!-- Buton pentru Google Vision API -->
            <span class="vision-toggle" id="visionToggle" title="Analizează toate imaginile din această cutie cu Google Vision">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="3"></circle>
                <path d="M12 1v6m0 6v6m-9-9h6m6 0h6"></path>
                <path d="M20.88 18.09A7 7 0 0 0 19 12h1a8 8 0 0 1-7 7.93"></path>
                <path d="M3.12 18.09A7 7 0 0 1 5 12H4a8 8 0 0 0 7 7.93"></path>
                <path d="M20.88 5.91A7 7 0 0 1 19 12h1a8 8 0 0 0-7-7.93"></path>
                <path d="M3.12 5.91A7 7 0 0 0 5 12H4a8 8 0 0 1 7-7.93"></path>
            </svg>
        </span>
        <?php else: ?>
            <!-- Mesaj pentru utilizatori fără permisiuni de editare -->
            <span style="color: #666; font-size: 12px; margin-left: 20px;">
            (Mod vizualizare)
        </span>
        <?php endif; ?>
    </h2>

    <div id="statusCautare" style="text-align: center; margin-bottom: 20px; font-weight: bold;"></div>

    <div class="select-categorie-wrapper">
        <label for="select-categorie">Categorie:</label>
        <select id="select-categorie" class="rotunjit"></select>
    </div>

    <div class="eticheta-pad" id="listaObiecte">
        <?php
        if (!empty($denumiri_obiect)) {
            foreach ($denumiri_obiect as $index => $nume) {
                if (!isset($etichete_obiect[$index])) continue;

                $culoare = $etichete_obiect[$index];

                // Extragem doar codul culorii dacă conține coordonate
                $culoareCurata = $culoare;
                if (preg_match('/^(#[0-9a-fA-F]{6})\s*\(/', $culoare, $matches)) {
                    $culoareCurata = $matches[1];
                }

                // Afișăm doar etichetele cu culoarea #ccc în lista de deasupra imaginii
                if (strtolower($culoareCurata) !== '#ccc') continue;

                // Extragem numărul imaginii din denumire dacă există
                $numeAfisare = $nume;
                $indexImagine = 0; // Valoare implicită
                if (preg_match('/^(.+?)\((\d+)\)$/', $nume, $matches)) {
                    $numeAfisare = $matches[1];
                    $indexImagine = (int)$matches[2];
                }

                // VERIFICARE STRICTĂ: Afișăm doar dacă indexul imaginii este 0 (global)
                // sau corespunde exact cu poziția imaginii curente
                if ($indexImagine !== 0 && $indexImagine !== $pozitie_imagine_curenta) continue;

                $textColor = hexdec(substr($culoareCurata, 1, 2)) * 0.299 + hexdec(substr($culoareCurata, 3, 2)) * 0.587 + hexdec(substr($culoareCurata, 5, 2)) * 0.114 > 186 ? '#000' : '#fff';

                // Obținem cantitatea asociată
                $cantitate = isset($cantitati_obiect[$index]) ? $cantitati_obiect[$index] : '1';

                echo "<span class='obiect-salvat rotunjit' style='background-color: $culoareCurata; color: $textColor;' data-img-index='$indexImagine' data-cantitate='$cantitate' data-index='$index'>" . htmlspecialchars(trim($numeAfisare)) . "</span> ";
            }
        }
        ?>
    </div>

    <div class="container-img" id="zonaImagine"
         data-cutie="<?php echo htmlspecialchars($obiect['cutie']); ?>"
         data-locatie="<?php echo htmlspecialchars($obiect['locatie']); ?>"
         data-descriere="<?php echo htmlspecialchars($obiect['descriere_categorie'] ?? ''); ?>"
         data-imagine="<?php echo addslashes($obiect['imagine']); ?>"
         data-imagine-curenta="<?php echo addslashes($imagine_selectata); ?>"
         data-pozitie-imagine="<?php echo $pozitie_imagine_curenta; ?>"
         data-id="<?php echo (int) $obiect['id_obiect']; ?>">
        <img id="imagine-obiect" src="<?php echo htmlspecialchars($cale_imagine); ?>" alt="Imagine obiect">
        <div id="tooltip" class="tooltip"></div>

        <!-- Adăugăm butoanele de navigare discrete pe marginile imaginii -->
        <button class="navigare-img navigare-img-anterior" id="butonAnterior" data-imagine="<?php echo htmlspecialchars($imagine_anterioara); ?>">&lt;</button>
        <button class="navigare-img navigare-img-urmator" id="butonUrmator" data-imagine="<?php echo htmlspecialchars($imagine_urmatoare); ?>">&gt;</button>

        <!-- Indicator pentru poziția imaginii -->
        <div class="indicator-pozitie"><?php echo ($index_imagine_curenta + 1); ?> / <?php echo $numar_total_imagini; ?></div>
    </div>

    <!-- Container pentru fereastra de crop -->
    <div class="crop-container" id="cropContainer">
        <div class="crop-area">
            <img id="cropImage" src="">
        </div>
        <div class="crop-actions">
            <button class="crop-button crop-rotate-left" id="cropRotateLeft" title="Rotire stânga">↺</button>
            <button class="crop-button crop-rotate-right" id="cropRotateRight" title="Rotire dreapta">↻</button>
            <button class="crop-button crop-cancel" id="cropCancel" title="Anulează">✕</button>
            <button class="crop-button crop-apply" id="cropApply" title="Salvează Obiect">✓</button>
        </div>
    </div>
</div>

<!-- Modal pentru confirmare Vision -->
<div class="vision-confirm-modal" id="visionConfirmModal">
    <div class="vision-confirm-content">
        <div class="vision-confirm-icon">🤖</div>
        <h3 class="vision-confirm-title">Analiză inteligentă cu Google Vision</h3>
        <p class="vision-confirm-message">
            Doriți să analizați automat toate imaginile din cutia <strong id="confirmCutie"></strong>?<br><br>
            <small style="color: #999;">⏱️ Această operație poate dura câteva minute, în funcție de numărul de imagini.</small>
        </p>
        <div class="vision-confirm-buttons">
            <button class="vision-confirm-btn vision-confirm-btn-primary" onclick="startVisionProcessing()">
                Da, analizează automat
            </button>
            <button class="vision-confirm-btn vision-confirm-btn-secondary" onclick="closeVisionConfirm()">
                Anulează
            </button>
        </div>
    </div>
</div>

<!-- Modal pentru rezultate Vision -->
<div class="vision-modal" id="visionModal">
    <div class="vision-modal-content">
        <div class="vision-modal-icon success">✨</div>
        <h3>Identificare automată completă!</h3>
        <p style="color: #666; margin-bottom: 20px;">Inventar.live a analizat imaginile folosind inteligența artificială</p>

        <div class="vision-modal-stats">
            <div class="vision-modal-stat">
                <span class="vision-modal-stat-number" id="visionImages">0</span>
                <span class="vision-modal-stat-label">Imagini procesate</span>
            </div>
            <div class="vision-modal-stat">
                <span class="vision-modal-stat-number" id="visionDetected">0</span>
                <span class="vision-modal-stat-label">Obiecte detectate</span>
            </div>
            <div class="vision-modal-stat">
                <span class="vision-modal-stat-number" id="visionTypes">0</span>
                <span class="vision-modal-stat-label">Tipuri unice</span>
            </div>
        </div>

        <div class="vision-modal-details" id="visionDetails" style="margin-top: 15px; font-size: 14px; color: #666; max-height: 150px; overflow-y: auto;">
        </div>

        <p style="color: #999; font-size: 14px;" id="visionModalFooterMessage">Pagina se va reîmprospăta pentru a afișa noile etichete</p>

        <button class="vision-modal-button" onclick="closeVisionModal()">Închide</button>
    </div>
</div>

<!-- Modal pentru debug imagini procesate -->
<div class="vision-modal" id="visionDebugModal">
    <div class="vision-modal-content" style="max-width: 800px;">
        <div class="vision-modal-icon success">🔍</div>
        <h3>Imagini procesate de Google Vision</h3>
        <div id="visionDebugContent" style="text-align: left; max-height: 400px; overflow-y: auto; font-family: monospace; font-size: 12px;">
            <!-- Aici vor fi afișate imaginile procesate -->
        </div>
        <button class="vision-modal-button" onclick="closeVisionDebugModal()">Închide</button>
    </div>
</div>

<!-- Modal pentru informații detectare cărți -->
<div class="vision-modal" id="cartiInfoModal">
    <div class="vision-modal-content" style="max-width: 600px; max-height: 80vh; display: flex; flex-direction: column;">
        <div class="vision-modal-icon success">📚</div>
        <h3>Informații Detectare Cărți</h3>
        <div id="cartiInfoContent" style="text-align: left; padding: 20px; overflow-y: auto; max-height: 60vh;">
            <!-- Aici vor fi afișate informațiile despre cărți -->
        </div>
        <button class="vision-modal-button" onclick="closeCartiInfoModal()">Închide</button>
    </div>
</div>

<!-- Modal pentru Hartă Text -->
<div class="vision-modal" id="hartaTextModal" style="display: none;">
    <div class="vision-modal-content" style="max-width: 90%; max-height: 90vh; overflow: auto;">
        <div class="vision-modal-icon success">🗺️</div>
        <h3>Hartă Text - Vizualizare Cuvinte Detectate</h3>
        <p style="color: #666; margin-bottom: 10px;">📌 Harta rămâne deschisă pentru analiză. Folosiți butoanele de mai jos pentru control și navigare.</p>
        
        <div style="display: flex; gap: 10px; margin-bottom: 10px; flex-wrap: wrap; justify-content: center;">
            <button class="vision-modal-button" style="padding: 8px 15px; font-size: 14px;" onclick="toggleHartaGrid()">📊 Grid On/Off</button>
            <button class="vision-modal-button" style="padding: 8px 15px; font-size: 14px;" onclick="toggleHartaCorners()">📍 Colțuri On/Off</button>
            <button class="vision-modal-button" style="padding: 8px 15px; font-size: 14px;" onclick="zoomHartaIn()">🔍 Zoom +</button>
            <button class="vision-modal-button" style="padding: 8px 15px; font-size: 14px;" onclick="zoomHartaOut()">🔍 Zoom -</button>
        </div>
        
        <div style="border: 2px solid #ddd; background: white; overflow: auto; max-height: 60vh; position: relative;">
            <canvas id="hartaTextCanvas" style="display: block; cursor: crosshair;"></canvas>
        </div>
        
        <div style="margin-top: 10px; font-size: 12px; color: #666; text-align: center;">
            <span id="hartaStats"></span>
        </div>
        
        <div style="display: flex; gap: 10px; margin-top: 15px; justify-content: center;">
            <button class="vision-modal-button" style="background: #ff6600;" onclick="inapoiLaInfoCarti()">← Înapoi la Info Cărți</button>
            <button class="vision-modal-button" style="background: #dc3545;" onclick="closeHartaTextModal()">✖ Închide Tot</button>
        </div>
    </div>
</div>

<script>
    // Variabile globale pentru Vision
    let visionCutie = '';
    let visionLocatie = '';
    let visionToggleBtn = null;

    // Funcție pentru închiderea modalului debug
    function closeVisionDebugModal() {
        document.getElementById('visionDebugModal').style.display = 'none';
    }
    
    // Funcție pentru închiderea modalului info cărți
    function closeCartiInfoModal() {
        document.getElementById('cartiInfoModal').style.display = 'none';
    }
    
    // Funcție pentru afișarea info cărți
    function showCartiInfo() {
        document.getElementById('cartiInfoModal').style.display = 'flex';
    }

    // Funcție pentru închiderea modalului Vision
    function closeVisionModal() {
        document.getElementById('visionModal').style.display = 'none';
    }

    // Funcție pentru închiderea modalului de confirmare
    function closeVisionConfirm() {
        document.getElementById('visionConfirmModal').style.display = 'none';
    }

    // Funcție pentru pornirea procesării Vision
    function startVisionProcessing() {
        closeVisionConfirm();

        if (visionToggleBtn) {
            visionToggleBtn.classList.add('processing');
            visionToggleBtn.title = 'Procesare în curs...';
        }

        // Trimitem cererea pentru procesare
        const formData = new FormData();
        formData.append('action', 'procesare_cutie');
        formData.append('cutie', visionCutie);
        formData.append('locatie', visionLocatie);
        
        // Adăugăm ID-ul obiectului din URL
        const urlParams = new URLSearchParams(window.location.search);
        const idObiect = urlParams.get('id');
        // Facem idObiect disponibil global pentru alte funcții
        window.idObiectGlobal = idObiect;
        if (idObiect) {
            formData.append('id_obiect', idObiect);
        }

        // Adăugăm ID-ul colecției curente din URL
        const idColectie = urlParams.get('colectie');
        if (idColectie) {
            formData.append('id_colectie', idColectie);
        }

        fetch('procesare_cutie_vision.php', {
            method: 'POST',
            body: formData
        })
            .then(response => {
                return response.json();
            })
            .then(data => {
                console.log('Răspuns Vision primit:', data);

                if (visionToggleBtn) {
                    visionToggleBtn.classList.remove('processing');
                }

                if (data.success) {
                    const modal = document.getElementById('visionModal');
                    const modalIcon = modal.querySelector('.vision-modal-icon');
                    const modalTitle = modal.querySelector('h3');
                    const modalSubtitle = modal.querySelector('p');
                    const detailsDiv = document.getElementById('visionDetails');
                    const footerMessage = document.getElementById('visionModalFooterMessage');

                    // Verificăm tipul de detectare
                    if (data.tip === 'carti') {
                        // ═══════════════════════════════════════════════════════════
                        // AFIȘARE PENTRU CĂRȚI
                        // ═══════════════════════════════════════════════════════════
                        modalIcon.innerHTML = '📚';
                        modalTitle.textContent = 'Detectare cărți finalizată!';

                        // Subtitlu cu aranjament detectat
                        let aranjamentText = '';
                        if (data.aranjament === 'vertical') {
                            aranjamentText = ' (stivă verticală)';
                        } else if (data.aranjament === 'orizontal') {
                            aranjamentText = ' (alăturate orizontal)';
                        }
                        modalSubtitle.textContent = 'Am identificat automat cărțile din imagini' + aranjamentText;

                        // Statistici
                        document.getElementById('visionImages').textContent = data.imagini_procesate || 0;
                        document.getElementById('visionDetected').textContent = data.total_detectate || 0;
                        document.getElementById('visionTypes').textContent = data.aranjament ? data.aranjament.toUpperCase() : '-';

                        // Lista cărților detectate - numerotată
                        let detailsHTML = '<div style="margin-bottom: 15px;">';
                        detailsHTML += '<strong style="font-size: 14px;">📖 Cărți detectate:</strong>';
                        detailsHTML += '<div style="max-height: 200px; overflow-y: auto; margin-top: 10px; padding: 10px; background: #f8f9fa; border-radius: 5px;">';

                        if (data.lista_rezultate && data.lista_rezultate.length > 0) {
                            data.lista_rezultate.forEach((carte, index) => {
                                detailsHTML += `<div style="padding: 3px 0; border-bottom: 1px solid #eee;">${index + 1}. ${carte}</div>`;
                            });
                        } else {
                            detailsHTML += '<div style="color: #999;">Nu s-au putut identifica cărți din imagine.</div>';
                        }
                        detailsHTML += '</div></div>';

                        // MESAJ INFORMATIV PENTRU CĂRȚI
                        detailsHTML += '<div style="background: #e7f3ff; border: 1px solid #b6d4fe; border-radius: 5px; padding: 12px; margin-top: 10px;">';
                        detailsHTML += '<div style="font-weight: bold; color: #084298; margin-bottom: 5px;">ℹ️ Notă</div>';
                        detailsHTML += '<div style="font-size: 13px; color: #084298; line-height: 1.4;">';
                        detailsHTML += 'Algoritmul de identificare este în dezvoltare. Ordinea sau titlurile pot să nu fie 100% corecte. ';
                        detailsHTML += 'Rezultatele sunt utile pentru <strong>căutare ulterioară</strong> după cuvinte din titlu și identificarea locației în bibliotecă.';
                        detailsHTML += '</div></div>';

                        detailsDiv.innerHTML = detailsHTML;
                        detailsDiv.style.display = 'block';

                        // Footer message
                        if (footerMessage) {
                            footerMessage.textContent = 'Cărțile au fost adăugate în inventar.';
                            footerMessage.style.color = '#28a745';
                        }

                    } else {
                        // ═══════════════════════════════════════════════════════════
                        // AFIȘARE PENTRU OBIECTE
                        // ═══════════════════════════════════════════════════════════
                        modalIcon.innerHTML = '✨';
                        modalTitle.textContent = 'Identificare automată completă!';
                        modalSubtitle.textContent = 'Inventar.live a analizat imaginile folosind inteligența artificială';

                        // Statistici
                        document.getElementById('visionImages').textContent = data.imagini_procesate || 0;
                        document.getElementById('visionDetected').textContent = data.total_detectate || 0;

                        // Calculăm categorii unice (dacă există)
                        const categoriiUnice = data.lista_rezultate ? [...new Set(data.lista_rezultate)].length : 0;
                        document.getElementById('visionTypes').textContent = categoriiUnice;

                        // Lista obiectelor detectate
                        let detailsHTML = '<div style="margin-bottom: 10px;">';
                        detailsHTML += '<strong style="font-size: 14px;">🏷️ Obiecte detectate:</strong>';
                        detailsHTML += '<div style="max-height: 200px; overflow-y: auto; margin-top: 10px; padding: 10px; background: #f8f9fa; border-radius: 5px;">';

                        if (data.lista_rezultate && data.lista_rezultate.length > 0) {
                            data.lista_rezultate.forEach(obiect => {
                                detailsHTML += `<div style="padding: 3px 0; border-bottom: 1px solid #eee;">• ${obiect}</div>`;
                            });
                        } else {
                            detailsHTML += '<div style="color: #999;">Nu s-au detectat obiecte.</div>';
                        }
                        detailsHTML += '</div></div>';

                        detailsDiv.innerHTML = detailsHTML;
                        detailsDiv.style.display = 'block';

                        // Footer message
                        if (footerMessage) {
                            footerMessage.textContent = 'Obiectele au fost adăugate în inventar. Pagina se va reîmprospăta în 5 secunde...';
                            footerMessage.style.color = '#666';
                        }
                    }

                    // Afișăm modalul
                    modal.style.display = 'flex';

                    // Redirecționare doar pentru obiecte (nu pentru cărți)
                    if (data.tip !== 'carti') {
                        setTimeout(() => {
                            const urlParams = new URLSearchParams(window.location.search);
                            const idColectie = urlParams.get('colectie') || '';
                            let redirectUrl = 'index.php';
                            if (idColectie) {
                                redirectUrl += `?colectie=${idColectie}`;
                            }
                            window.location.href = redirectUrl;
                        }, 5000);
                    }
                } else {
                    alert(`Eroare: ${data.error || 'Eroare necunoscută'}`);
                }
            })
            .catch(error => {
                if (visionToggleBtn) {
                    visionToggleBtn.classList.remove('processing');
                }
                console.error('Eroare:', error);
                alert('Eroare la procesare. Verificați consola pentru detalii.');
            });
    }

    // Închide modalurile la click pe fundal
    document.getElementById('visionModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeVisionModal();
        }
    });

    document.getElementById('visionConfirmModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeVisionConfirm();
        }
    });

    document.getElementById('visionDebugModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeVisionDebugModal();
        }
    });

    (function(){
        const zona = document.getElementById('zonaImagine');
        const imgObiect = document.getElementById('imagine-obiect');
        const selectCategorie = document.getElementById('select-categorie');
        const listaObiecte = document.getElementById('listaObiecte');
        const tooltip = document.getElementById('tooltip');
        const butonAnterior = document.getElementById('butonAnterior');
        const butonUrmator = document.getElementById('butonUrmator');
        const indicatorPozitie = document.querySelector('.indicator-pozitie');

        // Variabile pentru crop
        let cropper = null;
        let inCropMode = false;
        let activeSelection = null;
        let cropModeEnabled = false; // Flag pentru modul de decupare
        const cropContainer = document.getElementById('cropContainer');
        const cropImage = document.getElementById('cropImage');
        const cropCancel = document.getElementById('cropCancel');
        const cropApply = document.getElementById('cropApply');
        const cropToggle = document.getElementById('cropToggle');
        const cropRotateLeft = document.getElementById('cropRotateLeft');
        const cropRotateRight = document.getElementById('cropRotateRight');
        const visionToggle = document.getElementById('visionToggle');

        let categoriiCulori = {};
        let skipNext = false;
        let filtruActiv = true; // Filtrul este activat din start
        // Poziția imaginii curente (în PHP este indexat de la 1)
        let pozitieImagineCurenta = parseInt(zona.dataset.pozitieImagine) || 1;
        let tooltipTimeout;


        // Detectare dispozitiv mobil
        const esteDispozitivMobil = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
        let tooltipTimerAscundere = null;
        let etichetaActiva = null;
        let ultimulClickTimestamp = 0;
        const DURATA_DUBLU_CLICK = 300; // milisecunde

        // Variabile pentru editare în tooltip
        let tooltipInModEditare = false;

        // Adăugăm variabilele globale pentru etichete și denumiri
        let eticheteGlobale = <?php echo json_encode($etichete_obiect); ?>;
        let denumiriGlobale = <?php echo json_encode($denumiri_obiect); ?>;
        let cantitati = <?php echo json_encode($cantitati_obiect); ?>;

        // Funcție pentru eliminarea diacriticelor din text
        function eliminaDiacritice(text) {
            if (!text) return text;

            const mapDiacritice = {
                'ă': 'a', 'â': 'a', 'î': 'i', 'ș': 's', 'ş': 's', 'ț': 't', 'ţ': 't',
                'Ă': 'A', 'Â': 'A', 'Î': 'I', 'Ș': 'S', 'Ş': 'S', 'Ț': 'T', 'Ţ': 'T',
                'é': 'e', 'è': 'e', 'ê': 'e', 'ë': 'e', 'É': 'E', 'È': 'E', 'Ê': 'E', 'Ë': 'E',
                'á': 'a', 'à': 'a', 'â': 'a', 'ä': 'a', 'Á': 'A', 'À': 'A', 'Â': 'A', 'Ä': 'A',
                'í': 'i', 'ì': 'i', 'î': 'i', 'ï': 'i', 'Í': 'I', 'Ì': 'I', 'Î': 'I', 'Ï': 'I',
                'ó': 'o', 'ò': 'o', 'ô': 'o', 'ö': 'o', 'Ó': 'O', 'Ò': 'O', 'Ô': 'O', 'Ö': 'O',
                'ú': 'u', 'ù': 'u', 'û': 'u', 'ü': 'u', 'Ú': 'U', 'Ù': 'U', 'Û': 'U', 'Ü': 'U',
                'ç': 'c', 'Ç': 'C', 'ñ': 'n', 'Ñ': 'N'
            };

            return text.replace(/[ăâîșşțţĂÂÎȘŞȚŢéèêëÉÈÊËáàâäÁÀÂÄíìîïÍÌÎÏóòôöÓÒÔÖúùûüÚÙÛÜçÇñÑ]/g,
                function(match) {
                    return mapDiacritice[match] || match;
                });
        }

        // Event listener pentru butonul de toggle decupare
        if (cropToggle) {
            cropToggle.addEventListener('click', function() {
                cropModeEnabled = !cropModeEnabled; // Comutăm starea

                if (cropModeEnabled) {
                    cropToggle.classList.add('active');
                    cropToggle.title = 'Dezactivează decuparea obiectelor';
                } else {
                    cropToggle.classList.remove('active');
                    cropToggle.title = 'Activează decuparea obiectelor';
                }
            });
        }

        // Event listener pentru butonul Google Vision
        if (visionToggle) {
            visionToggle.addEventListener('click', function() {
                const cutie = zona.dataset.cutie;
                const locatie = zona.dataset.locatie;

                // Salvăm datele în variabilele globale
                visionCutie = cutie;
                visionLocatie = locatie;
                visionToggleBtn = visionToggle;

                // Afișăm modalul de confirmare
                document.getElementById('confirmCutie').textContent = cutie;
                document.getElementById('visionConfirmModal').style.display = 'flex';
            });
        }

        // Event listeners pentru butoanele de rotire cu pas de 5 grade
        if (cropRotateLeft) {
            cropRotateLeft.addEventListener('click', function() {
                if (cropper) cropper.rotate(-5);
            });
        }

        if (cropRotateRight) {
            cropRotateRight.addEventListener('click', function() {
                if (cropper) cropper.rotate(5);
            });
        }

        // Event listeners pentru butoanele de crop
        if (cropCancel) {
            cropCancel.addEventListener('click', closeCropInterface);
        }
        if (cropApply) {
            cropApply.addEventListener('click', applyCrop);
        }

        let touchInceput = null;
        let touchFinal = null;
        const pragSwipe = 25; // pragul minim pentru a considera o acțiune ca swipe (în pixeli)

        // Detectarea evenimentelor de touch pentru swipe
        imgObiect.addEventListener('touchstart', function(e) {
            // Salvăm poziția inițială a atingerii
            touchInceput = e.touches[0].clientX;
        }, { passive: true });

        imgObiect.addEventListener('touchmove', function(e) {
            // Actualizăm poziția curentă a atingerii
            touchFinal = e.touches[0].clientX;
        }, { passive: true });

        imgObiect.addEventListener('touchend', function() {
            // Verificăm dacă avem ambele poziții de touch
            if (touchInceput === null || touchFinal === null) {
                // Resetăm pentru următoarea atingere
                touchInceput = null;
                touchFinal = null;
                return;
            }

            // Calculăm distanța de swipe
            const distantaSwipe = touchFinal - touchInceput;

            // Verificăm dacă distanța este mai mare decât pragul minim
            if (Math.abs(distantaSwipe) > pragSwipe) {
                if (distantaSwipe > 0) {
                    // Swipe la dreapta - imagine anterioară
                    butonAnterior.click();
                } else {
                    // Swipe la stânga - imagine următoare
                    butonUrmator.click();
                }
            }

            // Resetăm pentru următoarea atingere
            touchInceput = null;
            touchFinal = null;
        });

        // Adăugăm un indicator vizual pentru swipe doar pe mobile
        if (esteDispozitivMobil) {
            const indicatorSwipe = document.createElement('div');
            indicatorSwipe.className = 'indicator-swipe';
            indicatorSwipe.innerHTML = '<span>← Glisează →</span>';
            indicatorSwipe.style.position = 'absolute';
            indicatorSwipe.style.bottom = '40px';
            indicatorSwipe.style.left = '50%';
            indicatorSwipe.style.transform = 'translateX(-50%)';
            indicatorSwipe.style.backgroundColor = 'rgba(0, 0, 0, 0.5)';
            indicatorSwipe.style.color = 'white';
            indicatorSwipe.style.padding = '6px 12px';
            indicatorSwipe.style.borderRadius = '20px';
            indicatorSwipe.style.fontSize = '14px';
            indicatorSwipe.style.zIndex = '100';
            indicatorSwipe.style.opacity = '0.8';
            indicatorSwipe.style.transition = 'opacity 0.5s';

            // Adăugăm indicatorul în container
            zona.appendChild(indicatorSwipe);

            // Ascundem indicatorul după 3 secunde
            setTimeout(() => {
                indicatorSwipe.style.opacity = '0';
                // Eliminăm elementul după ce a dispărut complet
                setTimeout(() => {
                    if (zona.contains(indicatorSwipe)) {
                        zona.removeChild(indicatorSwipe);
                    }
                }, 500);
            }, 3000);
        }

        // Funcție pentru filtrarea etichetelor
        window.filtreazaEtichete = function(termen) {
            termen = termen.toLowerCase().trim();

            // Selectăm toate etichetele de pe imagine
            const eticheteImagine = document.querySelectorAll('.eticheta');
            // Selectăm toate etichetele din lista de deasupra
            const eticheteLista = document.querySelectorAll('.obiect-salvat');

            let contor = 0;

            // Filtrăm etichetele de pe imagine
            eticheteImagine.forEach(eticheta => {
                const textEticheta = eticheta.textContent.toLowerCase();
                const indexImagine = eticheta.getAttribute('data-img-index');
                const cantitate = eticheta.getAttribute('data-cantitate');

                // Construim șirul căutabil
                const continutCautabil = `${textEticheta} ${indexImagine} ${cantitate}`;
                const gasit = termen === '' || continutCautabil.includes(termen);

                if (gasit) {
                    eticheta.classList.remove('hidden');
                    contor++;
                } else {
                    eticheta.classList.add('hidden');
                }
            });

            // Filtrăm etichetele din lista de deasupra imaginii
            eticheteLista.forEach(eticheta => {
                const textEticheta = eticheta.textContent.toLowerCase();
                const indexImagine = eticheta.getAttribute('data-img-index');
                const cantitate = eticheta.getAttribute('data-cantitate');

                // Construim șirul căutabil
                const continutCautabil = `${textEticheta} ${indexImagine} ${cantitate}`;
                const gasit = termen === '' || continutCautabil.includes(termen);

                if (gasit) {
                    eticheta.classList.remove('hidden');
                } else {
                    eticheta.classList.add('hidden');
                }
            });

            // Actualizăm statusul căutării
            const statusElement = document.getElementById('statusCautare');
            statusElement.textContent = termen === '' ? '' : `${contor} etichete găsite pentru: "${termen}"`;
        };

        function culoareTextPotrivita(hex) {
            if (!hex || !hex.startsWith('#') || hex.length < 7) return '#fff';

            const r = parseInt(hex.slice(1,3),16);
            const g = parseInt(hex.slice(3,5),16);
            const b = parseInt(hex.slice(5,7),16);
            const lum = (r*299 + g*587 + b*114)/1000;
            return lum > 128 ? '#000' : '#fff';
        }

        // Funcția pentru extragerea culorii și coordonatelor din etichetă - îmbunătățită
        function parseEticheta(eticheta) {
            if (!eticheta || eticheta === '') {
                return { culoare: '#ccc', coordonate: null };
            }

            // Regex pentru a extrage culoarea și coordonatele
            const regex = /^(#[0-9a-fA-F]{6})\s*\(\s*(\d+)\s*,\s*(\d+)\s*\)/;
            const match = eticheta.match(regex);

            if (match && match.length === 4) {
                return {
                    culoare: match[1],
                    coordonate: {
                        x: parseInt(match[2]),
                        y: parseInt(match[3])
                    }
                };
            }

            // Verificare pentru a evita interpretarea unei culori simple ca o etichetă cu coordonate
            // Dacă eticheta este doar o culoare hex (ex: #ff0000), o returnăm ca atare
            if (eticheta.match(/^#[0-9a-fA-F]{6}$/)) {
                return {
                    culoare: eticheta,
                    coordonate: null
                };
            }

            // Alte cazuri neașteptate - nu aplicăm #ccc, ci păstrăm eticheta originală
            console.warn('Etichetă în format neașteptat:', eticheta);
            return {
                culoare: eticheta.startsWith('#') ? eticheta : '#ccc',
                coordonate: null
            };
        }

        // Funcție pentru extragerea indexului imaginii din denumire - CORECTATĂ
        function extrageIndexImagine(denumire) {
            const regex = /^(.+?)\((\d+)\)$/;
            const match = denumire.match(regex);

            if (match && match.length === 3) {
                return {
                    nume: match[1].trim(),
                    index: parseInt(match[2], 10) // Forțăm baza 10 pentru conversie
                };
            }

            return {
                nume: denumire,
                index: 0 // 0 înseamnă global/toate imaginile
            };
        }

        // Funcție pentru actualizarea cantității în baza de date - cu păstrarea asocierilor
        
        function actualizeazaCantitate(eticheta, nouaCantitate) {
            const indexElement = eticheta.getAttribute('data-index');
            if (!indexElement) return;

            const indexEticheta = parseInt(indexElement);
            const idObiect = zona.dataset.id;

            // Preluăm toate cantitățile curente
            const toateCantitățile = [...cantitati];
            toateCantitățile[indexEticheta] = nouaCantitate;

            // Creăm șirul complet de etichete pentru a păstra asocierile
            const eticheteActualizate = [...eticheteGlobale];

            // Trimitem cantitățile actualizate la server
            const form = new URLSearchParams();
            form.append('id', idObiect);
            form.append('camp', 'actualizare_cantitate');
            form.append('index_eticheta', indexEticheta);
            form.append('cantitate', nouaCantitate);
            form.append('cantitati', toateCantitățile.join(', '));
            form.append('pastrare_asocieri', 'true'); // Adăugăm flag-ul pentru păstrarea asocierilor
            form.append('etichete_obiect', eticheteActualizate.join('; ')); // Adăugăm etichetele complete

            // Adăugăm ID-ul colecției
            const urlParams = new URLSearchParams(window.location.search);
            const idColectie = urlParams.get('colectie');
            if (idColectie) {
                form.append('id_colectie', idColectie);
            }

            fetch('actualizeaza_obiect.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: form.toString()
            })
                .then(r => r.text())
                .then(msg => {

                    // Actualizăm cantitatea și în elementul DOM
                    eticheta.setAttribute('data-cantitate', nouaCantitate);

                    // Actualizăm array-ul de cantități
                    cantitati[indexEticheta] = nouaCantitate;

                    // MODIFICARE: Actualizăm toate etichetele care au același index
                    // pentru a sincroniza cantitatea între lista de obiecte și etichetele de pe imagine
                    const toateEtichetele = document.querySelectorAll(`.eticheta[data-index="${indexEticheta}"], .obiect-salvat[data-index="${indexEticheta}"]`);
                    toateEtichetele.forEach(e => {
                        e.setAttribute('data-cantitate', nouaCantitate);
                    });

                    // Afișăm un indicator de succes temporar
                    const parinteEticheta = eticheta.parentNode;
                    const checkMark = document.createElement('div');
                    checkMark.textContent = '✓';
                    checkMark.style.position = 'absolute';
                    checkMark.style.color = 'limegreen';
                    checkMark.style.fontWeight = 'bold';
                    checkMark.style.fontSize = '20px';

                    // Calculăm poziția indicatorului
                    const rect = eticheta.getBoundingClientRect();
                    const containerRect = parinteEticheta.getBoundingClientRect();
                    const x = rect.left + rect.width/2 - containerRect.left;
                    const y = rect.top - containerRect.top - 20;

                    checkMark.style.left = x + 'px';
                    checkMark.style.top = y + 'px';

                    parinteEticheta.appendChild(checkMark);

                    setTimeout(() => {
                        if (parinteEticheta.contains(checkMark)) {
                            parinteEticheta.removeChild(checkMark);
                        }
                    }, 1500);
                })
                .catch(err => {
                    console.error('Eroare la actualizarea cantității:', err);
                });
        }

        // Funcție pentru afișarea tooltip-ului
        function afiseazaTooltip(e) {
            // Curățăm orice timer existent
            if (tooltipTimerAscundere) {
                clearTimeout(tooltipTimerAscundere);
                tooltipTimerAscundere = null;
            }

            // Dacă avem deja o etichetă activă și e diferită de cea curentă, ascundem tooltip-ul vechi
            if (etichetaActiva && etichetaActiva !== e.target) {
                // Dacă tooltip-ul este în mod editare, salvăm înainte de a ascunde
                if (tooltipInModEditare) {
                    salveazaCantitateInTooltip();
                }
                tooltip.style.opacity = '0';
                tooltipInModEditare = false;
            }

            etichetaActiva = e.target;
            const eticheta = e.target;
            const cantitate = eticheta.getAttribute('data-cantitate') || '1';

            // Setăm conținutul tooltip-ului - doar numărul
            tooltip.textContent = cantitate;
            tooltip.classList.remove('tooltip-editare');

            // Obținem poziția etichetei
            const etichetaRect = eticheta.getBoundingClientRect();

            // Determinăm dacă eticheta este din lista de obiecte sau de pe imagine
            const esteEtichetaPeImagine = eticheta.classList.contains('eticheta');

            if (esteEtichetaPeImagine) {
                // Calculăm poziția pentru etichetele de pe imagine
                const containerRect = zona.getBoundingClientRect();
                const etichetaX = etichetaRect.left + etichetaRect.width/2 - containerRect.left;
                const etichetaY = etichetaRect.top - containerRect.top;

                // Poziționăm tooltip-ul deasupra etichetei
                tooltip.style.left = etichetaX + 'px';
                tooltip.style.top = (etichetaY - 25) + 'px'; // 25px deasupra etichetei
            } else {
                // Pentru etichetele din lista de obiecte
                const containerRect = document.querySelector('.container').getBoundingClientRect();
                const etichetaX = etichetaRect.left + etichetaRect.width/2 - containerRect.left;
                const etichetaY = etichetaRect.top - containerRect.top;

                // Poziționăm tooltip-ul deasupra etichetei
                tooltip.style.left = etichetaX + 'px';
                tooltip.style.top = (etichetaY - 25) + 'px'; // 25px deasupra etichetei
            }

            // Asigurăm că tooltip-ul rămâne în interiorul ferestrei
            const tooltipRect = tooltip.getBoundingClientRect();
            if (tooltipRect.left < 10) {
                tooltip.style.left = '10px';
            } else if (tooltipRect.right > window.innerWidth - 10) {
                tooltip.style.left = (window.innerWidth - tooltipRect.width - 10) + 'px';
            }

            // Afișăm tooltip-ul
            clearTimeout(tooltipTimeout);
            tooltipTimeout = setTimeout(() => {
                tooltip.style.opacity = '1';

                // Pe mobile, setăm un timer pentru a ascunde tooltip-ul după 3 secunde
                if (esteDispozitivMobil && !tooltipInModEditare) {
                    tooltipTimerAscundere = setTimeout(() => {
                        tooltip.style.opacity = '0';
                        etichetaActiva = null;
                    }, 3000);
                }
            }, 100);
        }

        // Funcție pentru ascunderea tooltip-ului
        function ascundeTooltip() {
            // Pe mobile nu ascundem la mouseout, ci doar la click în altă parte sau la timeout
            if (!esteDispozitivMobil && !tooltipInModEditare) {
                clearTimeout(tooltipTimeout);
                tooltip.style.opacity = '0';
                etichetaActiva = null;
            }
        }

        // Funcție pentru transformarea tooltip-ului în editor
        function transformaTooltipInEditor(eticheta) {
            if (!etichetaActiva) return;

            // Marcăm tooltip-ul ca fiind în mod editare
            tooltipInModEditare = true;

            // Obținem cantitatea curentă
            const cantitate = eticheta.getAttribute('data-cantitate') || '1';

            // Transformăm tooltip-ul într-un editor - doar cu un input, fără butoane
            tooltip.innerHTML = '';
            tooltip.classList.add('tooltip-editare');

            // Creăm un input pentru cantitate
            const input = document.createElement('input');
            input.type = 'number';
            input.min = '1';
            input.step = '1';
            input.value = cantitate;

            // Adăugăm input-ul în tooltip
            tooltip.appendChild(input);

            // Adăugăm event listener pentru Enter și Escape
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    salveazaCantitateInTooltip();
                } else if (e.key === 'Escape') {
                    e.preventDefault();
                    anuleazaEditareInTooltip();
                }
            });

            // Focusăm input-ul
            input.focus();
            input.select();

            // Oprim evenimentul de ascundere pe mobile
            if (tooltipTimerAscundere) {
                clearTimeout(tooltipTimerAscundere);
                tooltipTimerAscundere = null;
            }
        }

        // Funcție pentru salvarea cantității din tooltip
        function salveazaCantitateInTooltip() {
            if (!etichetaActiva || !tooltipInModEditare) return;

            // Obținem valoarea din input
            const input = tooltip.querySelector('input');
            if (!input) return;

            let nouaCantitate = input.value.trim();

            // Validăm noua cantitate
            if (!/^\d+$/.test(nouaCantitate) || parseInt(nouaCantitate) < 1) {
                nouaCantitate = etichetaActiva.getAttribute('data-cantitate') || '1';
            }

            // Resetăm tooltip-ul
            tooltipInModEditare = false;
            tooltip.classList.remove('tooltip-editare');
            tooltip.textContent = nouaCantitate;

            // Actualizăm cantitatea în baza de date
            actualizeazaCantitate(etichetaActiva, nouaCantitate);

            // Pe mobile, setăm un timer pentru a ascunde tooltip-ul după 3 secunde
            if (esteDispozitivMobil) {
                tooltipTimerAscundere = setTimeout(() => {
                    tooltip.style.opacity = '0';
                    etichetaActiva = null;
                }, 3000);
            }
        }

        // Funcție pentru anularea editării din tooltip
        function anuleazaEditareInTooltip() {
            if (!etichetaActiva || !tooltipInModEditare) return;

            // Resetăm tooltip-ul
            tooltipInModEditare = false;
            tooltip.classList.remove('tooltip-editare');
            const cantitate = etichetaActiva.getAttribute('data-cantitate') || '1';
            tooltip.textContent = cantitate;

            // Pe mobile, setăm un timer pentru a ascunde tooltip-ul după 3 secunde
            if (esteDispozitivMobil) {
                tooltipTimerAscundere = setTimeout(() => {
                    tooltip.style.opacity = '0';
                    etichetaActiva = null;
                }, 3000);
            }
        }

        // Funcție pentru gestionarea dublu click sau double tap
        function handleDubluClick(e) {
            const acumTimestamp = new Date().getTime();
            const eticheta = e.target;

            // Pe desktop folosim evenimentul nativ dblclick
            if (!esteDispozitivMobil) {
                transformaTooltipInEditor(eticheta);
                return;
            }

            // Pe mobil detectăm manual dublu click/tap
            if (acumTimestamp - ultimulClickTimestamp < DURATA_DUBLU_CLICK) {
                // Este dublu click
                e.preventDefault(); // Prevenim zoom-ul sau alte acțiuni
                transformaTooltipInEditor(eticheta);
                ultimulClickTimestamp = 0; // Resetăm pentru a evita triple click
            } else {
                // Este simplu click
                ultimulClickTimestamp = acumTimestamp;
            }
        }

        // Funcție pentru a adăuga event listeners la etichete
        function adaugaEventListenersPentruEtichete(eticheta) {
            if (esteDispozitivMobil) {
                // Pe mobile, folosim click/tap pentru tooltip și detectăm manual double tap
                eticheta.addEventListener('click', afiseazaTooltip);
                eticheta.addEventListener('click', handleDubluClick);
            } else {
                // Pe desktop, folosim hover pentru tooltip și dblclick pentru editare
                eticheta.addEventListener('mouseenter', afiseazaTooltip);
                eticheta.addEventListener('mouseleave', ascundeTooltip);
                eticheta.addEventListener('dblclick', handleDubluClick);
            }
        }

        // Funcție nouă pentru afișarea etichetelor Vision - facem global
        window.afiseazaEticheteVision = function(coordonateData) {
            if (!coordonateData) return;
            
            // Obținem datele din atributele elementului
            const zona = document.getElementById('zonaImagine');
            if (!zona) return;
            
            // IMPORTANT: Folosim indexul imaginii (1, 2, 3...) nu numele fișierului
            // pozitieImagineCurenta este deja setat global și reprezintă indexul corect
            const indexImagine = pozitieImagineCurenta; // Acesta este indexul corect (1, 2, 3...)
            
            
            // Folosim indexul imaginii pentru a obține etichetele corecte
            let eticheteImagine = coordonateData[indexImagine];
            
            if (!eticheteImagine) {
                // NU mai încercăm potriviri parțiale care pot cauza afișări greșite
                return;
            }
            
            // Ștergem etichetele Vision existente
            document.querySelectorAll('.eticheta-vision-auto').forEach(e => e.remove());
            
            // Obținem elementele necesare pentru afișarea etichetelor
            // Folosim direct zona (zonaImagine) care este containerul imaginii
            const zonaAfisare = zona; // zona este deja document.getElementById('zonaImagine')
            const imgObiect = document.getElementById('imagine-obiect');
            
            if (!imgObiect.complete) {
                // Dacă imaginea nu e încărcată, așteptăm
                imgObiect.onload = () => afiseazaEticheteVision(coordonateData);
                return;
            }
            
            const imgWidth = imgObiect.offsetWidth;
            const imgHeight = imgObiect.offsetHeight;
            
            // Afișăm fiecare etichetă detectată
            eticheteImagine.forEach((obiect, index) => {
                // Calculăm poziția în pixeli
                const xPx = (obiect.x / 100) * imgWidth;
                const yPx = (obiect.y / 100) * imgHeight;
                
                const et = document.createElement('div');
                et.className = 'eticheta eticheta-vision-auto';
                et.textContent = obiect.nume_tradus || obiect.nume_original;
                
                // Atribute pentru identificare
                et.setAttribute('data-img-index', pozitieImagineCurenta);
                et.setAttribute('data-cantitate', '1');
                et.setAttribute('data-index', 'vision_' + index);
                et.setAttribute('data-confidence', obiect.confidence);
                et.setAttribute('data-vision', 'true');
                
                // Poziționare și stil identic cu etichetele normale
                et.style.position = 'absolute';
                et.style.left = xPx + 'px';
                et.style.top = yPx + 'px';
                et.style.backgroundColor = '#ff6600'; // Portocaliu pentru Vision
                et.style.color = 'white';
                et.style.padding = '4px 8px';
                et.style.borderRadius = '4px';
                et.style.transform = 'translate(-50%, -50%)';
                et.style.zIndex = '1000'; // Z-index ridicat pentru a fi deasupra imaginii
                et.style.pointerEvents = 'auto'; // Să fie clickabile
                
                // Tooltip cu detalii Vision
                et.title = `Detectat automat: ${obiect.nume_original}\nÎncredere: ${(obiect.confidence * 100).toFixed(1)}%`;
                
                // Adăugăm evenimentele pentru tooltip și editare (opțional)
                // adaugaEventListenersPentruEtichete(et);
                
                zonaAfisare.appendChild(et);
            });
        }
        
        // Funcție pentru afișarea etichetelor pe imagine - CORECTATĂ pentru a respecta strict indexul imaginii
        function afiseazaEtichete() {
            // Folosim variabilele globale în loc de cele din PHP
            const eticheteRaw = eticheteGlobale;
            const denumiri = denumiriGlobale;

            // Ștergem etichetele existente
            document.querySelectorAll('.eticheta:not([contenteditable="true"])').forEach(e => e.remove());


            for (let i = 0; i < eticheteRaw.length; i++) {
                if (!denumiri[i] || !eticheteRaw[i] || eticheteRaw[i] === '') continue;

                const eticheta = eticheteRaw[i];
                const denumireInfo = extrageIndexImagine(denumiri[i]);
                const denumire = denumireInfo.nume;
                const indexImagine = denumireInfo.index;


                // VERIFICARE STRICTĂ - Afișăm DOAR etichetele pentru imaginea curentă
                // Obiectele cu index 0 sunt comune pentru toate imaginile
                // NOTĂ: Atât indexImagine cât și pozitieImagineCurenta sunt indexate de la 1
                if (indexImagine !== 0 && indexImagine !== pozitieImagineCurenta) {
                    continue;
                }

                const rezultatParsare = parseEticheta(eticheta);

                // Verificăm explicit dacă avem coordonate înainte de a afișa eticheta
                if (!rezultatParsare.coordonate) {
                    console.warn('Eticheta fără coordonate ignorată:', eticheta);
                    continue; // Nu afișăm etichete fără coordonate
                }

                // Obținem cantitatea pentru această etichetă
                const cantitate = cantitati[i] || '1';

                // Dacă avem coordonate, adăugăm eticheta pe imagine
                if (rezultatParsare.coordonate && imgObiect.complete) {
                    const imgWidth = imgObiect.offsetWidth;
                    const imgHeight = imgObiect.offsetHeight;
                    const xPx = (rezultatParsare.coordonate.x / 100) * imgWidth;
                    const yPx = (rezultatParsare.coordonate.y / 100) * imgHeight;

                    // Adăugăm eticheta
                    const et = document.createElement('div');
                    et.className = 'eticheta';
                    et.textContent = denumire;
                    et.setAttribute('data-img-index', indexImagine);
                    et.setAttribute('data-cantitate', cantitate);
                    et.setAttribute('data-index', i);
                    et.style.position = 'absolute';
                    et.style.left = xPx + 'px';
                    et.style.top = yPx + 'px';
                    et.style.backgroundColor = rezultatParsare.culoare;
                    et.style.color = culoareTextPotrivita(rezultatParsare.culoare);
                    et.style.padding = '4px 8px';
                    et.style.borderRadius = '4px';
                    et.style.transform = 'translate(-50%, -50%)';

                    // Adăugăm evenimentele pentru tooltip și editare
                    adaugaEventListenersPentruEtichete(et);

                    zona.appendChild(et);
                }
            }
        }

        // Funcție pentru inițializarea interfeței de crop
        function initCropInterface() {
            inCropMode = true;

            // Asigurăm-ne că butoanele din partea de jos au simbolurile corecte
            document.getElementById('cropRotateLeft').innerHTML = '↺';
            document.getElementById('cropRotateRight').innerHTML = '↻';
            document.getElementById('cropCancel').innerHTML = '✕';
            document.getElementById('cropApply').innerHTML = '✓';

            // Setăm sursa imaginii în containerul de crop
            cropImage.src = imgObiect.src;

            // Afișăm containerul de crop
            cropContainer.style.display = 'flex';

            // Inițializăm Cropper.js cu dimensiunea inițială a dreptunghiului
            cropImage.onload = function() {
                // Opțiuni pentru o centrare mai precisă
                const options = {
                    viewMode: 1,
                    aspectRatio: NaN, // Permitem orice raport de aspect
                    autoCropArea: 0.5,
                    zoomable: true,    // Permitem zoom pentru poziționare mai bună
                    rotatable: true,   // Activăm rotirea
                    minCropBoxWidth: 50,
                    minCropBoxHeight: 50,

                    // Setăm flag-ul initialCropBoxOnTouch la false pentru a preveni
                    // comportamentul implicit de poziționare a crop box-ului
                    initialCropBoxOnTouch: false,

                    // Adăugăm event handler pentru evenimentul 'crop' pentru debugging
                    crop: function(e) {
                        // Debugging - opțional
                        // console.log('Poziție crop box:', e.detail.x, e.detail.y);
                    },

                    ready: function() {
                        // Creăm o referință la instanța cropper
                        const cropperInstance = this.cropper;

                        // Obținem datele containerului și canvas-ului
                        const containerData = cropperInstance.getContainerData();
                        const canvasData = cropperInstance.getCanvasData();
                        const imageData = cropperInstance.getImageData();

                        // Calculăm factorul de scalare dintre imaginea originală și cea din cropper
                        const scaleX = canvasData.width / activeSelection.imgWidth;
                        const scaleY = canvasData.height / activeSelection.imgHeight;

                        // Calculăm poziția exactă a click-ului în coordonatele canvas-ului
                        const clickX = activeSelection.x * scaleX;
                        const clickY = activeSelection.y * scaleY;

                        // Dimensiunile implicite ale crop box-ului
                        const boxWidth = 200;
                        const boxHeight = 100;

                        // Calculăm poziția crop box-ului centrată pe click
                        const cropBoxData = {
                            left: canvasData.left + clickX - (boxWidth / 2),
                            top: canvasData.top + clickY - (boxHeight / 2),
                            width: boxWidth,
                            height: boxHeight
                        };

                        // Asigurăm-ne că crop box-ul nu iese din canvas
                        if (cropBoxData.left < canvasData.left)
                            cropBoxData.left = canvasData.left;

                        if (cropBoxData.top < canvasData.top)
                            cropBoxData.top = canvasData.top;

                        if (cropBoxData.left + cropBoxData.width > canvasData.left + canvasData.width)
                            cropBoxData.width = canvasData.left + canvasData.width - cropBoxData.left;

                        if (cropBoxData.top + cropBoxData.height > canvasData.top + canvasData.height)
                            cropBoxData.height = canvasData.top + canvasData.height - cropBoxData.top;

                        // Aplicăm poziția crop box-ului
                        setTimeout(() => {
                            cropperInstance.setCropBoxData(cropBoxData);

                            // Dacă imaginea este mai mare decât containerul, ajustăm zoom-ul
                            // pentru a vedea mai bine zona selectată
                            if (canvasData.width > containerData.width || canvasData.height > containerData.height) {
                                // Calculăm centrul crop box-ului
                                const cropCenterX = cropBoxData.left + (cropBoxData.width / 2);
                                const cropCenterY = cropBoxData.top + (cropBoxData.height / 2);

                                // Calculăm cât trebuie să deplasăm canvas-ul pentru a centra crop box-ul
                                const moveX = containerData.width / 2 - cropCenterX;
                                const moveY = containerData.height / 2 - cropCenterY;

                                // Actualizăm poziția canvas-ului
                                cropperInstance.moveTo(
                                    (canvasData.left + moveX) / scaleX,
                                    (canvasData.top + moveY) / scaleY
                                );
                            }
                        }, 100); // Un mic delay pentru a ne asigura că toate calculele sunt aplicate corect
                    }
                };

                // Inițializăm cropper cu opțiunile definite
                cropper = new Cropper(cropImage, options);
            };
        }

        // Funcție pentru închiderea interfeței de crop
        function closeCropInterface() {
            if (cropper) {
                cropper.destroy();
                cropper = null;
            }

            cropContainer.style.display = 'none';
            inCropMode = false;
            activeSelection = null;
        }

        // Funcție pentru aplicarea crop-ului și salvarea datelor
        function applyCrop() {
            if (!cropper) return;

            // Obținem datele despre selecție
            const cropBoxData = cropper.getCropBoxData();
            const imageData = cropper.getImageData();

            // Calculăm dimensiunea relativă a selecției
            const relWidth = (cropBoxData.width / imageData.width);
            const relHeight = (cropBoxData.height / imageData.height);

            // Calculăm poziția relativă
            const relLeft = (cropBoxData.left / imageData.width);
            const relTop = (cropBoxData.top / imageData.height);

            // Datele pentru selecție
            const selectionData = {
                x: activeSelection.xPercent,
                y: activeSelection.yPercent,
                width: Math.round(relWidth * 100), // În procente
                height: Math.round(relHeight * 100), // În procente
                left: Math.round(relLeft * 100), // În procente
                top: Math.round(relTop * 100) // În procente
            };

            // Obținem datele imaginii decupate ca Base64
            const canvas = cropper.getCroppedCanvas({
                maxWidth: 800,
                maxHeight: 800
            });

            const imgData = canvas.toDataURL('image/png');

            // Închid interfața de crop
            closeCropInterface();

            // Continuăm cu crearea etichetei
            createLabelWithCrop(selectionData, imgData);
        }

        // Funcție pentru crearea etichetei cu imagine decupată
        // Funcția createLabelWithCrop modificată
        function createLabelWithCrop(selectionData, imgData) {
            const categorie = selectCategorie.value.trim();
            const culoare = categoriiCulori[categorie];

            if (!culoare) {
                console.error('Nu există o culoare asociată categoriei selectate');
                return;
            }

            const textColor = culoareTextPotrivita(culoare);

            // Creăm o etichetă editabilă
            const et = document.createElement('div');
            et.className = 'eticheta';
            et.contentEditable = true;
            et.style.left = (selectionData.x * imgObiect.offsetWidth / 100) + 'px';
            et.style.top = (selectionData.y * imgObiect.offsetHeight / 100) + 'px';
            et.style.backgroundColor = culoare;
            et.style.color = textColor;
            et.style.padding = '4px 8px';
            et.style.borderRadius = '4px';
            et.style.transform = 'translate(-50%, -50%)';
            et.style.position = 'absolute';
            et.setAttribute('data-img-index', pozitieImagineCurenta);
            et.setAttribute('data-cantitate', '1');
            et.setAttribute('data-crop', JSON.stringify(selectionData));

            zona.appendChild(et);
            et.focus();

            et.addEventListener('mousedown', ev => ev.stopPropagation());

            // Handler-ul pentru blur pe eticheta nou adăugată
            et.addEventListener('blur', () => {
                let den = et.innerText.trim();
                if (!den) { et.remove(); return; }

                // Curățăm virgulele din textul etichetei
                den = curataVirgule(den);

                skipNext = true;

                // Formatăm denumirea cu indexul imaginii în paranteze
                const denumireCuIndex = `${den}(${pozitieImagineCurenta})`;

                // Formatăm eticheta cu coordonate
                const etichetaCuCoordonata = `${culoare}(${selectionData.x},${selectionData.y})`;

                // Creăm șirurile actualizate complete
                const denumiriNoi = [...denumiriGlobale, denumireCuIndex];
                const eticheteNoi = [...eticheteGlobale, etichetaCuCoordonata];
                const cantitatiNoi = [...cantitati, '1'];

                const form = new FormData();
                form.append('id', 0);
                form.append('camp', 'denumire_obiect');
                form.append('valoare', denumireCuIndex);
                form.append('categorie', categorie);
                form.append('eticheta', etichetaCuCoordonata);
                form.append('cutie', zona.dataset.cutie);
                form.append('locatie', zona.dataset.locatie);
                form.append('cantitate', 1);
                form.append('descriere_categorie', zona.dataset.descriere);
                form.append('imagine', zona.dataset.imagine);
                form.append('imagine_curenta', zona.dataset.imagineCurenta);
                form.append('pozitie_imagine', pozitieImagineCurenta);
                form.append('pastrare_asocieri', 'true');
                form.append('denumiri', denumiriNoi.join(', '));
                form.append('cantitati', cantitatiNoi.join(', '));
                form.append('etichete_obiect', eticheteNoi.join('; '));

                // Adăugăm datele pentru imaginea decupată
                form.append('imagine_decupata', imgData);

                // MODIFICARE: Aplicăm eliminarea diacriticelor pentru numele obiectului
                const denFaraDiacritice = eliminaDiacritice(den);
                form.append('nume_obiect', denFaraDiacritice);
                // Adăugăm și numele original pentru afișare
                form.append('nume_obiect_afisare', den);

                // Adăugăm ID-ul colecției
                const urlParams = new URLSearchParams(window.location.search);
                const idColectie = urlParams.get('colectie');
                if (idColectie) {
                    form.append('id_colectie', idColectie);
                }

                fetch('actualizeaza_obiect.php', {
                    method: 'POST',
                    body: form
                })
                    .then(r => r.text())
                    .then(msg => {
                        const b = document.createElement('span');
                        b.innerText = ' ✔';
                        b.style.color = 'limegreen';
                        b.style.marginLeft = '5px';
                        et.appendChild(b);
                        setTimeout(() => b.remove(), 1500);

                        const badge = document.createElement('span');
                        badge.className = 'obiect-salvat rotunjit';
                        badge.textContent = den;
                        badge.setAttribute('data-img-index', pozitieImagineCurenta);
                        badge.setAttribute('data-cantitate', '1');
                        badge.setAttribute('data-index', eticheteGlobale.length); // Adăugăm indexul
                        badge.style.backgroundColor = culoare;
                        badge.style.color = textColor;

                        // Adăugăm badge-ul în lista de obiecte doar dacă culoarea este #ccc
                        if (culoare.toLowerCase() === '#ccc') {
                            document.getElementById('listaObiecte').appendChild(badge);
                            // Adăugăm evenimentele pentru tooltip și editare pentru badge
                            adaugaEventListenersPentruEtichete(badge);
                        }

                        // Actualizăm textul etichetei cu versiunea curățată
                        et.textContent = den;

                        // Actualizăm array-urile locale cu noile date
                        eticheteGlobale.push(etichetaCuCoordonata);
                        denumiriGlobale.push(denumireCuIndex);
                        cantitati.push('1');

                        // Setăm eticheta ca non-editabilă
                        et.contentEditable = false;

                        // Actualizăm atributul pentru index
                        et.setAttribute('data-index', eticheteGlobale.length - 1);

                        // Adăugăm evenimentele pentru tooltip și editare pentru eticheta de pe imagine
                        adaugaEventListenersPentruEtichete(et);
                        // Afișează tooltip-ul simulând un eveniment de click/hover
                        const evFals = { target: et };
                        afiseazaTooltip(evFals);

                        // Setează un timer de 2 secunde pentru a ascunde tooltip-ul
                        setTimeout(() => {
                            tooltip.style.opacity = '0';
                            etichetaActiva = null;
                        }, 2000);
                    }).catch(console.error);
            });
        }

        // Funcție pentru curățarea virgulelor din textul etichetei
        function curataVirgule(text) {
            return text.replace(/,/g, '-');
        }

        // Încărcăm categoriile și apoi afișăm etichetele
        // Adăugăm ID-ul colecției din URL
        const urlParams = new URLSearchParams(window.location.search);
        const idColectie = urlParams.get('colectie');
        
        // Verificăm dacă avem ID-ul obiectului înainte de a face request
        const idObiectFromDataset = zona.dataset.id;
        const idObiectFromUrl = urlParams.get('id');
        const idObiectToUse = idObiectFromDataset || idObiectFromUrl || '';
        
        if (!idObiectToUse) {
            console.warn('Nu s-a găsit ID-ul obiectului. Verificați dacă obiectul există în baza de date.');
            // Nu mai facem return aici, doar nu încărcăm categoriile
        } else {
            // Încărcăm categoriile doar dacă avem ID
            let urlCategorii = 'culori_categorii.php?id=' + idObiectToUse;
            if (idColectie) {
                urlCategorii += '&colectie=' + idColectie;
            }
            fetch(urlCategorii)
            .then(res => res.json())
            .then(data => {
                categoriiCulori = data;

                // Populăm selectorul cu categorii
                Object.keys(data).forEach((cat, i) => {
                    const opt = document.createElement('option');
                    opt.value = cat;
                    opt.textContent = cat;
                    opt.setAttribute('data-culoare', data[cat]);
                    opt.style.backgroundColor = data[cat];
                    opt.style.color = culoareTextPotrivita(data[cat]);
                    if (i === 0) {
                        opt.selected = true;
                        selectCategorie.style.backgroundColor = data[cat];
                        selectCategorie.style.color = culoareTextPotrivita(data[cat]);
                    }
                    selectCategorie.appendChild(opt);
                });

                // Verificăm dacă imaginea e încărcată pentru a afișa etichetele
                if (imgObiect.complete) {
                    afiseazaEtichete();
                } else {
                    imgObiect.onload = function() {
                        afiseazaEtichete();
                    };
                }
            })
            .catch(err => {
                console.error('Eroare la încărcarea categoriilor:', err);
            });
        } // Închidere else pentru verificarea idObiectToUse

        // Event listener pentru selector categorii - CORECTAT pentru a respecta indexul imaginii
        selectCategorie.addEventListener('change', () => {
            const categorie = selectCategorie.value.trim();
            const culoare = categoriiCulori[categorie];

            if (culoare) {
                selectCategorie.style.backgroundColor = culoare;
                selectCategorie.style.color = culoareTextPotrivita(culoare);
            }

            // Ștergem toate etichetele existente
            document.querySelectorAll('.eticheta:not([contenteditable="true"])').forEach(e => e.remove());

            // Reafișăm etichetele filtrate după categoria selectată
            for (let i = 0; i < eticheteGlobale.length; i++) {
                if (!denumiriGlobale[i]) continue;

                const eticheta = eticheteGlobale[i];
                if (!eticheta) continue;

                const denumireInfo = extrageIndexImagine(denumiriGlobale[i]);
                const denumire = denumireInfo.nume;
                const indexImagine = denumireInfo.index;

                // VERIFICARE STRICTĂ - afișăm doar etichetele pentru imaginea curentă sau globale (index 0)
                if (indexImagine !== 0 && indexImagine !== pozitieImagineCurenta) {
                    continue;
                }

                const rezultatParsare = parseEticheta(eticheta);

                // Afișăm doar etichetele care au culoarea categoriei curente și au coordonate
                if (rezultatParsare.culoare && culoare &&
                    rezultatParsare.culoare.toLowerCase() === culoare.toLowerCase() &&
                    rezultatParsare.coordonate) {

                    // Obținem cantitatea pentru această etichetă
                    const cantitate = cantitati[i] || '1';

                    const imgWidth = imgObiect.offsetWidth;
                    const imgHeight = imgObiect.offsetHeight;
                    const xPx = (rezultatParsare.coordonate.x / 100) * imgWidth;
                    const yPx = (rezultatParsare.coordonate.y / 100) * imgHeight;

                    // Adăugăm eticheta
                    const et = document.createElement('div');
                    et.className = 'eticheta';
                    et.textContent = denumire;
                    et.setAttribute('data-img-index', indexImagine);
                    et.setAttribute('data-cantitate', cantitate);
                    et.setAttribute('data-index', i);
                    et.style.position = 'absolute';
                    et.style.left = xPx + 'px';
                    et.style.top = yPx + 'px';
                    et.style.backgroundColor = rezultatParsare.culoare;
                    et.style.color = culoareTextPotrivita(rezultatParsare.culoare);
                    et.style.padding = '4px 8px';
                    et.style.borderRadius = '4px';
                    et.style.transform = 'translate(-50%, -50%)';

                    // Adăugăm evenimentele pentru tooltip și editare
                    adaugaEventListenersPentruEtichete(et);

                    zona.appendChild(et);
                }
            }
        });

        // Event listener pentru click pe imagine - MODIFICAT pentru suport decupare
        zona.addEventListener('click', e => {
            if (skipNext) { skipNext = false; return; }

            // Nu procesăm click-urile pe săgeți sau etichete
            if (e.target.classList.contains('navigare-img') ||
                e.target.classList.contains('eticheta') ||
                e.target.closest('.eticheta') ||
                e.target.classList.contains('indicator-pozitie')) return;
            if (e.target === tooltip || e.target.closest('#tooltip')) return;

            // Dacă tooltip-ul este în mod editare, salvăm înainte de a continua
            if (tooltipInModEditare) {
                salveazaCantitateInTooltip();
                return;
            }

            // Verificăm dacă modul de decupare este activat
            if (cropModeEnabled) {
                // Dacă suntem deja în modul crop, ignorăm click-ul
                if (inCropMode) {
                    return;
                }

                // Salvăm informațiile despre click pentru utilizare ulterioară
                const rect = zona.getBoundingClientRect();
                const x = e.clientX - rect.left;
                const y = e.clientY - rect.top;

                // Calculăm procentajele pentru coordonate
                const imgWidth = imgObiect.offsetWidth;
                const imgHeight = imgObiect.offsetHeight;
                const xProcentaj = Math.round((x / imgWidth) * 100);
                const yProcentaj = Math.round((y / imgHeight) * 100);

                // Salvăm coordonatele inițiale pentru viitoarea etichetă
                activeSelection = {
                    x: x,
                    y: y,
                    imgWidth: imgWidth,
                    imgHeight: imgHeight,
                    xPercent: xProcentaj,
                    yPercent: yProcentaj
                };

                // Inițializăm interfața de crop
                initCropInterface();
            } else {
                // Comportamentul original fără decupare
                const rect = zona.getBoundingClientRect();
                const x = e.clientX - rect.left;
                const y = e.clientY - rect.top;
                const categorie = selectCategorie.value.trim();
                const culoare = categoriiCulori[categorie];

                if (!culoare) {
                    console.error('Nu există o culoare asociată categoriei selectate');
                    return;
                }

                const textColor = culoareTextPotrivita(culoare);

                // Calculăm procentajele pentru coordonate
                const imgWidth = imgObiect.offsetWidth;
                const imgHeight = imgObiect.offsetHeight;
                const xProcentaj = Math.round((x / imgWidth) * 100);
                const yProcentaj = Math.round((y / imgHeight) * 100);

                const et = document.createElement('div');
                et.className = 'eticheta';
                et.contentEditable = true;
                et.style.left = x + 'px';
                et.style.top = y + 'px';
                et.style.backgroundColor = culoare;
                et.style.color = textColor;
                et.style.padding = '4px 8px';
                et.style.borderRadius = '4px';
                et.style.transform = 'translate(-50%, -50%)';
                et.style.position = 'absolute';
                et.setAttribute('data-img-index', pozitieImagineCurenta);
                et.setAttribute('data-cantitate', '1');

                zona.appendChild(et);
                et.focus();

                et.addEventListener('mousedown', ev => ev.stopPropagation());

                // Handler-ul pentru blur pe eticheta nou adăugată
                et.addEventListener('blur', () => {
                    let den = et.innerText.trim();
                    if (!den) { et.remove(); return; }

                    // Curățăm virgulele din textul etichetei
                    den = curataVirgule(den);

                    skipNext = true;

                    // Formatăm denumirea cu indexul imaginii în paranteze
                    const denumireCuIndex = `${den}(${pozitieImagineCurenta})`;

                    // Formatăm eticheta cu coordonate
                    const etichetaCuCoordonata = `${culoare}(${xProcentaj},${yProcentaj})`;

                    // Creăm șirurile actualizate complete
                    const denumiriNoi = [...denumiriGlobale, denumireCuIndex];
                    const eticheteNoi = [...eticheteGlobale, etichetaCuCoordonata];
                    const cantitatiNoi = [...cantitati, '1'];

                    const form = new URLSearchParams();
                    form.append('id', 0);
                    form.append('camp', 'denumire_obiect');
                    form.append('valoare', denumireCuIndex);
                    form.append('categorie', categorie);
                    form.append('eticheta', etichetaCuCoordonata);
                    form.append('cutie', zona.dataset.cutie);
                    form.append('locatie', zona.dataset.locatie);
                    form.append('cantitate', 1);
                    form.append('descriere_categorie', zona.dataset.descriere);
                    form.append('imagine', zona.dataset.imagine);
                    form.append('imagine_curenta', zona.dataset.imagineCurenta);
                    form.append('pozitie_imagine', pozitieImagineCurenta);
                    form.append('pastrare_asocieri', 'true');
                    form.append('denumiri', denumiriNoi.join(', '));
                    form.append('cantitati', cantitatiNoi.join(', '));
                    form.append('etichete_obiect', eticheteNoi.join('; '));

                    // Adăugăm ID-ul colecției
                    const urlParams = new URLSearchParams(window.location.search);
                    const idColectie = urlParams.get('colectie');
                    if (idColectie) {
                        form.append('id_colectie', idColectie);
                    }

                    fetch('actualizeaza_obiect.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: form.toString()
                    })
                        .then(r => r.text())
                        .then(msg => {
                            const b = document.createElement('span');
                            b.innerText = ' ✔';
                            b.style.color = 'limegreen';
                            b.style.marginLeft = '5px';
                            et.appendChild(b);
                            setTimeout(() => b.remove(), 1500);

                            const badge = document.createElement('span');
                            badge.className = 'obiect-salvat rotunjit';
                            badge.textContent = den;
                            badge.setAttribute('data-img-index', pozitieImagineCurenta);
                            badge.setAttribute('data-cantitate', '1');
                            badge.setAttribute('data-index', eticheteGlobale.length); // Adăugăm indexul
                            badge.style.backgroundColor = culoare;
                            badge.style.color = textColor;

                            // Adăugăm badge-ul în lista de obiecte doar dacă culoarea este #ccc
                            if (culoare.toLowerCase() === '#ccc') {
                                document.getElementById('listaObiecte').appendChild(badge);
                                // Adăugăm evenimentele pentru tooltip și editare pentru badge
                                adaugaEventListenersPentruEtichete(badge);
                            }

                            // Actualizăm textul etichetei cu versiunea curățată
                            et.textContent = den;

                            // Actualizăm array-urile locale cu noile date
                            eticheteGlobale.push(etichetaCuCoordonata);
                            denumiriGlobale.push(denumireCuIndex);
                            cantitati.push('1');

                            // Setăm eticheta ca non-editabilă
                            et.contentEditable = false;

                            // Actualizăm atributul pentru index
                            et.setAttribute('data-index', eticheteGlobale.length - 1);

                            // Adăugăm evenimentele pentru tooltip și editare pentru eticheta de pe imagine
                            adaugaEventListenersPentruEtichete(et);
                            // Afișează tooltip-ul simulând un eveniment de click/hover
                            const evFals = { target: et };
                            afiseazaTooltip(evFals);

                            // Setează un timer de 2 secunde pentru a ascunde tooltip-ul
                            setTimeout(() => {
                                tooltip.style.opacity = '0';
                                etichetaActiva = null;
                            }, 2000);
                        }).catch(console.error);
                });
            }
        });

        // Reafișăm etichetele la redimensionare
        window.addEventListener('resize', () => {
            // Resetăm etichetele
            document.querySelectorAll('.eticheta:not([contenteditable="true"])').forEach(e => e.remove());
            // Reafișăm etichetele
            afiseazaEtichete();
        });

        // Adăugăm eveniment pentru tooltip și editare și la badge-urile din lista de obiecte
        document.querySelectorAll('.obiect-salvat').forEach(badge => {
            adaugaEventListenersPentruEtichete(badge);
        });

        // Adaugă un event listener pentru document care să ascundă tooltip-ul și editorul la click în altă parte
        document.addEventListener('click', (e) => {
            // Dacă tooltip-ul este în mod editare și se face click în altă parte, salvăm
            if (tooltipInModEditare && tooltip.style.opacity === '1' &&
                e.target !== tooltip && !tooltip.contains(e.target) &&
                e.target !== etichetaActiva && !etichetaActiva?.contains(e.target)) {
                salveazaCantitateInTooltip();
            }
            // Altfel, ascundem tooltip-ul pe mobile la click în altă parte
            else if (esteDispozitivMobil && tooltip.style.opacity === '1' &&
                etichetaActiva && !etichetaActiva.contains(e.target) &&
                e.target !== tooltip && !tooltip.contains(e.target)) {
                tooltip.style.opacity = '0';
                etichetaActiva = null;
            }
        });

        // Verificăm dacă există un termen de căutare la încărcarea paginii
        document.addEventListener('DOMContentLoaded', function() {
            const campCautare = document.getElementById('campCautare');
            if (campCautare.value.trim() !== '') {
                window.filtreazaEtichete(campCautare.value);
            }
        });

        // Adăugăm suport pentru navigarea prin tastatura - săgețile stânga/dreapta
        document.addEventListener('keydown', function(e) {
            // Săgeată stânga - imagine anterioară
            if (e.key === 'ArrowLeft') {
                butonAnterior.click();
            }
            // Săgeată dreapta - imagine următoare
            else if (e.key === 'ArrowRight') {
                butonUrmator.click();
            }
        });

        // Funcție pentru procesarea cărților cu Vision
        function startBooksProcessing() {
            closeVisionConfirm();
            
            const progressModal = document.getElementById('visionProgressModal');
            const progressBar = document.getElementById('visionProgressBar');
            const progressStatus = document.getElementById('visionProgressStatus');
            const progressImages = document.getElementById('visionProgressImages');
            
            progressModal.style.display = 'flex';
            progressBar.style.width = '0%';
            
            let imaginiProcesate = 0;
            let cartiDetectate = [];
            const totalImagini = toateImaginile.length;
            
            progressStatus.textContent = 'Inițializez detectarea cărților...';
            
            // Procesăm fiecare imagine
            async function procesareSecventiala(index) {
                if (index >= totalImagini) {
                    // Afișăm rezultatele
                    progressModal.style.display = 'none';
                    afiseazaRezultateCarti(cartiDetectate);
                    return;
                }
                
                const imagine = toateImaginile[index];
                progressStatus.textContent = `Analizez cărți în imaginea ${index + 1} din ${totalImagini}...`;
                progressImages.textContent = `${index + 1} / ${totalImagini}`;
                
                const formData = new FormData();
                formData.append('imagine', imagine);
                formData.append('locatie', locatie);
                formData.append('cutie', cutie);
                if (typeof id_colectie !== 'undefined' && id_colectie) {
                    formData.append('id_colectie', id_colectie);
                }
                
                try {
                    const response = await fetch('procesare_carti_vision.php', {
                        method: 'POST',
                        body: formData
                    });
                    
                    const data = await response.json();
                    
                    if (data.success && data.carti && data.carti.length > 0) {
                        cartiDetectate = cartiDetectate.concat(data.carti);
                    }
                } catch (error) {
                    console.error('Eroare la procesarea imaginii pentru cărți:', error);
                }
                
                imaginiProcesate++;
                const progres = Math.round((imaginiProcesate / totalImagini) * 100);
                progressBar.style.width = progres + '%';
                
                // Continuăm cu următoarea imagine
                setTimeout(() => procesareSecventiala(index + 1), 500);
            }
            
            // Începem procesarea
            procesareSecventiala(0);
        }
        
        // Funcție pentru afișarea rezultatelor detectării cărților
        function afiseazaRezultateCarti(carti) {
            const modal = document.getElementById('visionModal');
            const modalIcon = modal.querySelector('.vision-modal-icon');
            const modalTitle = modal.querySelector('h3');
            const modalSubtitle = modal.querySelector('p');
            
            modalIcon.innerHTML = '📚';
            modalTitle.textContent = 'Detectare cărți completă!';
            modalSubtitle.textContent = 'Am identificat următoarele cărți folosind Google Vision și Google Books';
            
            // Actualizăm statisticile
            document.getElementById('visionImages').textContent = toateImaginile.length;
            document.getElementById('visionDetected').textContent = carti.length;
            
            // Calculăm numărul de autori unici
            const autoriUnici = [...new Set(carti.map(c => c.autor).filter(a => a))];
            document.getElementById('visionTypes').textContent = autoriUnici.length;
            
            // Afișăm detaliile cărților
            const detaliiDiv = document.getElementById('visionDetails');
            detaliiDiv.innerHTML = '';
            
            if (carti.length > 0) {
                const lista = document.createElement('ul');
                lista.style.listStyle = 'none';
                lista.style.padding = '0';
                
                carti.forEach(carte => {
                    const item = document.createElement('li');
                    item.style.marginBottom = '10px';
                    item.style.padding = '10px';
                    item.style.backgroundColor = '#f5f5f5';
                    item.style.borderRadius = '5px';
                    
                    const incredereColor = carte.incredere_detectie > 0.7 ? '#4CAF50' : 
                                          carte.incredere_detectie > 0.5 ? '#FF9800' : '#f44336';
                    
                    item.innerHTML = `
                        <div style="display: flex; align-items: center;">
                            ${carte.imagine_coperta ? `<img src="${carte.imagine_coperta}" style="width: 40px; height: 60px; margin-right: 10px; border-radius: 3px;">` : ''}
                            <div style="flex: 1;">
                                <strong>${carte.titlu || 'Titlu nedetectat'}</strong><br>
                                <small style="color: #666;">
                                    ${carte.autor ? `Autor: ${carte.autor}<br>` : ''}
                                    ${carte.isbn ? `ISBN: ${carte.isbn}<br>` : ''}
                                    ${carte.editura ? `Editura: ${carte.editura}` : ''}
                                    ${carte.an_publicare ? ` (${carte.an_publicare})` : ''}
                                </small>
                            </div>
                            <div style="text-align: right;">
                                <span style="color: ${incredereColor}; font-weight: bold;">
                                    ${Math.round(carte.incredere_detectie * 100)}%
                                </span><br>
                                <small style="color: #999;">${carte.sursa_detectie}</small>
                            </div>
                        </div>
                    `;
                    lista.appendChild(item);
                });
                
                detaliiDiv.appendChild(lista);
            } else {
                detaliiDiv.innerHTML = '<p style="text-align: center; color: #999;">Nu s-au detectat cărți în imaginile analizate.</p>';
            }
            
            // Adăugăm buton pentru editare/verificare
            const btnContainer = modal.querySelector('.vision-modal-buttons') || 
                                (() => {
                                    const div = document.createElement('div');
                                    div.className = 'vision-modal-buttons';
                                    div.style.marginTop = '20px';
                                    modal.querySelector('.vision-modal-content').appendChild(div);
                                    return div;
                                })();
            
            btnContainer.innerHTML = `
                <button class="vision-modal-btn" onclick="closeVisionModal()">Închide</button>
                ${carti.length > 0 ? '<button class="vision-modal-btn" style="background-color: #4CAF50;" onclick="window.location.href=\'carti.php?locatie=' + encodeURIComponent(locatie) + '&cutie=' + encodeURIComponent(cutie) + '\'">Vezi toate cărțile</button>' : ''}
            `;
            
            modal.style.display = 'flex';
        }
        
        // Funcție pentru navigarea AJAX la altă imagine
        function navigheazaLaImagine(numeImagine) {
            // Afișăm un indicator de încărcare
            const divIncarcare = document.createElement('div');
            divIncarcare.className = 'indicator-incarcare';
            divIncarcare.innerHTML = '<div class="spinner"></div>';
            divIncarcare.style.position = 'fixed';
            divIncarcare.style.top = '0';
            divIncarcare.style.left = '0';
            divIncarcare.style.width = '100%';
            divIncarcare.style.height = '100%';
            divIncarcare.style.backgroundColor = 'rgba(255, 255, 255, 0.7)';
            divIncarcare.style.display = 'flex';
            divIncarcare.style.justifyContent = 'center';
            divIncarcare.style.alignItems = 'center';
            divIncarcare.style.zIndex = '9999';

            // Stiluri pentru spinner
            const style = document.createElement('style');
            style.textContent = `
                .spinner {
                    border: 5px solid #f3f3f3;
                    border-top: 5px solid #3498db;
                    border-radius: 50%;
                    width: 50px;
                    height: 50px;
                    animation: spin 1s linear infinite;
                }
                @keyframes spin {
                    0% { transform: rotate(0deg); }
                    100% { transform: rotate(360deg); }
                }
            `;
            document.head.appendChild(style);
            document.body.appendChild(divIncarcare);

            // Actualizăm URL-ul pentru a reflecta noua imagine
            const idObiect = zona.dataset.id;

            // Adăugăm ID-ul colecției din URL-ul curent
            const urlParams = new URLSearchParams(window.location.search);
            const idColectie = urlParams.get('colectie');

            let url = `etichete_imagine.php?id=${idObiect}&imagine=${encodeURIComponent(numeImagine)}`;
            if (idColectie) {
                url += `&colectie=${idColectie}`;
            }

            // Folosim History API pentru a actualiza URL-ul fără a reîncărca pagina
            history.pushState({ id: idObiect, imagine: numeImagine, colectie: idColectie }, '', url);

            // Facem cererea AJAX pentru a obține noile date
            fetch(url)
                .then(response => response.text())
                .then(html => {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');

                    // Extragem informațiile necesare din noul document
                    const nouaImagine = doc.getElementById('imagine-obiect').src;
                    const nouaPozitie = parseInt(doc.querySelector('.container-img').dataset.pozitieImagine);
                    const nouaImagineCurenta = doc.querySelector('.container-img').dataset.imagineCurenta;

                    // Actualizăm sursa imaginii
                    imgObiect.src = nouaImagine;

                    // Actualizăm datele în zona imaginii
                    zona.dataset.pozitieImagine = nouaPozitie;
                    zona.dataset.imagineCurenta = nouaImagineCurenta;

                    // IMPORTANT: Actualizăm variabila locală pozitieImagineCurenta
                    // Această este modificarea cheie pentru a sincroniza corect etichetele
                    pozitieImagineCurenta = nouaPozitie;


                    // Actualizăm butoanele de navigare
                    if (doc.querySelector('.navigare-img-anterior')) {
                        butonAnterior.setAttribute('data-imagine',
                            doc.querySelector('.navigare-img-anterior').getAttribute('data-imagine'));
                    }

                    if (doc.querySelector('.navigare-img-urmator')) {
                        butonUrmator.setAttribute('data-imagine',
                            doc.querySelector('.navigare-img-urmator').getAttribute('data-imagine'));
                    }

                    // Actualizăm indicatorul de poziție
                    if (doc.querySelector('.indicator-pozitie')) {
                        indicatorPozitie.textContent = doc.querySelector('.indicator-pozitie').textContent;
                    }

                    // Resetăm etichetele existente
                    document.querySelectorAll('.eticheta').forEach(e => e.remove());

                    // Extragem etichetele și denumirile din noul document
                    const eticheteNouElement = doc.querySelector('.container-img');
                    if (eticheteNouElement) {
                        // Actualizăm etichetele afișate pe prima linie (deasupra imaginii)
                        const listaObiecteNou = doc.getElementById('listaObiecte').innerHTML;
                        document.getElementById('listaObiecte').innerHTML = listaObiecteNou;

                        // Adăugăm event listeners pentru etichetele din lista de deasupra
                        document.querySelectorAll('.obiect-salvat').forEach(badge => {
                            adaugaEventListenersPentruEtichete(badge);
                        });

                        // Actualizăm etichetele globale
                        const scriptTags = doc.getElementsByTagName('script');
                        for (let i = 0; i < scriptTags.length; i++) {
                            const scriptContent = scriptTags[i].textContent;

                            // Căutăm definițiile variabilelor globale în script
                            if (scriptContent.includes('eticheteGlobale =')) {
                                // Extragem și executăm definițiile variabilelor
                                const regex = /eticheteGlobale = (.*?);[\s\n]*denumiriGlobale = (.*?);[\s\n]*cantitati = (.*?);/s;
                                const match = scriptContent.match(regex);

                                if (match && match.length >= 4) {
                                    try {
                                        eval('eticheteGlobale = ' + match[1] + ';');
                                        eval('denumiriGlobale = ' + match[2] + ';');
                                        eval('cantitati = ' + match[3] + ';');

                                    } catch (err) {
                                        console.error('Eroare la actualizarea variabilelor globale:', err);
                                    }
                                    break;
                                }
                            }
                        }
                    }

                    // După ce imaginea se încarcă, reafișăm etichetele
                    imgObiect.onload = function() {
                        // Ștergem indicatorul de încărcare
                        document.body.removeChild(divIncarcare);

                        // Afișăm etichetele corespunzătoare noii imagini
                        afiseazaEtichete();
                        
                        // Reafișăm și etichetele Vision dacă există
                        const cheieUnica = 'vision_coords_obj_' + idObiect + '_col_' + (new URLSearchParams(window.location.search).get('colectie') || '1');
                        const coordonateSalvate = localStorage.getItem(cheieUnica);
                        if (coordonateSalvate) {
                            try {
                                const coordonate = JSON.parse(coordonateSalvate);
                                if (coordonate && Object.keys(coordonate).length > 0) {
                                    setTimeout(() => {
                                        afiseazaEticheteVision(coordonate);
                                    }, 100);
                                }
                            } catch (e) {
                                console.error('Eroare la parsarea coordonatelor Vision:', e);
                            }
                        }

                        // Afișăm notificare pentru confirmare
                        const notificare = document.createElement('div');
                        notificare.style.position = 'fixed';
                        notificare.style.bottom = '20px';
                        notificare.style.right = '20px';
                        notificare.style.backgroundColor = '#4CAF50';
                        notificare.style.color = 'white';
                        notificare.style.padding = '10px 20px';
                        notificare.style.borderRadius = '4px';
                        notificare.style.boxShadow = '0 2px 5px rgba(0,0,0,0.2)';
                        notificare.style.zIndex = '1000';
                        notificare.textContent = 'Imaginea ' + nouaPozitie + ' încărcată.';

                        document.body.appendChild(notificare);
                        setTimeout(() => {
                            notificare.style.opacity = '0';
                            notificare.style.transition = 'opacity 0.5s';
                            setTimeout(() => {
                                document.body.removeChild(notificare);
                            }, 500);
                        }, 2000);
                    };

                    // Actualizăm imaginea - aceasta va declanșa și afișarea etichetelor datorită event handler-ului onload
                    imgObiect.src = nouaImagine;
                })
                .catch(error => {
                    console.error('Eroare la navigare:', error);
                    // În caz de eroare, facem o reîncărcare completă a paginii
                    window.location.href = url;
                });
        }

        // Adăugăm event listeners pentru butoanele de navigare
        butonAnterior.addEventListener('click', function(e) {
            e.preventDefault();
            navigheazaLaImagine(this.getAttribute('data-imagine'));
            
        });

        butonUrmator.addEventListener('click', function(e) {
            e.preventDefault();
            navigheazaLaImagine(this.getAttribute('data-imagine'));
            
        });

        // Gestionăm evenimentul de back/forward din browser
        window.addEventListener('popstate', function(event) {
            if (event.state && event.state.imagine) {
                navigheazaLaImagine(event.state.imagine);
            } else {
                // Dacă nu avem stare, reîncărcăm pagina
                window.location.reload();
            }
        });
    })();
</script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Găsim butonul de întoarcere
        const butonIntoarcere = document.querySelector('a[href="index.php"]');

        if (butonIntoarcere) {
            // Modificăm butonul pentru a include funcționalitatea de revenire la ultima imagine vizualizată
            butonIntoarcere.addEventListener('click', function(e) {
                // Salvăm informații despre imaginea curentă pentru a putea reveni la ea ulterior
                const idObiect = document.getElementById('zonaImagine').dataset.id;
                const imagineCurenta = document.getElementById('zonaImagine').dataset.imagineCurenta;

                localStorage.setItem('ultimImagineEditata', imagineCurenta);
                localStorage.setItem('ultimIdObiectEditat', idObiect);

                // Permitem comportamentul normal al link-ului
                // URL-ul va fi index.php, iar logica de derulare
                // va fi gestionată de codul din acea pagină
            });
        }
    });
        // Funcții pentru Harta de Text
        let hartaTextData = null;
        let hartaShowGrid = false;
        let hartaShowCorners = false;
        let hartaZoom = 1;
        
        function afiseazaHartaText(hartaData) {
            console.log('Afișare hartă text cu', hartaData.elemente.length, 'cuvinte');
            hartaTextData = hartaData;
            
            // Afișăm modalul și îl facem să rămână deschis
            const modal = document.getElementById('hartaTextModal');
            modal.style.display = 'flex';
            
            // Prevenim închiderea accidentală - doar butoanele explicite închid
            modal.onclick = function(event) {
                if (event.target === modal) {
                    event.stopPropagation();
                    event.preventDefault();
                    // Nu închidem modalul la click pe fundal
                    console.log('Click pe fundal - modalul rămâne deschis. Folosiți butoanele pentru a închide.');
                }
            };
            
            // Inițializăm canvas-ul
            const canvas = document.getElementById('hartaTextCanvas');
            const ctx = canvas.getContext('2d');
            
            // Setăm dimensiunile canvas-ului
            canvas.width = hartaData.dimensiuni.width || 4000;
            canvas.height = hartaData.dimensiuni.height || 3000;
            
            // Desenăm harta
            deseneazaHartaText();
            
            // Actualizăm statisticile
            const stats = `${hartaData.elemente.length} cuvinte detectate | 
                          Dimensiuni: ${canvas.width} x ${canvas.height}px`;
            document.getElementById('hartaStats').textContent = stats;
            
            // Adăugăm notificare pentru utilizator
            console.log('Harta de text este afișată. Folosiți butoanele pentru control.');
        }
        
        function deseneazaHartaText() {
            if (!hartaTextData) return;
            
            const canvas = document.getElementById('hartaTextCanvas');
            const ctx = canvas.getContext('2d');
            
            // Curățăm canvas-ul
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            
            // Fundal alb
            ctx.fillStyle = 'white';
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            
            // Grid opțional
            if (hartaShowGrid) {
                ctx.strokeStyle = '#e0e0e0';
                ctx.lineWidth = 0.5;
                
                // Linii verticale la fiecare 100px
                for (let x = 0; x <= canvas.width; x += 100) {
                    ctx.beginPath();
                    ctx.moveTo(x, 0);
                    ctx.lineTo(x, canvas.height);
                    ctx.stroke();
                }
                
                // Linii orizontale la fiecare 100px
                for (let y = 0; y <= canvas.height; y += 100) {
                    ctx.beginPath();
                    ctx.moveTo(0, y);
                    ctx.lineTo(canvas.width, y);
                    ctx.stroke();
                }
            }
            
            // Desenăm toate cuvintele
            ctx.font = '14px Arial';
            hartaTextData.elemente.forEach(elem => {
                // Determinăm culoarea
                let color = 'black';
                
                if (hartaShowCorners && hartaTextData.colturi) {
                    const colturi = hartaTextData.colturi;
                    if (colturi.stanga_sus && elem.text === colturi.stanga_sus.text) {
                        color = 'red';
                        ctx.font = 'bold 16px Arial';
                    } else if (colturi.dreapta_sus && elem.text === colturi.dreapta_sus.text) {
                        color = 'green';
                        ctx.font = 'bold 16px Arial';
                    } else if (colturi.stanga_jos && elem.text === colturi.stanga_jos.text) {
                        color = 'blue';
                        ctx.font = 'bold 16px Arial';
                    } else if (colturi.dreapta_jos && elem.text === colturi.dreapta_jos.text) {
                        color = 'purple';
                        ctx.font = 'bold 16px Arial';
                    } else {
                        ctx.font = '14px Arial';
                    }
                }
                
                ctx.fillStyle = color;
                ctx.fillText(elem.text, elem.x, elem.y);
                
                // Marcăm poziția exactă cu un punct mic
                ctx.fillStyle = color;
                ctx.fillRect(elem.x - 2, elem.y - 2, 4, 4);
            });
            
            // Evidențiem colțurile cu dreptunghiuri
            if (hartaShowCorners && hartaTextData.colturi) {
                ctx.lineWidth = 3;
                const padding = 20;
                const colturi = hartaTextData.colturi;
                
                // Stânga-sus
                if (colturi.stanga_sus) {
                    ctx.strokeStyle = 'red';
                    ctx.strokeRect(colturi.stanga_sus.x - padding, colturi.stanga_sus.y - padding, 
                                  padding * 4, padding * 2);
                }
                
                // Dreapta-sus
                if (colturi.dreapta_sus) {
                    ctx.strokeStyle = 'green';
                    ctx.strokeRect(colturi.dreapta_sus.x - padding, colturi.dreapta_sus.y - padding, 
                                  padding * 4, padding * 2);
                }
                
                // Stânga-jos
                if (colturi.stanga_jos) {
                    ctx.strokeStyle = 'blue';
                    ctx.strokeRect(colturi.stanga_jos.x - padding, colturi.stanga_jos.y - padding, 
                                  padding * 4, padding * 2);
                }
                
                // Dreapta-jos
                if (colturi.dreapta_jos) {
                    ctx.strokeStyle = 'purple';
                    ctx.strokeRect(colturi.dreapta_jos.x - padding, colturi.dreapta_jos.y - padding, 
                                  padding * 4, padding * 2);
                }
            }
        }
        
        function toggleHartaGrid() {
            hartaShowGrid = !hartaShowGrid;
            deseneazaHartaText();
        }
        
        function toggleHartaCorners() {
            hartaShowCorners = !hartaShowCorners;
            deseneazaHartaText();
        }
        
        function zoomHartaIn() {
            hartaZoom *= 1.2;
            const canvas = document.getElementById('hartaTextCanvas');
            canvas.style.transform = `scale(${hartaZoom})`;
            canvas.style.transformOrigin = 'top left';
        }
        
        function zoomHartaOut() {
            hartaZoom /= 1.2;
            const canvas = document.getElementById('hartaTextCanvas');
            canvas.style.transform = `scale(${hartaZoom})`;
            canvas.style.transformOrigin = 'top left';
        }
        
        function closeHartaTextModal() {
            document.getElementById('hartaTextModal').style.display = 'none';
            hartaZoom = 1;
            const canvas = document.getElementById('hartaTextCanvas');
            canvas.style.transform = 'scale(1)';
        }
        
        function veziHartaCuvintelor() {
            // Închidem modalul de info cărți
            closeCartiInfoModal();
            // Afișăm harta de text
            setTimeout(() => {
                if (window.hartaTextPendinta) {
                    afiseazaHartaText(window.hartaTextPendinta);
                }
            }, 300);
        }
        
        function inapoiLaInfoCarti() {
            // Închidem harta de text
            closeHartaTextModal();
            // Re-afișăm modalul cu informații despre cărți
            setTimeout(() => {
                document.getElementById('cartiInfoModal').style.display = 'flex';
            }, 300);
        }
</script>
</body>
</html>