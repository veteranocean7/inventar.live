<?php
/**
 * Test Claude Vision Service
 *
 * Acest script testează conexiunea și funcționarea serviciului Claude Vision
 * Rulează: php test_claude_vision.php
 * Sau accesează în browser (doar pentru dezvoltare!)
 */

// Previne accesul public în producție
if (php_sapi_name() !== 'cli') {
    // În browser, verifică dacă e pe localhost
    $allowed_hosts = ['localhost', '127.0.0.1', '::1'];
    if (!in_array($_SERVER['SERVER_NAME'] ?? '', $allowed_hosts)) {
        die('Acces interzis. Rulează doar local sau din CLI.');
    }
}

require_once 'includes/claude_vision_service.php';

echo "========================================\n";
echo "  TEST CLAUDE VISION SERVICE\n";
echo "  inventar.live\n";
echo "========================================\n\n";

// 1. Test încărcare configurare
echo "1. Verificare configurare...\n";
if (file_exists('config_claude.php')) {
    include 'config_claude.php';
    if (defined('CLAUDE_API_KEY') && CLAUDE_API_KEY !== 'INTRODU_API_KEY_AICI') {
        echo "   ✓ config_claude.php există și are API key configurat\n";
        echo "   Model: " . (defined('CLAUDE_MODEL') ? CLAUDE_MODEL : 'default') . "\n";
    } else {
        echo "   ✗ API key nu este configurat!\n";
        echo "   → Editează config_claude.php și adaugă API key-ul\n";
        exit(1);
    }
} else {
    echo "   ✗ config_claude.php nu există!\n";
    exit(1);
}

echo "\n";

// 2. Test inițializare serviciu
echo "2. Inițializare serviciu...\n";
try {
    $service = new ClaudeVisionService(CLAUDE_API_KEY, true);
    echo "   ✓ Serviciu inițializat\n";
} catch (Exception $e) {
    echo "   ✗ Eroare: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n";

// 3. Test conexiune API
echo "3. Test conexiune API Claude...\n";
$test_result = $service->testConnection();
if ($test_result['success']) {
    echo "   ✓ Conexiune reușită!\n";
    echo "   Model: " . $test_result['model'] . "\n";
} else {
    echo "   ✗ Conexiune eșuată: " . $test_result['error'] . "\n";
    exit(1);
}

echo "\n";

// 4. Test estimare cost
echo "4. Estimare costuri...\n";
$cost_100 = $service->estimateCost(100);
$cost_1000 = $service->estimateCost(1000);
echo "   100 imagini:  ~$" . number_format($cost_100['estimated_cost_usd'], 4) . " USD\n";
echo "   1000 imagini: ~$" . number_format($cost_1000['estimated_cost_usd'], 4) . " USD\n";
echo "   Cost/imagine: ~$" . number_format($cost_100['cost_per_image_usd'], 6) . " USD\n";

echo "\n";

// 5. Test analiză imagine (dacă există o imagine de test)
echo "5. Test analiză imagine...\n";

// Caută o imagine de test
$test_images = [
    'test_image.jpg',
    'placeholder..png',
    'Inky.png'
];

$test_image = null;
foreach ($test_images as $img) {
    if (file_exists($img)) {
        $test_image = $img;
        break;
    }
}

// Sau caută în imagini_obiecte
if (!$test_image) {
    $dirs = glob('imagini_obiecte/user_*', GLOB_ONLYDIR);
    foreach ($dirs as $dir) {
        $images = glob($dir . '/*.{jpg,jpeg,png}', GLOB_BRACE);
        if (!empty($images)) {
            $test_image = $images[0];
            break;
        }
    }
}

if ($test_image) {
    echo "   Imagine test: $test_image\n";
    echo "   Analizez... (poate dura câteva secunde)\n";

    $result = $service->analyzeImage($test_image, [
        'context' => 'Test identificare obiecte',
        'location' => 'Test'
    ]);

    if ($result['success']) {
        echo "   ✓ Analiză reușită!\n";
        echo "   Timp procesare: " . ($result['processing_time'] ?? '?') . "s\n";

        if (isset($result['data']['obiecte'])) {
            $num_obj = count($result['data']['obiecte']);
            echo "   Obiecte găsite: $num_obj\n";

            foreach ($result['data']['obiecte'] as $i => $obj) {
                echo "   " . ($i + 1) . ". " . ($obj['denumire'] ?? 'N/A');
                echo " [" . ($obj['certitudine'] ?? '?') . "]";
                echo " - " . ($obj['categorie'] ?? 'Diverse') . "\n";
            }
        }

        if (isset($result['usage'])) {
            echo "   Tokens folosite: input=" . ($result['usage']['input_tokens'] ?? '?');
            echo ", output=" . ($result['usage']['output_tokens'] ?? '?') . "\n";
        }
    } else {
        echo "   ✗ Eroare analiză: " . $result['error'] . "\n";
    }
} else {
    echo "   ⚠ Nu am găsit imagine de test\n";
    echo "   → Adaugă o imagine în directorul curent sau în imagini_obiecte/\n";
}

echo "\n";
echo "========================================\n";
echo "  TEST COMPLET\n";
echo "========================================\n";

// Rezumat
echo "\nRezumat:\n";
echo "- Serviciul Claude Vision este " . ($test_result['success'] ? "FUNCȚIONAL" : "NEFUNCȚIONAL") . "\n";
echo "- Cost estimat: ~$" . number_format($cost_1000['cost_per_image_usd'] * 1000, 2) . "/1000 imagini\n";
echo "- Log-uri în: logs/claude_vision.log\n";

echo "\nPași următori:\n";
echo "1. Aplică SQL din sql/claude_vision_queue.sql în phpMyAdmin\n";
echo "2. Creează cron job pentru procesare nocturnă\n";
echo "3. Modifică UI pentru a arăta statusul procesării\n";
