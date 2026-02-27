<?php
// cautare_simpla.php - Căutare simplă în toate colecțiile
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

// Obține termenul de căutare
$termen = isset($_GET['q']) ? trim($_GET['q']) : '';
if (strlen($termen) < 3) {
    echo json_encode(['success' => false, 'error' => 'Minim 3 caractere']);
    exit;
}

$rezultate = [];
$total = 0;

try {
    // Conexiune la baza centrală
    $conn_central = getCentralDbConnection();
    
    // 1. Găsim toate colecțiile la care are acces utilizatorul
    // Colecții proprii
    $sql = "SELECT c.id_colectie, c.nume_colectie, c.prefix_tabele, c.id_utilizator, 
                   'proprietar' as tip_acces, u.db_name
            FROM colectii_utilizatori c
            JOIN utilizatori u ON c.id_utilizator = u.id_utilizator
            WHERE c.id_utilizator = ?
            
            UNION
            
            SELECT c.id_colectie, c.nume_colectie, c.prefix_tabele, c.id_utilizator,
                   p.tip_acces, u.db_name
            FROM partajari p
            JOIN colectii_utilizatori c ON p.id_colectie = c.id_colectie
            JOIN utilizatori u ON c.id_utilizator = u.id_utilizator
            WHERE p.id_utilizator_partajat = ? AND p.activ = 1";
    
    $stmt = mysqli_prepare($conn_central, $sql);
    mysqli_stmt_bind_param($stmt, "ii", $user['id_utilizator'], $user['id_utilizator']);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $colectii = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $colectii[] = $row;
    }
    mysqli_stmt_close($stmt);
    mysqli_close($conn_central);
    
    // 2. Căutăm în fiecare colecție
    $termen_like = '%' . $termen . '%';
    
    foreach ($colectii as $colectie) {
        // Conectare la baza de date a proprietarului
        $conn_user = getUserDbConnection($colectie['db_name']);
        if (!$conn_user) continue;
        
        $table = $colectie['prefix_tabele'] . 'obiecte';
        
        // Căutare simplă și rapidă - includem și imaginea
        $sql_search = "SELECT id_obiect, cutie, locatie, denumire_obiect, imagine 
                      FROM `$table`
                      WHERE denumire_obiect LIKE ?
                      LIMIT 10";
        
        $stmt_search = mysqli_prepare($conn_user, $sql_search);
        if ($stmt_search) {
            mysqli_stmt_bind_param($stmt_search, "s", $termen_like);
            mysqli_stmt_execute($stmt_search);
            $result_search = mysqli_stmt_get_result($stmt_search);
            
            $obiecte_gasite = [];
            while ($obiect = mysqli_fetch_assoc($result_search)) {
                // Procesăm denumirile pentru a găsi exact ce obiect conține termenul
                $denumiri = explode(',', $obiect['denumire_obiect']);
                $obiecte_match = [];
                $imagine_asociata = '';
                
                foreach ($denumiri as $index => $denumire) {
                    $denumire = trim($denumire);
                    if (stripos($denumire, $termen) !== false) {
                        // Extragem numele și indexul imaginii
                        if (preg_match('/^(.*?)\s*\((\d+)\)\s*$/', $denumire, $matches)) {
                            $nume_curat = $matches[1];
                            $index_imagine = intval($matches[2]) - 1; // Indexul imaginii (bazat pe 0)
                            
                            // Găsim imaginea asociată
                            if (!empty($obiect['imagine']) && empty($imagine_asociata)) {
                                $lista_imagini = array_map('trim', explode(',', $obiect['imagine']));
                                
                                if (isset($lista_imagini[$index_imagine])) {
                                    $imagine_asociata = 'imagini_obiecte/user_' . $colectie['id_utilizator'] . '/' . $lista_imagini[$index_imagine];
                                }
                            }
                        } else {
                            $nume_curat = $denumire;
                        }
                        $obiecte_match[] = $nume_curat;
                    }
                }
                
                if (!empty($obiecte_match)) {
                    $obiecte_gasite[] = [
                        'id_obiect' => $obiect['id_obiect'],
                        'cutie' => $obiect['cutie'],
                        'locatie' => $obiect['locatie'],
                        'obiecte' => implode(', ', array_unique($obiecte_match)),
                        'imagine' => $imagine_asociata
                    ];
                }
            }
            
            mysqli_stmt_close($stmt_search);
            
            if (!empty($obiecte_gasite)) {
                $rezultate[] = [
                    'id_colectie' => $colectie['id_colectie'],
                    'nume_colectie' => $colectie['nume_colectie'],
                    'tip_acces' => $colectie['tip_acces'],
                    'obiecte' => $obiecte_gasite
                ];
                $total += count($obiecte_gasite);
            }
        }
        
        mysqli_close($conn_user);
    }
    
    // 3. ADĂUGĂM: Căutare în colecțiile publice (doar în obiectele marcate ca publice)
    // Reconectăm la baza centrală pentru a găsi colecțiile publice
    $conn_central = getCentralDbConnection();
    
    // Găsim ID-urile colecțiilor deja procesate
    $colectii_procesate = array_column($colectii, 'id_colectie');
    
    // Găsim toate colecțiile care NU sunt ale utilizatorului și NU sunt deja partajate cu el
    // Includem și ranking-ul proprietarului
    $sql_publice = "SELECT DISTINCT c.id_colectie, c.nume_colectie, c.prefix_tabele, c.id_utilizator,
                           u.db_name, u.nume, u.prenume,
                           r.scor_total as ranking_scor,
                           r.nivel_ranking,
                           r.scor_disponibilitate,
                           r.scor_credibilitate
                    FROM colectii_utilizatori c
                    JOIN utilizatori u ON c.id_utilizator = u.id_utilizator
                    LEFT JOIN user_rankings r ON c.id_utilizator = r.id_utilizator
                    WHERE c.id_utilizator != ?";
    
    if (!empty($colectii_procesate)) {
        $sql_publice .= " AND c.id_colectie NOT IN (" . str_repeat('?,', count($colectii_procesate) - 1) . '?)';
    }
    
    $stmt = mysqli_prepare($conn_central, $sql_publice);
    if (!empty($colectii_procesate)) {
        $types = str_repeat('i', count($colectii_procesate) + 1);
        $params = array_merge([$user['id_utilizator']], $colectii_procesate);
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    } else {
        mysqli_stmt_bind_param($stmt, "i", $user['id_utilizator']);
    }
    
    mysqli_stmt_execute($stmt);
    $result_publice = mysqli_stmt_get_result($stmt);
    
    // Procesăm colecțiile publice
    while ($colectie_publica = mysqli_fetch_assoc($result_publice)) {
        $conn_user = getUserDbConnection($colectie_publica['db_name']);
        if (!$conn_user) continue;
        
        $table = $colectie_publica['prefix_tabele'] . 'obiecte';
        
        // Pentru colecțiile publice, căutăm DOAR în obiectele partajate
        $sql_search = "SELECT id_obiect, cutie, locatie, denumire_obiect, obiecte_partajate, imagine 
                      FROM `$table`
                      WHERE obiecte_partajate IS NOT NULL 
                      AND obiecte_partajate != ''
                      AND (obiecte_partajate LIKE ? OR 
                           (obiecte_partajate = denumire_obiect AND denumire_obiect LIKE ?))
                      LIMIT 5";
        
        $stmt_search = mysqli_prepare($conn_user, $sql_search);
        if ($stmt_search) {
            mysqli_stmt_bind_param($stmt_search, "ss", $termen_like, $termen_like);
            mysqli_stmt_execute($stmt_search);
            $result_search = mysqli_stmt_get_result($stmt_search);
            
            $obiecte_gasite = [];
            while ($obiect = mysqli_fetch_assoc($result_search)) {
                // Verificăm doar obiectele publice
                $obiecte_partajate = $obiect['obiecte_partajate'];
                $toate_partajate = ($obiecte_partajate === $obiect['denumire_obiect']);
                
                // Determinăm lista de obiecte de procesat
                if ($toate_partajate) {
                    $denumiri = explode(',', $obiect['denumire_obiect']);
                } else {
                    $denumiri = explode(',', $obiecte_partajate);
                }
                
                $obiecte_match = [];
                $imagine_asociata = '';
                
                foreach ($denumiri as $index => $denumire) {
                    $denumire = trim($denumire);
                    if (stripos($denumire, $termen) !== false) {
                        // Extragem numele și indexul imaginii
                        if (preg_match('/^(.*?)\s*\((\d+)\)\s*$/', $denumire, $matches)) {
                            $nume_curat = $matches[1];
                            $index_imagine = intval($matches[2]) - 1;
                            
                            // Găsim imaginea asociată
                            if (!empty($obiect['imagine']) && empty($imagine_asociata)) {
                                $lista_imagini = array_map('trim', explode(',', $obiect['imagine']));
                                if (isset($lista_imagini[$index_imagine])) {
                                    $imagine_asociata = 'imagini_obiecte/user_' . $colectie_publica['id_utilizator'] . '/' . $lista_imagini[$index_imagine];
                                }
                            }
                        } else {
                            $nume_curat = $denumire;
                        }
                        $obiecte_match[] = $nume_curat;
                    }
                }
                
                if (!empty($obiecte_match)) {
                    $obiecte_gasite[] = [
                        'id_obiect' => $obiect['id_obiect'],
                        'cutie' => $obiect['cutie'],
                        'locatie' => $obiect['locatie'],
                        'obiecte' => implode(', ', array_unique($obiecte_match)),
                        'imagine' => $imagine_asociata,
                        'proprietar' => $colectie_publica['prenume'] . ' ' . substr($colectie_publica['nume'], 0, 1) . '.'
                    ];
                }
            }
            
            mysqli_stmt_close($stmt_search);
            
            if (!empty($obiecte_gasite)) {
                $rezultate[] = [
                    'id_colectie' => $colectie_publica['id_colectie'],
                    'nume_colectie' => $colectie_publica['nume_colectie'] . ' (Public)',
                    'tip_acces' => 'public',
                    'proprietar' => $colectie_publica['prenume'] . ' ' . substr($colectie_publica['nume'], 0, 1) . '.',
                    'id_proprietar' => $colectie_publica['id_utilizator'],
                    'ranking_scor' => $colectie_publica['ranking_scor'],
                    'nivel_ranking' => $colectie_publica['nivel_ranking'],
                    'scor_disponibilitate' => $colectie_publica['scor_disponibilitate'],
                    'scor_credibilitate' => $colectie_publica['scor_credibilitate'],
                    'obiecte' => $obiecte_gasite
                ];
                $total += count($obiecte_gasite);
            }
        }
        
        mysqli_close($conn_user);
    }
    
    mysqli_stmt_close($stmt);
    mysqli_close($conn_central);
    
    echo json_encode([
        'success' => true, 
        'total' => $total,
        'rezultate' => $rezultate
    ]);
    
} catch (Exception $e) {
    error_log("Eroare căutare simplă: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Eroare la căutare']);
}
?>