<?php
// Handler îmbunătățit pentru import
require_once 'includes/auth_functions.php';
require_once 'config.php';

header('Content-Type: application/json');

// Verifică autentificarea
$user = checkSession();
if (!$user) {
    echo json_encode(['error' => 'Neautentificat']);
    exit;
}

// Verifică dacă utilizatorul are baza de date configurată
if (empty($user['db_name'])) {
    echo json_encode(['error' => 'Baza de date nu este configurată']);
    exit;
}

// Reconectează la baza de date a utilizatorului
mysqli_close($conn);
$conn = getUserDbConnection($user['db_name']);
if (!$conn) {
    echo json_encode(['error' => 'Eroare la conectarea la baza de date']);
    exit;
}

// Setează prefix pentru tabele
$table_prefix = $user['prefix_tabele'] ?? 'user_' . $user['id_utilizator'] . '_';

$response = [
    'success' => false,
    'message' => '',
    'details' => []
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['import_file'])) {
    if ($_FILES['import_file']['error'] === UPLOAD_ERR_OK) {
        $content = file_get_contents($_FILES['import_file']['tmp_name']);
        
        // Detectează automat ce tabele sunt în fișier
        $detected_tables = [];
        if (preg_match_all('/(?:INSERT INTO|CREATE TABLE|DROP TABLE IF EXISTS)\s*`([^`]+)`/i', $content, $matches)) {
            $detected_tables = array_unique($matches[1]);
            $response['details']['detected_tables'] = $detected_tables;
        }
        
        // Verifică dacă este un export din aplicație (cu prefix corect) sau din phpMyAdmin
        $has_correct_prefix = false;
        $needs_prefix_update = false;
        $detected_prefix = null;
        
        foreach ($detected_tables as $table) {
            if ($table === "{$table_prefix}obiecte" || $table === "{$table_prefix}detectii_obiecte") {
                $has_correct_prefix = true;
                break;
            } elseif (preg_match('/^(user_\d+_)(obiecte|detectii_obiecte)$/', $table, $matches)) {
                $needs_prefix_update = true;
                $detected_prefix = $matches[1];
                break;
            } elseif ($table === 'obiecte' || $table === 'detectii_obiecte') {
                // Tabele fără prefix - bază de date mono-utilizator veche
                $needs_prefix_update = true;
                $detected_prefix = '';
                break;
            }
        }
        
        // Procesează importul
        if ($has_correct_prefix || $needs_prefix_update) {
            // Dacă trebuie actualizat prefixul
            if ($needs_prefix_update) {
                if ($detected_prefix === '') {
                    // Tabele fără prefix - adaugă prefixul
                    $content = preg_replace(
                        '/`(obiecte|detectii_obiecte)`/i', 
                        '`' . $table_prefix . '$1`', 
                        $content
                    );
                } else {
                    // Înlocuiește prefixul vechi cu cel nou
                    $content = preg_replace(
                        '/`' . preg_quote($detected_prefix, '/') . '(obiecte|detectii_obiecte)`/i', 
                        '`' . $table_prefix . '$1`', 
                        $content
                    );
                }
                $response['details']['prefix_updated'] = true;
                $response['details']['old_prefix'] = $detected_prefix ?: 'none';
                $response['details']['new_prefix'] = $table_prefix;
            }
            
            // Elimină comenzile CREATE TABLE și DROP TABLE pentru siguranță
            // Elimină DROP TABLE cu toate variantele
            $content = preg_replace('/DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?[^;]+;/si', '', $content);
            
            // Elimină CREATE TABLE - pattern mai robust
            // Găsește CREATE TABLE și elimină tot până la următorul ";" care nu e într-un string
            $content = preg_replace('/CREATE\s+TABLE\s+.*?;\s*(?=\n|$)/si', '', $content);
            
            // Elimină și comentariile specifice phpMyAdmin care pot cauza probleme
            $content = preg_replace('/^--\s+Eliminarea datelor.*$/mi', '', $content);
            $content = preg_replace('/^--\s+Structur[ăa].*$/mi', '', $content);
            
            // Elimină ALTER TABLE pentru constrângeri
            $content = preg_replace('/ALTER\s+TABLE\s+[^;]+\s+ADD\s+(?:CONSTRAINT\s+)?[^;]*FOREIGN\s+KEY[^;]+;/si', '', $content);
            $content = preg_replace('/ALTER\s+TABLE\s+[^;]+\s+ADD\s+(?:CONSTRAINT\s+)?[^;]*PRIMARY\s+KEY[^;]+;/si', '', $content);
            $content = preg_replace('/ALTER\s+TABLE\s+[^;]+\s+ADD\s+(?:KEY|INDEX)[^;]+;/si', '', $content);
            
            // Elimină și alte comenzi care pot cauza probleme
            $content = preg_replace('/^\/\*![\d]+\s+SET[^*]+\*\/;?\s*$/mi', '', $content);
            $content = preg_replace('/^SET\s+[^;]+;\s*$/mi', '', $content);
            $content = preg_replace('/^LOCK\s+TABLES[^;]+;\s*$/mi', '', $content);
            $content = preg_replace('/^UNLOCK\s+TABLES;\s*$/mi', '', $content);
            
            // Pentru phpMyAdmin exports, INSERT-urile sunt de obicei terminate cu ");\n"
            // Să procesăm conținutul mai simplu
            $lines = explode("\n", $content);
            $current_query = '';
            $queries = [];
            
            foreach ($lines as $line) {
                // Sari peste linii goale și comentarii
                if (trim($line) === '' || strpos(trim($line), '--') === 0 || strpos(trim($line), '/*') === 0) {
                    continue;
                }
                
                $current_query .= $line . "\n";
                
                // Dacă linia se termină cu ; și nu suntem într-un string
                if (preg_match('/;\s*$/', $line)) {
                    // Verifică dacă nu suntem într-un VALUES multiline
                    $open_parens = substr_count($current_query, '(');
                    $close_parens = substr_count($current_query, ')');
                    
                    if ($open_parens <= $close_parens) {
                        $queries[] = trim($current_query);
                        $current_query = '';
                    }
                }
            }
            
            // Adaugă ultima query dacă există
            if (trim($current_query) !== '') {
                $queries[] = trim($current_query);
            }
            
            $imported = 0;
            $failed = 0;
            $skipped = 0;
            $inserts_found = 0;
            
            // Numără câte INSERT-uri sunt în fișier
            foreach ($queries as $q) {
                if (stripos(trim($q), 'INSERT INTO') === 0) {
                    $inserts_found++;
                }
            }
            $response['details']['inserts_found'] = $inserts_found;
            
            // Debug: arată conținutul după procesare (primele 500 caractere)
            $response['details']['content_preview'] = substr($content, 0, 500);
            $response['details']['total_queries_before_filter'] = count($queries);
            
            // Debug: arată primele 3 queries găsite
            $debug_queries = array_slice($queries, 0, 3);
            $response['details']['first_queries'] = array_map(function($q) {
                return substr($q, 0, 80) . '...';
            }, $debug_queries);
            
            // Începe tranzacția
            mysqli_begin_transaction($conn);
            
            try {
                // Opțional: truncate tabelele existente înainte de import
                if (isset($_POST['truncate_before_import']) && $_POST['truncate_before_import'] === '1') {
                    // Dezactivează temporar verificarea cheilor străine
                    mysqli_query($conn, "SET FOREIGN_KEY_CHECKS = 0");
                    
                    // Șterge datele din ambele tabele
                    mysqli_query($conn, "TRUNCATE TABLE `{$table_prefix}detectii_obiecte`");
                    mysqli_query($conn, "TRUNCATE TABLE `{$table_prefix}obiecte`");
                    
                    // Reactivează verificarea cheilor străine
                    mysqli_query($conn, "SET FOREIGN_KEY_CHECKS = 1");
                    
                    $response['details']['truncated'] = true;
                }
                
                foreach ($queries as $query) {
                    $query = trim($query);
                    
                    // Sari peste comentarii și query-uri goale
                    if (empty($query) || 
                        preg_match('/^\s*--/', $query) || 
                        preg_match('/^\s*\/\*/', $query) ||
                        preg_match('/^\s*SET\s+/i', $query) ||
                        preg_match('/^\s*LOCK\s+TABLES/i', $query) ||
                        preg_match('/^\s*UNLOCK\s+TABLES/i', $query) ||
                        preg_match('/^\s*\/\*!\d+/', $query)) {
                        $skipped++;
                        continue;
                    }
                    
                    // Procesează doar INSERT-uri
                    if (stripos($query, 'INSERT INTO') === 0) {
                        // Debug: verifică primele 3 INSERT-uri
                        if ($imported + $failed < 3) {
                            $response['details']['debug_queries'][] = substr($query, 0, 100) . '...';
                        }
                        
                        if (mysqli_query($conn, $query)) {
                            $imported++;
                            // Pentru INSERT cu ON DUPLICATE KEY UPDATE, verifică câte rânduri au fost afectate
                            $affected = mysqli_affected_rows($conn);
                            if ($affected == 0) {
                                $response['details']['duplicates'] = ($response['details']['duplicates'] ?? 0) + 1;
                            }
                        } else {
                            $failed++;
                            throw new Exception("Eroare SQL: " . mysqli_error($conn));
                        }
                    } else {
                        $skipped++;
                    }
                }
                
                // Commit dacă totul e ok
                mysqli_commit($conn);
                
                $response['success'] = true;
                if ($imported > 0) {
                    $response['message'] = "Import realizat cu succes! $imported înregistrări adăugate.";
                } else {
                    $response['message'] = "Import finalizat, dar nu s-au adăugat înregistrări noi.";
                }
                $response['details']['imported'] = $imported;
                $response['details']['failed'] = $failed;
                $response['details']['skipped'] = $skipped;
                $response['details']['total_queries'] = count($queries);
                
            } catch (Exception $e) {
                // Rollback în caz de eroare
                mysqli_rollback($conn);
                $response['message'] = "Import eșuat: " . $e->getMessage();
                $response['details']['error'] = $e->getMessage();
                $response['details']['imported'] = $imported;
                $response['details']['failed'] = $failed;
            }
            
        } else {
            $response['message'] = "Fișierul nu conține tabele compatibile cu utilizatorul curent.";
            $response['details']['detected_tables'] = $detected_tables;
            $response['details']['expected_prefix'] = $table_prefix;
            $response['details']['info'] = "Se așteaptă tabele cu prefixul '{$table_prefix}' dar s-au găsit: " . implode(', ', $detected_tables);
        }
        
    } else {
        $response['message'] = "Eroare la încărcarea fișierului";
    }
} else {
    $response['message'] = "Cerere invalidă";
}

echo json_encode($response);
?>