<?php
// actualizeaza_obiect.php
error_reporting(0);
ini_set('display_errors', 0);
ob_start(); // Pornim buffer-ul de output pentru a preveni output accidental

session_start();
include 'config.php';

// Verifică autentificarea pentru sistemul multi-tenant
if (file_exists('includes/auth_functions.php')) {
    require_once 'includes/auth_functions.php';
    
    $user = checkSession();
    if (!$user) {
        ob_clean();
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Neautorizat']);
        exit;
    }
    
    // Reconectează la baza de date a utilizatorului
    mysqli_close($conn);
    $conn = getUserDbConnection($user['db_name']);
    
    // Determină prefixul corect bazat pe colecția curentă
    // Prioritate: POST > sesiune selectată > sesiune curentă
    $id_colectie = $_POST['id_colectie'] ?? $_SESSION['id_colectie_selectata'] ?? $_SESSION['id_colectie_curenta'] ?? null;
    
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
                ob_clean();
                http_response_code(403);
                header('Content-Type: application/json');
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
                    $user_id = $colectie_proprietar_id; // Actualizăm user_id pentru a salva imaginile în folderul corect
                }
                mysqli_stmt_close($stmt_owner);
            }
        } else {
            $table_prefix = $user['prefix_tabele'] ?? 'user_' . $user['id_utilizator'] . '_';
        }
        mysqli_stmt_close($stmt_prefix);
        mysqli_close($conn_central);
    } else {
        $table_prefix = $_SESSION['prefix_tabele'] ?? $user['prefix_tabele'] ?? 'user_' . $user['id_utilizator'] . '_';
    }
    
    // Setăm user_id doar dacă nu a fost setat mai sus (pentru colecții non-partajate)
    if (!isset($user_id)) {
        $user_id = $user['id_utilizator'];
    }
} else {
    $table_prefix = $GLOBALS['table_prefix'] ?? '';
    $user_id = getCurrentUserId();
}

// Funcție pentru eliminarea diacriticelor și transformarea în echivalente fără diacritice
function eliminaDiacritice($text) {
    if (empty($text)) return $text;

    $diacritice = [
        'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't',
        'Ă' => 'A', 'Â' => 'A', 'Î' => 'I', 'Ș' => 'S', 'Ş' => 'S', 'Ț' => 'T', 'Ţ' => 'T',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'Á' => 'A', 'À' => 'A', 'Ä' => 'A',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'Í' => 'I', 'Ì' => 'I', 'Ï' => 'I',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Ö' => 'O',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
        'ç' => 'c', 'Ç' => 'C', 'ñ' => 'n', 'Ñ' => 'N'
    ];

    return strtr($text, $diacritice);
}

// Funcție pentru salvarea unei imagini din Base64
function salveazaImagineBase64($base64img, $nume_fisier) {
    // Directorul pentru imaginile decupate - specific pentru fiecare utilizator
    global $user_id;
    $director = 'imagini_decupate/user_' . $user_id . '/';

    // Asigură-te că directorul există
    if (!is_dir($director)) {
        mkdir($director, 0777, true);
    }

    // ÎNLOCUIȚI ACEASTĂ LINIE:
    // $nume_fisier = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $nume_fisier);

    // CU ACESTE DOUĂ LINII:
    $nume_fisier = eliminaDiacritice($nume_fisier); // Mai întâi eliminăm diacriticele
    $nume_fisier = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $nume_fisier); // Apoi înlocuim alte caractere nepermise

    $nume_fisier .= '.png';

    // Calea completă
    $cale_fisier = $director . $nume_fisier;

    // Extrage datele din Base64
    if (strpos($base64img, ',') !== false) {
        list(, $base64img) = explode(',', $base64img);
    }
    $data = base64_decode($base64img);

    // Salvează imaginea
    if (file_put_contents($cale_fisier, $data)) {
        return $nume_fisier;
    }

    return false;
}

// Funcție pentru generarea unei culori consistente bazată pe textul categoriei
// Replica funcția hash din JavaScript pentru consistență
function genereaza_culoare_consistenta($text) {
    // Implementăm funcția hashCode din JavaScript
    $hash = 0;
    $text_length = strlen($text);
    for ($i = 0; $i < $text_length; $i++) {
        $char = ord($text[$i]);
        $hash = (($hash << 5) - $hash) + $char;
        $hash = $hash & $hash; // Convertim la 32bit integer
    }

    // Implementăm funcția intToRGB din JavaScript
    $c = $hash & 0x00FFFFFF;
    $hex = sprintf("#%06X", $c);

    return $hex;
}

function genereaza_culoare_random() {
    return sprintf('#%06X', mt_rand(0, 0xFFFFFF));
}

// Funcție pentru curățarea virgulelor din textul etichetelor
function curataVirgule($text) {
    if (empty($text)) return $text;
    return str_replace(',', '-', $text);
}

// Funcție pentru curățarea întregului array de denumiri
function curataDenumiri($denumiri) {
    if (empty($denumiri)) return $denumiri;
    $rezultat = [];
    foreach ($denumiri as $denumire) {
        // Verificăm dacă avem indexul imaginii în paranteze
        if (preg_match('/^(.+)(\(\d+\))$/', $denumire, $matches)) {
            $rezultat[] = curataVirgule($matches[1]) . $matches[2];
        } else {
            $rezultat[] = curataVirgule($denumire);
        }
    }
    return $rezultat;
}

// Verificare modificată pentru a permite actualizarea cantității fără 'valoare'
if ((!isset($_POST['camp'])) ||
    ($_POST['camp'] !== 'actualizare_cantitate' && !isset($_POST['valoare']))) {
    http_response_code(400);
    header('Content-Type: application/json');
    exit(json_encode(['success' => false, 'error' => 'Date insuficiente.']));
}

$id_obiect = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$camp = trim($_POST['camp']);
$valoare = isset($_POST['valoare']) ? trim($_POST['valoare']) : '';
$cantitati = isset($_POST['cantitati']) ? trim($_POST['cantitati']) : '';
$cutie = trim($_POST['cutie'] ?? '');
$locatie = trim($_POST['locatie'] ?? '');
$imagine = trim($_POST['imagine'] ?? '');
$pozitie_imagine = isset($_POST['pozitie_imagine']) ? (int)$_POST['pozitie_imagine'] : 0; // MODIFICAT: 1->0
$pastrare_asocieri = isset($_POST['pastrare_asocieri']) && $_POST['pastrare_asocieri'] === 'true';
$pastrare_virgule = isset($_POST['pastrare_virgule']) && $_POST['pastrare_virgule'] === 'true';
$etichete_obiect = isset($_POST['etichete_obiect']) ? trim($_POST['etichete_obiect']) : '';

// Curățăm virgulele din valoarea primită direct DOAR dacă nu este categorie cu păstrare virgule
if (!($camp === 'categorie' && $pastrare_virgule)) {
    $valoare = curataVirgule($valoare);
}

$campuri_permise = [
    'categorie',
    'eticheta',
    'descriere_categorie',
    'denumire_obiect',
    'cantitate_obiect',
    'cutie',
    'locatie'
];

// 1) Adăugare obiect nou în același rând (id = 0)
if ($camp === 'denumire_obiect' && $id_obiect === 0) {
    // Verificăm dacă există deja obiecte în înregistrare
    $sql = "SELECT denumire_obiect, cantitate_obiect, eticheta_obiect, imagine_obiect FROM `{$table_prefix}obiecte` 
           WHERE cutie = ? AND locatie = ? AND imagine = ? LIMIT 1";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'sss', $cutie, $locatie, $imagine);
    mysqli_stmt_execute($stmt);
    $rezultat = mysqli_stmt_get_result($stmt);
    $date_existente = mysqli_fetch_assoc($rezultat);
    mysqli_stmt_close($stmt);

    $denumiri_existente = $date_existente['denumire_obiect'] ?? '';
    $cantitati_existente = $date_existente['cantitate_obiect'] ?? '';
    $etichete_existente = $date_existente['eticheta_obiect'] ?? '';
    $imagini_obiect_existente = $date_existente['imagine_obiect'] ?? '';

    // Valorea din formular vine deja cu indexul imaginii inclus
    // Pentru a menține compatibilitatea, verificăm dacă valoarea are deja index
    if (!preg_match('/\(\d+\)$/', $valoare)) {
        $valoare = $valoare . "({$pozitie_imagine})";
    }

    // Adăugăm noile valori, folosind separatorul corect doar dacă există deja înregistrări
    $denumiri_noi = !empty($denumiri_existente) ? $denumiri_existente . ', ' . $valoare : $valoare;
    $cantitati_noi = !empty($cantitati_existente) ? $cantitati_existente . ', 1' : '1';

    $culoare = isset($_POST['eticheta']) ? trim($_POST['eticheta']) : '#ccc';
    $etichete_noi = !empty($etichete_existente) ? $etichete_existente . '; ' . $culoare : $culoare;

    // Procesăm imaginea decupată dacă există
    $imagine_obiect_noua = '';
    if (isset($_POST['imagine_decupata']) && !empty($_POST['imagine_decupata']) &&
        isset($_POST['nume_obiect']) && !empty($_POST['nume_obiect'])) {

        $nume_obiect = $_POST['nume_obiect'];
        $imagine_decupata = $_POST['imagine_decupata'];

        // Salvăm imaginea decupată
        $nume_fisier = salveazaImagineBase64($imagine_decupata, $nume_obiect);

        if ($nume_fisier) {
            $imagine_obiect_noua = $nume_fisier;
        }
    }

    // Adăugăm numele imaginii decupate în șirul de imagini decupate
    $imagini_obiect_noi = !empty($imagini_obiect_existente) ?
        $imagini_obiect_existente . ', ' . $imagine_obiect_noua : $imagine_obiect_noua;

    // Obținem categoria și descrierea din POST
    $categorie = $_POST['categorie'] ?? 'obiecte';
    $descriere_categorie = $_POST['descriere_categorie'] ?? 'obiecte';
    
    // Verificăm dacă există deja o înregistrare
    if ($date_existente) {
        // Actualizăm înregistrarea existentă
        $sql = "UPDATE `{$table_prefix}obiecte` SET denumire_obiect = ?, cantitate_obiect = ?, eticheta_obiect = ?, imagine_obiect = ?, categorie = ?, descriere_categorie = ?
                WHERE cutie = ? AND locatie = ? AND imagine = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, 'sssssssss', $denumiri_noi, $cantitati_noi, $etichete_noi, $imagini_obiect_noi, $categorie, $descriere_categorie, $cutie, $locatie, $imagine);
    } else {
        // Cream o înregistrare nouă
        $sql = "INSERT INTO `{$table_prefix}obiecte` (denumire_obiect, cantitate_obiect, eticheta_obiect, imagine_obiect, categorie, descriere_categorie, cutie, locatie, imagine) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, 'sssssssss', $denumiri_noi, $cantitati_noi, $etichete_noi, $imagini_obiect_noi, $categorie, $descriere_categorie, $cutie, $locatie, $imagine);
    }

    if (mysqli_stmt_execute($stmt)) {
        // Găsim ID-ul obiectului tocmai actualizat
        $get_id_sql = "SELECT id_obiect FROM `{$table_prefix}obiecte` WHERE cutie = ? AND locatie = ? AND imagine = ? LIMIT 1";
        $get_id_stmt = mysqli_prepare($conn, $get_id_sql);
        mysqli_stmt_bind_param($get_id_stmt, 'sss', $cutie, $locatie, $imagine);
        mysqli_stmt_execute($get_id_stmt);
        $result_id = mysqli_stmt_get_result($get_id_stmt);
        $row_id = mysqli_fetch_assoc($result_id);
        mysqli_stmt_close($get_id_stmt);

        if ($row_id && $row_id['id_obiect']) {
            // Salvăm în tabela de detecții ca manual
            $nume_curat = preg_replace('/\s*\(\d+\)$/', '', $valoare);
            $detect_sql = "INSERT INTO `{$table_prefix}detectii_obiecte` (id_obiect, denumire, sursa) 
                          VALUES (?, ?, 'manual')";
            if ($detect_stmt = mysqli_prepare($conn, $detect_sql)) {
                mysqli_stmt_bind_param($detect_stmt, 'is', $row_id['id_obiect'], $nume_curat);
                mysqli_stmt_execute($detect_stmt);
                mysqli_stmt_close($detect_stmt);
            }
            
            // VERIFICARE INTELIGENTĂ 2: Dacă adaugă manual un obiect blocat, îl deblocăm
            if (!empty($nume_curat)) {
                $conn_central = getCentralDbConnection();
                
                // Verificăm dacă acest obiect este în lista de excluderi
                $sql_check_exclus = "SELECT id, obiecte_excluse FROM context_locatii 
                                    WHERE locatie = ? AND cutie = ?";
                $stmt_check = mysqli_prepare($conn_central, $sql_check_exclus);
                mysqli_stmt_bind_param($stmt_check, "ss", $locatie, $cutie);
                mysqli_stmt_execute($stmt_check);
                $result_check = mysqli_stmt_get_result($stmt_check);
                
                if ($row_context = mysqli_fetch_assoc($result_check)) {
                    if (!empty($row_context['obiecte_excluse'])) {
                        $obiecte_excluse = array_map('trim', explode(',', $row_context['obiecte_excluse']));
                        $nume_curat_lower = strtolower(trim($nume_curat));
                        
                        // Verificăm dacă obiectul adăugat manual este în lista de excluderi
                        $gasit_in_excluderi = false;
                        $index_gasit = -1;
                        foreach ($obiecte_excluse as $index => $exclus) {
                            if (strcasecmp(trim($exclus), $nume_curat_lower) == 0 || 
                                stripos($nume_curat_lower, trim($exclus)) !== false ||
                                stripos(trim($exclus), $nume_curat_lower) !== false) {
                                $gasit_in_excluderi = true;
                                $index_gasit = $index;
                                break;
                            }
                        }
                        
                        if ($gasit_in_excluderi) {
                            // Îl eliminăm din excluderi
                            error_log("[Vision Smart Unblock] Obiect '$nume_curat' adăugat manual - îl deblochez din excluderi");
                            
                            unset($obiecte_excluse[$index_gasit]);
                            $excluderi_noi = implode(',', array_filter($obiecte_excluse));
                            
                            $sql_update = "UPDATE context_locatii 
                                         SET obiecte_excluse = ?,
                                             ultima_actualizare = NOW()
                                         WHERE id = ?";
                            $stmt_update = mysqli_prepare($conn_central, $sql_update);
                            mysqli_stmt_bind_param($stmt_update, "si", $excluderi_noi, $row_context['id']);
                            mysqli_stmt_execute($stmt_update);
                            mysqli_stmt_close($stmt_update);
                            
                            // Înregistrăm în istoric
                            $sql_istoric = "INSERT INTO context_corectii 
                                          (id_utilizator, locatie, cutie, obiect_original, actiune, data_corectie) 
                                          VALUES (?, ?, ?, ?, 'deblocat_manual', NOW())";
                            $stmt_istoric = mysqli_prepare($conn_central, $sql_istoric);
                            mysqli_stmt_bind_param($stmt_istoric, "isss", $user_id, $locatie, $cutie, $nume_curat);
                            mysqli_stmt_execute($stmt_istoric);
                            mysqli_stmt_close($stmt_istoric);
                            
                            error_log("[Vision Smart Unblock] ✓ Obiect '$nume_curat' deblocat cu succes din excluderi");
                        }
                    }
                }
                mysqli_stmt_close($stmt_check);
                mysqli_close($conn_central);
            }
        }

        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Obiect adăugat cu succes în lista curentă.']);
    } else {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Eroare la adăugarea obiectului: ' . mysqli_error($conn)]);
    }

    mysqli_stmt_close($stmt);
    mysqli_close($conn);
    exit;
}

// 2) Actualizare combinată denumire_obiect + cantitate_obiect + eticheta_obiect
if ($id_obiect > 0 && $camp === 'denumire_obiect') {
    // Inițializăm obiectul de debugging
    $debug_mode = isset($_POST['debug_mode']) && $_POST['debug_mode'] === 'true';
    $debug_info = [];

    if ($debug_mode) {
        $debug_info['time'] = date('Y-m-d H:i:s');
        $debug_info['post_data'] = $_POST;
    }

    // Obținem datele actuale din baza de date pentru toate coloanele corelate
    $sql = "SELECT denumire_obiect, cantitate_obiect, eticheta_obiect, imagine_obiect FROM `{$table_prefix}obiecte` WHERE id_obiect = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'i', $id_obiect);
    mysqli_stmt_execute($stmt);
    $rezultat = mysqli_stmt_get_result($stmt);
    $date_existente = mysqli_fetch_assoc($rezultat);
    mysqli_stmt_close($stmt);
    
    // HOOK SIMPLIFICAT AICI - Detectăm ștergeri înainte de procesare (doar dacă avem date)
    if (file_exists('hook_stergere_vision.php') && !empty($date_existente['denumire_obiect']) && isset($valoare)) {
        // Verificăm dacă e obiect Vision (are #ff6600)
        $sql_vision = "SELECT eticheta_obiect, locatie, cutie FROM `{$table_prefix}obiecte` WHERE id_obiect = ?";
        $stmt_v = mysqli_prepare($conn, $sql_vision);
        mysqli_stmt_bind_param($stmt_v, "i", $id_obiect);
        mysqli_stmt_execute($stmt_v);
        $result_v = mysqli_stmt_get_result($stmt_v);
        $row_v = mysqli_fetch_assoc($result_v);
        mysqli_stmt_close($stmt_v);
        
        if ($row_v && strpos($row_v['eticheta_obiect'], '#ff6600') !== false) {
            // E obiect Vision! Comparăm listele
            require_once 'hook_stergere_vision.php';
            $conn_central = getCentralDbConnection();
            
            $obiecte_vechi = array_map('trim', explode(',', $date_existente['denumire_obiect']));
            $obiecte_noi = !empty($valoare) 
                ? array_map('trim', explode(',', $valoare)) 
                : [];
            
            // VERIFICARE INTELIGENTĂ: Dacă șterge TOT, resetăm contextul
            if (count($obiecte_noi) == 0 || (count($obiecte_noi) == 1 && empty($obiecte_noi[0]))) {
                // Utilizatorul a șters TOATE obiectele
                // Verificăm dacă cutia are imagini
                $sql_check_img = "SELECT COUNT(DISTINCT imagine_obiect) as nr_imagini 
                                 FROM `{$table_prefix}obiecte` 
                                 WHERE locatie = ? AND cutie = ? AND imagine_obiect IS NOT NULL AND imagine_obiect != ''";
                $stmt_img = mysqli_prepare($conn, $sql_check_img);
                mysqli_stmt_bind_param($stmt_img, "ss", $row_v['locatie'], $row_v['cutie']);
                mysqli_stmt_execute($stmt_img);
                $result_img = mysqli_stmt_get_result($stmt_img);
                $row_img = mysqli_fetch_assoc($result_img);
                mysqli_stmt_close($stmt_img);
                
                if ($row_img && $row_img['nr_imagini'] > 0) {
                    // Cutia are imagini - utilizatorul vrea să o ia de la zero
                    // RESETĂM CONTEXTUL pentru această locație/cutie
                    error_log("[Vision Smart Reset] Ștergere totală detectată pentru {$row_v['locatie']}/{$row_v['cutie']} - resetez contextul");
                    
                    // NU MAI RESETĂM EXCLUDERILE - păstrăm învățarea
                    $sql_reset = "UPDATE context_locatii 
                                 SET incredere = 0.5,
                                     ultima_actualizare = NOW()
                                 WHERE locatie = ? AND cutie = ?";
                    $stmt_reset = mysqli_prepare($conn_central, $sql_reset);
                    mysqli_stmt_bind_param($stmt_reset, "ss", $row_v['locatie'], $row_v['cutie']);
                    mysqli_stmt_execute($stmt_reset);
                    $affected = mysqli_affected_rows($conn_central);
                    mysqli_stmt_close($stmt_reset);
                    
                    if ($affected > 0) {
                        error_log("[Vision Smart Reset] ✓ Context resetat PARȚIAL (păstrez excluderi) pentru {$row_v['locatie']}/{$row_v['cutie']}");
                        
                        // Înregistrăm în istoric
                        $sql_istoric = "INSERT INTO context_corectii 
                                       (id_utilizator, locatie, cutie, obiect_original, actiune, data_corectie) 
                                       VALUES (?, ?, ?, 'RESET_TOTAL', 'reset', NOW())";
                        $stmt_istoric = mysqli_prepare($conn_central, $sql_istoric);
                        mysqli_stmt_bind_param($stmt_istoric, "iss", $user_id, $row_v['locatie'], $row_v['cutie']);
                        mysqli_stmt_execute($stmt_istoric);
                        mysqli_stmt_close($stmt_istoric);
                    }
                }
            } else {
                // Procesare normală - găsim ce s-a șters individual
                foreach ($obiecte_vechi as $vechi) {
                    if (empty(trim($vechi))) continue;
                    
                    $vechi_clean = preg_replace('/\(\d+\)$/', '', trim($vechi));
                    $gasit = false;
                    
                    foreach ($obiecte_noi as $nou) {
                        if (empty(trim($nou))) continue;
                        $nou_clean = preg_replace('/\(\d+\)$/', '', trim($nou));
                        if (strcasecmp($vechi_clean, $nou_clean) == 0) {
                            $gasit = true;
                            break;
                        }
                    }
                    
                    if (!$gasit && !empty($vechi_clean)) {
                        // Obiect șters - apelăm hook-ul
                        inregistreazaStergereVision($conn, $conn_central, $id_obiect, 
                            $vechi, $row_v['locatie'], $row_v['cutie'], 
                            $user_id, $table_prefix, $id_colectie);
                    }
                }
            }
            
            mysqli_close($conn_central);
        }
    }

    if ($debug_mode) {
        $debug_info['date_existente'] = $date_existente;
    }

    // Verificăm dacă avem denumiri și cantități din formular
    if (!isset($_POST['denumiri']) || !isset($_POST['cantitati'])) {
        if ($debug_mode) {
            header('Content-Type: application/json');
            echo json_encode([
                'error' => "Date insuficiente. Trebuie să trimiteți 'denumiri' și 'cantitati'.",
                'debug' => $debug_info
            ]);
        } else {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Date insuficiente. Interfața actualizată trebuie să trimită denumiri și cantitati.']);
        }
        exit;
    }
    
    // Convertim arrays în string-uri dacă e necesar
    $denumiri_post = $_POST['denumiri'];
    $cantitati_post = $_POST['cantitati'];
    
    // Tratăm cazul special când primim arrays goale
    if (is_array($denumiri_post)) {
        if (empty($denumiri_post)) {
            $denumiri_post = '';
        } else {
            $denumiri_post = implode(', ', $denumiri_post);
        }
    }
    if (is_array($cantitati_post)) {
        if (empty($cantitati_post)) {
            $cantitati_post = '';
        } else {
            $cantitati_post = implode(', ', $cantitati_post);
        }
    }
    
    // Asigurăm că sunt string-uri
    $denumiri_post = (string)$denumiri_post;
    $cantitati_post = (string)$cantitati_post;

    // Cazul special: Verificare pentru lista goală
    if (empty(trim($denumiri_post))) {
        // Toate obiectele au fost șterse
        error_log("[Vision Smart Reset v2] ÎNCEPUT RESET - Lista goală detectată pentru id_obiect=$id_obiect");
        error_log("[Vision Smart Reset v2] User curent: {$user['id_utilizator']}, User_id folosit: $user_id");
        error_log("[Vision Smart Reset v2] Table prefix: $table_prefix");
        error_log("[Vision Smart Reset v2] ID colecție: " . ($id_colectie ?? 'NULL'));
        
        // SMART RESET: Verificăm dacă trebuie să resetăm contextul Vision
        $sql_check_reset = "SELECT locatie, cutie FROM `{$table_prefix}obiecte` WHERE id_obiect = ?";
        $stmt_reset = mysqli_prepare($conn, $sql_check_reset);
        if (!$stmt_reset) {
            error_log("[Vision Smart Reset v2] EROARE prepare statement: " . mysqli_error($conn));
        }
        mysqli_stmt_bind_param($stmt_reset, "i", $id_obiect);
        mysqli_stmt_execute($stmt_reset);
        $result_reset = mysqli_stmt_get_result($stmt_reset);
        $row_reset = mysqli_fetch_assoc($result_reset);
        mysqli_stmt_close($stmt_reset);
        
        error_log("[Vision Smart Reset v2] Date găsite pentru obiect: " . json_encode($row_reset));
        
        if ($row_reset) {
            // Pentru colecții partajate, trebuie să folosim prefixul corect pentru context
            // Determinăm ID-ul real al proprietarului colecției
            $proprietar_real = isset($colectie_proprietar_id) ? $colectie_proprietar_id : $user['id_utilizator'];
            $locatie_context = $row_reset['locatie'];
            $cutie_context = $row_reset['cutie'];
            
            // Pentru a evita conflicte, prefixăm locația cu ID-ul proprietarului
            // DAR doar pentru context, nu pentru query-uri în BD
            if ($proprietar_real != $user['id_utilizator']) {
                error_log("[Vision Smart Reset v2] Colecție partajată detectată - proprietar: $proprietar_real, utilizator curent: {$user['id_utilizator']}");
            }
            
            // Verificăm dacă cutia are imagini - INCLUSIV imaginile principale
            $sql_img = "SELECT COUNT(DISTINCT imagine_obiect) as nr_imagini_obiect,
                              COUNT(DISTINCT imagine) as nr_imagini_principale
                       FROM `{$table_prefix}obiecte` 
                       WHERE locatie = ? AND cutie = ?";
            $stmt_img = mysqli_prepare($conn, $sql_img);
            mysqli_stmt_bind_param($stmt_img, "ss", $locatie_context, $cutie_context);
            mysqli_stmt_execute($stmt_img);
            $result_img = mysqli_stmt_get_result($stmt_img);
            $row_img = mysqli_fetch_assoc($result_img);
            mysqli_stmt_close($stmt_img);
            
            $total_imagini = ($row_img['nr_imagini_obiect'] ?? 0) + ($row_img['nr_imagini_principale'] ?? 0);
            error_log("[Vision Smart Reset v2] Verificare imagini pentru {$row_reset['locatie']}/{$row_reset['cutie']}: obiect={$row_img['nr_imagini_obiect']}, principale={$row_img['nr_imagini_principale']}");
            
            if ($total_imagini > 0) {
                // Cutia are imagini - resetăm contextul Vision
                error_log("[Vision Smart Reset v2] Ștergere totală cu imagini pentru {$row_reset['locatie']}/{$row_reset['cutie']} (user_id=$user_id, table_prefix=$table_prefix)");
                
                $conn_central = getCentralDbConnection();
                
                // Verificăm mai întâi ce avem în context pentru această colecție
                $sql_check = "SELECT * FROM context_locatii WHERE locatie = ? AND cutie = ? AND (id_colectie = ? OR id_colectie IS NULL) ORDER BY (id_colectie IS NOT NULL) DESC LIMIT 1";
                $stmt_check = mysqli_prepare($conn_central, $sql_check);
                mysqli_stmt_bind_param($stmt_check, "ssi", $row_reset['locatie'], $row_reset['cutie'], $id_colectie);
                mysqli_stmt_execute($stmt_check);
                $result_check = mysqli_stmt_get_result($stmt_check);
                $context_existent = mysqli_fetch_assoc($result_check);
                mysqli_stmt_close($stmt_check);
                
                if ($context_existent) {
                    error_log("[Vision Smart Reset v2] Context găsit - excluderi actuale: " . substr($context_existent['obiecte_excluse'], 0, 200));
                    
                    // NU RESETĂM EXCLUDERILE! Păstrăm învățarea utilizatorului
                    // Resetăm doar încrederea pentru a permite re-detectare
                    $sql_reset_ctx = "UPDATE context_locatii 
                                     SET incredere = 0.5,
                                         ultima_actualizare = NOW()
                                     WHERE locatie = ? AND cutie = ? AND (id_colectie = ? OR (id_colectie IS NULL AND ? IS NULL))";
                    $stmt_ctx = mysqli_prepare($conn_central, $sql_reset_ctx);
                    mysqli_stmt_bind_param($stmt_ctx, "ssii", $row_reset['locatie'], $row_reset['cutie'], $id_colectie, $id_colectie);
                    mysqli_stmt_execute($stmt_ctx);
                    $affected = mysqli_affected_rows($conn_central);
                    mysqli_stmt_close($stmt_ctx);
                    
                    error_log("[Vision Smart Reset v2] Rezultat reset parțial (păstrez excluderi): $affected rânduri afectate");
                } else {
                    error_log("[Vision Smart Reset v2] Nu există context pentru această locație/cutie - nimic de resetat");
                }
                
                mysqli_close($conn_central);
            } else {
                error_log("[Vision Smart Reset v2] Nu sunt imagini în {$row_reset['locatie']}/{$row_reset['cutie']} - nu resetez");
            }
        }
        
        $valoare_finala = '';
        $cantitati_finala = '';
        $etichete_final = '';
        $imagini_obiect_noi = '';

        // Actualizăm cu valori goale
        $sql = "UPDATE `{$table_prefix}obiecte` SET denumire_obiect = ?, cantitate_obiect = ?, eticheta_obiect = ?, imagine_obiect = ? WHERE id_obiect = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, 'ssssi', $valoare_finala, $cantitati_finala, $etichete_final, $imagini_obiect_noi, $id_obiect);

        if (mysqli_stmt_execute($stmt)) {
            if ($debug_mode) {
                header('Content-Type: application/json');
                echo json_encode([
                    'message' => "Toate obiectele au fost șterse.",
                    'debug' => $debug_info
                ]);
            } else {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => 'Toate obiectele au fost șterse.']);
            }
        } else {
            if ($debug_mode) {
                $debug_info['sql_error'] = mysqli_error($conn);
                header('Content-Type: application/json');
                echo json_encode([
                    'error' => "Eroare la actualizare: " . mysqli_error($conn),
                    'debug' => $debug_info
                ]);
            } else {
                http_response_code(500);
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'Eroare la actualizare: ' . mysqli_error($conn)]);
            }
        }

        mysqli_stmt_close($stmt);
        mysqli_close($conn);
        exit;
    }

    // Prioritizăm flag-ul de actualizare directă a imaginilor - dacă este setat, folosim direct datele trimise
    if (isset($_POST['actualizeaza_imagini']) && $_POST['actualizeaza_imagini'] === 'true' &&
        isset($denumiri_post) && isset($cantitati_post) &&
        isset($_POST['etichete_obiect']) && isset($_POST['imagini_obiect'])) {

        // Folosim direct valorile trimise din frontend
        $valoare_finala = $denumiri_post;
        $cantitati_finala = $cantitati_post;
        $etichete_final = $_POST['etichete_obiect'];
        $imagini_obiect_noi = $_POST['imagini_obiect'];

        if ($debug_mode) {
            $debug_info['action'] = "UPDATE_DIRECT";
            $debug_info['values_for_update'] = [
                'valoare_finala' => $valoare_finala,
                'cantitati_finala' => $cantitati_finala,
                'etichete_final' => $etichete_final,
                'imagini_obiect_noi' => $imagini_obiect_noi
            ];
        }

        // Actualizăm obiectul cu datele primite direct
        $sql = "UPDATE `{$table_prefix}obiecte` SET denumire_obiect = ?, cantitate_obiect = ?, eticheta_obiect = ?, imagine_obiect = ? WHERE id_obiect = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, 'ssssi', $valoare_finala, $cantitati_finala, $etichete_final, $imagini_obiect_noi, $id_obiect);

        if (mysqli_stmt_execute($stmt)) {
            // SMART UNBLOCK: Verificăm dacă vreun obiect adăugat manual trebuie deblocat
            if (!empty($valoare_finala)) {
                $obiecte_adaugate = array_map('trim', explode(',', $valoare_finala));
                if (!empty($obiecte_adaugate)) {
                    // Obținem locația și cutia
                    $sql_loc = "SELECT locatie, cutie FROM `{$table_prefix}obiecte` WHERE id_obiect = ?";
                    $stmt_loc = mysqli_prepare($conn, $sql_loc);
                    mysqli_stmt_bind_param($stmt_loc, "i", $id_obiect);
                    mysqli_stmt_execute($stmt_loc);
                    $result_loc = mysqli_stmt_get_result($stmt_loc);
                    $row_loc = mysqli_fetch_assoc($result_loc);
                    mysqli_stmt_close($stmt_loc);
                    
                    if ($row_loc) {
                        $conn_central = getCentralDbConnection();
                        
                        // Verificăm contextul pentru această locație/cutie și colecție
                        $sql_ctx = "SELECT id, obiecte_excluse FROM context_locatii 
                                   WHERE locatie = ? AND cutie = ? AND (id_colectie = ? OR id_colectie IS NULL) 
                                   ORDER BY (id_colectie IS NOT NULL) DESC LIMIT 1";
                        $stmt_ctx = mysqli_prepare($conn_central, $sql_ctx);
                        mysqli_stmt_bind_param($stmt_ctx, "ssi", $row_loc['locatie'], $row_loc['cutie'], $id_colectie);
                        mysqli_stmt_execute($stmt_ctx);
                        $result_ctx = mysqli_stmt_get_result($stmt_ctx);
                        $row_ctx = mysqli_fetch_assoc($result_ctx);
                        
                        if ($row_ctx && !empty($row_ctx['obiecte_excluse'])) {
                            $excluse_array = array_map('trim', explode(',', $row_ctx['obiecte_excluse']));
                            $excluse_noi = $excluse_array;
                            $obiecte_deblocate = [];
                            
                            // Verificăm fiecare obiect adăugat
                            foreach ($obiecte_adaugate as $obiect_adaugat) {
                                $obiect_curat = preg_replace('/\s*\(\d+\)\s*/', '', strtolower(trim($obiect_adaugat)));
                                
                                // Căutăm în excluderi
                                foreach ($excluse_array as $key => $exclus) {
                                    $exclus_lower = strtolower(trim($exclus));
                                    if ($obiect_curat == $exclus_lower || 
                                        stripos($obiect_curat, $exclus_lower) !== false ||
                                        stripos($exclus_lower, $obiect_curat) !== false) {
                                        unset($excluse_noi[$key]);
                                        $obiecte_deblocate[] = $obiect_curat;
                                        error_log("[Vision Smart Unblock v2] Deblochez '$obiect_curat' din excluderi");
                                    }
                                }
                            }
                            
                            // Dacă am deblocat ceva, actualizăm
                            if (count($obiecte_deblocate) > 0) {
                                $excluderi_finale = implode(',', array_filter($excluse_noi));
                                $sql_update = "UPDATE context_locatii 
                                             SET obiecte_excluse = ?,
                                                 ultima_actualizare = NOW()
                                             WHERE id = ?";
                                $stmt_update = mysqli_prepare($conn_central, $sql_update);
                                mysqli_stmt_bind_param($stmt_update, "si", $excluderi_finale, $row_ctx['id']);
                                mysqli_stmt_execute($stmt_update);
                                mysqli_stmt_close($stmt_update);
                                
                                error_log("[Vision Smart Unblock v2] ✓ Deblocate " . count($obiecte_deblocate) . " obiecte");
                            }
                        }
                        mysqli_stmt_close($stmt_ctx);
                        mysqli_close($conn_central);
                    }
                }
            }
            
            if ($debug_mode) {
                header('Content-Type: application/json');
                echo json_encode([
                    'message' => "Obiecte actualizate cu datele directe din frontend.",
                    'debug' => $debug_info
                ]);
            } else {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => 'Obiecte actualizate.']);
            }
        } else {
            if ($debug_mode) {
                $debug_info['sql_error'] = mysqli_error($conn);
                header('Content-Type: application/json');
                echo json_encode([
                    'error' => "Eroare la actualizare directă: " . mysqli_error($conn),
                    'debug' => $debug_info
                ]);
            } else {
                http_response_code(500);
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'Eroare la actualizare: ' . mysqli_error($conn)]);
            }
        }

        mysqli_stmt_close($stmt);
        mysqli_close($conn);
        exit;
    }

    // Pentru cazurile în care nu avem actualizeaza_imagini=true, procesăm manual
    // Construim array-uri sincronizate pentru toate coloanele

    // Pregătim array-uri din valorile existente
    $obiecte_existente = [];

    // Extragem denumirile și cantitățile existente
    $denumiri_vechi = array_map('trim', explode(',', $date_existente['denumire_obiect'] ?? ''));
    $cantitati_vechi = array_map('trim', explode(',', $date_existente['cantitate_obiect'] ?? ''));
    $etichete_vechi = array_map('trim', explode(';', $date_existente['eticheta_obiect'] ?? ''));
    $imagini_vechi = array_map('trim', explode(',', $date_existente['imagine_obiect'] ?? ''));

    // Construim array-ul de obiecte existente, păstrând toate informațiile împreună
    for ($i = 0; $i < count($denumiri_vechi); $i++) {
        $denumire_curenta = $denumiri_vechi[$i];
        $denumire_simpla = $denumire_curenta;
        $index_imagine = 0;

        // Extragem indexul imaginii din denumire
        if (preg_match('/^(.*?)\((\d+)\)$/', $denumire_curenta, $matches)) {
            $denumire_simpla = trim($matches[1]);
            $index_imagine = $matches[2];
        }

        $obiect = [
            'denumire_simpla' => $denumire_simpla,
            'denumire_completa' => $denumire_curenta,
            'index_imagine' => $index_imagine,
            'cantitate' => isset($cantitati_vechi[$i]) ? $cantitati_vechi[$i] : '1',
            'eticheta' => isset($etichete_vechi[$i]) ? $etichete_vechi[$i] : '#ccc',
            'imagine' => isset($imagini_vechi[$i]) ? $imagini_vechi[$i] : ''
        ];

        $obiecte_existente[] = $obiect;
    }

    if ($debug_mode) {
        $debug_info['obiecte_existente'] = $obiecte_existente;
    }

    // Pregătim datele din POST
    $denumiri_noi = !empty($denumiri_post) ? array_map('trim', explode(',', $denumiri_post)) : [];
    $denumiri_noi = curataDenumiri($denumiri_noi);
    $cantitati_noi = !empty($cantitati_post) ? array_map('trim', explode(',', $cantitati_post)) : [];

    // Construim lista de obiecte noi din POST
    $obiecte_din_post = [];
    for ($i = 0; $i < count($denumiri_noi); $i++) {
        $denumire_noua = $denumiri_noi[$i];
        $cantitate_noua = isset($cantitati_noi[$i]) ? $cantitati_noi[$i] : '1';

        $obiect = [
            'denumire_simpla' => $denumire_noua,
            'cantitate' => $cantitate_noua
        ];

        $obiecte_din_post[] = $obiect;
    }

    if ($debug_mode) {
        $debug_info['obiecte_din_post'] = $obiecte_din_post;
    }

    // Identificăm obiectele care trebuie păstrate și actualizate
    $obiecte_finale = [];
    $obiecte_eliminate = [];

    // Construim lista finală de obiecte
    foreach ($obiecte_din_post as $obiect_post) {
        $gasit = false;
        $obiect_actualizat = null;

        // Căutăm obiectul în lista de obiecte existente
        foreach ($obiecte_existente as $obiect_existent) {
            if ($obiect_existent['denumire_simpla'] === $obiect_post['denumire_simpla']) {
                $gasit = true;

                // Creăm un obiect actualizat, păstrând toate informațiile asociate
                $obiect_actualizat = [
                    'denumire_simpla' => $obiect_post['denumire_simpla'],
                    'index_imagine' => $obiect_existent['index_imagine'],
                    'cantitate' => $obiect_post['cantitate'], // Actualizăm cantitatea
                    'eticheta' => $obiect_existent['eticheta'],
                    'imagine' => $obiect_existent['imagine']
                ];

                break;
            }
        }

        if ($gasit && $obiect_actualizat) {
            $obiecte_finale[] = $obiect_actualizat;
        } else {
            // Obiect nou, îl adăugăm cu valori implicite
            $obiecte_finale[] = [
                'denumire_simpla' => $obiect_post['denumire_simpla'],
                'index_imagine' => 0, // Index implicit
                'cantitate' => $obiect_post['cantitate'],
                'eticheta' => '#ccc', // Culoare implicită
                'imagine' => '' // Imagine implicită goală
            ];
        }
    }

    // Identificăm obiectele eliminate (prezente în obiecte_existente dar absente din obiecte_finale)
    foreach ($obiecte_existente as $obiect_existent) {
        $gasit = false;
        foreach ($obiecte_finale as $obiect_final) {
            if ($obiect_existent['denumire_simpla'] === $obiect_final['denumire_simpla']) {
                $gasit = true;
                break;
            }
        }

        if (!$gasit) {
            $obiecte_eliminate[] = $obiect_existent;
            
            // INTEGRARE HOOK - Învățare din ștergeri individuale
            if (file_exists('hook_stergere_vision.php')) {
                require_once 'hook_stergere_vision.php';
                $conn_central_temp = getCentralDbConnection();
                
                // Obținem locația și cutia
                $sql_loc = "SELECT locatie, cutie FROM `{$table_prefix}obiecte` WHERE id_obiect = ?";
                $stmt_loc = mysqli_prepare($conn, $sql_loc);
                mysqli_stmt_bind_param($stmt_loc, "i", $id_obiect);
                mysqli_stmt_execute($stmt_loc);
                $result_loc = mysqli_stmt_get_result($stmt_loc);
                $loc_data = mysqli_fetch_assoc($result_loc);
                mysqli_stmt_close($stmt_loc);
                
                if ($loc_data) {
                    inregistreazaStergereVision($conn, $conn_central_temp, $id_obiect, 
                        $obiect_existent['denumire_completa'], 
                        $loc_data['locatie'], 
                        $loc_data['cutie'], 
                        $user_id, $table_prefix, $id_colectie);
                }
                mysqli_close($conn_central_temp);
            }
        }
    }

    if ($debug_mode) {
        $debug_info['obiecte_finale'] = $obiecte_finale;
        $debug_info['obiecte_eliminate'] = $obiecte_eliminate;
    }

    // Reconstruim șirurile finale pentru toate coloanele
    $denumiri_finale = [];
    $cantitati_finale = [];
    $etichete_finale = [];
    $imagini_finale = [];

    foreach ($obiecte_finale as $obiect) {
        // Adăugăm denumirea cu indexul imaginii
        $denumiri_finale[] = $obiect['denumire_simpla'] . '(' . $obiect['index_imagine'] . ')';
        $cantitati_finale[] = $obiect['cantitate'];
        $etichete_finale[] = $obiect['eticheta'];
        $imagini_finale[] = $obiect['imagine'];
    }

    // Construim șirurile finale pentru UPDATE
    $valoare_finala = implode(', ', $denumiri_finale);
    $cantitati_finala = implode(', ', $cantitati_finale);
    $etichete_final = implode('; ', $etichete_finale);
    $imagini_obiect_noi = implode(', ', $imagini_finale);

    if ($debug_mode) {
        $debug_info['siruri_finale'] = [
            'denumiri' => $valoare_finala,
            'cantitati' => $cantitati_finala,
            'etichete' => $etichete_final,
            'imagini' => $imagini_obiect_noi
        ];
    }

    // Actualizăm obiectul cu toate datele noi
    $sql = "UPDATE `{$table_prefix}obiecte` SET denumire_obiect = ?, cantitate_obiect = ?, eticheta_obiect = ?, imagine_obiect = ? WHERE id_obiect = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'ssssi', $valoare_finala, $cantitati_finala, $etichete_final, $imagini_obiect_noi, $id_obiect);

    if (mysqli_stmt_execute($stmt)) {
        // Salvăm în tabela de detecții toate obiectele noi adăugate manual
        $denumiri_array = explode(', ', $valoare_finala);
        foreach ($denumiri_array as $den) {
            if (!empty(trim($den))) {
                $nume_curat = preg_replace('/\s*\(\d+\)$/', '', $den);
                // Verificăm dacă acest obiect nu există deja în detecții
                $check_sql = "SELECT id FROM `{$table_prefix}detectii_obiecte` WHERE id_obiect = ? AND denumire = ?";
                if ($check_stmt = mysqli_prepare($conn, $check_sql)) {
                    mysqli_stmt_bind_param($check_stmt, 'is', $id_obiect, $nume_curat);
                    mysqli_stmt_execute($check_stmt);
                    $check_result = mysqli_stmt_get_result($check_stmt);
                    if (mysqli_num_rows($check_result) == 0) {
                        // Nu există, îl adăugăm
                        $detect_sql = "INSERT INTO `{$table_prefix}detectii_obiecte` (id_obiect, denumire, sursa) 
                                      VALUES (?, ?, 'manual')";
                        if ($detect_stmt = mysqli_prepare($conn, $detect_sql)) {
                            mysqli_stmt_bind_param($detect_stmt, 'is', $id_obiect, $nume_curat);
                            mysqli_stmt_execute($detect_stmt);
                            mysqli_stmt_close($detect_stmt);
                        }
                    }
                    mysqli_stmt_close($check_stmt);
                }
            }
        }

        $message = "Obiecte actualizate cu succes.";
        if (count($obiecte_eliminate) > 0) {
            $message = count($obiecte_eliminate) > 1 ?
                "Au fost eliminate " . count($obiecte_eliminate) . " obiecte." :
                "A fost eliminat un obiect.";
        }

        if ($debug_mode) {
            header('Content-Type: application/json');
            echo json_encode([
                'message' => $message,
                'debug' => $debug_info
            ]);
        } else {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => $message]);
        }
    } else {
        if ($debug_mode) {
            $debug_info['sql_error'] = mysqli_error($conn);
            header('Content-Type: application/json');
            echo json_encode([
                'error' => "Eroare la actualizare: " . mysqli_error($conn),
                'debug' => $debug_info
            ]);
        } else {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Eroare la actualizare: ' . mysqli_error($conn)]);
        }
    }

    mysqli_stmt_close($stmt);
    mysqli_close($conn);
    exit;
}

// 3) Procesare categorii + etichete
if ($id_obiect > 0 && $camp === 'categorie') {
    $sqlSelect = "SELECT categorie, eticheta, denumire_obiect, eticheta_obiect FROM `{$table_prefix}obiecte` WHERE id_obiect = ?";
    $stmt = mysqli_prepare($conn, $sqlSelect);
    mysqli_stmt_bind_param($stmt, 'i', $id_obiect);
    mysqli_stmt_execute($stmt);
    $rezultat = mysqli_stmt_get_result($stmt);
    $existente = mysqli_fetch_assoc($rezultat);
    mysqli_stmt_close($stmt);

    // Gestionăm separat categoriile pentru a păstra virgulele
    if ($pastrare_virgule) {
        // Categoriile sunt deja separate corect prin virgule în $valoare
        $categorii_noi = !empty($valoare) ? array_map('trim', explode(',', $valoare)) : [];
    } else {
        // Pentru retrocompatibilitate, aplicăm curățarea virgulelor
        $categorii_noi = !empty($valoare) ? array_map('trim', explode(',', $valoare)) : [];
        $categorii_noi = curataDenumiri($categorii_noi);
    }

    $categorii_existente = array_map('trim', explode(',', $existente['categorie'] ?? ''));
    $culori_existente = array_map('trim', explode(',', $existente['eticheta'] ?? ''));

    // Procesăm denumirile pentru a păstra indexurile imaginilor
    $denumiri_brut = array_map('trim', explode(',', $existente['denumire_obiect'] ?? ''));
    $obiecte_existente = [];
    $indexuri_imagini = [];

    foreach ($denumiri_brut as $den) {
        if (preg_match('/^(.+)\((\d+)\)$/', $den, $matches)) {
            $obiecte_existente[] = $matches[1];
            $indexuri_imagini[] = $matches[2];
        } else {
            $obiecte_existente[] = $den;
            $indexuri_imagini[] = 0; // DEJA ESTE 0
        }
    }

    $culori_obiecte = array_map('trim', explode(';', $existente['eticheta_obiect'] ?? ''));

    // MODIFICAT: Folosim algoritm hash pentru generarea de culori consistente
    $asocieri = [];
    foreach ($categorii_noi as $cat) {
        $gasit = false;
        // Căutăm în categoriile existente
        foreach ($categorii_existente as $index => $cat_veche) {
            if ($cat === $cat_veche) {
                $asocieri[$cat] = $culori_existente[$index];
                $gasit = true;
                break;
            }
        }

        // Dacă nu am găsit, generăm o culoare consistentă în loc de una aleatorie
        if (!$gasit) {
            // Utilizăm 'Obiecte' ca un caz special - întotdeauna #ccc
            if (strtolower($cat) === 'obiecte') {
                $asocieri[$cat] = '#ccc';
            } else {
                $asocieri[$cat] = genereaza_culoare_consistenta($cat);
            }
        }
    }

    $mapare_culori = array_values($asocieri);

    // Facem o mapare între culorile vechi și categoriile lor
    $culoare_la_categorie = [];
    foreach ($categorii_existente as $index => $categorie) {
        if (isset($culori_existente[$index])) {
            $culoare_la_categorie[$culori_existente[$index]] = $categorie;
        }
    }

    // Facem o mapare inversă între categorii și culorile noi
    $categorie_la_culoare_noua = [];
    foreach ($asocieri as $categorie => $culoare) {
        $categorie_la_culoare_noua[$categorie] = $culoare;
    }

    // Pentru fiecare culoare de obiect, verificăm dacă categoria sa încă există
    $culori_actualizate = [];
    foreach ($culori_obiecte as $culoare) {
        // Verificăm dacă culoarea aparține unei categorii cunoscute
        if (isset($culoare_la_categorie[$culoare])) {
            // Obținem categoria asociată acestei culori
            $categoria_obiectului = $culoare_la_categorie[$culoare];

            // Verificăm dacă această categorie încă există în noua listă
            if (isset($categorie_la_culoare_noua[$categoria_obiectului])) {
                // Categoria există, păstrăm culoarea asociată acestei categorii
                $culori_actualizate[] = $categorie_la_culoare_noua[$categoria_obiectului];
            } else {
                // Categoria nu mai există, setăm culoarea la #ccc
                $culori_actualizate[] = '#ccc';
            }
        } else {
            // Dacă nu găsim categoria asociată culorii, păstrăm culoarea originală
            // Acest lucru menține culorile pentru obiecte care nu sunt asociate categoriilor
            $culori_actualizate[] = $culoare;
        }
    }

    $categorie_final = implode(', ', array_keys($asocieri));
    $eticheta_final = implode(', ', array_values($asocieri));
    $eticheta_obiect_final = implode('; ', $culori_actualizate);

    $sql = "UPDATE `{$table_prefix}obiecte` SET categorie = ?, eticheta = ?, eticheta_obiect = ? WHERE id_obiect = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'sssi', $categorie_final, $eticheta_final, $eticheta_obiect_final, $id_obiect);

    if (mysqli_stmt_execute($stmt)) {
        // Returnăm un obiect JSON cu categoriile și culorile lor
        $response = [
            'message' => "Categorii, culori și etichete obiect actualizate.",
            'categorii' => array_keys($asocieri),
            'culori' => array_values($asocieri)
        ];

        // Curățăm buffer-ul și setăm header-ul pentru JSON
        ob_clean();
        header('Content-Type: application/json');
        echo json_encode($response);
    } else {
        ob_clean();
        http_response_code(500);
        // Returnăm eroarea în format JSON
        header('Content-Type: application/json');
        echo json_encode(['error' => "Eroare actualizare categorii: " . mysqli_error($conn)]);
    }

    mysqli_stmt_close($stmt);
    mysqli_close($conn);
    exit;
}

// 4) Actualizare simplă pentru alte câmpuri
if ($id_obiect > 0 && in_array($camp, $campuri_permise)) {
    $sql = "UPDATE `{$table_prefix}obiecte` SET $camp = ? WHERE id_obiect = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'si', $valoare, $id_obiect);
    if (mysqli_stmt_execute($stmt)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Modificare salvată.']);
    } else {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Eroare la actualizare: ' . mysqli_error($conn)]);
    }
    mysqli_stmt_close($stmt);
    mysqli_close($conn);
    exit;
}

// 5) Actualizare cantitate pentru un obiect specific
if ($id_obiect > 0 && $camp === 'actualizare_cantitate') {
    $index_eticheta = isset($_POST['index_eticheta']) ? (int)$_POST['index_eticheta'] : -1;
    $cantitate = isset($_POST['cantitate']) ? trim($_POST['cantitate']) : '1';

    if ($index_eticheta >= 0) {
        // Obținem cantitățile existente
        $sql = "SELECT cantitate_obiect FROM `{$table_prefix}obiecte` WHERE id_obiect = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, 'i', $id_obiect);
        mysqli_stmt_execute($stmt);
        $rezultat = mysqli_stmt_get_result($stmt);
        $date_existente = mysqli_fetch_assoc($rezultat);
        mysqli_stmt_close($stmt);

        // Actualizăm cantitatea la indexul specificat
        $cantitati_existente = array_map('trim', explode(',', $date_existente['cantitate_obiect'] ?? ''));

        // Verificăm dacă indexul există în array
        if (isset($cantitati_existente[$index_eticheta])) {
            $cantitati_existente[$index_eticheta] = $cantitate;
            $cantitati_actualizate = implode(', ', $cantitati_existente);

            // Actualizăm în baza de date
            $sql = "UPDATE `{$table_prefix}obiecte` SET cantitate_obiect = ? WHERE id_obiect = ?";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, 'si', $cantitati_actualizate, $id_obiect);

            if (mysqli_stmt_execute($stmt)) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => 'Cantitate actualizată cu succes.']);
            } else {
                http_response_code(500);
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'Eroare la actualizarea cantității: ' . mysqli_error($conn)]);
            }
            mysqli_stmt_close($stmt);
        } else {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Index etichetă invalid.']);
        }
    } else {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Index etichetă lipsă sau invalid.']);
    }

    mysqli_close($conn);
    exit;
}

ob_clean();
http_response_code(400);
header('Content-Type: application/json');
echo json_encode(['success' => false, 'error' => 'Operație nevalidă sau câmp nepermis.']);
exit;
?>