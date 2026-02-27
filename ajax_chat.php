<?php
// ajax_chat.php - Backend pentru sistemul de chat între utilizatori
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

session_start();
require_once 'config.php';
require_once 'includes/auth_functions.php';

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
    
    switch($actiune) {
        case 'obtine_mesaje':
            obtineMesaje($user);
            break;
            
        case 'verifica_mesaje_noi':
            verificaMesajeNoi($user);
            break;
            
        case 'numar_mesaje_necitite':
            numarMesajeNecitite($user);
            break;
            
        case 'obtine_detalii_cerere':
            obtineDetaliiCerere($user);
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Acțiune necunoscută']);
    }
} else {
    // Pentru POST requests cu JSON
    $raw_input = file_get_contents('php://input');
    $data = json_decode($raw_input, true);
    $actiune = $data['actiune'] ?? '';
    
    switch($actiune) {
        case 'trimite_mesaj':
            trimiteMesaj($user, $data);
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Acțiune necunoscută']);
    }
}

function obtineMesaje($user) {
    $id_cerere = intval($_GET['id_cerere'] ?? 0);
    
    if (!$id_cerere) {
        echo json_encode(['success' => false, 'error' => 'ID cerere invalid']);
        return;
    }
    
    $conn_central = getCentralDbConnection();
    
    // Verifică dacă utilizatorul are acces la această conversație
    $sql_check = "SELECT c.*, col.nume_colectie,
                  u1.prenume as prenume_solicitant, u1.nume as nume_solicitant,
                  u2.prenume as prenume_proprietar, u2.nume as nume_proprietar
                  FROM cereri_imprumut c
                  JOIN colectii_utilizatori col ON c.id_colectie = col.id_colectie
                  JOIN utilizatori u1 ON c.id_solicitant = u1.id_utilizator
                  JOIN utilizatori u2 ON c.id_proprietar = u2.id_utilizator
                  WHERE c.id_cerere = ? 
                  AND (c.id_solicitant = ? OR c.id_proprietar = ?)";
    
    $stmt = mysqli_prepare($conn_central, $sql_check);
    mysqli_stmt_bind_param($stmt, "iii", $id_cerere, $user['id_utilizator'], $user['id_utilizator']);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if (!$cerere = mysqli_fetch_assoc($result)) {
        echo json_encode(['success' => false, 'error' => 'Acces neautorizat']);
        mysqli_close($conn_central);
        return;
    }
    
    // Obține mesajele
    $sql_mesaje = "SELECT m.*, u.prenume, u.nume
                   FROM mesaje_imprumut m
                   JOIN utilizatori u ON m.id_expeditor = u.id_utilizator
                   WHERE m.id_cerere = ?
                   ORDER BY m.data_trimitere ASC";
    
    $stmt_mesaje = mysqli_prepare($conn_central, $sql_mesaje);
    mysqli_stmt_bind_param($stmt_mesaje, "i", $id_cerere);
    mysqli_stmt_execute($stmt_mesaje);
    $result_mesaje = mysqli_stmt_get_result($stmt_mesaje);
    
    $mesaje = [];
    while ($row = mysqli_fetch_assoc($result_mesaje)) {
        // Protejează datele personale
        $row['nume_expeditor'] = $row['prenume'] . ' ' . substr($row['nume'], 0, 1) . '.';
        unset($row['prenume'], $row['nume']);
        $mesaje[] = $row;
    }
    
    // Marchează mesajele ca citite
    $sql_update = "UPDATE mesaje_imprumut SET citit = 1 
                   WHERE id_cerere = ? AND id_expeditor != ? AND citit = 0";
    $stmt_update = mysqli_prepare($conn_central, $sql_update);
    mysqli_stmt_bind_param($stmt_update, "ii", $id_cerere, $user['id_utilizator']);
    mysqli_stmt_execute($stmt_update);
    
    mysqli_stmt_close($stmt);
    mysqli_stmt_close($stmt_mesaje);
    mysqli_stmt_close($stmt_update);
    mysqli_close($conn_central);
    
    echo json_encode([
        'success' => true,
        'mesaje' => $mesaje,
        'cerere' => $cerere
    ]);
}

function trimiteMesaj($user, $data) {
    $id_cerere = intval($data['id_cerere'] ?? 0);
    $mesaj = trim($data['mesaj'] ?? '');
    
    if (!$id_cerere || !$mesaj) {
        echo json_encode(['success' => false, 'error' => 'Date incomplete']);
        return;
    }
    
    $conn_central = getCentralDbConnection();
    
    // Verifică dacă utilizatorul are acces la această conversație
    $sql_check = "SELECT id_cerere, status FROM cereri_imprumut 
                  WHERE id_cerere = ? 
                  AND (id_solicitant = ? OR id_proprietar = ?)
                  AND status IN ('aprobat', 'imprumutat')";
    
    $stmt_check = mysqli_prepare($conn_central, $sql_check);
    mysqli_stmt_bind_param($stmt_check, "iii", $id_cerere, $user['id_utilizator'], $user['id_utilizator']);
    mysqli_stmt_execute($stmt_check);
    $result = mysqli_stmt_get_result($stmt_check);
    
    if (!mysqli_fetch_assoc($result)) {
        echo json_encode(['success' => false, 'error' => 'Nu poți trimite mesaje pentru această cerere']);
        mysqli_close($conn_central);
        return;
    }
    
    // Inserează mesajul
    $sql_insert = "INSERT INTO mesaje_imprumut (id_cerere, id_expeditor, mesaj, tip_mesaj) 
                   VALUES (?, ?, ?, 'text')";
    
    $stmt_insert = mysqli_prepare($conn_central, $sql_insert);
    mysqli_stmt_bind_param($stmt_insert, "iis", $id_cerere, $user['id_utilizator'], $mesaj);
    
    if (mysqli_stmt_execute($stmt_insert)) {
        echo json_encode(['success' => true, 'message' => 'Mesaj trimis']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Eroare la trimiterea mesajului']);
    }
    
    mysqli_stmt_close($stmt_check);
    mysqli_stmt_close($stmt_insert);
    mysqli_close($conn_central);
}

function verificaMesajeNoi($user) {
    $id_cerere = intval($_GET['id_cerere'] ?? 0);
    $ultimul_id = intval($_GET['ultimul_id'] ?? 0);
    
    if (!$id_cerere) {
        echo json_encode(['success' => false, 'error' => 'ID cerere invalid']);
        return;
    }
    
    $conn_central = getCentralDbConnection();
    
    // Verifică dacă există mesaje noi
    $sql = "SELECT COUNT(*) as numar FROM mesaje_imprumut 
            WHERE id_cerere = ? AND id_mesaj > ? AND id_expeditor != ?";
    
    $stmt = mysqli_prepare($conn_central, $sql);
    mysqli_stmt_bind_param($stmt, "iii", $id_cerere, $ultimul_id, $user['id_utilizator']);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $row = mysqli_fetch_assoc($result);
    
    mysqli_stmt_close($stmt);
    mysqli_close($conn_central);
    
    echo json_encode([
        'success' => true,
        'mesaje_noi' => $row['numar'] > 0
    ]);
}

function numarMesajeNecitite($user) {
    $id_cerere = intval($_GET['id_cerere'] ?? 0);
    
    if (!$id_cerere) {
        echo json_encode(['success' => false, 'error' => 'ID cerere invalid']);
        return;
    }
    
    $conn_central = getCentralDbConnection();
    
    // Verifică dacă utilizatorul are acces la această conversație
    $sql_check = "SELECT id_solicitant, id_proprietar FROM cereri_imprumut 
                  WHERE id_cerere = ? 
                  AND (id_solicitant = ? OR id_proprietar = ?)";
    
    $stmt_check = mysqli_prepare($conn_central, $sql_check);
    mysqli_stmt_bind_param($stmt_check, "iii", $id_cerere, $user['id_utilizator'], $user['id_utilizator']);
    mysqli_stmt_execute($stmt_check);
    $result = mysqli_stmt_get_result($stmt_check);
    
    if (!mysqli_fetch_assoc($result)) {
        echo json_encode(['success' => false, 'error' => 'Acces neautorizat']);
        mysqli_close($conn_central);
        return;
    }
    
    // Numără mesajele necitite (care nu sunt trimise de utilizatorul curent)
    $sql = "SELECT COUNT(*) as numar FROM mesaje_imprumut 
            WHERE id_cerere = ? AND id_expeditor != ? AND citit = 0";
    
    $stmt = mysqli_prepare($conn_central, $sql);
    mysqli_stmt_bind_param($stmt, "ii", $id_cerere, $user['id_utilizator']);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $row = mysqli_fetch_assoc($result);
    
    mysqli_stmt_close($stmt_check);
    mysqli_stmt_close($stmt);
    mysqli_close($conn_central);
    
    echo json_encode([
        'success' => true,
        'numar' => $row['numar'] ?? 0
    ]);
}

function obtineDetaliiCerere($user) {
    $id_cerere = intval($_GET['id_cerere'] ?? 0);
    
    if (!$id_cerere) {
        echo json_encode(['success' => false, 'error' => 'ID cerere invalid']);
        return;
    }
    
    $conn_central = getCentralDbConnection();
    
    // Obține detaliile complete ale cererii
    $sql = "SELECT c.*, col.nume_colectie,
            u1.prenume as prenume_solicitant, u1.nume as nume_solicitant,
            u2.prenume as prenume_proprietar, u2.nume as nume_proprietar
            FROM cereri_imprumut c
            JOIN colectii_utilizatori col ON c.id_colectie = col.id_colectie
            JOIN utilizatori u1 ON c.id_solicitant = u1.id_utilizator
            JOIN utilizatori u2 ON c.id_proprietar = u2.id_utilizator
            WHERE c.id_cerere = ? 
            AND (c.id_solicitant = ? OR c.id_proprietar = ?)";
    
    $stmt = mysqli_prepare($conn_central, $sql);
    mysqli_stmt_bind_param($stmt, "iii", $id_cerere, $user['id_utilizator'], $user['id_utilizator']);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($cerere = mysqli_fetch_assoc($result)) {
        // Formatează datele pentru afișare
        $cerere['data_inceput'] = date('d.m.Y', strtotime($cerere['data_inceput']));
        $cerere['data_sfarsit'] = date('d.m.Y', strtotime($cerere['data_sfarsit']));
        
        // Protejează datele personale
        $cerere['nume_solicitant'] = $cerere['prenume_solicitant'] . ' ' . substr($cerere['nume_solicitant'], 0, 1) . '.';
        $cerere['nume_proprietar'] = $cerere['prenume_proprietar'] . ' ' . substr($cerere['nume_proprietar'], 0, 1) . '.';
        
        echo json_encode(['success' => true, 'cerere' => $cerere]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Cererea nu a fost găsită sau nu ai acces']);
    }
    
    mysqli_stmt_close($stmt);
    mysqli_close($conn_central);
}
?>