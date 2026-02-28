<?php
/**
 * Cron Job - Procesare Automată Imagini cu Claude Vision
 *
 * Rulează noaptea (recomandat 02:00-05:00) pentru a procesa
 * imaginile încărcate în timpul zilei.
 *
 * Configurare cron:
 * 0 2 * * * /usr/bin/php /home/inventar/public_html/cron/procesare_automata_imagini.php >> /home/inventar/public_html/logs/cron_procesare.log 2>&1
 *
 * @version 1.0.0
 * @date 28 Februarie 2026
 */

// Setări PHP pentru procesare lungă
set_time_limit(3600); // 1 oră max
ini_set('memory_limit', '512M');

// Definește ROOT path
define('ROOT_PATH', dirname(__DIR__));

// Logging
$log_file = ROOT_PATH . '/logs/cron_procesare.log';
$log_dir = dirname($log_file);
if (!is_dir($log_dir)) {
    mkdir($log_dir, 0755, true);
}

/**
 * Funcție de logging
 */
function cron_log($message, $level = 'INFO') {
    global $log_file;
    $timestamp = date('Y-m-d H:i:s');
    $log_line = "[$timestamp] [$level] $message\n";
    file_put_contents($log_file, $log_line, FILE_APPEND | LOCK_EX);

    // Afișează și în console pentru debugging
    echo $log_line;
}

/**
 * Rotație log (max 10MB)
 */
function rotate_log() {
    global $log_file;
    if (file_exists($log_file) && filesize($log_file) > 10 * 1024 * 1024) {
        rename($log_file, $log_file . '.' . date('Y-m-d-His') . '.old');
        cron_log("Log rotated");
    }
}

// Start
cron_log("========================================");
cron_log("CRON JOB PORNIT - Procesare Imagini");
cron_log("========================================");

// Verifică că nu rulează deja
$lock_file = ROOT_PATH . '/logs/cron_procesare.lock';
if (file_exists($lock_file)) {
    $lock_time = filemtime($lock_file);
    $lock_age = time() - $lock_time;

    // Dacă lock-ul e mai vechi de 2 ore, îl ștergem (probabil crashed)
    if ($lock_age > 7200) {
        unlink($lock_file);
        cron_log("Lock file vechi șters (age: {$lock_age}s)", 'WARNING');
    } else {
        cron_log("Alt proces rulează deja (lock age: {$lock_age}s). Exit.", 'WARNING');
        exit(0);
    }
}

// Creează lock file
file_put_contents($lock_file, date('Y-m-d H:i:s'));

// Cleanup la exit
register_shutdown_function(function() use ($lock_file) {
    if (file_exists($lock_file)) {
        unlink($lock_file);
    }
});

// Încarcă dependențele (FĂRĂ config.php care verifică autentificarea!)
try {
    // Încarcă config centrală direct (nu config.php care face redirect)
    require_once ROOT_PATH . '/config_central.php';
    require_once ROOT_PATH . '/config_claude.php';
    require_once ROOT_PATH . '/includes/claude_vision_service.php';

    cron_log("Dependențe încărcate");
} catch (Exception $e) {
    cron_log("EROARE la încărcare dependențe: " . $e->getMessage(), 'ERROR');
    exit(1);
}

// Conectare la baza de date centrală
try {
    $conn = getCentralDbConnection();
    if (!$conn) {
        throw new Exception("Nu pot conecta la DB centrală");
    }
    cron_log("Conexiune DB stabilită");
} catch (Exception $e) {
    cron_log("EROARE DB: " . $e->getMessage(), 'ERROR');
    exit(1);
}

// Verifică dacă tabela queue există
$table_check = mysqli_query($conn, "SHOW TABLES LIKE 'procesare_imagini_queue'");
if (mysqli_num_rows($table_check) === 0) {
    cron_log("Tabela procesare_imagini_queue nu există. Rulează sql/claude_vision_queue.sql mai întâi.", 'ERROR');
    mysqli_close($conn);
    exit(1);
}

// Inițializează serviciul Claude Vision
$claude = new ClaudeVisionService(CLAUDE_API_KEY, true);

// Test conexiune API
$test = $claude->testConnection();
if (!$test['success']) {
    cron_log("EROARE conexiune Claude API: " . $test['error'], 'ERROR');
    mysqli_close($conn);
    exit(1);
}
cron_log("Conexiune Claude API OK (model: " . $test['model'] . ")");

// Selectează imaginile pending
$batch_size = defined('CLAUDE_BATCH_SIZE') ? CLAUDE_BATCH_SIZE : 50;
$sql = "SELECT * FROM procesare_imagini_queue
        WHERE status = 'pending'
        AND retry_count < max_retries
        ORDER BY prioritate ASC, data_adaugare ASC
        LIMIT ?";

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "i", $batch_size);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$pending_count = mysqli_num_rows($result);
cron_log("Imagini pending în queue: $pending_count");

if ($pending_count === 0) {
    cron_log("Nimic de procesat. Exit.");
    mysqli_close($conn);
    exit(0);
}

// Statistici
$stats = [
    'processed' => 0,
    'success' => 0,
    'failed' => 0,
    'total_objects' => 0,
    'total_tokens' => 0,
    'total_time' => 0
];

$start_time = microtime(true);

// Procesează fiecare imagine
while ($row = mysqli_fetch_assoc($result)) {
    $queue_id = $row['id'];
    $image_path = $row['cale_imagine'];
    $user_id = $row['id_utilizator'];
    $colectie_id = $row['id_colectie'];

    cron_log("Procesez #{$queue_id}: " . basename($image_path));

    // Marchează ca "processing"
    $update_sql = "UPDATE procesare_imagini_queue
                   SET status = 'processing', data_procesare = NOW()
                   WHERE id = ?";
    $update_stmt = mysqli_prepare($conn, $update_sql);
    mysqli_stmt_bind_param($update_stmt, "i", $queue_id);
    mysqli_stmt_execute($update_stmt);

    // Construiește calea completă
    $full_path = ROOT_PATH . '/' . $image_path;

    // Verifică dacă imaginea există
    if (!file_exists($full_path)) {
        cron_log("  ✗ Imaginea nu există: $full_path", 'WARNING');

        $fail_sql = "UPDATE procesare_imagini_queue
                     SET status = 'failed',
                         eroare_mesaj = 'Imaginea nu există pe disc',
                         data_completare = NOW()
                     WHERE id = ?";
        $fail_stmt = mysqli_prepare($conn, $fail_sql);
        mysqli_stmt_bind_param($fail_stmt, "i", $queue_id);
        mysqli_stmt_execute($fail_stmt);

        $stats['failed']++;
        continue;
    }

    // Opțiuni pentru analiză
    $options = [
        'context' => $row['context_manual'] ?? '',
        'location' => $row['locatie'] ?? '',
        'box_name' => $row['cutie'] ?? ''
    ];

    // Apelează Claude Vision
    $analysis_start = microtime(true);
    $result_analysis = $claude->analyzeImage($full_path, $options);
    $analysis_time = microtime(true) - $analysis_start;

    $stats['processed']++;
    $stats['total_time'] += $analysis_time;

    if ($result_analysis['success']) {
        // Succes!
        $data = $result_analysis['data'];
        $num_objects = count($data['obiecte'] ?? []);
        $tokens_used = ($result_analysis['usage']['input_tokens'] ?? 0) +
                       ($result_analysis['usage']['output_tokens'] ?? 0);

        cron_log("  ✓ Succes: $num_objects obiecte găsite în " . round($analysis_time, 2) . "s");

        $stats['success']++;
        $stats['total_objects'] += $num_objects;
        $stats['total_tokens'] += $tokens_used;

        // Salvează rezultatul
        $result_json = json_encode($data, JSON_UNESCAPED_UNICODE);
        $cost_estimate = ($tokens_used / 1000000) * 1.50; // Estimare cost

        $success_sql = "UPDATE procesare_imagini_queue
                        SET status = 'completed',
                            rezultat_json = ?,
                            numar_obiecte_gasite = ?,
                            tokens_utilizate = ?,
                            cost_estimat = ?,
                            data_completare = NOW()
                        WHERE id = ?";
        $success_stmt = mysqli_prepare($conn, $success_sql);
        mysqli_stmt_bind_param($success_stmt, "siidi",
            $result_json, $num_objects, $tokens_used, $cost_estimate, $queue_id);
        mysqli_stmt_execute($success_stmt);

        // Salvează obiectele individuale în tabela utilizatorului
        // (Opțional - pentru integrare completă cu sistemul existent)
        saveIdentifiedObjects($conn, $user_id, $colectie_id, $queue_id, $data['obiecte'] ?? []);

    } else {
        // Eroare
        $error_msg = $result_analysis['error'] ?? 'Eroare necunoscută';
        cron_log("  ✗ Eroare: $error_msg", 'WARNING');

        $stats['failed']++;

        // Incrementează retry count
        $retry_sql = "UPDATE procesare_imagini_queue
                      SET status = 'pending',
                          retry_count = retry_count + 1,
                          eroare_mesaj = ?
                      WHERE id = ?";
        $retry_stmt = mysqli_prepare($conn, $retry_sql);
        mysqli_stmt_bind_param($retry_stmt, "si", $error_msg, $queue_id);
        mysqli_stmt_execute($retry_stmt);
    }

    // Pauză între request-uri
    $delay = defined('CLAUDE_BATCH_DELAY') ? CLAUDE_BATCH_DELAY : 500;
    usleep($delay * 1000); // Convert ms to microseconds
}

$total_time = round(microtime(true) - $start_time, 2);

// Rezumat
cron_log("========================================");
cron_log("PROCESARE COMPLETĂ");
cron_log("========================================");
cron_log("Imagini procesate: " . $stats['processed']);
cron_log("  - Succes: " . $stats['success']);
cron_log("  - Eșuate: " . $stats['failed']);
cron_log("Obiecte identificate: " . $stats['total_objects']);
cron_log("Tokens utilizate: " . $stats['total_tokens']);
cron_log("Timp total: {$total_time}s");

// Salvează statistici zilnice
saveStats($conn, $stats);

// Curățare queue vechi (> 30 zile)
$cleanup_sql = "DELETE FROM procesare_imagini_queue
                WHERE status IN ('completed', 'failed')
                AND data_completare < DATE_SUB(NOW(), INTERVAL 30 DAY)";
$cleanup_result = mysqli_query($conn, $cleanup_sql);
$cleaned = mysqli_affected_rows($conn);
if ($cleaned > 0) {
    cron_log("Curățat $cleaned înregistrări vechi din queue");
}

// Rotație log
rotate_log();

mysqli_close($conn);
cron_log("CRON JOB FINALIZAT\n");

/**
 * Salvează obiectele identificate în baza de date a utilizatorului
 */
function saveIdentifiedObjects($conn, $user_id, $colectie_id, $queue_id, $objects) {
    if (empty($objects)) {
        return;
    }

    // Obține prefix-ul tabelei pentru colecție
    $prefix_sql = "SELECT prefix_tabele FROM colectii_utilizatori WHERE id_colectie = ?";
    $prefix_stmt = mysqli_prepare($conn, $prefix_sql);
    mysqli_stmt_bind_param($prefix_stmt, "i", $colectie_id);
    mysqli_stmt_execute($prefix_stmt);
    $prefix_result = mysqli_stmt_get_result($prefix_stmt);
    $prefix_row = mysqli_fetch_assoc($prefix_result);

    if (!$prefix_row) {
        cron_log("    Nu găsesc prefix pentru colecția $colectie_id", 'WARNING');
        return;
    }

    $prefix = $prefix_row['prefix_tabele'];

    // Conectare la baza de date a utilizatorului
    // (Presupunem că avem acces - în producție ar trebui să verificăm)

    // Pentru acum, doar logăm ce am găsit
    // Implementarea completă ar crea înregistrări în {prefix}obiecte

    foreach ($objects as $obj) {
        $denumire = $obj['denumire'] ?? 'Necunoscut';
        $categorie = $obj['categorie'] ?? 'Diverse';
        cron_log("    → $denumire [$categorie]");
    }
}

/**
 * Salvează statisticile zilnice
 */
function saveStats($conn, $stats) {
    $today = date('Y-m-d');

    $sql = "INSERT INTO claude_vision_stats
            (data_stat, imagini_procesate, obiecte_identificate, tokens_total, cost_total_usd)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
            imagini_procesate = imagini_procesate + VALUES(imagini_procesate),
            obiecte_identificate = obiecte_identificate + VALUES(obiecte_identificate),
            tokens_total = tokens_total + VALUES(tokens_total),
            cost_total_usd = cost_total_usd + VALUES(cost_total_usd)";

    $cost = ($stats['total_tokens'] / 1000000) * 1.50;

    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "siiid",
            $today, $stats['processed'], $stats['total_objects'], $stats['total_tokens'], $cost);
        mysqli_stmt_execute($stmt);
    }
}
