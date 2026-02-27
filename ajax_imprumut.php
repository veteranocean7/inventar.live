<?php
// ajax_imprumut.php - Gestionare cereri de împrumut
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

session_start();
require_once 'config.php';
require_once 'includes/auth_functions.php';
require_once 'includes/email_notifications.php';

header('Content-Type: application/json');

// Verifică autentificarea
$user = checkSession();
if (!$user) {
    echo json_encode(['success' => false, 'error' => 'Neautorizat']);
    exit;
}

// Pentru GET requests
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $actiune = $_GET['actiune'] ?? '';
} else {
    // Pentru POST requests cu JSON
    $raw_input = file_get_contents('php://input');
    $data = json_decode($raw_input, true);
    $actiune = $data['actiune'] ?? $_POST['actiune'] ?? '';
}

switch($actiune) {
    case 'trimite_cerere':
        trimitereCerereImprumut($user);
        break;
        
    case 'obtine_cereri_primite':
        obtineCereriPrimite($user);
        break;
        
    case 'obtine_cereri_trimise':
        obtineCereriTrimise($user);
        break;
        
    case 'numar_cereri_necitite':
        numarCereriNecitite($user);
        break;
        
    case 'numar_raspunsuri_necitite':
        numarRaspunsuriNecitite($user);
        break;
        
    case 'raspunde_cerere':
        raspundeCerere($user);
        break;
        
    case 'obtine_detalii_pentru_qr':
        obtineDetaliiPentruQR($user);
        break;
        
    case 'salveaza_qr':
        salveazaQR($user);
        break;
        
    case 'valideaza_qr':
        valideazaQR($user);
        break;
        
    case 'confirma_transfer':
        confirmaTransfer($user);
        break;
        
    case 'obtine_imprumuturi_active':
        obtineImprumuturiActive($user);
        break;
        
    default:
        echo json_encode(['success' => false, 'error' => 'Acțiune necunoscută']);
}

function trimitereCerereImprumut($user) {
    $raw_input = file_get_contents('php://input');
    $data = json_decode($raw_input, true);
    
    // Debug - verifică ce date primim
    error_log("Date primite pentru împrumut: " . $raw_input);
    
    if (!$data) {
        echo json_encode(['success' => false, 'error' => 'Date invalide primite']);
        return;
    }
    
    $conn_central = getCentralDbConnection();
    
    $id_colectie = intval($data['id_colectie']);
    $id_obiect = intval($data['id_obiect']);
    $denumire_obiect = mysqli_real_escape_string($conn_central, $data['denumire_obiect']);
    $cutie = mysqli_real_escape_string($conn_central, $data['cutie']);
    $locatie = mysqli_real_escape_string($conn_central, $data['locatie']);
    $data_inceput = mysqli_real_escape_string($conn_central, $data['data_inceput']);
    $data_sfarsit = mysqli_real_escape_string($conn_central, $data['data_sfarsit']);
    $mesaj = mysqli_real_escape_string($conn_central, $data['mesaj'] ?? '');
    
    // Debug - verifică valorile procesate
    error_log("ID colectie: $id_colectie, ID obiect: $id_obiect, Obiect: $denumire_obiect");
    
    // Găsește proprietarul colecției
    $sql_proprietar = "SELECT id_utilizator FROM colectii_utilizatori WHERE id_colectie = ?";
    $stmt = mysqli_prepare($conn_central, $sql_proprietar);
    mysqli_stmt_bind_param($stmt, "i", $id_colectie);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($row = mysqli_fetch_assoc($result)) {
        $id_proprietar = $row['id_utilizator'];
        
        // Verifică să nu-și ceară singur împrumut
        if ($id_proprietar == $user['id_utilizator']) {
            echo json_encode(['success' => false, 'error' => 'Nu poți cere împrumut pentru propriile obiecte']);
            return;
        }
        
        // Inserează cererea
        $sql_insert = "INSERT INTO cereri_imprumut (id_colectie, id_obiect, id_solicitant, id_proprietar, 
                       denumire_obiect, cutie, locatie, data_inceput, data_sfarsit, mesaj) 
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt_insert = mysqli_prepare($conn_central, $sql_insert);
        mysqli_stmt_bind_param($stmt_insert, "iiiissssss", 
            $id_colectie, $id_obiect, $user['id_utilizator'], $id_proprietar,
            $denumire_obiect, $cutie, $locatie, $data_inceput, $data_sfarsit, $mesaj
        );
        
        if (mysqli_stmt_execute($stmt_insert)) {
            $id_cerere = mysqli_insert_id($conn_central);
            error_log("Cerere salvată cu succes, ID: $id_cerere");

            // Trimite email de notificare proprietarului
            try {
                $detalii_cerere = [
                    'denumire_obiect' => $denumire_obiect,
                    'cutie' => $cutie,
                    'locatie' => $locatie,
                    'data_inceput' => $data_inceput,
                    'data_sfarsit' => $data_sfarsit,
                    'mesaj' => $mesaj
                ];

                @trimiteEmailCerereImprumut($id_proprietar, $user['id_utilizator'], $detalii_cerere);

            } catch (Exception $e) {
                error_log("Eroare trimitere email cerere împrumut: " . $e->getMessage());
                // Nu blocăm aplicația dacă email-ul eșuează
            }

            echo json_encode(['success' => true, 'message' => 'Cererea a fost trimisă cu succes', 'id_cerere' => $id_cerere]);
        } else {
            $error = mysqli_stmt_error($stmt_insert);
            error_log("Eroare la salvarea cererii: $error");
            echo json_encode(['success' => false, 'error' => 'Eroare la salvarea cererii: ' . $error]);
        }
        
        mysqli_stmt_close($stmt_insert);
    } else {
        echo json_encode(['success' => false, 'error' => 'Colecția nu a fost găsită']);
    }
    
    mysqli_stmt_close($stmt);
    mysqli_close($conn_central);
}

function obtineCereriPrimite($user) {
    $conn_central = getCentralDbConnection();
    
    // Verifică dacă tabela există
    $check_table = "SHOW TABLES LIKE 'cereri_imprumut'";
    $result_check = mysqli_query($conn_central, $check_table);
    
    if (mysqli_num_rows($result_check) == 0) {
        echo json_encode(['success' => false, 'error' => 'Tabela cereri_imprumut nu există']);
        mysqli_close($conn_central);
        return;
    }
    
    $sql = "SELECT c.*, u.prenume, u.nume,
            col.nume_colectie,
            r.scor_total as ranking_scor,
            r.nivel_ranking,
            r.scor_disponibilitate,
            r.scor_credibilitate,
            -- Calculăm credibilitatea PERSONALĂ (istoric cu mine)
            (SELECT COUNT(*) FROM cereri_imprumut 
             WHERE id_solicitant = c.id_solicitant 
             AND id_proprietar = c.id_proprietar 
             AND status = 'returnat') as returnate_la_mine,
            (SELECT COUNT(*) FROM cereri_imprumut 
             WHERE id_solicitant = c.id_solicitant 
             AND id_proprietar = c.id_proprietar 
             AND status IN ('aprobat', 'imprumutat', 'returnat')) as imprumuturi_de_la_mine
            FROM cereri_imprumut c
            JOIN utilizatori u ON c.id_solicitant = u.id_utilizator
            JOIN colectii_utilizatori col ON c.id_colectie = col.id_colectie
            LEFT JOIN user_rankings r ON c.id_solicitant = r.id_utilizator
            WHERE c.id_proprietar = ?
            ORDER BY c.citit ASC, c.data_cerere DESC";
    
    $stmt = mysqli_prepare($conn_central, $sql);
    
    if (!$stmt) {
        echo json_encode(['success' => false, 'error' => 'Eroare SQL: ' . mysqli_error($conn_central)]);
        mysqli_close($conn_central);
        return;
    }
    
    mysqli_stmt_bind_param($stmt, "i", $user['id_utilizator']);
    
    if (!mysqli_stmt_execute($stmt)) {
        echo json_encode(['success' => false, 'error' => 'Eroare la executare: ' . mysqli_stmt_error($stmt)]);
        mysqli_stmt_close($stmt);
        mysqli_close($conn_central);
        return;
    }
    
    $result = mysqli_stmt_get_result($stmt);
    
    $cereri = [];
    while ($row = mysqli_fetch_assoc($result)) {
        // Ascunde datele personale complete
        $row['solicitant_nume'] = $row['prenume'] . ' ' . substr($row['nume'], 0, 1) . '.';
        unset($row['prenume'], $row['nume']);
        $cereri[] = $row;
    }
    
    // Marchează ca citite
    $sql_update = "UPDATE cereri_imprumut SET citit = 1 WHERE id_proprietar = ? AND citit = 0";
    $stmt_update = mysqli_prepare($conn_central, $sql_update);
    mysqli_stmt_bind_param($stmt_update, "i", $user['id_utilizator']);
    mysqli_stmt_execute($stmt_update);
    
    mysqli_stmt_close($stmt);
    mysqli_close($conn_central);
    
    echo json_encode(['success' => true, 'cereri' => $cereri]);
}

function obtineCereriTrimise($user) {
    $conn_central = getCentralDbConnection();
    
    // Verifică dacă tabela există
    $check_table = "SHOW TABLES LIKE 'cereri_imprumut'";
    $result_check = mysqli_query($conn_central, $check_table);
    
    if (mysqli_num_rows($result_check) == 0) {
        echo json_encode(['success' => true, 'cereri' => []]);
        mysqli_close($conn_central);
        return;
    }
    
    $sql = "SELECT c.*, u.prenume, u.nume,
            col.nume_colectie,
            r.scor_total as ranking_scor,
            r.nivel_ranking,
            r.scor_disponibilitate,
            r.scor_credibilitate,
            -- Calculăm disponibilitatea PERSONALĂ (față de mine)
            (SELECT COUNT(*) FROM cereri_imprumut 
             WHERE id_proprietar = c.id_proprietar 
             AND id_solicitant = c.id_solicitant 
             AND status IN ('aprobat', 'imprumutat', 'returnat')) as aprobate_pentru_mine,
            (SELECT COUNT(*) FROM cereri_imprumut 
             WHERE id_proprietar = c.id_proprietar 
             AND id_solicitant = c.id_solicitant) as cereri_catre_el
            FROM cereri_imprumut c
            JOIN utilizatori u ON c.id_proprietar = u.id_utilizator
            JOIN colectii_utilizatori col ON c.id_colectie = col.id_colectie
            LEFT JOIN user_rankings r ON c.id_proprietar = r.id_utilizator
            WHERE c.id_solicitant = ?
            ORDER BY c.data_cerere DESC";
    
    $stmt = mysqli_prepare($conn_central, $sql);
    
    if (!$stmt) {
        echo json_encode(['success' => false, 'error' => 'Eroare SQL: ' . mysqli_error($conn_central)]);
        mysqli_close($conn_central);
        return;
    }
    
    mysqli_stmt_bind_param($stmt, "i", $user['id_utilizator']);
    
    if (!mysqli_stmt_execute($stmt)) {
        echo json_encode(['success' => false, 'error' => 'Eroare la executare: ' . mysqli_stmt_error($stmt)]);
        mysqli_stmt_close($stmt);
        mysqli_close($conn_central);
        return;
    }
    
    $result = mysqli_stmt_get_result($stmt);
    
    $cereri = [];
    while ($row = mysqli_fetch_assoc($result)) {
        // Ascunde datele personale complete
        $row['proprietar_nume'] = $row['prenume'] . ' ' . substr($row['nume'], 0, 1) . '.';
        unset($row['prenume'], $row['nume']);
        $cereri[] = $row;
    }
    
    mysqli_stmt_close($stmt);
    
    // Marchează răspunsurile ca citite
    $sql_update = "UPDATE cereri_imprumut SET raspuns_citit = 1 
                   WHERE id_solicitant = ? AND raspuns_citit = 0 
                   AND status IN ('aprobat', 'refuzat')";
    $stmt_update = mysqli_prepare($conn_central, $sql_update);
    mysqli_stmt_bind_param($stmt_update, "i", $user['id_utilizator']);
    mysqli_stmt_execute($stmt_update);
    mysqli_stmt_close($stmt_update);
    
    mysqli_close($conn_central);
    
    echo json_encode(['success' => true, 'cereri' => $cereri]);
}

function numarCereriNecitite($user) {
    $conn_central = getCentralDbConnection();
    
    // Verifică dacă tabela există
    $check_table = "SHOW TABLES LIKE 'cereri_imprumut'";
    $result_check = mysqli_query($conn_central, $check_table);
    
    if (mysqli_num_rows($result_check) == 0) {
        echo json_encode(['success' => true, 'numar' => 0]);
        mysqli_close($conn_central);
        return;
    }
    
    $sql = "SELECT COUNT(*) as numar FROM cereri_imprumut 
            WHERE id_proprietar = ? AND citit = 0 AND status = 'in_asteptare'";
    
    $stmt = mysqli_prepare($conn_central, $sql);
    
    if (!$stmt) {
        echo json_encode(['success' => true, 'numar' => 0]);
        mysqli_close($conn_central);
        return;
    }
    
    mysqli_stmt_bind_param($stmt, "i", $user['id_utilizator']);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $row = mysqli_fetch_assoc($result);
    
    mysqli_stmt_close($stmt);
    mysqli_close($conn_central);
    
    echo json_encode(['success' => true, 'numar' => $row['numar'] ?? 0]);
}

function numarRaspunsuriNecitite($user) {
    $conn_central = getCentralDbConnection();
    
    // Verifică dacă tabela există
    $check_table = "SHOW TABLES LIKE 'cereri_imprumut'";
    $result_check = mysqli_query($conn_central, $check_table);
    
    if (mysqli_num_rows($result_check) == 0) {
        echo json_encode(['success' => true, 'numar' => 0]);
        mysqli_close($conn_central);
        return;
    }
    
    // Numără răspunsurile necitite pentru cererile trimise de utilizator
    $sql = "SELECT COUNT(*) as numar FROM cereri_imprumut 
            WHERE id_solicitant = ? AND raspuns_citit = 0 
            AND status IN ('aprobat', 'refuzat')";
    
    $stmt = mysqli_prepare($conn_central, $sql);
    
    if (!$stmt) {
        echo json_encode(['success' => true, 'numar' => 0]);
        mysqli_close($conn_central);
        return;
    }
    
    mysqli_stmt_bind_param($stmt, "i", $user['id_utilizator']);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $row = mysqli_fetch_assoc($result);
    
    mysqli_stmt_close($stmt);
    mysqli_close($conn_central);
    
    echo json_encode(['success' => true, 'numar' => $row['numar'] ?? 0]);
}

function raspundeCerere($user) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $id_cerere = intval($data['id_cerere']);
    $raspuns = mysqli_real_escape_string(getCentralDbConnection(), $data['raspuns']); // 'aprobat' sau 'refuzat'
    
    $conn_central = getCentralDbConnection();
    
    // Verifică că utilizatorul este proprietarul
    $sql_check = "SELECT id_proprietar FROM cereri_imprumut WHERE id_cerere = ?";
    $stmt_check = mysqli_prepare($conn_central, $sql_check);
    mysqli_stmt_bind_param($stmt_check, "i", $id_cerere);
    mysqli_stmt_execute($stmt_check);
    $result = mysqli_stmt_get_result($stmt_check);
    
    if ($row = mysqli_fetch_assoc($result)) {
        if ($row['id_proprietar'] != $user['id_utilizator']) {
            echo json_encode(['success' => false, 'error' => 'Nu ai permisiunea să răspunzi la această cerere']);
            return;
        }
        
        // Actualizează statusul și marchează răspunsul ca necitit pentru solicitant
        $sql_update = "UPDATE cereri_imprumut SET status = ?, data_raspuns = NOW(), raspuns_citit = 0 WHERE id_cerere = ?";
        $stmt_update = mysqli_prepare($conn_central, $sql_update);
        mysqli_stmt_bind_param($stmt_update, "si", $raspuns, $id_cerere);
        
        if (mysqli_stmt_execute($stmt_update)) {
            // Obține detaliile complete pentru email și ranking
            $sql_detalii = "SELECT c.*, col.nume_colectie
                           FROM cereri_imprumut c
                           LEFT JOIN colectii_utilizatori col ON c.id_colectie = col.id_colectie
                           WHERE c.id_cerere = ?";
            $stmt_detalii = mysqli_prepare($conn_central, $sql_detalii);
            mysqli_stmt_bind_param($stmt_detalii, "i", $id_cerere);
            mysqli_stmt_execute($stmt_detalii);
            $result_detalii = mysqli_stmt_get_result($stmt_detalii);
            $detalii = mysqli_fetch_assoc($result_detalii);

            // Trimite email de notificare solicitantului
            try {
                $detalii_cerere = [
                    'id_proprietar' => $detalii['id_proprietar'],
                    'denumire_obiect' => $detalii['denumire_obiect'],
                    'cutie' => $detalii['cutie'],
                    'locatie' => $detalii['locatie'],
                    'raspuns' => $data['mesaj_raspuns'] ?? ''
                ];

                @trimiteEmailRaspunsCerere($detalii['id_solicitant'], $raspuns, $detalii_cerere);

            } catch (Exception $e) {
                error_log("Eroare trimitere email răspuns: " . $e->getMessage());
            }

            echo json_encode([
                'success' => true,
                'message' => 'Răspuns înregistrat',
                'trigger_ranking_update' => true,
                'id_proprietar' => $detalii['id_proprietar'],
                'id_solicitant' => $detalii['id_solicitant']
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Eroare la salvarea răspunsului']);
        }
        
        mysqli_stmt_close($stmt_update);
    } else {
        echo json_encode(['success' => false, 'error' => 'Cererea nu a fost găsită']);
    }
    
    mysqli_stmt_close($stmt_check);
    mysqli_close($conn_central);
}

function obtineDetaliiPentruQR($user) {
    $id_cerere = intval($_GET['id_cerere'] ?? 0);
    $tip = $_GET['tip'] ?? ''; // 'predare' sau 'returnare'
    
    if (!$id_cerere || !in_array($tip, ['predare', 'returnare'])) {
        echo json_encode(['success' => false, 'error' => 'Parametri invalizi']);
        return;
    }
    
    $conn_central = getCentralDbConnection();
    
    // Obține detaliile cererii
    $sql = "SELECT c.*, col.nume_colectie,
            u1.prenume as prenume_solicitant, u1.nume as nume_solicitant,
            u2.prenume as prenume_proprietar, u2.nume as nume_proprietar
            FROM cereri_imprumut c
            JOIN colectii_utilizatori col ON c.id_colectie = col.id_colectie
            JOIN utilizatori u1 ON c.id_solicitant = u1.id_utilizator
            JOIN utilizatori u2 ON c.id_proprietar = u2.id_utilizator
            WHERE c.id_cerere = ?";
    
    $stmt = mysqli_prepare($conn_central, $sql);
    mysqli_stmt_bind_param($stmt, "i", $id_cerere);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($cerere = mysqli_fetch_assoc($result)) {
        // Verifică permisiuni
        $este_proprietar = ($cerere['id_proprietar'] == $user['id_utilizator']);
        $este_solicitant = ($cerere['id_solicitant'] == $user['id_utilizator']);
        
        // Validare permisiuni pentru generare QR
        if ($tip == 'predare' && !$este_proprietar) {
            echo json_encode(['success' => false, 'error' => 'Doar proprietarul poate genera QR pentru predare']);
            return;
        }
        if ($tip == 'returnare' && !$este_solicitant) {
            echo json_encode(['success' => false, 'error' => 'Doar solicitantul poate genera QR pentru returnare']);
            return;
        }
        
        // Creează datele COMPACTE pentru QR (pentru a evita overflow)
        $qr_compact = [
            't' => $tip[0], // 'p' pentru predare, 'r' pentru returnare
            'id' => $cerere['id_cerere'],
            'ts' => time() // timestamp Unix mai scurt
        ];
        
        // Generează hash pentru securitate bazat pe toate datele importante
        $hash_data = $tip . $cerere['id_cerere'] . $cerere['id_proprietar'] . 
                     $cerere['id_solicitant'] . time() . 'inventar_live_secret_2025';
        $qr_compact['h'] = substr(hash('sha256', $hash_data), 0, 12); // Hash scurtat la 12 caractere
        
        // Datele complete pentru afișare în UI (nu sunt incluse în QR)
        $display_data = [
            'tip' => $tip,
            'id_cerere' => $cerere['id_cerere'],
            'id_proprietar' => $cerere['id_proprietar'],
            'id_solicitant' => $cerere['id_solicitant'],
            'obiect' => $cerere['denumire_obiect'],
            'cutie' => $cerere['cutie'],
            'locatie' => $cerere['locatie'],
            'colectie' => $cerere['nume_colectie'],
            'timestamp' => date('c')
        ];
        
        echo json_encode([
            'success' => true, 
            'qr_data' => $qr_compact,  // Datele compacte pentru QR
            'display_data' => $display_data  // Datele complete pentru afișare
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Cererea nu a fost găsită']);
    }
    
    mysqli_stmt_close($stmt);
    mysqli_close($conn_central);
}

function salveazaQR($user) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $id_cerere = intval($data['id_cerere'] ?? 0);
    $tip = $data['tip'] ?? '';
    $qr_data = $data['qr_data'] ?? '';
    
    if (!$id_cerere || !in_array($tip, ['predare', 'returnare']) || !$qr_data) {
        echo json_encode(['success' => false, 'error' => 'Date incomplete']);
        return;
    }
    
    $conn_central = getCentralDbConnection();
    
    // Verifică permisiuni
    $sql_check = "SELECT id_proprietar, id_solicitant, status FROM cereri_imprumut WHERE id_cerere = ?";
    $stmt = mysqli_prepare($conn_central, $sql_check);
    mysqli_stmt_bind_param($stmt, "i", $id_cerere);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($row = mysqli_fetch_assoc($result)) {
        $este_proprietar = ($row['id_proprietar'] == $user['id_utilizator']);
        $este_solicitant = ($row['id_solicitant'] == $user['id_utilizator']);
        
        // Validare permisiuni și status
        if ($tip == 'predare') {
            if (!$este_proprietar || $row['status'] != 'aprobat') {
                echo json_encode(['success' => false, 'error' => 'Nu poți genera acest QR']);
                mysqli_close($conn_central);
                return;
            }
            $column = 'qr_predare';
        } else {
            if (!$este_solicitant || $row['status'] != 'imprumutat') {
                echo json_encode(['success' => false, 'error' => 'Nu poți genera acest QR']);
                mysqli_close($conn_central);
                return;
            }
            $column = 'qr_returnare';
        }
        
        // Salvează QR în baza de date
        $sql_update = "UPDATE cereri_imprumut SET $column = ? WHERE id_cerere = ?";
        $stmt_update = mysqli_prepare($conn_central, $sql_update);
        mysqli_stmt_bind_param($stmt_update, "si", $qr_data, $id_cerere);
        
        if (mysqli_stmt_execute($stmt_update)) {
            // Nu mai adăugăm mesaj în chat pentru generarea QR
            // deoarece QR-ul trebuie scanat direct din fereastra modală
            
            echo json_encode(['success' => true, 'message' => 'QR salvat cu succes']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Eroare la salvarea QR']);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Cererea nu a fost găsită']);
    }
    
    mysqli_close($conn_central);
}

function valideazaQR($user) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $qr_scanat = $data['qr_data'] ?? '';
    
    if (!$qr_scanat) {
        echo json_encode(['success' => false, 'error' => 'Date QR invalide']);
        return;
    }
    
    // Decodifică datele QR compacte
    $qr_data = json_decode($qr_scanat, true);
    if (!$qr_data) {
        echo json_encode(['success' => false, 'error' => 'Format QR invalid']);
        return;
    }
    
    // Extrage datele compacte
    $tip = ($qr_data['t'] == 'p') ? 'predare' : 'returnare';
    $id_cerere = intval($qr_data['id'] ?? 0);
    $timestamp = intval($qr_data['ts'] ?? 0);
    $hash_primit = $qr_data['h'] ?? '';
    
    if (!$id_cerere || !$timestamp || !$hash_primit) {
        echo json_encode(['success' => false, 'error' => 'Date QR incomplete']);
        return;
    }
    
    $conn_central = getCentralDbConnection();
    
    // Obține toate detaliile cererii din BD folosind ID-ul din QR
    $sql = "SELECT c.*, col.nume_colectie,
            u1.prenume as prenume_solicitant, u1.nume as nume_solicitant,
            u2.prenume as prenume_proprietar, u2.nume as nume_proprietar
            FROM cereri_imprumut c
            JOIN colectii_utilizatori col ON c.id_colectie = col.id_colectie
            JOIN utilizatori u1 ON c.id_solicitant = u1.id_utilizator
            JOIN utilizatori u2 ON c.id_proprietar = u2.id_utilizator
            WHERE c.id_cerere = ?";
    $stmt = mysqli_prepare($conn_central, $sql);
    mysqli_stmt_bind_param($stmt, "i", $id_cerere);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($cerere = mysqli_fetch_assoc($result)) {
        // Verifică hash-ul pentru securitate cu datele din BD
        $hash_data = $tip . $cerere['id_cerere'] . $cerere['id_proprietar'] . 
                     $cerere['id_solicitant'] . $timestamp . 'inventar_live_secret_2025';
        $hash_calculat = substr(hash('sha256', $hash_data), 0, 12);
        
        // Verifică că hash-ul este corect și timestamp-ul nu e prea vechi (max 24 ore)
        if ($hash_primit !== $hash_calculat || (time() - $timestamp) > 86400) {
            echo json_encode(['success' => false, 'error' => 'QR invalid sau expirat']);
            mysqli_close($conn_central);
            return;
        }
        
        $este_proprietar = ($cerere['id_proprietar'] == $user['id_utilizator']);
        $este_solicitant = ($cerere['id_solicitant'] == $user['id_utilizator']);
        
        // Validare: cine poate scana ce
        if ($tip == 'predare') {
            if (!$este_solicitant) {
                echo json_encode(['success' => false, 'error' => 'Doar solicitantul poate scana acest QR']);
                mysqli_close($conn_central);
                return;
            }
            if ($cerere['status'] != 'aprobat') {
                echo json_encode(['success' => false, 'error' => 'Status invalid pentru predare']);
                mysqli_close($conn_central);
                return;
            }
        } else if ($tip == 'returnare') {
            if (!$este_proprietar) {
                echo json_encode(['success' => false, 'error' => 'Doar proprietarul poate scana acest QR']);
                mysqli_close($conn_central);
                return;
            }
            if ($cerere['status'] != 'imprumutat') {
                echo json_encode(['success' => false, 'error' => 'Status invalid pentru returnare']);
                mysqli_close($conn_central);
                return;
            }
        }
        
        // Verifică că QR-ul corespunde cu cel salvat
        $qr_field = ($tip == 'predare') ? 'qr_predare' : 'qr_returnare';
        if ($cerere[$qr_field] != $qr_scanat) {
            echo json_encode(['success' => false, 'error' => 'QR nu corespunde cu cel generat']);
            mysqli_close($conn_central);
            return;
        }
        
        // Formatează numele pentru afișare (prenume + inițială)
        $nume_solicitant = $cerere['prenume_solicitant'] . ' ' . substr($cerere['nume_solicitant'], 0, 1) . '.';
        $nume_proprietar = $cerere['prenume_proprietar'] . ' ' . substr($cerere['nume_proprietar'], 0, 1) . '.';
        
        // Construiește datele complete din BD pentru afișare
        $detalii_complete = [
            'tip' => $tip,
            'id_cerere' => $cerere['id_cerere'],
            'id_proprietar' => $cerere['id_proprietar'],
            'id_solicitant' => $cerere['id_solicitant'],
            'obiect' => $cerere['denumire_obiect'],
            'cutie' => $cerere['cutie'],
            'locatie' => $cerere['locatie'],
            'colectie' => $cerere['nume_colectie']
        ];
        
        echo json_encode([
            'success' => true, 
            'valid' => true,
            'tip' => $tip,
            'detalii' => $detalii_complete,  // Datele complete din BD
            'nume_solicitant' => $nume_solicitant,
            'nume_proprietar' => $nume_proprietar
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Cererea nu există']);
    }
    
    mysqli_close($conn_central);
}

function confirmaTransfer($user) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $id_cerere = intval($data['id_cerere'] ?? 0);
    $tip = $data['tip'] ?? '';
    
    if (!$id_cerere || !in_array($tip, ['predare', 'returnare'])) {
        echo json_encode(['success' => false, 'error' => 'Date incomplete']);
        return;
    }
    
    $conn_central = getCentralDbConnection();
    
    // Verifică permisiuni și actualizează status
    if ($tip == 'predare') {
        $sql_update = "UPDATE cereri_imprumut 
                       SET status = 'imprumutat', data_predare = NOW() 
                       WHERE id_cerere = ? AND status = 'aprobat'";
        $mesaj_sistem = "✅ Obiectul a fost predat cu succes.";
    } else {
        $sql_update = "UPDATE cereri_imprumut 
                       SET status = 'returnat', data_returnare_efectiva = NOW() 
                       WHERE id_cerere = ? AND status = 'imprumutat'";
        $mesaj_sistem = "✅ Obiectul a fost returnat cu succes.";
    }
    
    $stmt = mysqli_prepare($conn_central, $sql_update);
    mysqli_stmt_bind_param($stmt, "i", $id_cerere);
    
    if (mysqli_stmt_execute($stmt) && mysqli_affected_rows($conn_central) > 0) {
        // Adaugă mesaj de sistem în chat
        $sql_mesaj = "INSERT INTO mesaje_imprumut (id_cerere, id_expeditor, mesaj, tip_mesaj) 
                      VALUES (?, ?, ?, 'system')";
        $stmt_mesaj = mysqli_prepare($conn_central, $sql_mesaj);
        mysqli_stmt_bind_param($stmt_mesaj, "iis", $id_cerere, $user['id_utilizator'], $mesaj_sistem);
        mysqli_stmt_execute($stmt_mesaj);
        
        // Obține ID-urile pentru actualizarea ranking-ului
        $sql_ids = "SELECT id_proprietar, id_solicitant FROM cereri_imprumut WHERE id_cerere = ?";
        $stmt_ids = mysqli_prepare($conn_central, $sql_ids);
        mysqli_stmt_bind_param($stmt_ids, "i", $id_cerere);
        mysqli_stmt_execute($stmt_ids);
        $result_ids = mysqli_stmt_get_result($stmt_ids);
        $ids = mysqli_fetch_assoc($result_ids);
        
        // Actualizează ranking-ul pentru ambii utilizatori (prin AJAX separat)
        // Returnăm ID-urile pentru ca frontend-ul să poată apela ajax_ranking.php
        $response = [
            'success' => true, 
            'message' => 'Transfer confirmat',
            'trigger_ranking_update' => true,
            'id_proprietar' => $ids['id_proprietar'],
            'id_solicitant' => $ids['id_solicitant']
        ];
        
        // Pentru returnare, semnalăm că poate fi afișat feedback-ul
        if ($tip == 'returnare') {
            $response['show_feedback'] = true;
            $response['id_cerere'] = $id_cerere;
        }
        
        echo json_encode($response);
    } else {
        echo json_encode(['success' => false, 'error' => 'Nu s-a putut confirma transferul']);
    }
    
    mysqli_close($conn_central);
}

function obtineImprumuturiActive($user) {
    $conn_central = getCentralDbConnection();
    
    // Obține toate împrumuturile active ale utilizatorului (ca solicitant sau proprietar)
    $sql = "SELECT c.*, 
            u1.prenume as prenume_solicitant, u1.nume as nume_solicitant,
            u2.prenume as prenume_proprietar, u2.nume as nume_proprietar,
            col.nume_colectie,
            CASE 
                WHEN c.id_solicitant = ? THEN 'solicitant'
                WHEN c.id_proprietar = ? THEN 'proprietar'
            END as rol_utilizator,
            DATEDIFF(c.data_sfarsit, NOW()) as zile_ramase,
            TIMESTAMPDIFF(HOUR, NOW(), c.data_sfarsit) as ore_ramase
            FROM cereri_imprumut c
            JOIN utilizatori u1 ON c.id_solicitant = u1.id_utilizator
            JOIN utilizatori u2 ON c.id_proprietar = u2.id_utilizator
            JOIN colectii_utilizatori col ON c.id_colectie = col.id_colectie
            WHERE (c.id_solicitant = ? OR c.id_proprietar = ?) 
            AND c.status = 'imprumutat'
            ORDER BY c.data_sfarsit ASC";
    
    $stmt = mysqli_prepare($conn_central, $sql);
    
    if (!$stmt) {
        echo json_encode(['success' => false, 'error' => 'Eroare SQL: ' . mysqli_error($conn_central)]);
        mysqli_close($conn_central);
        return;
    }
    
    $id_user = $user['id_utilizator'];
    mysqli_stmt_bind_param($stmt, "iiii", $id_user, $id_user, $id_user, $id_user);
    
    if (!mysqli_stmt_execute($stmt)) {
        echo json_encode(['success' => false, 'error' => 'Eroare la executare: ' . mysqli_stmt_error($stmt)]);
        mysqli_stmt_close($stmt);
        mysqli_close($conn_central);
        return;
    }
    
    $result = mysqli_stmt_get_result($stmt);
    
    $imprumuturi = [];
    $cel_mai_urgent = null;
    
    while ($row = mysqli_fetch_assoc($result)) {
        // Formatează numele pentru afișare
        $row['nume_solicitant_format'] = $row['prenume_solicitant'] . ' ' . substr($row['nume_solicitant'], 0, 1) . '.';
        $row['nume_proprietar_format'] = $row['prenume_proprietar'] . ' ' . substr($row['nume_proprietar'], 0, 1) . '.';
        
        // Calculează culoarea și urgența (doar pentru împrumuturi active)
        if ($row['ore_ramase'] !== null) {
            if ($row['ore_ramase'] <= 24) {
                $row['culoare_timer'] = '#ff0000'; // Roșu - mai puțin de 24 ore
                $row['urgenta'] = 'foarte_urgent';
            } else if ($row['zile_ramase'] <= 3) {
                $row['culoare_timer'] = '#ff9800'; // Portocaliu - 1-3 zile
                $row['urgenta'] = 'urgent';
            } else {
                $row['culoare_timer'] = '#4CAF50'; // Verde - mai mult de 3 zile
                $row['urgenta'] = 'normal';
            }
            
            // Animație pulsare pentru mai puțin de 12 ore
            $row['animatie_pulsare'] = ($row['ore_ramase'] <= 12 && $row['ore_ramase'] > 0);
            
            // Formatează timpul rămas pentru afișare
            if ($row['ore_ramase'] <= 0) {
                $row['timp_ramas_text'] = abs($row['zile_ramase']) . 'z depășit';
            } else if ($row['ore_ramase'] <= 48) {
                $row['timp_ramas_text'] = $row['ore_ramase'] . 'h';
            } else {
                $row['timp_ramas_text'] = $row['zile_ramase'] . 'z';
            }
        }
        
        $imprumuturi[] = $row;
        
        // Păstrează cel mai urgent împrumut pentru afișare pe avatar
        if (!$cel_mai_urgent || 
            ($row['ore_ramase'] !== null && 
             ($cel_mai_urgent['ore_ramase'] === null || $row['ore_ramase'] < $cel_mai_urgent['ore_ramase']))) {
            $cel_mai_urgent = $row;
        }
    }
    
    mysqli_stmt_close($stmt);
    mysqli_close($conn_central);
    
    echo json_encode([
        'success' => true, 
        'imprumuturi' => $imprumuturi,
        'cel_mai_urgent' => $cel_mai_urgent,
        'total' => count($imprumuturi)
    ]);
}
?>