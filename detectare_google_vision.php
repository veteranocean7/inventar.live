<?php
session_start();
require_once 'config.php';
require_once 'vendor/autoload.php';

use Google\Cloud\Vision\V1\ImageAnnotatorClient;
use Google\Cloud\Vision\V1\Feature\Type;
use Google\Cloud\Vision\V1\Feature;

// Verificăm dacă cererea este POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Metodă HTTP nevalidă']);
    exit;
}

// Verificăm parametrii necesari
if (!isset($_POST['imagine_curenta']) || !isset($_POST['id_obiect'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Parametri lipsă']);
    exit;
}

$imagine_curenta = $_POST['imagine_curenta'];
$id_obiect = (int) $_POST['id_obiect'];

// Determinăm utilizatorul corect pentru căile imaginilor
if (file_exists('includes/auth_functions.php')) {
    require_once 'includes/auth_functions.php';
    
    $user = checkSession();
    if (!$user) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Neautorizat']);
        exit;
    }
    
    // Verificăm dacă lucrăm cu o colecție partajată
    $id_colectie = $_POST['id_colectie'] ?? $_SESSION['id_colectie_selectata'] ?? $_SESSION['id_colectie_curenta'] ?? null;
    
    if ($id_colectie) {
        $conn_central = getCentralDbConnection();
        // Verificăm dacă utilizatorul are acces la colecție
        $sql_check = "SELECT c.id_utilizator as proprietar_id 
                      FROM colectii_utilizatori c
                      LEFT JOIN partajari p ON c.id_colectie = p.id_colectie 
                           AND p.id_utilizator_partajat = ? AND p.activ = 1
                      WHERE c.id_colectie = ? 
                      AND (c.id_utilizator = ? OR p.id_partajare IS NOT NULL)";
        $stmt_check = mysqli_prepare($conn_central, $sql_check);
        mysqli_stmt_bind_param($stmt_check, "iii", $user['id_utilizator'], $id_colectie, $user['id_utilizator']);
        mysqli_stmt_execute($stmt_check);
        $result_check = mysqli_stmt_get_result($stmt_check);
        
        if ($row_check = mysqli_fetch_assoc($result_check)) {
            $user_id = $row_check['proprietar_id']; // Folosim ID-ul proprietarului pentru căile imaginilor
        } else {
            $user_id = $user['id_utilizator'];
        }
        mysqli_stmt_close($stmt_check);
        mysqli_close($conn_central);
    } else {
        $user_id = $_SESSION['colectie_proprietar_id'] ?? $user['id_utilizator'];
    }
} else {
    $user_id = getCurrentUserId();
}

// Construim calea imaginii - folosim structura multi-user cu ID-ul corect
$cale_imagine = 'imagini_obiecte/user_' . $user_id . '/' . basename($imagine_curenta);

// Verificăm dacă există imaginea
if (!file_exists($cale_imagine)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Imaginea nu există pentru utilizatorul curent: ' . basename($imagine_curenta)]);
    exit;
}

try {
    // Verificăm dacă fișierul cheie există
    if (!file_exists('google-vision-key.json')) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Fișierul cheie Google Vision lipsește']);
        exit;
    }

    // Setăm credențialele pentru Google Vision
    putenv('GOOGLE_APPLICATION_CREDENTIALS=' . realpath('google-vision-key.json'));

    // Inițializăm clientul Google Vision
    $imageAnnotator = new ImageAnnotatorClient([
        'credentials' => 'google-vision-key.json'
    ]);

    // Pregătim imaginea pentru analiză
    $imageContent = file_get_contents($cale_imagine);
    $image = ['content' => $imageContent];

    // Configurăm tipurile de detecție
    $features = [
        new Feature([
            'type' => Type::OBJECT_LOCALIZATION,
            'max_results' => 10
        ]),
        new Feature([
            'type' => Type::LABEL_DETECTION,
            'max_results' => 10
        ])
    ];

    // Facem apelul către API
    $response = $imageAnnotator->annotateImage($image, $features);

    // Procesăm obiectelele detectate
    $obiecte_detectate = [];
    $localizedObjectAnnotations = $response->getLocalizedObjectAnnotations();

    foreach ($localizedObjectAnnotations as $object) {
        // Obținem informațiile despre obiect
        $nume = $object->getName();
        $confidenta = $object->getScore();
        $vertices = $object->getBoundingPoly()->getVertices();

        // Calculăm dimensiunile crop-ului
        $minX = PHP_INT_MAX;
        $minY = PHP_INT_MAX;
        $maxX = 0;
        $maxY = 0;

        foreach ($vertices as $vertex) {
            $x = $vertex->getX();
            $y = $vertex->getY();
            $minX = min($minX, $x);
            $minY = min($minY, $y);
            $maxX = max($maxX, $x);
            $maxY = max($maxY, $y);
        }

        // Încărcăm imaginea originală
        $img_originala = imagecreatefromjpeg($cale_imagine);
        $img_width = imagesx($img_originala);
        $img_height = imagesy($img_originala);

        // Calculăm dimensiunile decupării
        $crop_width = $maxX - $minX;
        $crop_height = $maxY - $minY;

        // Creăm imaginea decupată
        $img_decupata = imagecreatetruecolor($crop_width, $crop_height);
        imagecopy($img_decupata, $img_originala, 0, 0, $minX, $minY, $crop_width, $crop_height);

        // Salvăm imaginea temporar în memorie
        ob_start();
        imagepng($img_decupata);
        $imagine_decupata_content = ob_get_contents();
        ob_end_clean();

        // Convertim în base64
        $imagine_decupata_base64 = 'data:image/png;base64,' . base64_encode($imagine_decupata_content);

        // Adăugăm obiectul în lista de obiecte detectate
        $obiecte_detectate[] = [
            'nume' => $nume,
            'confidenta' => $confidenta,
            'imagine_decupata' => $imagine_decupata_base64,
            'coordonate' => [
                'minX' => $minX,
                'minY' => $minY,
                'maxX' => $maxX,
                'maxY' => $maxY
            ]
        ];

        // Eliberăm memoria
        imagedestroy($img_decupata);
    }

    // Eliberăm memoria pentru imaginea originală
    imagedestroy($img_originala);

    // Închidem clientul
    $imageAnnotator->close();

    // Returnăm rezultatele
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'obiecte' => $obiecte_detectate
    ]);

} catch (Exception $e) {
    // Gestionăm erorile
    error_log('Eroare Google Vision: ' . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Eroare la procesarea imaginii: ' . $e->getMessage()
    ]);
}
?>