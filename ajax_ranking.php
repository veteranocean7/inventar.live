<?php
session_start();
require_once 'config.php';
require_once 'includes/auth_functions.php';

// Verifică autentificarea
$user = checkSession();
if (!$user) {
    echo json_encode(['success' => false, 'message' => 'Neautentificat']);
    exit;
}

$conn_central = getCentralDbConnection();

// Funcție pentru actualizare automată ranking
function actualizeazaRanking($id_utilizator, $conn) {
    
    // 1. CALCULEAZĂ DISPONIBILITATEA (ca proprietar)
    $sql_proprietar = "
        SELECT 
            COUNT(*) as total_cereri,
            SUM(CASE WHEN status IN ('aprobat','imprumutat','returnat') THEN 1 ELSE 0 END) as aprobate,
            SUM(CASE WHEN status = 'refuzat' THEN 1 ELSE 0 END) as refuzate
        FROM cereri_imprumut
        WHERE id_proprietar = ?";
    
    $stmt = mysqli_prepare($conn, $sql_proprietar);
    mysqli_stmt_bind_param($stmt, "i", $id_utilizator);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $stats_proprietar = mysqli_fetch_assoc($result);
    
    // Calcul scor disponibilitate
    $scor_disponibilitate = 50; // valoare implicită
    if ($stats_proprietar['total_cereri'] > 0) {
        $scor_disponibilitate = ($stats_proprietar['aprobate'] / $stats_proprietar['total_cereri']) * 100;
    }
    
    // 2. CALCULEAZĂ CREDIBILITATEA (ca împrumutător)
    // IMPORTANT: Calculăm DOAR pe baza împrumuturilor efectiv realizate (aprobate)
    // Cererile refuzate NU afectează scorul de credibilitate
    $sql_imprumutator = "
        SELECT 
            COUNT(*) as total_imprumuturi,
            SUM(CASE WHEN status = 'returnat' AND DATE(data_returnare_efectiva) <= DATE(data_sfarsit) THEN 1 ELSE 0 END) as la_timp,
            SUM(CASE WHEN status = 'returnat' AND DATE(data_returnare_efectiva) > DATE(data_sfarsit) THEN 1 ELSE 0 END) as intarziate,
            COALESCE(SUM(CASE WHEN status = 'returnat' AND DATE(data_returnare_efectiva) > DATE(data_sfarsit) 
                        THEN DATEDIFF(data_returnare_efectiva, data_sfarsit) ELSE 0 END), 0) as zile_intarziere,
            SUM(CASE WHEN stare_obiect_returnare IN ('deteriorat_usor', 'deteriorat_grav') THEN 1 ELSE 0 END) as deteriorate
        FROM cereri_imprumut
        WHERE id_solicitant = ? 
        AND status IN ('returnat', 'imprumutat', 'aprobat')";
    
    $stmt = mysqli_prepare($conn, $sql_imprumutator);
    mysqli_stmt_bind_param($stmt, "i", $id_utilizator);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $stats_imprumutator = mysqli_fetch_assoc($result);
    
    // Calcul scor credibilitate
    $scor_credibilitate = 100; // valoare inițială maximă
    if ($stats_imprumutator['total_imprumuturi'] > 0) {
        // Penalizări
        $scor_credibilitate -= ($stats_imprumutator['zile_intarziere'] * 0.5); // -0.5p per zi întârziere
        $scor_credibilitate -= ($stats_imprumutator['deteriorate'] * 10); // -10p per obiect deteriorat
        
        // Bonus pentru returnări la timp
        $procent_la_timp = ($stats_imprumutator['la_timp'] / $stats_imprumutator['total_imprumuturi']) * 100;
        if ($procent_la_timp >= 90) {
            $scor_credibilitate += 10; // Bonus pentru >90% la timp
        }
    }
    
    // Limitare între 0 și 100
    $scor_credibilitate = max(0, min(100, $scor_credibilitate));
    
    // 3. CALCULEAZĂ SCOR TOTAL ȘI NIVEL
    $scor_total = ($scor_disponibilitate + $scor_credibilitate) / 2;
    
    // Determină nivelul
    if ($scor_total >= 90) {
        $nivel = 'diamond';
    } elseif ($scor_total >= 75) {
        $nivel = 'platinum';
    } elseif ($scor_total >= 60) {
        $nivel = 'gold';
    } elseif ($scor_total >= 40) {
        $nivel = 'silver';
    } else {
        $nivel = 'bronze';
    }
    
    // 4. SALVEAZĂ ÎN BAZA DE DATE
    $sql_update = "
        INSERT INTO user_rankings (
            id_utilizator,
            total_cereri_primite, cereri_aprobate, cereri_refuzate, scor_disponibilitate,
            total_imprumuturi, returnate_la_timp, returnate_cu_intarziere, 
            total_zile_intarziere, returnate_deteriorate, scor_credibilitate,
            scor_total, nivel_ranking
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            total_cereri_primite = VALUES(total_cereri_primite),
            cereri_aprobate = VALUES(cereri_aprobate),
            cereri_refuzate = VALUES(cereri_refuzate),
            scor_disponibilitate = VALUES(scor_disponibilitate),
            total_imprumuturi = VALUES(total_imprumuturi),
            returnate_la_timp = VALUES(returnate_la_timp),
            returnate_cu_intarziere = VALUES(returnate_cu_intarziere),
            total_zile_intarziere = VALUES(total_zile_intarziere),
            returnate_deteriorate = VALUES(returnate_deteriorate),
            scor_credibilitate = VALUES(scor_credibilitate),
            scor_total = VALUES(scor_total),
            nivel_ranking = VALUES(nivel_ranking),
            ultima_actualizare = NOW()";
    
    $stmt = mysqli_prepare($conn, $sql_update);
    mysqli_stmt_bind_param($stmt, "iiidiiiiidds", 
        $id_utilizator,
        $stats_proprietar['total_cereri'],
        $stats_proprietar['aprobate'],
        $stats_proprietar['refuzate'],
        $scor_disponibilitate,
        $stats_imprumutator['total_imprumuturi'],
        $stats_imprumutator['la_timp'],
        $stats_imprumutator['intarziate'],
        $stats_imprumutator['zile_intarziere'],
        $stats_imprumutator['deteriorate'],
        $scor_credibilitate,
        $scor_total,
        $nivel
    );
    
    return mysqli_stmt_execute($stmt);
}

// Procesează acțiunile
$actiune = $_POST['actiune'] ?? $_GET['actiune'] ?? '';

switch($actiune) {
    
    case 'actualizeaza_dupa_cerere':
        // Apelat automat după aprobare/refuz/returnare
        $id_proprietar = $_POST['id_proprietar'] ?? 0;
        $id_solicitant = $_POST['id_solicitant'] ?? 0;
        
        if ($id_proprietar) {
            actualizeazaRanking($id_proprietar, $conn_central);
        }
        if ($id_solicitant) {
            actualizeazaRanking($id_solicitant, $conn_central);
        }
        
        echo json_encode(['success' => true]);
        break;
    
    case 'get_ranking_utilizator':
        // Obține ranking-ul unui utilizator specific
        $id_utilizator = $_GET['id'] ?? $user['id_utilizator'];
        
        $sql = "SELECT r.*, 
                CONCAT(u.prenume, ' ', LEFT(u.nume, 1), '.') as nume_afisat,
                CASE r.nivel_ranking
                    WHEN 'diamond' THEN '💎'
                    WHEN 'platinum' THEN '🏆'
                    WHEN 'gold' THEN '🥇'
                    WHEN 'silver' THEN '🥈'
                    ELSE '🥉'
                END as badge
                FROM user_rankings r
                JOIN utilizatori u ON r.id_utilizator = u.id_utilizator
                WHERE r.id_utilizator = ?";
        
        $stmt = mysqli_prepare($conn_central, $sql);
        mysqli_stmt_bind_param($stmt, "i", $id_utilizator);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $ranking = mysqli_fetch_assoc($result);
        
        echo json_encode(['success' => true, 'ranking' => $ranking]);
        break;
    
    case 'get_top_utilizatori':
        // Obține TOP 10 utilizatori
        $sql = "SELECT r.*, 
                CONCAT(u.prenume, ' ', LEFT(u.nume, 1), '.') as nume_afisat,
                CASE r.nivel_ranking
                    WHEN 'diamond' THEN '💎'
                    WHEN 'platinum' THEN '🏆'
                    WHEN 'gold' THEN '🥇'
                    WHEN 'silver' THEN '🥈'
                    ELSE '🥉'
                END as badge
                FROM user_rankings r
                JOIN utilizatori u ON r.id_utilizator = u.id_utilizator
                ORDER BY r.scor_total DESC
                LIMIT 10";
        
        $result = mysqli_query($conn_central, $sql);
        $top = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $top[] = $row;
        }
        
        echo json_encode(['success' => true, 'top' => $top]);
        break;
    
    case 'adauga_feedback_returnare':
        // Pentru când proprietarul confirmă starea obiectului la returnare
        $id_cerere = $_POST['id_cerere'] ?? 0;
        $stare_obiect = $_POST['stare_obiect'] ?? 'buna';
        $observatii = $_POST['observatii'] ?? '';
        
        $sql = "UPDATE cereri_imprumut 
                SET stare_obiect_returnare = ?, observatii_returnare = ?
                WHERE id_cerere = ?";
        
        $stmt = mysqli_prepare($conn_central, $sql);
        mysqli_stmt_bind_param($stmt, "ssi", $stare_obiect, $observatii, $id_cerere);
        
        if (mysqli_stmt_execute($stmt)) {
            // Actualizează ranking-urile după feedback
            $sql_cerere = "SELECT id_proprietar, id_solicitant FROM cereri_imprumut WHERE id_cerere = ?";
            $stmt2 = mysqli_prepare($conn_central, $sql_cerere);
            mysqli_stmt_bind_param($stmt2, "i", $id_cerere);
            mysqli_stmt_execute($stmt2);
            $result = mysqli_stmt_get_result($stmt2);
            $cerere = mysqli_fetch_assoc($result);
            
            actualizeazaRanking($cerere['id_proprietar'], $conn_central);
            actualizeazaRanking($cerere['id_solicitant'], $conn_central);
            
            echo json_encode(['success' => true, 'message' => 'Feedback salvat']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Eroare salvare feedback']);
        }
        break;
    
    default:
        echo json_encode(['success' => false, 'message' => 'Acțiune necunoscută']);
}

mysqli_close($conn_central);
?>