<?php
/**
 * Backend pentru gestionarea colecțiilor multiple
 * Inventar.live - August 2025
 */

require_once 'includes/auth_functions.php';
require_once 'config.php';

// Verifică autentificarea
$user = checkSession();
if (!$user) {
    http_response_code(401);
    die(json_encode(['success' => false, 'message' => 'Neautentificat']));
}

// Setează header pentru JSON
header('Content-Type: application/json');

// Obține conexiunea la baza centrală
$conn_central = getCentralDbConnection();

// Obține acțiunea din request
$data = json_decode(file_get_contents('php://input'), true);
if ($data) {
    $_POST = array_merge($_POST, $data);
}
$actiune = $_POST['actiune'] ?? $_GET['actiune'] ?? '';

switch ($actiune) {
    case 'lista_colectii':
        listeazaColectii($conn_central, $user);
        break;
        
    case 'creare_colectie':
        creareColectieNoua($conn_central, $user);
        break;
        
    case 'redenumire_colectie':
        redenumireColectie($conn_central, $user);
        break;
        
    case 'stergere_colectie':
        stergereColectie($conn_central, $user);
        break;
        
    case 'schimba_colectie':
        schimbaColectiaCurenta($conn_central, $user);
        break;
        
    default:
        echo json_encode(['success' => false, 'message' => 'Acțiune necunoscută']);
}

mysqli_close($conn_central);

/**
 * Listează toate colecțiile utilizatorului (proprii + partajate)
 */
function listeazaColectii($conn, $user) {
    $rezultat = [
        'success' => true,
        'colectii_proprii' => [],
        'colectii_partajate' => [],
        'id_colectie_curenta' => $_SESSION['id_colectie_curenta'] ?? $user['id_colectie_principala']
    ];
    
    // 1. Colecțiile proprii
    $sql_proprii = "SELECT c.*, 
                    (SELECT COUNT(*) FROM partajari p WHERE p.id_colectie = c.id_colectie AND p.activ = 1) as nr_partajari
                    FROM colectii_utilizatori c 
                    WHERE c.id_utilizator = ? 
                    ORDER BY c.este_principala DESC, c.data_creare";
    
    $stmt = mysqli_prepare($conn, $sql_proprii);
    mysqli_stmt_bind_param($stmt, "i", $user['id_utilizator']);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    while ($row = mysqli_fetch_assoc($result)) {
        $rezultat['colectii_proprii'][] = [
            'id_colectie' => $row['id_colectie'],
            'nume_colectie' => $row['nume_colectie'],
            'prefix_tabele' => $row['prefix_tabele'],
            'este_principala' => $row['este_principala'],
            'este_publica' => $row['este_publica'],
            'nr_partajari' => $row['nr_partajari']
        ];
    }
    mysqli_stmt_close($stmt);
    
    // 2. Colecțiile partajate cu mine
    $sql_partajate = "SELECT c.*, p.tip_acces, u.nume, u.prenume,
                      (SELECT COUNT(*) FROM notificari_partajare n 
                       WHERE n.id_colectie = c.id_colectie 
                       AND n.id_utilizator_destinatar = ? 
                       AND n.citita = 0) as notificari_necitite
                      FROM partajari p
                      JOIN colectii_utilizatori c ON p.id_colectie = c.id_colectie
                      JOIN utilizatori u ON c.id_utilizator = u.id_utilizator
                      WHERE p.id_utilizator_partajat = ? AND p.activ = 1
                      ORDER BY p.data_partajare DESC";
    
    $stmt = mysqli_prepare($conn, $sql_partajate);
    mysqli_stmt_bind_param($stmt, "ii", $user['id_utilizator'], $user['id_utilizator']);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    while ($row = mysqli_fetch_assoc($result)) {
        $rezultat['colectii_partajate'][] = [
            'id_colectie' => $row['id_colectie'],
            'nume_colectie' => $row['nume_colectie'],
            'prefix_tabele' => $row['prefix_tabele'],
            'tip_acces' => $row['tip_acces'],
            'proprietar' => $row['prenume'] . ' ' . $row['nume'],
            'notificari_necitite' => $row['notificari_necitite']
        ];
    }
    mysqli_stmt_close($stmt);
    
    echo json_encode($rezultat);
}

/**
 * Creează o colecție nouă
 */
function creareColectieNoua($conn, $user) {
    $nume = trim($_POST['nume'] ?? '');
    
    if (empty($nume)) {
        echo json_encode(['success' => false, 'message' => 'Numele colecției este obligatoriu']);
        return;
    }
    
    // Generează prefix unic pentru tabele
    $prefix_base = 'user_' . $user['id_utilizator'] . '_';
    $nume_normalized = preg_replace('/[^a-z0-9]/i', '', strtolower($nume));
    $nume_normalized = substr($nume_normalized, 0, 20); // Limitează lungimea
    
    // Verifică unicitatea prefixului
    $prefix_final = $prefix_base . $nume_normalized . '_';
    $counter = 1;
    
    while (true) {
        $check_sql = "SELECT id_colectie FROM colectii_utilizatori WHERE prefix_tabele = ?";
        $stmt = mysqli_prepare($conn, $check_sql);
        mysqli_stmt_bind_param($stmt, "s", $prefix_final);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if (mysqli_num_rows($result) == 0) {
            mysqli_stmt_close($stmt);
            break;
        }
        
        mysqli_stmt_close($stmt);
        $prefix_final = $prefix_base . $nume_normalized . $counter . '_';
        $counter++;
    }
    
    // Începe tranzacția
    mysqli_begin_transaction($conn);
    
    try {
        // 1. Inserează în colectii_utilizatori
        $insert_sql = "INSERT INTO colectii_utilizatori (id_utilizator, nume_colectie, prefix_tabele, este_principala) 
                       VALUES (?, ?, ?, 0)";
        $stmt = mysqli_prepare($conn, $insert_sql);
        mysqli_stmt_bind_param($stmt, "iss", $user['id_utilizator'], $nume, $prefix_final);
        
        if (!mysqli_stmt_execute($stmt)) {
            throw new Exception("Eroare la crearea colecției: " . mysqli_error($conn));
        }
        
        $id_colectie = mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);
        
        // 2. Creează tabelele pentru colecție
        $table_obiecte = $prefix_final . 'obiecte';
        $table_detectii = $prefix_final . 'detectii_obiecte';
        
        // Tabel obiecte
        $create_obiecte = "CREATE TABLE IF NOT EXISTS `$table_obiecte` (
            id_obiect INT AUTO_INCREMENT PRIMARY KEY,
            denumire_obiect TEXT,
            cantitate_obiect TEXT,
            cutie VARCHAR(255),
            locatie VARCHAR(255),
            categorie TEXT,
            eticheta TEXT,
            descriere_categorie TEXT,
            eticheta_obiect TEXT,
            imagine TEXT,
            imagine_obiect TEXT,
            obiecte_partajate TEXT,
            data_adaugare TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_cutie_locatie (cutie, locatie),
            INDEX idx_categorie (categorie(255))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        if (!mysqli_query($conn, $create_obiecte)) {
            throw new Exception("Eroare la crearea tabelei obiecte: " . mysqli_error($conn));
        }
        
        // Tabel detecții
        $create_detectii = "CREATE TABLE IF NOT EXISTS `$table_detectii` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            id_obiect INT NOT NULL,
            denumire VARCHAR(255) NOT NULL,
            sursa ENUM('manual', 'google_vision') DEFAULT 'manual',
            data_detectie TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (id_obiect) REFERENCES `$table_obiecte`(id_obiect) ON DELETE CASCADE,
            INDEX idx_obiect_denumire (id_obiect, denumire),
            INDEX idx_sursa (sursa)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        if (!mysqli_query($conn, $create_detectii)) {
            throw new Exception("Eroare la crearea tabelei detecții: " . mysqli_error($conn));
        }
        
        // 3. Creează directoarele pentru imagini
        $user_dir = "user_" . $user['id_utilizator'];
        $dirs = [
            "imagini_obiecte/$user_dir/" . $id_colectie,
            "imagini_decupate/$user_dir/" . $id_colectie
        ];
        
        foreach ($dirs as $dir) {
            if (!file_exists($dir)) {
                if (!mkdir($dir, 0777, true)) {
                    throw new Exception("Eroare la crearea directorului: $dir");
                }
            }
        }
        
        // Commit tranzacția
        mysqli_commit($conn);
        
        echo json_encode([
            'success' => true,
            'message' => 'Colecția a fost creată cu succes',
            'id_colectie' => $id_colectie,
            'nume_colectie' => $nume,
            'prefix_tabele' => $prefix_final
        ]);
        
    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

/**
 * Redenumește o colecție
 */
function redenumireColectie($conn, $user) {
    $id_colectie = intval($_POST['id_colectie'] ?? 0);
    $nume_nou = trim($_POST['nume_nou'] ?? '');
    
    if (!$id_colectie || empty($nume_nou)) {
        echo json_encode(['success' => false, 'message' => 'Date invalide']);
        return;
    }
    
    // Verifică dacă utilizatorul deține colecția
    $check_sql = "SELECT id_colectie FROM colectii_utilizatori 
                  WHERE id_colectie = ? AND id_utilizator = ?";
    $stmt = mysqli_prepare($conn, $check_sql);
    mysqli_stmt_bind_param($stmt, "ii", $id_colectie, $user['id_utilizator']);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if (mysqli_num_rows($result) == 0) {
        mysqli_stmt_close($stmt);
        echo json_encode(['success' => false, 'message' => 'Nu aveți permisiunea de a modifica această colecție']);
        return;
    }
    mysqli_stmt_close($stmt);
    
    // Actualizează numele
    $update_sql = "UPDATE colectii_utilizatori SET nume_colectie = ? WHERE id_colectie = ?";
    $stmt = mysqli_prepare($conn, $update_sql);
    mysqli_stmt_bind_param($stmt, "si", $nume_nou, $id_colectie);
    
    if (mysqli_stmt_execute($stmt)) {
        echo json_encode(['success' => true, 'message' => 'Numele colecției a fost actualizat']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Eroare la actualizarea numelui']);
    }
    
    mysqli_stmt_close($stmt);
}

/**
 * Șterge o colecție (doar dacă nu e principală și nu are partajări active)
 */
function stergereColectie($conn, $user) {
    $id_colectie = intval($_POST['id_colectie'] ?? 0);
    
    if (!$id_colectie) {
        echo json_encode(['success' => false, 'message' => 'ID colecție invalid']);
        return;
    }
    
    // Verificări multiple
    $check_sql = "SELECT c.*, 
                  (SELECT COUNT(*) FROM partajari p WHERE p.id_colectie = c.id_colectie AND p.activ = 1) as nr_partajari
                  FROM colectii_utilizatori c 
                  WHERE c.id_colectie = ? AND c.id_utilizator = ?";
    
    $stmt = mysqli_prepare($conn, $check_sql);
    mysqli_stmt_bind_param($stmt, "ii", $id_colectie, $user['id_utilizator']);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($row = mysqli_fetch_assoc($result)) {
        if ($row['este_principala']) {
            echo json_encode(['success' => false, 'message' => 'Nu puteți șterge colecția principală']);
            mysqli_stmt_close($stmt);
            return;
        }
        
        if ($row['nr_partajari'] > 0) {
            echo json_encode(['success' => false, 'message' => 'Nu puteți șterge o colecție partajată cu alți utilizatori']);
            mysqli_stmt_close($stmt);
            return;
        }
        
        $prefix = $row['prefix_tabele'];
    } else {
        echo json_encode(['success' => false, 'message' => 'Colecție inexistentă sau fără permisiuni']);
        mysqli_stmt_close($stmt);
        return;
    }
    mysqli_stmt_close($stmt);
    
    // Începe ștergerea
    mysqli_begin_transaction($conn);
    
    try {
        // 1. Șterge tabelele
        $tables = [$prefix . 'detectii_obiecte', $prefix . 'obiecte'];
        foreach ($tables as $table) {
            $drop_sql = "DROP TABLE IF EXISTS `$table`";
            if (!mysqli_query($conn, $drop_sql)) {
                throw new Exception("Eroare la ștergerea tabelei $table");
            }
        }
        
        // 2. Șterge înregistrarea din colectii_utilizatori
        $delete_sql = "DELETE FROM colectii_utilizatori WHERE id_colectie = ?";
        $stmt = mysqli_prepare($conn, $delete_sql);
        mysqli_stmt_bind_param($stmt, "i", $id_colectie);
        
        if (!mysqli_stmt_execute($stmt)) {
            throw new Exception("Eroare la ștergerea colecției din baza de date");
        }
        mysqli_stmt_close($stmt);
        
        // 3. Șterge directoarele cu imagini
        $user_dir = "user_" . $user['id_utilizator'];
        $dirs = [
            "imagini_obiecte/$user_dir/" . $id_colectie,
            "imagini_decupate/$user_dir/" . $id_colectie
        ];
        
        foreach ($dirs as $dir) {
            if (is_dir($dir)) {
                // Șterge recursiv conținutul
                $files = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST
                );
                
                foreach ($files as $fileinfo) {
                    $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
                    $todo($fileinfo->getRealPath());
                }
                
                rmdir($dir);
            }
        }
        
        mysqli_commit($conn);
        echo json_encode(['success' => true, 'message' => 'Colecția a fost ștearsă cu succes']);
        
    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

/**
 * Schimbă colecția curentă în sesiune
 */
function schimbaColectiaCurenta($conn, $user) {
    $id_colectie = intval($_POST['id_colectie'] ?? 0);
    
    if (!$id_colectie) {
        echo json_encode(['success' => false, 'message' => 'ID colecție invalid']);
        return;
    }
    
    // Verifică dacă utilizatorul are acces la colecție și obține ID-ul proprietarului
    $check_sql = "SELECT c.*, c.id_utilizator as proprietar_id, p.tip_acces
                  FROM colectii_utilizatori c
                  LEFT JOIN partajari p ON c.id_colectie = p.id_colectie 
                       AND p.id_utilizator_partajat = ? AND p.activ = 1
                  WHERE c.id_colectie = ? 
                  AND (c.id_utilizator = ? OR p.id_partajare IS NOT NULL)";
    
    $stmt = mysqli_prepare($conn, $check_sql);
    mysqli_stmt_bind_param($stmt, "iii", $user['id_utilizator'], $id_colectie, $user['id_utilizator']);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($row = mysqli_fetch_assoc($result)) {
        // Salvează în sesiune
        $_SESSION['id_colectie_curenta'] = $id_colectie;
        $_SESSION['id_colectie_selectata'] = $id_colectie; // Important pentru persistență
        $_SESSION['prefix_tabele'] = $row['prefix_tabele'];
        $_SESSION['tip_acces_colectie'] = $row['tip_acces'] ?? 'proprietar';
        $_SESSION['colectie_proprietar_id'] = $row['proprietar_id']; // IMPORTANT: Salvăm ID-ul proprietarului
        
        echo json_encode([
            'success' => true,
            'message' => 'Colecția curentă a fost schimbată',
            'colectie' => [
                'id_colectie' => $row['id_colectie'],
                'nume_colectie' => $row['nume_colectie'],
                'prefix_tabele' => $row['prefix_tabele'],
                'proprietar_id' => $row['proprietar_id'],
                'tip_acces' => $_SESSION['tip_acces_colectie']
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Nu aveți acces la această colecție']);
    }
    
    mysqli_stmt_close($stmt);
}
?>