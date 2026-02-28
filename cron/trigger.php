<?php
/**
 * Trigger pentru procesare automată imagini
 *
 * Poate fi apelat de:
 * 1. cPanel Cron Job
 * 2. Servicii externe (cron-job.org, easycron.com)
 * 3. wget/curl din alt server
 *
 * Securizat prin token secret
 */

// Token de securitate - schimbă acest token!
define('CRON_SECRET_TOKEN', 'inv3nt4r_cl4ud3_v1s10n_2026');

// Verifică token
$provided_token = $_GET['token'] ?? $_SERVER['HTTP_X_CRON_TOKEN'] ?? '';

if ($provided_token !== CRON_SECRET_TOKEN) {
    http_response_code(403);
    die(json_encode(['error' => 'Invalid token']));
}

// Setări pentru procesare lungă
set_time_limit(3600);
ignore_user_abort(true);

header('Content-Type: application/json');

$log = [];
$log[] = "Trigger started: " . date('Y-m-d H:i:s');

// Calea către scriptul principal
$script_path = dirname(__DIR__) . '/cron/procesare_automata_imagini.php';

if (!file_exists($script_path)) {
    $log[] = "ERROR: Script not found: $script_path";
    echo json_encode(['success' => false, 'log' => $log]);
    exit;
}

// Execută scriptul
ob_start();
$start_time = microtime(true);

try {
    // Include și execută scriptul
    // Folosim output buffering pentru a captura output-ul
    include $script_path;
    $output = ob_get_clean();

    $elapsed = round(microtime(true) - $start_time, 2);

    $log[] = "Script executed in {$elapsed}s";
    $log[] = "Output lines: " . substr_count($output, "\n");

    echo json_encode([
        'success' => true,
        'elapsed_seconds' => $elapsed,
        'log' => $log,
        'output_preview' => substr($output, -1000) // Ultimele 1000 caractere
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    ob_end_clean();
    $log[] = "ERROR: " . $e->getMessage();
    echo json_encode(['success' => false, 'log' => $log]);
}
