<?php
// Versiune 3.0 - Algoritm CORECTAT pentru detectare cărți
// Data actualizare: 2025-12-15
// FIX v3.0: CORECTARE ALGORITM ORIENTARE - numără linii/coloane distincte, nu benzi ocupate
// FIX v3.0: TOLERANȚE DINAMICE - bazate pe înălțimea medie a textului detectat
// FIX v3.0: GRUPARE ÎMBUNĂTĂȚITĂ - toleranțe mai mici pentru separare corectă a cărților
// ISTORIC: v2.9 avea problemă: detecta ORIZONTAL în loc de VERTICAL pentru cărți stivuite
// ISTORIC: v2.9 folosea toleranțe fixe de 50px care combinau cărți apropiate


// Anti-cache headers
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Error reporting standard
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

session_start();
include 'config.php';

require_once 'traducere_automata.php';

// Nu includem creator_context.php pentru a evita output HTML

// Verifică autentificarea pentru sistemul multi-tenant
if (file_exists('includes/auth_functions.php')) {
    require_once 'includes/auth_functions.php';
    $user = checkSession();
    if (!$user) {
        die(json_encode(['success' => false, 'error' => 'Sesiune expirată']));
    }
} else {
    // Verificare simplă de autentificare
    if (!isset($_SESSION['user_id'])) {
        die(json_encode(['success' => false, 'error' => 'Neautentificat']));
    }
}

header('Content-Type: application/json; charset=utf-8');
ob_start();

// Funcție log pentru debug cu timestamp și versiune
function logDebug($message) {
    $timestamp = date('Y-m-d H:i:s');
    error_log("[Vision v3.0 $timestamp] $message");
    
    // Salvăm și într-un fișier local accesibil
    $log_file = __DIR__ . '/vision_debug.log';
    $log_entry = "[$timestamp] $message\n";
    
    // Limităm dimensiunea fișierului la 5MB
    if (file_exists($log_file) && filesize($log_file) > 5 * 1024 * 1024) {
        // Păstrăm doar ultimele 100 linii
        $lines = file($log_file);
        $lines = array_slice($lines, -100);
        file_put_contents($log_file, implode('', $lines));
    }
    
    file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
}

// Funcție simplificată pentru procesarea cărților din text
function procesareTextCartiSimplu($text, $coordonate = null) {
    $carti = [];

    // Dacă avem coordonate, folosim algoritmul real cu coordonate carteziene
    if ($coordonate && !empty($coordonate)) {
        logDebug("AVEM COORDONATE REALE! " . count($coordonate) . " cuvinte cu poziții X,Y");

        // Pregătim elementele cu coordonate pentru algoritm
        $elemente_text = [];
        foreach ($coordonate as $elem) {
            if (isset($elem['vertices'][0])) {
                $elemente_text[] = [
                    'text' => $elem['text'],
                    'x' => $elem['vertices'][0]['x'] ?? 0,
                    'y' => $elem['vertices'][0]['y'] ?? 0
                ];
            }
        }

        // Acum algoritmul va funcționa cu coordonate reale!
        logDebug("Elemente cu coordonate pregătite: " . count($elemente_text));
    }

    // Curățăm și împărțim textul în linii
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $linii = array_map('trim', explode("\n", $text));

    // Edituri cunoscute pentru pattern matching generic
    $edituri = ['POLIROM', 'HUMANITAS', 'NEMIRA', 'RAO', 'PUBLICA', 'NICULESCU',
        'CURTEA VECHE', 'TREI', 'LITERA', 'CORINT', 'ART', 'VICTORIA',
        'BOOKS', 'OPEN', 'PENGUIN', 'VINTAGE', 'EDITURA', 'PRESS',
        'MINERVA', 'UNIVERS', 'ALBATROS', 'ARAMIS', 'PARALELA', 'TEORA',
        'ALL', 'CARTEA ROMÂNEASCĂ', 'DACIA', 'EMINESCU', 'MONDERO'];

    // Parcurgem textul și detectăm pattern-uri generice
    $i = 0;
    $linii_procesate = [];

    while ($i < count($linii)) {
        $linie = trim($linii[$i]);

        // Skip linii goale sau prea scurte
        if (mb_strlen($linie) < 2) {
            $i++;
            continue;
        }

        // Skip elemente care NU sunt cărți
        if (preg_match('/ISBN|©|Copyright|\d{4}-\d{4}|^●$|barcode|\d{13}|^\d+$/i', $linie)) {
            $i++;
            continue;
        }

        // Adăugăm linia pentru procesare ulterioară
        $linii_procesate[] = [
            'text' => $linie,
            'index' => $i,
            'este_majuscula' => preg_match('/^[A-ZĂÎÂȘȚ]/', $linie) ? true : false,
            'procent_majuscule' => calculeazaProcentMajuscule($linie),
            'lungime' => mb_strlen($linie)
        ];

        $i++;
    }

    // Analizăm liniile pentru a găsi pattern-uri de carte
    for ($i = 0; $i < count($linii_procesate); $i++) {
        $linie_curenta = $linii_procesate[$i];
        $linie_urmatoare = ($i + 1 < count($linii_procesate)) ? $linii_procesate[$i + 1] : null;

        // Pattern 1: TITLU urmat de AUTOR
        if (estePosibilTitlu($linie_curenta) && $linie_urmatoare && estePosibilAutor($linie_urmatoare)) {
            $titlu = $linie_curenta['text'];
            $autor = $linie_urmatoare['text'];

            // Căutăm editura în următoarele 2 linii
            $editura = '';
            for ($j = $i + 2; $j < min($i + 4, count($linii_procesate)); $j++) {
                $editura_gasita = gasesteEditura($linii_procesate[$j]['text'], $edituri);
                if ($editura_gasita) {
                    $editura = $editura_gasita;
                    break;
                }
            }

            if ($editura) {
                $carti[] = "$titlu - $autor ($editura)";
            } else {
                $carti[] = "$titlu - $autor";
            }

            // Skip următoarea linie (autor)
            $i++;
            continue;
        }

        // Pattern 2: AUTOR urmat de TITLU
        if (estePosibilAutor($linie_curenta) && $linie_urmatoare && estePosibilTitlu($linie_urmatoare)) {
            $autor = $linie_curenta['text'];
            $titlu = $linie_urmatoare['text'];
            $carti[] = "$titlu - $autor";
            $i++;
            continue;
        }

        // Pattern 3: Linie cu EDITURA - căutăm titlu și autor înapoi
        $editura_gasita = gasesteEditura($linie_curenta['text'], $edituri);
        if ($editura_gasita && $i > 0) {
            $titlu = '';
            $autor = '';

            // Căutăm înapoi (max 3 linii)
            for ($j = max(0, $i - 3); $j < $i; $j++) {
                if (!$titlu && estePosibilTitlu($linii_procesate[$j])) {
                    $titlu = $linii_procesate[$j]['text'];
                } else if (!$autor && estePosibilAutor($linii_procesate[$j])) {
                    $autor = $linii_procesate[$j]['text'];
                }
            }

            if ($titlu) {
                if ($autor) {
                    $carti[] = "$titlu - $autor ($editura_gasita)";
                } else {
                    $carti[] = "$titlu ($editura_gasita)";
                }
            }
        }
    }

    // Eliminăm duplicatele și filtrăm rezultate invalide
    $carti = array_unique($carti);
    $carti = array_filter($carti, function($carte) {
        return mb_strlen($carte) > 5 &&
            !preg_match('/^[\d\s\-]+$/', $carte) &&
            !preg_match('/^[^\w\s]+$/', $carte);
    });

    return array_values($carti);
}

// Funcții helper pentru detectare pattern-uri
function calculeazaProcentMajuscule($text) {
    $litere = preg_replace('/[^a-zA-ZăîâșțĂÎÂȘȚ]/u', '', $text);
    if (mb_strlen($litere) == 0) return 0;

    $majuscule = preg_replace('/[^A-ZĂÎÂȘȚ]/u', '', $text);
    return mb_strlen($majuscule) / mb_strlen($litere);
}

function estePosibilTitlu($linie_info) {
    if (!$linie_info) return false;

    return $linie_info['lungime'] >= 3 &&
        $linie_info['lungime'] <= 100 &&
        ($linie_info['este_majuscula'] || $linie_info['procent_majuscule'] > 0.4) &&
        !preg_match('/^[\d\s\-\.]+$/', $linie_info['text']);
}

function estePosibilAutor($linie_info) {
    if (!$linie_info) return false;

    $text = $linie_info['text'];

    // Pattern-uri pentru nume de autori
    $patterns = [
        '/^[A-ZĂÎÂȘȚ][a-zăîâșț]+\s+[A-ZĂÎÂȘȚ][a-zăîâșț]+/u', // Prenume Nume
        '/^[A-ZĂÎÂȘȚ][a-zăîâșț]+,\s*[A-ZĂÎÂȘȚ]/u', // Nume, Prenume
        '/^[A-ZĂÎÂȘȚ]\.\s*[A-ZĂÎÂȘȚ][a-zăîâșț]+/u', // Inițială. Nume
        '/^[A-ZĂÎÂȘȚ][a-zăîâșț]+\s+(de|von|van|della|del|la|le)\s+[A-ZĂÎÂȘȚ]/ui', // Nume compuse
        '/\b(jr\.|sr\.|dr\.|prof\.|ing\.)/i' // Titluri academice
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text)) {
            return true;
        }
    }

    // Verificăm și dacă are 2-3 cuvinte care par nume proprii
    $cuvinte = explode(' ', $text);
    if (count($cuvinte) >= 2 && count($cuvinte) <= 4) {
        $nume_proprii = 0;
        foreach ($cuvinte as $cuvant) {
            if (preg_match('/^[A-ZĂÎÂȘȚ][a-zăîâșț]+$/u', $cuvant)) {
                $nume_proprii++;
            }
        }
        if ($nume_proprii >= 2) {
            return true;
        }
    }

    return false;
}

function gasesteEditura($text, $edituri) {
    $text_upper = mb_strtoupper($text);
    foreach ($edituri as $editura) {
        if (stripos($text_upper, $editura) !== false) {
            return $editura;
        }
    }
    return '';
}

/**
 * Detectează cuvintele răsturnate analizând vertices din boundingPoly
 * Un cuvânt răsturnat (180°) va avea vertices inversate
 */
function detecteazaCuvantRasturnat($vertices) {
    if (!$vertices || count($vertices) < 4) {
        return false;
    }
    
    // În mod normal, vertices sunt ordonate astfel:
    // 0: stânga-sus, 1: dreapta-sus, 2: dreapta-jos, 3: stânga-jos
    // Pentru text răsturnat, ordinea e diferită
    
    // Calculăm vectorul de la stânga la dreapta (baza cuvântului)
    // Folosim vertices[0] și vertices[1] care ar trebui să fie pe linia de sus
    $dx = ($vertices[1]['x'] ?? 0) - ($vertices[0]['x'] ?? 0);
    $dy = ($vertices[1]['y'] ?? 0) - ($vertices[0]['y'] ?? 0);
    
    // Calculăm unghiul de rotație față de orizontală
    $unghi = atan2($dy, $dx) * 180 / M_PI;
    
    // Normalizăm unghiul între -180 și 180
    while ($unghi > 180) $unghi -= 360;
    while ($unghi < -180) $unghi += 360;
    
    // Text normal: unghi între -15° și 15° (ușor înclinat e OK)
    // Text răsturnat (180°): unghi între 165° și 180° sau între -180° și -165°
    // Text rotit 90°: unghi între 75° și 105°
    // Text rotit -90°: unghi între -105° și -75°
    
    // Detectează text răsturnat (180°)
    if (abs($unghi) > 165) {
        return true;
    }
    
    // Pentru cărți verticale (text pe cotorul lateral)
    // Detectează text rotit 90° (carte pe lateral, citire de jos în sus)
    if ($unghi > 75 && $unghi < 105) {
        return true;
    }
    
    // Detectează text rotit -90° (carte pe lateral, citire de sus în jos)
    if ($unghi < -75 && $unghi > -105) {
        return true;
    }
    
    return false;
}

/**
 * Evită suprapunerea cuvintelor prin ajustarea coordonatelor
 * Când două cuvinte au aceeași coordonată, mutăm al doilea și toate următoarele
 * @param array $elemente - array cu toate cuvintele
 * @param string $orientare - 'vertical' sau 'orizontal'
 */
function evitaSuprapunereCuvinte($elemente, $orientare = 'vertical') {
    if (empty($elemente)) {
        return $elemente;
    }
    
    $elemente_ajustate = [];
    $spatiu_minim = 5; // Spațiu minim între cuvinte
    
    if ($orientare === 'vertical') {
        // Pentru aranjament vertical, sortăm după Y apoi X
        usort($elemente, function($a, $b) {
            if (abs($a['y'] - $b['y']) < 5) { // Dacă sunt pe aceeași linie (±5px)
                return $a['x'] - $b['x']; // Sortăm după X
            }
            return $a['y'] - $b['y']; // Altfel după Y
        });
        
        // Grupăm elementele pe linii orizontale
        $linii = [];
        $linie_curenta = [];
        $y_linie_curenta = null;
        $toleranta_linie = 15; // Toleranță pentru a considera că sunt pe aceeași linie
        
        foreach ($elemente as $elem) {
            if ($y_linie_curenta === null || abs($elem['y'] - $y_linie_curenta) <= $toleranta_linie) {
                // Adaugă la linia curentă
                if ($y_linie_curenta === null) {
                    $y_linie_curenta = $elem['y'];
                }
                $linie_curenta[] = $elem;
            } else {
                // Salvează linia curentă și începe una nouă
                if (!empty($linie_curenta)) {
                    $linii[] = $linie_curenta;
                }
                $linie_curenta = [$elem];
                $y_linie_curenta = $elem['y'];
            }
        }
        // Adaugă ultima linie
        if (!empty($linie_curenta)) {
            $linii[] = $linie_curenta;
        }
        
        // Procesăm fiecare linie pentru a evita suprapunerile orizontale
        $y_curent = 0;
        $inaltime_linie_anterioara = 0;
        
        foreach ($linii as $idx_linie => $linie) {
            // Sortăm elementele din linie după X
            usort($linie, function($a, $b) {
                return $a['x'] - $b['x'];
            });
            
            // Determinăm Y-ul pentru această linie (evitând suprapunerea cu linia anterioară)
            $y_minim_linie = PHP_INT_MAX;
            $inaltime_maxima_linie = 0;
            foreach ($linie as $elem) {
                $y_minim_linie = min($y_minim_linie, $elem['y']);
                $inaltime_maxima_linie = max($inaltime_maxima_linie, $elem['inaltime'] ?? 20);
            }
            
            // Asigurăm că nu se suprapune cu linia anterioară
            if ($idx_linie > 0) {
                $y_minim_necesar = $y_curent + $inaltime_linie_anterioara + $spatiu_minim;
                if ($y_minim_linie < $y_minim_necesar) {
                    $y_minim_linie = $y_minim_necesar;
                }
            }
            
            $y_curent = $y_minim_linie;
            $inaltime_linie_anterioara = $inaltime_maxima_linie;
            
            // Procesăm elementele din linie pentru a evita suprapunerile orizontale
            foreach ($linie as $idx_elem => $elem) {
                // Setăm Y-ul uniform pentru toată linia
                $elem['y'] = $y_minim_linie;
                
                // Verificăm suprapunerea orizontală
                if ($idx_elem > 0) {
                    $elem_anterior = $elemente_ajustate[count($elemente_ajustate) - 1];
                    $x_final_anterior = $elem_anterior['x'] + ($elem_anterior['latime'] ?? 50);
                    
                    if ($elem['x'] < $x_final_anterior + $spatiu_minim) {
                        $elem['x'] = $x_final_anterior + $spatiu_minim;
                    }
                }
                
                $elemente_ajustate[] = $elem;
            }
        }
        
    } else { // orientare === 'orizontal'
        // Pentru aranjament orizontal, sortăm după X (stânga la dreapta)
        usort($elemente, function($a, $b) {
            return $a['x'] - $b['x'];
        });
        
        $ultim_element = null;
        foreach ($elemente as $elem) {
            // Verificăm suprapunere REALĂ cu elementul anterior
            if ($ultim_element !== null) {
                // Calculăm limitele orizontale ale cuvintelor
                $ultim_x_right = $ultim_element['x'] + ($ultim_element['latime'] ?? 50); // Default 50px lățime
                $curent_x_left = $elem['x'];
                
                // Dacă cuvântul curent începe înainte ca ultimul să se termine = SUPRAPUNERE
                if ($curent_x_left < $ultim_x_right + 5) { // 5px spațiu minim între cuvinte
                    // Aplicăm corecția
                    $elem['x'] = $ultim_x_right + 5;
                }
            }
            
            $ultim_element = $elem;
            $elemente_ajustate[] = $elem;
        }
    }
    
    return $elemente_ajustate;
}

/**
 * Corectează pozițiile cuvintelor răsturnate prin oglindire față de mediană
 * @param array $cuvinte_carte - array cu toate cuvintele de pe un cotor
 * @param string $orientare - 'vertical' sau 'orizontal'
 * @param array $limite - limitele globale ale textului
 */
function corecteazaCarteRasturnata($cuvinte_carte, $orientare, $limite) {
    if (empty($cuvinte_carte)) {
        return $cuvinte_carte;
    }
    
    // Numărăm câte cuvinte sunt răsturnate
    $cuvinte_rasturnate = 0;
    $total_cuvinte = count($cuvinte_carte);
    
    foreach ($cuvinte_carte as $cuvant) {
        if (isset($cuvant['vertices']) && detecteazaCuvantRasturnat($cuvant['vertices'])) {
            $cuvinte_rasturnate++;
        }
    }
    
    // Dacă mai mult de 50% din cuvinte sunt răsturnate, considerăm cartea răsturnată
    $procent_rasturnate = ($cuvinte_rasturnate / $total_cuvinte) * 100;
    
    if ($procent_rasturnate < 50) {
        return $cuvinte_carte; // Nu suficiente cuvinte răsturnate
    }
    
    logDebug("🔄 CARTE RĂSTURNATĂ DETECTATĂ! $cuvinte_rasturnate/$total_cuvinte cuvinte răsturnate ($procent_rasturnate%)");
    
    // Calculăm mediana bazată pe orientare
    if ($orientare === 'vertical') {
        // Pentru cărți stivuite vertical, oglindim pe axa X
        $x_values = array_map(function($c) { return $c['x']; }, $cuvinte_carte);
        $mediana_x = (min($x_values) + max($x_values)) / 2;
        
        // Oglindim fiecare cuvânt față de mediana X și ajustăm pentru lățimea cuvântului
        foreach ($cuvinte_carte as &$cuvant) {
            $x_original = $cuvant['x'];
            $latime = $cuvant['latime'] ?? 0;
            
            // Oglindim poziția X și ajustăm pentru lățimea cuvântului
            $distanta_la_mediana = $x_original - $mediana_x;
            $cuvant['x'] = $mediana_x - $distanta_la_mediana - $latime;
        }
        
        // Inversăm și ordinea cuvintelor pentru a corecta ordinea de citire
        $cuvinte_carte = array_reverse($cuvinte_carte);
        
    } else {
        // Pentru cărți alăturate orizontal, oglindim pe axa Y
        $y_values = array_map(function($c) { return $c['y']; }, $cuvinte_carte);
        $mediana_y = (min($y_values) + max($y_values)) / 2;
        
        // Oglindim fiecare cuvânt față de mediana Y și ajustăm pentru înălțimea cuvântului
        foreach ($cuvinte_carte as &$cuvant) {
            $y_original = $cuvant['y'];
            $inaltime = $cuvant['inaltime'] ?? 0;
            
            // Oglindim poziția Y și ajustăm pentru înălțimea cuvântului
            $distanta_la_mediana = $y_original - $mediana_y;
            $cuvant['y'] = $mediana_y - $distanta_la_mediana - $inaltime;
        }
        
        // Inversăm și ordinea cuvintelor pentru a corecta ordinea de citire
        $cuvinte_carte = array_reverse($cuvinte_carte);
    }
    
    return $cuvinte_carte;
}

// Funcție îmbunătățită pentru procesarea cărților folosind informații despre poziție și mărime (DEZACTIVATĂ)
function procesareTextCartiCuPozitii($textAnnotations, $traductor = null) {
    logDebug("\n=== INTRARE ÎN procesareTextCartiCuPozitii ===");
    logDebug("Număr elemente primite: " . count($textAnnotations));
    
    // Setăm flag global că procesăm cărți (pentru harta de text)
    $GLOBALS['este_procesare_carti'] = true;
    
    if (empty($textAnnotations) || count($textAnnotations) < 2) {
        logDebug("⚠ Nu sunt suficiente elemente pentru procesare");
        return [];
    }

    // PARAMETRU DE TEST: Limitează numărul de cărți procesate pentru debugging
    // DEZACTIVAT - procesează toate cărțile normal
    $MAX_CARTI_TEST = 0; // 0 = procesează toate cărțile (normal), >0 = limitează pentru test

    // TEST MODE COLȚURI: Returnează doar cele 4 colțuri
    $TEST_DOAR_COLTURI = false; // false = procesare normală, true = doar test colțuri

    if ($MAX_CARTI_TEST > 0) {
        logDebug("=====================================");
        logDebug("MODE TEST ACTIVAT: Procesez maximum $MAX_CARTI_TEST cărți");
        logDebug("Pentru a dezactiva, setați MAX_CARTI_TEST = 0");
        logDebug("=====================================");
    }

    if ($TEST_DOAR_COLTURI) {
        logDebug("=====================================");
        logDebug("MODE TEST COLȚURI: Returnez DOAR cele 4 colțuri");
        logDebug("=====================================");
    }

    // Primul element conține tot textul, îl skipăm
    $text_complet = $textAnnotations[0]['description'] ?? '';

    // Extragem toate elementele text cu poziții (skip primul element care e textul complet)
    // Facem global pentru a putea fi accesat pentru harta de text
    global $elemente_text;
    $elemente_text = [];
    for ($i = 1; $i < count($textAnnotations); $i++) {
        $annotation = $textAnnotations[$i];
        if (!isset($annotation['boundingPoly']['vertices'])) continue;

        $vertices = $annotation['boundingPoly']['vertices'];

        // Calculăm poziția și mărimea
        $y_top = min($vertices[0]['y'] ?? 0, $vertices[1]['y'] ?? 0);
        $y_bottom = max($vertices[2]['y'] ?? 0, $vertices[3]['y'] ?? 0);
        $x_left = min($vertices[0]['x'] ?? 0, $vertices[3]['x'] ?? 0);
        $x_right = max($vertices[1]['x'] ?? 0, $vertices[2]['x'] ?? 0);
        $inaltime = $y_bottom - $y_top;
        $latime = $x_right - $x_left;

        $elemente_text[] = [
            'text' => $annotation['description'],
            'y' => $y_top,
            'x' => $x_left,
            'inaltime' => $inaltime,
            'latime' => $latime,
            'y_bottom' => $y_bottom,
            'vertices' => $vertices // Păstrăm vertices pentru a detecta rotația
        ];
    }

    // FOLOSIM COORDONATELE ORIGINALE GOOGLE VISION
    // Sistemul Google Vision: Y=0 sus, Y crește spre jos (deja standard!)
    // NU mai inversăm coordonatele - le păstrăm exact cum vin de la API
    
    logDebug("=== SISTEM COORDONATE GOOGLE VISION ORIGINAL ===");
    logDebug("Folosim coordonatele exact cum vin de la API:");
    logDebug("  - Y=0 este în partea de SUS");
    logDebug("  - Y crește spre JOS");
    logDebug("  - X=0 este în STÂNGA");
    logDebug("  - X crește spre DREAPTA");
    logDebug("=======================================");
    
    // ====================================================================
    // PASUL 1: APLICĂM TOATE CORECȚIILE PE HARTA DE CUVINTE
    // ====================================================================
    logDebug("\n=== PASUL 1: APLICARE CORECȚII PE HARTA DE CUVINTE ===");
    
    // 1A. Detectăm orientarea preliminară pentru a ști cum să aplicăm corecțiile
    $orientare_preliminara = 'vertical'; // Default
    
    // 1B. Detectăm și corectăm cuvintele răsturnate
    $cuvinte_rasturnate = 0;
    logDebug("Verificăm " . count($elemente_text) . " cuvinte pentru răsturnare...");
    
    // Verificăm primele 5 cuvinte pentru debug
    $contor_debug = 0;
    foreach ($elemente_text as $elem) {
        if ($contor_debug < 5 && isset($elem['vertices'])) {
            $dx = ($elem['vertices'][1]['x'] ?? 0) - ($elem['vertices'][0]['x'] ?? 0);
            $dy = ($elem['vertices'][1]['y'] ?? 0) - ($elem['vertices'][0]['y'] ?? 0);
            $unghi = atan2($dy, $dx) * 180 / M_PI;
            logDebug("  Cuvânt '{$elem['text']}': unghi = " . round($unghi, 1) . "° (răsturnat = " . (abs($unghi) > 165 ? "DA" : "NU") . ")");
        }
        $contor_debug++;
        
        if (isset($elem['vertices']) && detecteazaCuvantRasturnat($elem['vertices'])) {
            $cuvinte_rasturnate++;
        }
    }
    
    $procent_rasturnate = count($elemente_text) > 0 ? ($cuvinte_rasturnate / count($elemente_text)) * 100 : 0;
    logDebug("Total cuvinte răsturnate: $cuvinte_rasturnate din " . count($elemente_text) . " ($procent_rasturnate%)");
    
    if ($procent_rasturnate > 50) {
        logDebug("🔄 DETECTATE CĂRȚI RĂSTURNATE! $cuvinte_rasturnate/" . count($elemente_text) . " cuvinte răsturnate ($procent_rasturnate%)");
        
        // Găsim limitele pentru corecție
        $x_min_temp = PHP_INT_MAX;
        $y_min_temp = PHP_INT_MAX;
        $x_max_temp = PHP_INT_MIN;
        $y_max_temp = PHP_INT_MIN;
        
        foreach ($elemente_text as $elem) {
            $x_min_temp = min($x_min_temp, $elem['x']);
            $y_min_temp = min($y_min_temp, $elem['y']);
            $x_max_temp = max($x_max_temp, $elem['x']);
            $y_max_temp = max($y_max_temp, $elem['y']);
        }
        
        $limite_temp = [
            'x_min' => $x_min_temp,
            'x_max' => $x_max_temp,
            'y_min' => $y_min_temp,
            'y_max' => $y_max_temp
        ];
        
        // Aplicăm corecția de răsturnare
        $elemente_text = corecteazaCarteRasturnata($elemente_text, $orientare_preliminara, $limite_temp);
        logDebug("✓ Corecție răsturnare aplicată");
    }
    
    // 1C. Evităm suprapunerile
    logDebug("\nVerific și corectez suprapunerile...");
    $elemente_text = evitaSuprapunereCuvinte($elemente_text, $orientare_preliminara);
    logDebug("✓ Corecție suprapuneri aplicată");
    
    // Găsim extremele textului DUPĂ CORECȚII pentru referință
    $x_min_global = PHP_INT_MAX;
    $y_min_global = PHP_INT_MAX;
    $x_max_global = PHP_INT_MIN;
    $y_max_global = PHP_INT_MIN;

    foreach ($elemente_text as $element) {
        $x_min_global = min($x_min_global, $element['x']);
        $y_min_global = min($y_min_global, $element['y']);
        $x_max_global = max($x_max_global, $element['x']);
        $y_max_global = max($y_max_global, $element['y']);
    }
    
    // ====================================================================
    // PASUL 2: IDENTIFICARE COLȚURI PE HARTA CORECTATĂ
    // ====================================================================
    logDebug("\n=== PASUL 2: IDENTIFICARE COLȚURI PE HARTA CORECTATĂ ===");
    logDebug("Toate corecțiile au fost aplicate, acum identificăm colțurile");
    
    // IDENTIFICARE COLȚURI PE HARTA CORECTATĂ
    // În sistemul Google Vision după corecții:
    // Y mic = SUS vizual (partea de sus a imaginii)
    // Y mare = JOS vizual (partea de jos a imaginii)
    
    // Facem colțurile globale pentru a le putea accesa în harta de text
    global $colt_stanga_sus, $colt_dreapta_sus, $colt_stanga_jos, $colt_dreapta_jos;
    $colt_stanga_sus = null;
    $toleranta_colt = 100; // toleranță pentru a găsi cuvinte apropiate de colț
    
    // Găsim toate cuvintele care sunt aproape de Y MINIM (sus în Google Vision)
    $cuvinte_sus = [];
    foreach ($elemente_text as $elem) {
        if ($elem['y'] <= $y_min_global + $toleranta_colt) { // Y mic = sus
            $cuvinte_sus[] = $elem;
        }
    }
    // Din acestea, alegem cel cu X minim
    if (!empty($cuvinte_sus)) {
        usort($cuvinte_sus, function($a, $b) { return $a['x'] - $b['x']; });
        $colt_stanga_sus = $cuvinte_sus[0];
    }
    
    // DREAPTA-SUS: Similar, dar căutăm X maxim
    $colt_dreapta_sus = null;
    if (!empty($cuvinte_sus)) {
        $colt_dreapta_sus = end($cuvinte_sus); // ultimul după sortarea pe X
    }
    
    // STÂNGA-JOS: Căutăm cuvântul cu Y MAXIM (jos) și X minim
    $colt_stanga_jos = null;
    $cuvinte_jos = [];
    foreach ($elemente_text as $elem) {
        if ($elem['y'] >= $y_max_global - $toleranta_colt) { // Y mare = jos
            $cuvinte_jos[] = $elem;
        }
    }
    // Din acestea, alegem cel cu X minim
    if (!empty($cuvinte_jos)) {
        usort($cuvinte_jos, function($a, $b) { return $a['x'] - $b['x']; });
        $colt_stanga_jos = $cuvinte_jos[0];
    }
    
    // DREAPTA-JOS: Similar, dar căutăm X maxim
    $colt_dreapta_jos = null;
    if (!empty($cuvinte_jos)) {
        $colt_dreapta_jos = end($cuvinte_jos); // ultimul după sortarea pe X
    }
    
    logDebug("=== COLȚURI IDENTIFICATE (Google Vision original) ===");
    logDebug("  Algoritm: Găsim cuvintele cele mai apropiate de colțurile absolute");
    logDebug("  Limite globale: X=[$x_min_global, $x_max_global], Y=[$y_min_global, $y_max_global]");
    logDebug("");
    
    // Afișăm și alte cuvinte candidate pentru debugging
    logDebug("  Top 3 candidați pentru fiecare colț:");
    
    // Calculăm distanțele pentru toți candidații
    $candidati_ss = [];
    $candidati_ds = [];
    $candidati_sj = [];
    $candidati_dj = [];
    
    foreach ($elemente_text as $elem) {
        // În Google Vision: Y mic = sus, Y mare = jos
        $candidati_ss[] = ['text' => $elem['text'], 'x' => $elem['x'], 'y' => $elem['y'], 
                          'dist' => ($elem['x'] - $x_min_global) + ($elem['y'] - $y_min_global)]; // stânga-sus
        $candidati_ds[] = ['text' => $elem['text'], 'x' => $elem['x'], 'y' => $elem['y'],
                          'dist' => ($x_max_global - $elem['x']) + ($elem['y'] - $y_min_global)]; // dreapta-sus
        $candidati_sj[] = ['text' => $elem['text'], 'x' => $elem['x'], 'y' => $elem['y'],
                          'dist' => ($elem['x'] - $x_min_global) + ($y_max_global - $elem['y'])]; // stânga-jos
        $candidati_dj[] = ['text' => $elem['text'], 'x' => $elem['x'], 'y' => $elem['y'],
                          'dist' => ($x_max_global - $elem['x']) + ($y_max_global - $elem['y'])]; // dreapta-jos
    }
    
    // Sortăm după distanță
    usort($candidati_ss, function($a, $b) { return $a['dist'] - $b['dist']; });
    usort($candidati_ds, function($a, $b) { return $a['dist'] - $b['dist']; });
    usort($candidati_sj, function($a, $b) { return $a['dist'] - $b['dist']; });
    usort($candidati_dj, function($a, $b) { return $a['dist'] - $b['dist']; });
    
    logDebug("  STÂNGA-SUS (X min, Y max):");
    for ($i = 0; $i < min(3, count($candidati_ss)); $i++) {
        $c = $candidati_ss[$i];
        logDebug("    " . ($i+1) . ". '{$c['text']}' la ({$c['x']}, {$c['y']}) - distanță: {$c['dist']}");
    }
    
    logDebug("  DREAPTA-SUS (X max, Y max):");
    for ($i = 0; $i < min(3, count($candidati_ds)); $i++) {
        $c = $candidati_ds[$i];
        logDebug("    " . ($i+1) . ". '{$c['text']}' la ({$c['x']}, {$c['y']}) - distanță: {$c['dist']}");
    }
    
    logDebug("  STÂNGA-JOS (X min, Y min):");
    for ($i = 0; $i < min(3, count($candidati_sj)); $i++) {
        $c = $candidati_sj[$i];
        logDebug("    " . ($i+1) . ". '{$c['text']}' la ({$c['x']}, {$c['y']}) - distanță: {$c['dist']}");
    }
    
    logDebug("  DREAPTA-JOS (X max, Y min):");
    for ($i = 0; $i < min(3, count($candidati_dj)); $i++) {
        $c = $candidati_dj[$i];
        logDebug("    " . ($i+1) . ". '{$c['text']}' la ({$c['x']}, {$c['y']}) - distanță: {$c['dist']}");
    }
    logDebug("  Colțuri identificate:");
    logDebug("    Stânga-sus: " . ($colt_stanga_sus ? "'{$colt_stanga_sus['text']}' la Y={$colt_stanga_sus['y']}, X={$colt_stanga_sus['x']}" : 'NULL'));
    logDebug("    Dreapta-sus: " . ($colt_dreapta_sus ? "'{$colt_dreapta_sus['text']}' la Y={$colt_dreapta_sus['y']}, X={$colt_dreapta_sus['x']}" : 'NULL'));
    logDebug("    Stânga-jos: " . ($colt_stanga_jos ? "'{$colt_stanga_jos['text']}' la Y={$colt_stanga_jos['y']}, X={$colt_stanga_jos['x']}" : 'NULL'));
    logDebug("    Dreapta-jos: " . ($colt_dreapta_jos ? "'{$colt_dreapta_jos['text']}' la Y={$colt_dreapta_jos['y']}, X={$colt_dreapta_jos['x']}" : 'NULL'));

    logDebug("");
    logDebug("=== REZUMAT COLȚURI IDENTIFICATE ===");
    logDebug("Coordonate extreme text:");
    logDebug("  X: de la $x_min_global la $x_max_global");
    logDebug("  Y: de la $y_min_global (sus vizual) la $y_max_global (jos vizual)");
    logDebug("");
    logDebug("COLȚURI FINALE:");
    logDebug("  1. STÂNGA-SUS: " . ($colt_stanga_sus ? "'{$colt_stanga_sus['text']}' la ({$colt_stanga_sus['x']}, {$colt_stanga_sus['y']})" : 'NEGĂSIT'));
    logDebug("  2. DREAPTA-SUS: " . ($colt_dreapta_sus ? "'{$colt_dreapta_sus['text']}' la ({$colt_dreapta_sus['x']}, {$colt_dreapta_sus['y']})" : 'NEGĂSIT'));
    logDebug("  3. STÂNGA-JOS: " . ($colt_stanga_jos ? "'{$colt_stanga_jos['text']}' la ({$colt_stanga_jos['x']}, {$colt_stanga_jos['y']})" : 'NEGĂSIT'));
    logDebug("  4. DREAPTA-JOS: " . ($colt_dreapta_jos ? "'{$colt_dreapta_jos['text']}' la ({$colt_dreapta_jos['x']}, {$colt_dreapta_jos['y']})" : 'NEGĂSIT'));
    logDebug("========================================");

    // PASUL 3: Detectăm orientarea folosind LOGICA CORECTATĂ v3.0
    // FIX: Numărăm LINII DISTINCTE de text, nu benzi ocupate
    $orientare = 'necunoscut';

    logDebug("=== DETERMINARE ARANJAMENT - LOGICĂ CORECTATĂ v3.0 ===");

    // Verificăm dacă avem toate colțurile necesare
    if (!$colt_stanga_sus || !$colt_dreapta_sus || !$colt_stanga_jos || !$colt_dreapta_jos) {
        logDebug("⚠ Nu toate colțurile au fost identificate. Folosim orientare implicită: vertical");
        $orientare = 'vertical';
    } else {
        // LOGICĂ CORECTATĂ: Numărăm LINII și COLOANE distincte de text
        logDebug("ANALIZĂ DISTRIBUȚIE TEXT - METODA LINII/COLOANE DISTINCTE:");

        // Calculăm înălțimea medie a textului pentru toleranță dinamică
        $inaltimi = array_column($elemente_text, 'inaltime');
        $inaltime_medie = count($inaltimi) > 0 ? array_sum($inaltimi) / count($inaltimi) : 20;
        $toleranta_linie = max(15, min(40, $inaltime_medie * 0.8));

        logDebug("  Înălțime medie text: " . round($inaltime_medie, 1) . "px");
        logDebug("  Toleranță grupare linie: " . round($toleranta_linie, 1) . "px");

        // GRUPARE PE LINII ORIZONTALE (cuvinte cu Y apropiate)
        // Sortăm după Y
        $elemente_sortate_y = $elemente_text;
        usort($elemente_sortate_y, function($a, $b) {
            return $a['y'] - $b['y'];
        });

        $linii_orizontale = [];
        $y_curent = null;
        foreach ($elemente_sortate_y as $elem) {
            $gasit_linie = false;
            foreach ($linii_orizontale as $idx => $linie) {
                if (abs($elem['y'] - $linie['y_mediu']) <= $toleranta_linie) {
                    // Adaugă la linia existentă
                    $linii_orizontale[$idx]['cuvinte']++;
                    $linii_orizontale[$idx]['y_mediu'] =
                        ($linii_orizontale[$idx]['y_mediu'] * ($linii_orizontale[$idx]['cuvinte'] - 1) + $elem['y'])
                        / $linii_orizontale[$idx]['cuvinte'];
                    $gasit_linie = true;
                    break;
                }
            }
            if (!$gasit_linie) {
                // Linie nouă
                $linii_orizontale[] = ['y_mediu' => $elem['y'], 'cuvinte' => 1];
            }
        }

        // GRUPARE PE COLOANE VERTICALE (cuvinte cu X apropiate)
        $toleranta_coloana = max(20, min(50, $inaltime_medie * 1.5));

        $elemente_sortate_x = $elemente_text;
        usort($elemente_sortate_x, function($a, $b) {
            return $a['x'] - $b['x'];
        });

        $coloane_verticale = [];
        foreach ($elemente_sortate_x as $elem) {
            $gasit_coloana = false;
            foreach ($coloane_verticale as $idx => $coloana) {
                if (abs($elem['x'] - $coloana['x_mediu']) <= $toleranta_coloana) {
                    // Adaugă la coloana existentă
                    $coloane_verticale[$idx]['cuvinte']++;
                    $coloane_verticale[$idx]['x_mediu'] =
                        ($coloane_verticale[$idx]['x_mediu'] * ($coloane_verticale[$idx]['cuvinte'] - 1) + $elem['x'])
                        / $coloane_verticale[$idx]['cuvinte'];
                    $gasit_coloana = true;
                    break;
                }
            }
            if (!$gasit_coloana) {
                // Coloană nouă
                $coloane_verticale[] = ['x_mediu' => $elem['x'], 'cuvinte' => 1];
            }
        }

        $nr_linii = count($linii_orizontale);
        $nr_coloane = count($coloane_verticale);

        logDebug("  Linii orizontale distincte: $nr_linii (fiecare linie = potențial un cotor de carte stivuită)");
        logDebug("  Coloane verticale distincte: $nr_coloane (fiecare coloană = potențial un cotor de carte alăturată)");

        // DECIZIE:
        // - Cărți VERTICALE (stivuite) = MULTE linii orizontale de text
        // - Cărți ORIZONTALE (alăturate) = MULTE coloane verticale de text

        logDebug("");
        logDebug("DECIZIE FINALĂ:");

        // Folosim factor 1.3 pentru a evita false positives
        if ($nr_linii > $nr_coloane * 1.3) {
            $orientare = 'vertical';
            logDebug("✓ ARANJAMENT VERTICAL detectat (cărți stivuite una peste alta)");
            logDebug("  Motivul: $nr_linii linii > $nr_coloane coloane × 1.3");
            logDebug("  → $nr_linii cotoare orizontale = aproximativ $nr_linii cărți stivuite");
        } else if ($nr_coloane > $nr_linii * 1.3) {
            $orientare = 'orizontal';
            logDebug("✓ ARANJAMENT ORIZONTAL detectat (cărți alăturate una lângă alta)");
            logDebug("  Motivul: $nr_coloane coloane > $nr_linii linii × 1.3");
            logDebug("  → $nr_coloane cotoare verticale = aproximativ $nr_coloane cărți alăturate");
        } else {
            // Caz ambiguu - folosim aspect ratio al zonei de text
            $width_text = $x_max_global - $x_min_global;
            $height_text = $y_max_global - $y_min_global;
            $aspect_ratio = $width_text / max(1, $height_text);

            logDebug("  Caz AMBIGUU ($nr_linii linii vs $nr_coloane coloane). Analizăm aspect ratio:");
            logDebug("  Zona text: {$width_text}px × {$height_text}px, aspect ratio: " . round($aspect_ratio, 2));

            if ($aspect_ratio > 1.5) {
                // Zona de text e mai lată decât înaltă = probabil cărți stivuite (cotoare orizontale)
                $orientare = 'vertical';
                logDebug("✓ ARANJAMENT VERTICAL (zona text lată = cotoare orizontale)");
            } else if ($aspect_ratio < 0.67) {
                // Zona de text e mai înaltă decât lată = probabil cărți alăturate (cotoare verticale)
                $orientare = 'orizontal';
                logDebug("✓ ARANJAMENT ORIZONTAL (zona text înaltă = cotoare verticale)");
            } else {
                // Foarte ambiguu - default la vertical (cel mai comun)
                $orientare = 'vertical';
                logDebug("✓ ARANJAMENT VERTICAL (default pentru caz foarte ambiguu)");
            }
        }
    }

    // Verificăm colțurile identificate
    logDebug("");
    logDebug("=== VERIFICARE COLȚURI ===");
    if ($colt_stanga_sus && $colt_dreapta_sus && $colt_stanga_jos && $colt_dreapta_jos) {
        logDebug("✓ Toate cele 4 colțuri au fost identificate");
    } else {
        logDebug("⚠ Nu s-au găsit toate colțurile!");
        if (!$colt_stanga_sus) logDebug("  - Lipsește colțul stânga-sus");
        if (!$colt_dreapta_sus) logDebug("  - Lipsește colțul dreapta-sus");
        if (!$colt_stanga_jos) logDebug("  - Lipsește colțul stânga-jos");
        if (!$colt_dreapta_jos) logDebug("  - Lipsește colțul dreapta-jos");
    }

    // TEST MODE: Returnează doar colțurile
    if ($TEST_DOAR_COLTURI) {
        $carti = [];
        
        // Adăugăm Y_min și Y_max pentru debug
        if ($colt_stanga_sus) {
            $carti[] = $colt_stanga_sus['text'] . " (STANGA-SUS)";
        }
        if ($colt_dreapta_sus) {
            $carti[] = $colt_dreapta_sus['text'] . " (DREAPTA-SUS)";
        }
        if ($colt_stanga_jos) {
            $carti[] = $colt_stanga_jos['text'] . " (STANGA-JOS)";
        }
        if ($colt_dreapta_jos) {
            $carti[] = $colt_dreapta_jos['text'] . " (DREAPTA-JOS)";
        }
        // DEBUG - verificăm valorile Y
        $carti[] = "[Y_max=" . $y_max_global . ", Y_min=" . $y_min_global . "]";

        
        logDebug("RETURNEZ DOAR COLȚURILE PENTRU TEST");
        return [
            'carti' => $carti,
            'info_detectie' => [
                'orientare' => $orientare,
                'numar_carti' => 4,
                'colturi' => [
                    'stanga_sus' => $colt_stanga_sus ? $colt_stanga_sus['text'] : null,
                    'dreapta_sus' => $colt_dreapta_sus ? $colt_dreapta_sus['text'] : null,
                    'stanga_jos' => $colt_stanga_jos ? $colt_stanga_jos['text'] : null,
                    'dreapta_jos' => $colt_dreapta_jos ? $colt_dreapta_jos['text'] : null
                ],
                'y_max' => $y_max_global,
                'y_min' => $y_min_global
            ]
        ];
    }

    // PASUL 4: NOUĂ LOGICĂ CONFORM DOCUMENTAȚIEI - Construcție explicită prima și ultima carte
    // FIX v3.0: Toleranțe DINAMICE bazate pe înălțimea medie a textului

    logDebug("=== CONSTRUCȚIE CĂRȚI CONFORM REGULII FUNDAMENTALE v3.0 ===");
    logDebug("Total cuvinte de procesat: " . count($elemente_text));

    // CALCULARE TOLERANȚE DINAMICE
    $inaltimi_text = array_filter(array_column($elemente_text, 'inaltime'), function($h) { return $h > 0; });
    $inaltime_medie_text = count($inaltimi_text) > 0 ? array_sum($inaltimi_text) / count($inaltimi_text) : 25;

    // Toleranțe calculate dinamic (adaptat la dimensiunea textului)
    $toleranta_carte = max(15, min(35, $inaltime_medie_text * 1.2)); // pentru prima/ultima carte
    $toleranta_y_distincte = max(10, min(25, $inaltime_medie_text * 0.6)); // pentru detectare Y-uri distincte
    $toleranta_grupare = max(12, min(30, $inaltime_medie_text * 0.8)); // pentru colectare cuvinte pe carte

    logDebug("TOLERANȚE DINAMICE (bazate pe înălțime medie text = " . round($inaltime_medie_text, 1) . "px):");
    logDebug("  - toleranta_carte (prima/ultima): " . round($toleranta_carte, 1) . "px");
    logDebug("  - toleranta_y_distincte: " . round($toleranta_y_distincte, 1) . "px");
    logDebug("  - toleranta_grupare: " . round($toleranta_grupare, 1) . "px");

    $cartiGrupate = [];
    $cuvinte_folosite = [];

    if ($orientare == 'vertical') {
        logDebug("ARANJAMENT VERTICAL - Cotoare orizontale (citire stânga->dreapta)");

        // PASUL 1: Construim PRIMA CARTE - OBLIGATORIU cu colțurile de sus
        logDebug("");
        logDebug("=== CONSTRUCȚIE PRIMA CARTE ===");

        $prima_carte_cuvinte = [];
        $y_prima_carte = ($colt_stanga_sus['y'] + $colt_dreapta_sus['y']) / 2;
        $toleranta_prima = $toleranta_carte; // DINAMIC în loc de 50px hardcodat
        
        // Colectăm TOATE cuvintele de pe linia primei cărți
        foreach ($elemente_text as $idx => $elem) {
            if (abs($elem['y'] - $y_prima_carte) <= $toleranta_prima) {
                $prima_carte_cuvinte[] = $elem;
                $cuvinte_folosite[$idx] = true;
            }
        }
        
        // Sortăm după X (stânga->dreapta)
        usort($prima_carte_cuvinte, function($a, $b) { 
            return $a['x'] - $b['x']; 
        });
        
        // VERIFICARE CRITICĂ: Prima carte TREBUIE să înceapă cu STÂNGA-SUS și să se termine cu DREAPTA-SUS
        if (!empty($prima_carte_cuvinte)) {
            $primul_cuvant = $prima_carte_cuvinte[0];
            $ultimul_cuvant = $prima_carte_cuvinte[count($prima_carte_cuvinte) - 1];
            
            // Forțăm colțurile să fie exact la început și sfârșit dacă nu sunt
            if ($primul_cuvant['text'] !== $colt_stanga_sus['text']) {
                logDebug("⚠ Ajustare: Forțez STÂNGA-SUS să fie primul cuvânt");
                array_unshift($prima_carte_cuvinte, $colt_stanga_sus);
            }
            if ($ultimul_cuvant['text'] !== $colt_dreapta_sus['text']) {
                logDebug("⚠ Ajustare: Forțez DREAPTA-SUS să fie ultimul cuvânt");
                array_push($prima_carte_cuvinte, $colt_dreapta_sus);
            }
            
            // Verificăm dacă prima carte are cuvinte răsturnate și le corectăm
            $limite = ['x_min' => $x_min_global, 'x_max' => $x_max_global, 'y_min' => $y_min_global, 'y_max' => $y_max_global];
            $prima_carte_cuvinte = corecteazaCarteRasturnata($prima_carte_cuvinte, $orientare, $limite);
            
            $text_prima = implode(' ', array_column($prima_carte_cuvinte, 'text'));
            logDebug("✓ PRIMA CARTE: $text_prima");
            logDebug("  - Începe cu: '{$prima_carte_cuvinte[0]['text']}' (TREBUIE să fie '{$colt_stanga_sus['text']}')");
            logDebug("  - Se termină cu: '{$prima_carte_cuvinte[count($prima_carte_cuvinte) - 1]['text']}' (TREBUIE să fie '{$colt_dreapta_sus['text']}')");
            
            $cartiGrupate[] = $prima_carte_cuvinte;
        }
        
        // PASUL 2: Construim ULTIMA CARTE - OBLIGATORIU cu colțurile de jos
        logDebug("");
        logDebug("=== CONSTRUCȚIE ULTIMA CARTE ===");
        
        $ultima_carte_cuvinte = [];
        $y_ultima_carte = ($colt_stanga_jos['y'] + $colt_dreapta_jos['y']) / 2;
        $toleranta_ultima = $toleranta_carte; // DINAMIC în loc de 50px hardcodat
        
        // Colectăm TOATE cuvintele de pe linia ultimei cărți
        foreach ($elemente_text as $idx => $elem) {
            if (!isset($cuvinte_folosite[$idx])) {
                if (abs($elem['y'] - $y_ultima_carte) <= $toleranta_ultima) {
                    $ultima_carte_cuvinte[] = $elem;
                    $cuvinte_folosite[$idx] = true;
                }
            }
        }
        
        // Sortăm după X (stânga->dreapta)
        usort($ultima_carte_cuvinte, function($a, $b) { 
            return $a['x'] - $b['x']; 
        });
        
        // VERIFICARE CRITICĂ: Ultima carte TREBUIE să înceapă cu STÂNGA-JOS și să se termine cu DREAPTA-JOS
        if (!empty($ultima_carte_cuvinte)) {
            $primul_cuvant = $ultima_carte_cuvinte[0];
            $ultimul_cuvant = $ultima_carte_cuvinte[count($ultima_carte_cuvinte) - 1];
            
            // Forțăm colțurile să fie exact la început și sfârșit dacă nu sunt
            if ($primul_cuvant['text'] !== $colt_stanga_jos['text']) {
                logDebug("⚠ Ajustare: Forțez STÂNGA-JOS să fie primul cuvânt");
                array_unshift($ultima_carte_cuvinte, $colt_stanga_jos);
            }
            if ($ultimul_cuvant['text'] !== $colt_dreapta_jos['text']) {
                logDebug("⚠ Ajustare: Forțez DREAPTA-JOS să fie ultimul cuvânt");
                array_push($ultima_carte_cuvinte, $colt_dreapta_jos);
            }
            
            // Verificăm dacă ultima carte are cuvinte răsturnate și le corectăm
            $limite = ['x_min' => $x_min_global, 'x_max' => $x_max_global, 'y_min' => $y_min_global, 'y_max' => $y_max_global];
            $ultima_carte_cuvinte = corecteazaCarteRasturnata($ultima_carte_cuvinte, $orientare, $limite);
            
            $text_ultima = implode(' ', array_column($ultima_carte_cuvinte, 'text'));
            logDebug("✓ ULTIMA CARTE: $text_ultima");
            logDebug("  - Începe cu: '{$ultima_carte_cuvinte[0]['text']}' (TREBUIE să fie '{$colt_stanga_jos['text']}')");
            logDebug("  - Se termină cu: '{$ultima_carte_cuvinte[count($ultima_carte_cuvinte) - 1]['text']}' (TREBUIE să fie '{$colt_dreapta_jos['text']}')");
        }
        
        // PASUL 3: Identificăm cărțile intermediare
        logDebug("");
        logDebug("🔴 TEST: SUNT ÎN FUNCȚIA procesareTextCartiCuPozitii - VERSIUNE NOUĂ!");
        logDebug("🔴 Dacă vezi acest mesaj, fișierul s-a actualizat corect!");
        logDebug("=== CĂRȚI INTERMEDIARE ===");
        
        // Determinăm limitele pentru cărțile intermediare
        $y_prima = ($colt_stanga_sus['y'] + $colt_dreapta_sus['y']) / 2;
        $y_ultima = ($colt_stanga_jos['y'] + $colt_dreapta_jos['y']) / 2;
        
        // Colectăm TOATE Y-urile unice din cuvintele nefolosite
        $y_uri_intermediare = [];
        foreach ($elemente_text as $idx => $elem) {
            if (!isset($cuvinte_folosite[$idx])) {
                // GOOGLE VISION: Y mic sus, Y mare jos
                // Verificăm dacă e între prima și ultima carte
                if ($elem['y'] > $y_prima + $toleranta_prima && $elem['y'] < $y_ultima - $toleranta_ultima) {
                    $gasit = false;
                    foreach ($y_uri_intermediare as $y_existent) {
                        if (abs($elem['y'] - $y_existent) <= $toleranta_y_distincte) { // DINAMIC în loc de 30px
                            $gasit = true;
                            break;
                        }
                    }
                    if (!$gasit) {
                        $y_uri_intermediare[] = $elem['y'];
                    }
                }
            }
        }
        
        // GOOGLE VISION: Sortăm Y-urile de sus în jos (Y mic -> Y mare)
        sort($y_uri_intermediare); // sort normal pentru Google Vision (Y mic = sus)
        
        logDebug("Am găsit " . count($y_uri_intermediare) . " linii intermediare distincte");
        
        // Pentru fiecare linie intermediară, colectăm cuvintele
        foreach ($y_uri_intermediare as $y_linie) {
            $carte_intermediara = [];
            $toleranta_linie = $toleranta_grupare; // DINAMIC în loc de 50px hardcodat
            
            foreach ($elemente_text as $idx => $elem) {
                if (!isset($cuvinte_folosite[$idx])) {
                    if (abs($elem['y'] - $y_linie) <= $toleranta_linie) {
                        $carte_intermediara[] = $elem;
                        $cuvinte_folosite[$idx] = true;
                    }
                }
            }
            
            if (!empty($carte_intermediara)) {
                // Sortăm după X (stânga->dreapta)
                usort($carte_intermediara, function($a, $b) { 
                    return $a['x'] - $b['x']; 
                });
                
                // Verificăm dacă cartea are cuvinte răsturnate și le corectăm
                $limite = ['x_min' => $x_min_global, 'x_max' => $x_max_global, 'y_min' => $y_min_global, 'y_max' => $y_max_global];
                $carte_intermediara = corecteazaCarteRasturnata($carte_intermediara, $orientare, $limite);
                
                $text_carte = implode(' ', array_column($carte_intermediara, 'text'));
                logDebug("  Carte intermediară (Y≈" . round($y_linie) . "): $text_carte");
                
                $cartiGrupate[] = $carte_intermediara;
            }
        }
        
        // Adăugăm ultima carte la final
        if (!empty($ultima_carte_cuvinte)) {
            $cartiGrupate[] = $ultima_carte_cuvinte;
        }
        
        // PASUL 4: Colectăm TOATE cuvintele rămase nefolosite
        logDebug("");
        logDebug("=== VERIFICARE CUVINTE RĂMASE ===");
        
        $cuvinte_ramase = [];
        foreach ($elemente_text as $idx => $elem) {
            if (!isset($cuvinte_folosite[$idx])) {
                $cuvinte_ramase[] = $elem;
            }
        }
        
        if (!empty($cuvinte_ramase)) {
            logDebug("⚠ Au rămas " . count($cuvinte_ramase) . " cuvinte negrupate!");
            
            // Încercăm să le atribuim la cea mai apropiată carte
            foreach ($cuvinte_ramase as $cuvant) {
                $cea_mai_apropiata_distanta = PHP_INT_MAX;
                $index_carte_apropiata = -1;
                
                // Găsim cea mai apropiată carte
                foreach ($cartiGrupate as $idx => $carte) {
                    if (!empty($carte)) {
                        $y_mediu_carte = array_sum(array_column($carte, 'y')) / count($carte);
                        $distanta = abs($cuvant['y'] - $y_mediu_carte);
                        
                        if ($distanta < $cea_mai_apropiata_distanta) {
                            $cea_mai_apropiata_distanta = $distanta;
                            $index_carte_apropiata = $idx;
                        }
                    }
                }
                
                // Adăugăm cuvântul la cea mai apropiată carte
                if ($index_carte_apropiata >= 0) {
                    $cartiGrupate[$index_carte_apropiata][] = $cuvant;
                    logDebug("  - Cuvântul '{$cuvant['text']}' adăugat la cartea " . ($index_carte_apropiata + 1));
                }
            }
            
            // Re-sortăm cărțile după adăugarea cuvintelor
            foreach ($cartiGrupate as &$carte) {
                usort($carte, function($a, $b) { 
                    return $a['x'] - $b['x']; 
                });
            }
        } else {
            logDebug("✓ Toate cuvintele au fost grupate!");
        }
        
    } else { // orientare == 'orizontal'
        logDebug("ARANJAMENT ORIZONTAL - Cotoare verticale (citire sus->jos)");
        
        // PASUL 1: Construim PRIMA CARTE - OBLIGATORIU cu colțurile din stânga
        logDebug("");
        logDebug("=== CONSTRUCȚIE PRIMA CARTE ===");
        
        $prima_carte_cuvinte = [];
        $x_prima_carte = ($colt_stanga_sus['x'] + $colt_stanga_jos['x']) / 2;
        $toleranta_prima = $toleranta_carte; // DINAMIC în loc de 50px hardcodat
        
        // Colectăm TOATE cuvintele de pe linia primei cărți
        foreach ($elemente_text as $idx => $elem) {
            if (abs($elem['x'] - $x_prima_carte) <= $toleranta_prima) {
                $prima_carte_cuvinte[] = $elem;
                $cuvinte_folosite[$idx] = true;
            }
        }
        
        // Sortăm după Y (sus->jos, Y mare primul)
        usort($prima_carte_cuvinte, function($a, $b) { 
            return $b['y'] - $a['y']; 
        });
        
        // VERIFICARE CRITICĂ: Prima carte TREBUIE să înceapă cu STÂNGA-SUS și să se termine cu STÂNGA-JOS
        if (!empty($prima_carte_cuvinte)) {
            $primul_cuvant = $prima_carte_cuvinte[0];
            $ultimul_cuvant = $prima_carte_cuvinte[count($prima_carte_cuvinte) - 1];
            
            // Forțăm colțurile să fie exact la început și sfârșit dacă nu sunt
            if ($primul_cuvant['text'] !== $colt_stanga_sus['text']) {
                logDebug("⚠ Ajustare: Forțez STÂNGA-SUS să fie primul cuvânt");
                array_unshift($prima_carte_cuvinte, $colt_stanga_sus);
            }
            if ($ultimul_cuvant['text'] !== $colt_stanga_jos['text']) {
                logDebug("⚠ Ajustare: Forțez STÂNGA-JOS să fie ultimul cuvânt");
                array_push($prima_carte_cuvinte, $colt_stanga_jos);
            }
            
            $text_prima = implode(' ', array_column($prima_carte_cuvinte, 'text'));
            logDebug("✓ PRIMA CARTE: $text_prima");
            logDebug("  - Începe cu: '{$prima_carte_cuvinte[0]['text']}' (TREBUIE să fie '{$colt_stanga_sus['text']}')");
            logDebug("  - Se termină cu: '{$prima_carte_cuvinte[count($prima_carte_cuvinte) - 1]['text']}' (TREBUIE să fie '{$colt_stanga_jos['text']}')");
            
            $cartiGrupate[] = $prima_carte_cuvinte;
        }
        
        // PASUL 2: Construim ULTIMA CARTE - OBLIGATORIU cu colțurile din dreapta
        logDebug("");
        logDebug("=== CONSTRUCȚIE ULTIMA CARTE ===");
        
        $ultima_carte_cuvinte = [];
        $x_ultima_carte = ($colt_dreapta_sus['x'] + $colt_dreapta_jos['x']) / 2;
        $toleranta_ultima = $toleranta_carte; // DINAMIC în loc de 50px hardcodat
        
        // Colectăm TOATE cuvintele de pe linia ultimei cărți
        foreach ($elemente_text as $idx => $elem) {
            if (!isset($cuvinte_folosite[$idx])) {
                if (abs($elem['x'] - $x_ultima_carte) <= $toleranta_ultima) {
                    $ultima_carte_cuvinte[] = $elem;
                    $cuvinte_folosite[$idx] = true;
                }
            }
        }
        
        // Sortăm după Y (sus->jos, Y mare primul)
        usort($ultima_carte_cuvinte, function($a, $b) { 
            return $b['y'] - $a['y']; 
        });
        
        // VERIFICARE CRITICĂ: Ultima carte TREBUIE să înceapă cu DREAPTA-SUS și să se termine cu DREAPTA-JOS
        if (!empty($ultima_carte_cuvinte)) {
            $primul_cuvant = $ultima_carte_cuvinte[0];
            $ultimul_cuvant = $ultima_carte_cuvinte[count($ultima_carte_cuvinte) - 1];
            
            // Forțăm colțurile să fie exact la început și sfârșit dacă nu sunt
            if ($primul_cuvant['text'] !== $colt_dreapta_sus['text']) {
                logDebug("⚠ Ajustare: Forțez DREAPTA-SUS să fie primul cuvânt");
                array_unshift($ultima_carte_cuvinte, $colt_dreapta_sus);
            }
            if ($ultimul_cuvant['text'] !== $colt_dreapta_jos['text']) {
                logDebug("⚠ Ajustare: Forțez DREAPTA-JOS să fie ultimul cuvânt");
                array_push($ultima_carte_cuvinte, $colt_dreapta_jos);
            }
            
            $text_ultima = implode(' ', array_column($ultima_carte_cuvinte, 'text'));
            logDebug("✓ ULTIMA CARTE: $text_ultima");
            logDebug("  - Începe cu: '{$ultima_carte_cuvinte[0]['text']}' (TREBUIE să fie '{$colt_dreapta_sus['text']}')");
            logDebug("  - Se termină cu: '{$ultima_carte_cuvinte[count($ultima_carte_cuvinte) - 1]['text']}' (TREBUIE să fie '{$colt_dreapta_jos['text']}')");
        }
        
        // PASUL 3: Identificăm cărțile intermediare
        logDebug("");
        logDebug("🔴 TEST: SUNT ÎN FUNCȚIA procesareTextCartiCuPozitii - VERSIUNE NOUĂ!");
        logDebug("🔴 Dacă vezi acest mesaj, fișierul s-a actualizat corect!");
        logDebug("=== CĂRȚI INTERMEDIARE ===");
        
        // Determinăm limitele pentru cărțile intermediare
        $x_prima = ($colt_stanga_sus['x'] + $colt_stanga_jos['x']) / 2;
        $x_ultima = ($colt_dreapta_sus['x'] + $colt_dreapta_jos['x']) / 2;
        
        // Colectăm toate X-urile unice din cuvintele nefolosite
        $x_uri_intermediare = [];
        foreach ($elemente_text as $idx => $elem) {
            if (!isset($cuvinte_folosite[$idx])) {
                // Verificăm dacă e între prima și ultima carte
                if ($elem['x'] > $x_prima + $toleranta_prima && $elem['x'] < $x_ultima - $toleranta_ultima) {
                    $gasit = false;
                    foreach ($x_uri_intermediare as $x_existent) {
                        if (abs($elem['x'] - $x_existent) <= $toleranta_y_distincte) { // DINAMIC în loc de 30px
                            $gasit = true;
                            break;
                        }
                    }
                    if (!$gasit) {
                        $x_uri_intermediare[] = $elem['x'];
                    }
                }
            }
        }
        
        // Sortăm X-urile de la stânga la dreapta
        sort($x_uri_intermediare);
        
        logDebug("Am găsit " . count($x_uri_intermediare) . " linii intermediare distincte");
        
        // Pentru fiecare linie intermediară, colectăm cuvintele
        foreach ($x_uri_intermediare as $x_linie) {
            $carte_intermediara = [];
            $toleranta_linie = $toleranta_grupare; // DINAMIC în loc de 50px hardcodat
            
            foreach ($elemente_text as $idx => $elem) {
                if (!isset($cuvinte_folosite[$idx])) {
                    if (abs($elem['x'] - $x_linie) <= $toleranta_linie) {
                        $carte_intermediara[] = $elem;
                        $cuvinte_folosite[$idx] = true;
                    }
                }
            }
            
            if (!empty($carte_intermediara)) {
                // Sortăm după Y (sus->jos, Y mare primul)
                usort($carte_intermediara, function($a, $b) { 
                    return $b['y'] - $a['y']; 
                });
                
                $text_carte = implode(' ', array_column($carte_intermediara, 'text'));
                logDebug("  Carte intermediară (X≈" . round($x_linie) . "): $text_carte");
                
                $cartiGrupate[] = $carte_intermediara;
            }
        }
        
        // Adăugăm ultima carte la final
        if (!empty($ultima_carte_cuvinte)) {
            $cartiGrupate[] = $ultima_carte_cuvinte;
        }
        
        // PASUL 4: Colectăm TOATE cuvintele rămase nefolosite
        logDebug("");
        logDebug("=== VERIFICARE CUVINTE RĂMASE ===");
        
        $cuvinte_ramase = [];
        foreach ($elemente_text as $idx => $elem) {
            if (!isset($cuvinte_folosite[$idx])) {
                $cuvinte_ramase[] = $elem;
            }
        }
        
        if (!empty($cuvinte_ramase)) {
            logDebug("⚠ Au rămas " . count($cuvinte_ramase) . " cuvinte negrupate!");
            
            // Încercăm să le atribuim la cea mai apropiată carte
            foreach ($cuvinte_ramase as $cuvant) {
                $cea_mai_apropiata_distanta = PHP_INT_MAX;
                $index_carte_apropiata = -1;
                
                // Găsim cea mai apropiată carte
                foreach ($cartiGrupate as $idx => $carte) {
                    if (!empty($carte)) {
                        $x_mediu_carte = array_sum(array_column($carte, 'x')) / count($carte);
                        $distanta = abs($cuvant['x'] - $x_mediu_carte);
                        
                        if ($distanta < $cea_mai_apropiata_distanta) {
                            $cea_mai_apropiata_distanta = $distanta;
                            $index_carte_apropiata = $idx;
                        }
                    }
                }
                
                // Adăugăm cuvântul la cea mai apropiată carte
                if ($index_carte_apropiata >= 0) {
                    $cartiGrupate[$index_carte_apropiata][] = $cuvant;
                    logDebug("  - Cuvântul '{$cuvant['text']}' adăugat la cartea " . ($index_carte_apropiata + 1));
                }
            }
            
            // Re-sortăm cărțile după adăugarea cuvintelor
            foreach ($cartiGrupate as &$carte) {
                usort($carte, function($a, $b) { 
                    return $b['y'] - $a['y']; // Pentru orizontal, sortăm după Y
                });
            }
        } else {
            logDebug("✓ Toate cuvintele au fost grupate!");
        }
    }
    
    // VALIDARE FINALĂ
    $total_cuvinte = count($elemente_text);
    $cuvinte_grupate = count($cuvinte_folosite);
    $procent_acoperire = ($total_cuvinte > 0) ? ($cuvinte_grupate / $total_cuvinte) * 100 : 0;
    
    logDebug("");
    logDebug("=== REZULTAT FINAL ===");
    logDebug("Cărți detectate: " . count($cartiGrupate));
    logDebug("Cuvinte grupate: $cuvinte_grupate din $total_cuvinte (" . round($procent_acoperire, 2) . "%)");
    
    if ($procent_acoperire < 90) {
        logDebug("⚠ ATENȚIE: Acoperire sub 90% - posibil să fi pierdut unele cuvinte");
    } else {
        logDebug("✓ Acoperire excelentă!");
    }
    
    // VECHIUL COD DE GRUPARE - ÎNLOCUIT CU NOUA LOGICĂ DE PARCURGERE DUBLĂ
    /* 
    // ELIMINAT: Vechea metodă de grupare care nu garanta acoperire 100%
    // Acum folosim parcurgerea dublă cu validare completă
        
        foreach ($elemente_text as $element) {
            $gasit = false;
            foreach ($y_distincte as &$y_grup) {
                // Verificăm dacă elementul aparține unui grup existent
                if (abs($element['y'] - $y_grup['centru']) <= $toleranta_grupare) {
                    $y_grup['elemente'][] = $element;
                    // Recalculăm centrul grupului
                    $suma_y = 0;
                    foreach ($y_grup['elemente'] as $el) {
                        $suma_y += $el['y'];
                    }
                    $y_grup['centru'] = $suma_y / count($y_grup['elemente']);
                    $gasit = true;
                    break;
                }
            }
            if (!$gasit) {
                // Creăm un grup nou
                $y_distincte[] = [
                    'centru' => $element['y'],
                    'elemente' => [$element]
                ];
            }
        }
        
        // Sortăm grupurile după Y (de sus în jos în Google Vision)
        usort($y_distincte, function($a, $b) {
            return $a['centru'] - $b['centru']; // Google Vision: Y mic = sus, Y mare = jos
        });
        
        logDebug("=== GRUPARE CU LINII IMAGINARE FIXE ===");
        logDebug("Am identificat " . count($y_distincte) . " cărți/cotoare distincte");
        
        // Pentru fiecare grup, sortăm cuvintele pe X
        $grupuri_y = [];
        
        foreach ($y_distincte as $idx => $grup) {
            $elemente_grup = $grup['elemente'];
            if (!empty($elemente_grup)) {
                // Sortăm cuvintele pe X (de la stânga la dreapta pe cotor)
                usort($elemente_grup, function($a, $b) {
                    return $a['x'] - $b['x'];
                });
                
                // Calculăm Y mediu al grupului pentru compatibilitate
                $y_mediu = array_sum(array_column($elemente_grup, 'y')) / count($elemente_grup);
                
                $grupuri_y[] = [
                    'index' => $idx,
                    'y_mediu' => $y_mediu,
                    'elemente' => $elemente_grup
                ];
            }
        }
        
        logDebug("Am format " . count($grupuri_y) . " cărți din benzile ocupate");

        logDebug("=== GRUPARE CĂRȚI VERTICALE ===");
        logDebug("Am găsit " . count($grupuri_y) . " cotoare (grupuri de Y)");

        // Afișăm detalii despre fiecare grup
        foreach ($grupuri_y as $idx => $grup) {
            $texte = array_column($grup['elemente'], 'text');
            logDebug("Grup " . ($idx + 1) . " (Y mediu=" . round($grup['y_mediu']) . "): " . implode(' ', $texte));
        }

        // CONFORM DOCUMENTAȚIEI: Prima carte TREBUIE să conțină colțurile stânga-sus și dreapta-sus
        if ($colt_stanga_sus && $colt_dreapta_sus) {
            logDebug("\nIdentific prima carte folosind colțurile de sus:");
            logDebug("  Stânga-sus: '{$colt_stanga_sus['text']}' la Y={$colt_stanga_sus['y']}");
            logDebug("  Dreapta-sus: '{$colt_dreapta_sus['text']}' la Y={$colt_dreapta_sus['y']}");
            
            // Găsim grupul care conține aceste colțuri
            $index_prima_carte = -1;
            foreach ($grupuri_y as $idx => $grup) {
                $contine_stanga_sus = false;
                $contine_dreapta_sus = false;
                
                foreach ($grup['elemente'] as $elem) {
                    if ($elem['text'] == $colt_stanga_sus['text']) {
                        $contine_stanga_sus = true;
                    }
                    if ($elem['text'] == $colt_dreapta_sus['text']) {
                        $contine_dreapta_sus = true;
                    }
                }
                
                if ($contine_stanga_sus || $contine_dreapta_sus) {
                    $index_prima_carte = $idx;
                    logDebug("✓ Grupul " . ($idx + 1) . " este prima carte (conține colțurile de sus)");
                    
                    // Reorganizăm array-ul să înceapă cu acest grup
                    if ($idx > 0) {
                        $primul_grup = $grupuri_y[$idx];
                        unset($grupuri_y[$idx]);
                        array_unshift($grupuri_y, $primul_grup);
                        $grupuri_y = array_values($grupuri_y);
                        logDebug("Am reorganizat grupurile - prima carte este acum la început");
                    }
                    break;
                }
            }
        }

        // Procesăm fiecare grup ca o carte completă
        foreach ($grupuri_y as $idx => $grup) {
            $elemente_carte = $grup['elemente'];

            if (!empty($elemente_carte)) {
                // Sortăm elementele pe X (de la stânga la dreapta pe cotor)
                usort($elemente_carte, function($a, $b) {
                    return $a['x'] - $b['x'];
                });

                $cartiGrupate[] = $elemente_carte;
                $text_carte = implode(' ', array_column($elemente_carte, 'text'));
                logDebug("Carte " . ($idx + 1) . " (Y=" . round($grup['y_mediu']) . ", " . count($elemente_carte) . " cuvinte): $text_carte");

                // Verificăm dacă prima carte conține colțurile conform documentației
                if ($idx == 0 && $colt_stanga_sus) {
                    if (strpos($text_carte, $colt_stanga_sus['text']) !== false) {
                        logDebug("✓ Prima carte conține colțul stânga-sus: '{$colt_stanga_sus['text']}'");
                    } else {
                        logDebug("⚠ ATENȚIE: Prima carte NU conține colțul stânga-sus '{$colt_stanga_sus['text']}'!");
                    }
                }
            }
        }

    } else { // orientare == 'orizontal'
        // CĂRȚI ORIZONTALE (alături): Cotoarele sunt verticale, citim de sus în jos
        // Folosim Xcotor_curent pentru a grupa cuvintele pe fiecare cotor vertical

        // Găsim toate X-urile distincte (pozițiile cotorurilor verticale)
        $toleranta = 15; // toleranță pentru a grupa X-uri apropiate
        $x_uri_distincte = [];

        foreach ($elemente_text as $element) {
            $gasit = false;
            foreach ($x_uri_distincte as $x_existent) {
                if (abs($element['x'] - $x_existent) <= $toleranta) {
                    $gasit = true;
                    break;
                }
            }
            if (!$gasit) {
                $x_uri_distincte[] = $element['x'];
            }
        }

        // Sortăm X-urile de la stânga la dreapta
        sort($x_uri_distincte);
        logDebug("=== GRUPARE CĂRȚI ORIZONTALE (cotoare verticale) ===");
        logDebug("X-uri distincte găsite: " . count($x_uri_distincte));

        // NOUĂ LOGICĂ: Grupăm X-uri consecutive pentru a forma cotoare verticale
        // Un cotor de carte poate avea 2-3 coloane de text
        $carti_x_grupate = [];
        $i = 0;

        while ($i < count($x_uri_distincte)) {
            $x_start = $x_uri_distincte[$i];
            $x_carte = [$x_start];

            // Adăugăm următoarele 1-2 X-uri dacă sunt consecutive (aproape)
            $j = 1;
            while ($j <= 2 && ($i + $j) < count($x_uri_distincte)) {
                $x_curent = $x_uri_distincte[$i + $j - 1];
                $x_urmator = $x_uri_distincte[$i + $j];
                $diferenta = $x_urmator - $x_curent;

                // Dacă diferența e mică (sub 50 unități), e probabil același cotor
                if ($diferenta < 50) {
                    $x_carte[] = $x_urmator;
                    $j++;
                } else {
                    break; // Diferență prea mare, e alt cotor
                }
            }

            $carti_x_grupate[] = $x_carte;
            $i += count($x_carte); // Sărim peste X-urile deja procesate
        }

        logDebug("Am grupat " . count($x_uri_distincte) . " X-uri în " . count($carti_x_grupate) . " cărți");

        // APLICĂM LIMITA DE TEST pentru cărți orizontale
        if ($MAX_CARTI_TEST > 0) {
            $carti_originale = count($carti_x_grupate);
            $carti_x_grupate = array_slice($carti_x_grupate, 0, $MAX_CARTI_TEST);
            logDebug("*** LIMITĂ TEST: Procesez doar primele $MAX_CARTI_TEST cărți din $carti_originale detectate (ORIZONTALE) ***");
        }

        // Acum procesăm fiecare grup de X-uri ca o carte
        foreach ($carti_x_grupate as $idx => $x_grupa) {
            $elemente_carte = [];

            // Colectăm toate elementele care aparțin acestui grup de X-uri
            foreach ($elemente_text as $element) {
                foreach ($x_grupa as $x_ref) {
                    if (abs($element['x'] - $x_ref) <= 15) { // Toleranță mică pentru matching exact
                        $elemente_carte[] = $element;
                        break; // Am găsit match, nu mai verificăm alte X-uri
                    }
                }
            }

            if (!empty($elemente_carte)) {
                // Sortăm: mai întâi după X (stânga-dreapta), apoi după Y (sus-jos)
                usort($elemente_carte, function($a, $b) {
                    if (abs($a['x'] - $b['x']) < 10) { // Aceeași coloană
                        return $a['y'] - $b['y']; // Sortăm după Y
                    }
                    return $a['x'] - $b['x']; // Sortăm după X
                });

                $cartiGrupate[] = $elemente_carte;
                $text_carte = implode(' ', array_column($elemente_carte, 'text'));
                logDebug("Carte " . ($idx + 1) . " (X=" . implode(',', $x_grupa) . ", " . count($elemente_carte) . " cuvinte): $text_carte");

                // LOGGING DETALIAT pentru modul test
                if ($MAX_CARTI_TEST > 0 && $idx == 0) {
                    logDebug("===== DETALII PRIMA CARTE (ORIZONTALĂ) =====");
                    logDebug("X-uri grupate: " . implode(', ', $x_grupa));
                    logDebug("Număr cuvinte: " . count($elemente_carte));
                    logDebug("Text complet: $text_carte");
                    logDebug("===========================================");
                }
            }
        }
    }
    */ // SFÂRȘIT COD VECHI COMENTAT

    logDebug("Total cărți detectate: " . count($cartiGrupate));

    // POST-PROCESARE: DEZACTIVATĂ în v3.0 - combinarea cu prag 100px strica rezultatele
    // Vechea logică combina cărți cu Y diferență < 100px, dar aceasta amesteca cuvinte din cărți diferite
    logDebug("");
    logDebug("=== POST-PROCESARE: COMBINARE BENZI - DEZACTIVATĂ v3.0 ===");
    logDebug("Motivul dezactivării: Pragul de 100px era prea mare și combina cărți distincte");
    logDebug("Cărți păstrate așa cum au fost detectate: " . count($cartiGrupate));
    // NU mai combinăm - păstrăm cărțile exact așa cum au fost grupate
    
    // Salvăm informațiile pentru debug
    $info_detectie_carti = [
        'orientare' => $orientare,
        'numar_carti' => count($cartiGrupate),
        'colturi' => [
            'stanga_sus' => $colt_stanga_sus ? "({$colt_stanga_sus['x']}, {$colt_stanga_sus['y']})" : 'negăsit',
            'dreapta_sus' => $colt_dreapta_sus ? "({$colt_dreapta_sus['x']}, {$colt_dreapta_sus['y']})" : 'negăsit',
            'stanga_jos' => $colt_stanga_jos ? "({$colt_stanga_jos['x']}, {$colt_stanga_jos['y']})" : 'negăsit',
            'dreapta_jos' => $colt_dreapta_jos ? "({$colt_dreapta_jos['x']}, {$colt_dreapta_jos['y']})" : 'negăsit'
        ]
    ];

    // PASUL 4: MODIFICARE TEMPORARĂ DEZACTIVATĂ - Revenim la procesarea normală
    // Codul pentru returnarea celor 4 colțuri este comentat temporar

    /* TEST COLȚURI - DEZACTIVAT
    $carti = [];

    // NU mai procesăm cărțile detectate normal
    // În schimb, returnăm cele 4 cuvinte din colțuri ca "cărți" pentru testare

    logDebug("*** MOD TEST COLȚURI: Returnez DOAR cele 4 cuvinte din colțuri ***");

    // Adăugăm fiecare cuvânt din colț ca o "carte" separată
    if ($colt_stanga_sus) {
        $carti[] = [
            'titlu' => $colt_stanga_sus['text'],
            'pozitie' => "STÂNGA-SUS ({$colt_stanga_sus['x']}, {$colt_stanga_sus['y']})"
        ];
    }

    if ($colt_dreapta_sus) {
        $carti[] = [
            'titlu' => $colt_dreapta_sus['text'],
            'pozitie' => "DREAPTA-SUS ({$colt_dreapta_sus['x']}, {$colt_dreapta_sus['y']})"
        ];
    }

    if ($colt_stanga_jos) {
        $carti[] = [
            'titlu' => $colt_stanga_jos['text'],
            'pozitie' => "STÂNGA-JOS ({$colt_stanga_jos['x']}, {$colt_stanga_jos['y']})"
        ];
    }

    if ($colt_dreapta_jos) {
        $carti[] = [
            'titlu' => $colt_dreapta_jos['text'],
            'pozitie' => "DREAPTA-JOS ({$colt_dreapta_jos['x']}, {$colt_dreapta_jos['y']})"
        ];
    }

    logDebug("Returnez " . count($carti) . " cuvinte din colțuri");

    return $carti; // Returnăm direct cele 4 cuvinte
    FIN TEST COLȚURI */

    // PROCESARE NORMALĂ REACTIVATĂ - Procesăm TOATE cărțile
    $carti = [];

    logDebug("*** PROCESARE COMPLETĂ ACTIVATĂ - Returnez TOATE cărțile detectate ***");

    // Procesăm fiecare carte grupată complet
    foreach ($cartiGrupate as $idx => $elementeCarte) {
        // Combinăm toate textele din această carte
        $text_complet = implode(' ', array_column($elementeCarte, 'text'));

        logDebug("Procesez cartea " . ($idx + 1) . ": $text_complet");

        // Normalizăm majusculele
        $titlu = normalizareMajuscule($text_complet);

        // Verificăm pentru edituri cunoscute
        $edituri_cunoscute = ['POLIROM', 'HUMANITAS', 'NEMIRA', 'RAO', 'PUBLICA', 'NICULESCU',
            'CURTEA VECHE', 'TREI', 'LITERA', 'CORINT', 'ART', 'VICTORIA',
            'PARALELA 45', 'BOOKS', 'OPEN', 'PENGUIN', 'VINTAGE'];

        $editura_gasita = '';
        foreach ($edituri_cunoscute as $editura) {
            if (stripos($text_complet, $editura) !== false) {
                $editura_gasita = $editura;
                // NU eliminăm editura din titlu pentru a păstra textul complet
                // $titlu = trim(str_ireplace($editura, '', $titlu));
                break;
            }
        }

        // Detectăm autorul dacă există pattern nume propriu
        $autor = '';
        // Comentat temporar pentru a păstra textul complet
        /*
        $cuvinte = explode(' ', $titlu);
        for ($i = 0; $i < count($cuvinte) - 1; $i++) {
            $posibil_nume = $cuvinte[$i] . ' ' . $cuvinte[$i+1];
            if (preg_match('/^[A-Z][a-zăâîșț]+\s+[A-Z][A-ZĂÂÎȘȚ]+$/', $posibil_nume) ||
                preg_match('/^[A-Z][A-ZĂÂÎȘȚ]+\s+[A-Z][a-zăâîșț]+$/', $posibil_nume)) {
                $autor = $posibil_nume;
                $titlu = trim(str_replace($autor, '', $titlu));
                break;
            }
        }
        */

        // Debug pentru a vedea ce se întâmplă
        logDebug("  Text original: $text_complet");
        logDebug("  Titlu după procesare: $titlu");
        logDebug("  Autor detectat: $autor");
        logDebug("  Editura detectată: $editura_gasita");

        // Dacă titlul e gol după procesare, folosim textul complet
        if (empty(trim($titlu))) {
            logDebug("  ⚠ Titlu gol după procesare! Folosesc textul complet.");
            $titlu = normalizareMajuscule($text_complet);
        }

        // Formatăm cartea
        $carte_info = [
            'titlu' => $titlu,
            'autor' => $autor,
            'editura' => $editura_gasita
        ];

        $carte_formatata = formatCarteComplet($carte_info);
        if (!empty($carte_formatata)) {
            $carti[] = $carte_formatata;
            logDebug("Carte finală: $carte_formatata");
        } else {
            logDebug("  ⚠ Carte goală după formatare, skip!");
        }
    }

    // Colțurile au fost calculate mai devreme
    // Le afișăm aici pentru confirmare și logging
    logDebug("\n=== COLȚURI FOLOSITE CA REPERE ===");
    if ($colt_stanga_sus) {
        logDebug("  Stânga-sus: '{$colt_stanga_sus['text']}' la ({$colt_stanga_sus['x']}, {$colt_stanga_sus['y']})");
    }
    if ($colt_dreapta_sus) {
        logDebug("  Dreapta-sus: '{$colt_dreapta_sus['text']}' la ({$colt_dreapta_sus['x']}, {$colt_dreapta_sus['y']})");
    }
    if ($colt_stanga_jos) {
        logDebug("  Stânga-jos: '{$colt_stanga_jos['text']}' la ({$colt_stanga_jos['x']}, {$colt_stanga_jos['y']})");
    }
    if ($colt_dreapta_jos) {
        logDebug("  Dreapta-jos: '{$colt_dreapta_jos['text']}' la ({$colt_dreapta_jos['x']}, {$colt_dreapta_jos['y']})");
    }

    logDebug("=== REZULTAT FINAL (VERSIUNE NOUĂ CU CORECȚII) ===");
    logDebug("Total cărți detectate cu algoritmul cartezian: " . count($carti));

    if (empty($carti)) {
        logDebug("⚠ Nu s-au detectat cărți! Verificați imaginea.");
    } else {
        foreach ($carti as $idx => $carte) {
            logDebug("Carte " . ($idx + 1) . ": $carte");
        }
    }

    // Salvăm informații despre detectare în GLOBALS pentru a le putea accesa
    $GLOBALS['info_ultima_detectare'] = [
        'orientare' => $orientare ?? 'necunoscut',
        'numar_carti' => count($carti)
    ];

    logDebug("INFO SALVATĂ: orientare=" . ($orientare ?? 'necunoscut') . ", număr cărți=" . count($carti));

    return [
        'carti' => $carti,
        'info_detectie' => $info_detectie_carti
    ];
}

// Funcție nouă pentru procesarea liniilor unei singure cărți
function procesareLiniiCarte($liniiCarte) {
    $carte_info = [];
    $edituri_cunoscute = ['POLIROM', 'HUMANITAS', 'NEMIRA', 'RAO', 'PUBLICA', 'NICULESCU',
        'CURTEA VECHE', 'TREI', 'LITERA', 'CORINT', 'ART', 'VICTORIA',
        'PARALELA 45', 'BOOKS', 'OPEN', 'PENGUIN', 'VINTAGE'];

    // Combinăm toate liniile într-un text complet pentru analiză
    $text_complet = '';
    $texte_linii = [];

    foreach ($liniiCarte as $linie) {
        $text_linie = '';
        foreach ($linie as $element) {
            $text_linie .= ($text_linie ? ' ' : '') . $element['text'];
        }
        if (strlen($text_linie) > 1) {
            $texte_linii[] = $text_linie;
            $text_complet .= ($text_complet ? ' ' : '') . $text_linie;
        }
    }

    // Detectăm componentele cărții
    foreach ($texte_linii as $text_linie) {
        // Verificăm dacă e editură
        foreach ($edituri_cunoscute as $editura) {
            if (stripos($text_linie, $editura) !== false) {
                $carte_info['editura'] = $editura;
                continue 2;
            }
        }

        // Verificăm dacă e autor (pattern nume propriu)
        if (preg_match('/^[A-Z][a-zăâîșț]+\s+[A-Z][A-ZĂÂÎȘȚ]+$/', $text_linie) ||
            preg_match('/^[A-Z][A-ZĂÂÎȘȚ]+\s+[A-Z][a-zăâîșț]+$/', $text_linie) ||
            preg_match('/^[A-Z]\.\s*([A-Z]\.\s*)?[A-Z][a-zăâîșț]+/', $text_linie)) {

            if (!preg_match('/\b(de|ale|lui|pentru|despre|cu|și|sau|la|pe)\b/i', $text_linie)) {
                $carte_info['autor'] = $text_linie;
                continue;
            }
        }

        // Altfel considerăm că e titlu
        if (empty($carte_info['titlu']) && strlen($text_linie) > 2) {
            $carte_info['titlu'] = normalizareMajuscule($text_linie);
        }
    }

    // Returnăm cartea formatată dacă avem cel puțin un titlu
    if (!empty($carte_info['titlu'])) {
        return formatCarteComplet($carte_info);
    }

    return null;
}

// Funcție pentru procesarea standard (când nu detectăm cotor)
function procesareLiniiCartiStandard($linii) {
    // Procesăm liniile pentru a identifica cărțile
    $carti = [];
    $carte_curenta = [];
    $edituri_cunoscute = ['POLIROM', 'HUMANITAS', 'NEMIRA', 'RAO', 'PUBLICA', 'NICULESCU',
        'CURTEA VECHE', 'TREI', 'LITERA', 'CORINT', 'ART', 'VICTORIA',
        'PARALELA 45', 'BOOKS', 'OPEN', 'PENGUIN', 'VINTAGE'];

    for ($i = 0; $i < count($linii); $i++) {
        $linie = $linii[$i];

        // Combinăm textul din linie
        $text_linie = '';
        $inaltime_medie = 0;
        $inaltime_max = 0;

        foreach ($linie as $element) {
            $text_linie .= ($text_linie ? ' ' : '') . $element['text'];
            $inaltime_medie += $element['inaltime'];
            $inaltime_max = max($inaltime_max, $element['inaltime']);
        }

        if (count($linie) > 0) {
            $inaltime_medie = $inaltime_medie / count($linie);
        }

        // Skip linii foarte scurte
        if (strlen($text_linie) < 2) continue;

        // Verificăm dacă e editură
        $este_editura = false;
        foreach ($edituri_cunoscute as $editura) {
            if (stripos($text_linie, $editura) !== false) {
                $este_editura = true;
                if (!empty($carte_curenta)) {
                    $carte_curenta['editura'] = $editura;
                }
                break;
            }
        }

        if ($este_editura) continue;

        // Detectăm dacă e autor sau titlu bazat pe pattern și context
        $este_autor = false;

        // Pattern pentru autor - nume proprii
        if (preg_match('/^[A-Z][a-zăâîșț]+\s+[A-Z][A-ZĂÂÎȘȚ]+$/', $text_linie) ||
            preg_match('/^[A-Z][A-ZĂÂÎȘȚ]+\s+[A-Z][a-zăâîșț]+$/', $text_linie) ||
            preg_match('/^[A-Z]\.\s*([A-Z]\.\s*)?[A-Z][a-zăâîșț]+/', $text_linie)) {

            // Verificăm să nu fie titlu care arată ca nume
            if (!preg_match('/\b(de|ale|lui|pentru|despre|cu|și|sau|la|pe)\b/i', $text_linie)) {
                $este_autor = true;

                // Decidem dacă autorul vine înainte sau după titlu
                // Dacă următoarea linie are font mai mare, probabil e titlul
                if ($i + 1 < count($linii)) {
                    $linie_urmatoare = $linii[$i + 1];
                    $inaltime_urmatoare = 0;
                    foreach ($linie_urmatoare as $elem) {
                        $inaltime_urmatoare = max($inaltime_urmatoare, $elem['inaltime']);
                    }

                    // Dacă linia următoare are font mai mare, e probabil titlul
                    if ($inaltime_urmatoare > $inaltime_max * 1.1) {
                        // Salvăm autorul pentru următoarea carte
                        $carte_curenta = ['autor' => $text_linie];
                        continue;
                    }
                }

                // Altfel, autorul aparține cărții curente
                if (!empty($carte_curenta) && empty($carte_curenta['autor'])) {
                    $carte_curenta['autor'] = $text_linie;

                    // Salvăm cartea dacă avem și titlu
                    if (!empty($carte_curenta['titlu'])) {
                        $carti[] = formatCarteComplet($carte_curenta);
                        $carte_curenta = [];
                    }
                    continue;
                }
            }
        }

        // Dacă nu e autor sau editură, considerăm că e titlu
        if (!$este_autor && !$este_editura) {
            // Normalizăm textul
            $text_linie = normalizareMajuscule($text_linie);

            // Dacă avem deja o carte completă, o salvăm
            if (!empty($carte_curenta) && !empty($carte_curenta['titlu'])) {
                $carti[] = formatCarteComplet($carte_curenta);
                $carte_curenta = [];
            }

            // Setăm titlul
            $carte_curenta['titlu'] = $text_linie;

            // Dacă următoarea linie e autor, o procesăm anticipat
            if ($i + 1 < count($linii)) {
                $linie_urmatoare = $linii[$i + 1];
                $text_urmator = '';
                foreach ($linie_urmatoare as $element) {
                    $text_urmator .= ($text_urmator ? ' ' : '') . $element['text'];
                }

                if (preg_match('/^[A-Z][a-zăâîșț]+\s+[A-Z][A-ZĂÂÎȘȚ]+$/', $text_urmator)) {
                    $carte_curenta['autor'] = $text_urmator;
                    $i++; // Skip următoarea linie
                }
            }
        }
    }

    // Salvăm ultima carte dacă există
    if (!empty($carte_curenta)) {
        $carti[] = formatCarteComplet($carte_curenta);
    }

    // Eliminăm duplicatele și returnăm
    return array_unique($carti);
}

// Funcție pentru procesarea textului de cărți și extragerea titlurilor (versiunea veche, păstrată pentru compatibilitate)
function procesareTextCarti($text, $traductor = null) {
    $carti = [];

    // Curățăm textul și îl împărțim în linii
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $linii = array_filter(array_map('trim', explode("\n", $text)));

    // Liste de edituri cunoscute pentru identificare
    $edituri_cunoscute = ['POLIROM', 'HUMANITAS', 'NEMIRA', 'RAO', 'PUBLICA', 'NICULESCU',
        'CURTEA VECHE', 'TREI', 'LITERA', 'CORINT', 'ART', 'VICTORIA',
        'PARALELA 45', 'BOOKS', 'OPEN'];

    // Termeni de skip
    $termeni_skip = ['AUTOBIOGRAFIE', 'EXCEPȚIONAL', 'EXCEPTIONAL', 'ORIGINAL',
        'BESTSELLER', 'MEMORABIL', 'NOU', 'EDITIE'];

    // Procesăm textul ca blocuri continue
    $carte_curenta = [];
    $in_carte = false;

    for ($i = 0; $i < count($linii); $i++) {
        $linie = $linii[$i];
        $linie_upper = strtoupper($linie);

        // Skip linii foarte scurte sau foarte lungi
        if (strlen($linie) < 2 || strlen($linie) > 150) continue;

        // Skip ISBN și termeni generici
        if (preg_match('/ISBN|©|Copyright|\d{4}-\d{4}/i', $linie)) continue;

        // Verificăm dacă e termen de skip
        $este_skip = false;
        foreach ($termeni_skip as $skip) {
            if (stripos($linie, $skip) !== false && strlen($linie) < 20) {
                $este_skip = true;
                break;
            }
        }
        if ($este_skip) continue;

        // Verificăm dacă e editură
        $este_editura = false;
        foreach ($edituri_cunoscute as $editura) {
            if (stripos($linie_upper, $editura) !== false) {
                $este_editura = true;
                // Dacă avem o carte în lucru, adăugăm editura
                if (!empty($carte_curenta)) {
                    // Extragem doar numele editurii din paranteză sau linia întreagă
                    if (preg_match('/\(([^)]+)\)/', $linie, $matches)) {
                        $carte_curenta['editura'] = $matches[1];
                    } else {
                        $carte_curenta['editura'] = $linie;
                    }
                }
                break;
            }
        }

        if ($este_editura) continue;

        // Detectăm pattern de autor (nume proprii cu majuscule)
        $este_autor = false;

        // Pattern pentru autor: nume în format "Prenume NUME" sau "NUME Prenume" sau "Autor1 - Autor2"
        if (preg_match('/^[A-Z][a-zăâîșț]+\s+[A-Z][A-ZĂÂÎȘȚ]+$/', $linie) ||
            preg_match('/^[A-Z][A-ZĂÂÎȘȚ]+\s+[A-Z][a-zăâîșț]+$/', $linie) ||
            preg_match('/^[A-Z]\.\s*[A-Z]\.\s+[A-Z][a-zăâîșț]+/', $linie) ||
            (strpos($linie, ' - ') !== false && preg_match('/[A-Z][a-z]+/', $linie))) {

            // Verificăm să nu fie de fapt un titlu care arată ca un nume
            if (!preg_match('/\b(de|ale|lui|pentru|despre|cu|și|sau|la)\b/i', $linie)) {
                $este_autor = true;

                // Dacă avem o carte în lucru fără autor, adăugăm autorul
                if (!empty($carte_curenta) && empty($carte_curenta['autor'])) {
                    $carte_curenta['autor'] = $linie;
                }
                // Altfel, începem o carte nouă cu acest autor
                else if (empty($carte_curenta)) {
                    $carte_curenta = ['autor' => $linie];
                }
            }
        }

        if ($este_autor) continue;

        // Tot ce rămâne considerăm că e titlu
        // Dacă avem deja o carte completă, o salvăm și începem alta nouă
        if (!empty($carte_curenta) && !empty($carte_curenta['titlu'])) {
            // Formatăm și salvăm cartea curentă
            $denumire = formatCarteComplet($carte_curenta);
            if (!empty($denumire)) {
                $carti[] = $denumire;
            }
            $carte_curenta = [];
        }

        // Adăugăm ca titlu
        if (empty($carte_curenta['titlu'])) {
            // Normalizăm majusculele - prima literă mare, restul mici (cu excepții)
            $linie = normalizareMajuscule($linie);
            $carte_curenta['titlu'] = $linie;
        }
    }

    // Salvăm ultima carte dacă există
    if (!empty($carte_curenta)) {
        $denumire = formatCarteComplet($carte_curenta);
        if (!empty($denumire)) {
            $carti[] = $denumire;
        }
    }

    // Eliminăm duplicatele
    $carti = array_unique($carti);

    return $carti;
}

// Funcție pentru formatarea completă a unei cărți
function formatCarteComplet($carte) {
    if (empty($carte['titlu'])) return '';

    $rezultat = $carte['titlu'];

    if (!empty($carte['autor'])) {
        $rezultat .= ' - ' . $carte['autor'];
    }

    if (!empty($carte['editura'])) {
        $editura = str_replace(['(', ')'], '', $carte['editura']);
        $rezultat .= ' (' . $editura . ')';
    }

    return $rezultat;
}

// Funcție pentru normalizarea majusculelor
function normalizareMajuscule($text) {
    // Dacă tot textul e cu majuscule, îl convertim
    if ($text === strtoupper($text)) {
        $text = ucwords(strtolower($text));

        // Păstrăm majuscule pentru numerale romane
        $text = preg_replace_callback('/\b([IVX]+)\b/', function($matches) {
            return strtoupper($matches[1]);
        }, $text);
    }

    // Corectăm articolele și prepozițiile
    $text = preg_replace_callback('/\b(De|La|În|Pe|Cu|Și|Sau|Ale|Lui|Pentru)\b/', function($matches) {
        return strtolower($matches[1]);
    }, $text);

    // Prima literă mare întotdeauna
    $text = ucfirst($text);

    return $text;
}

// Funcție pentru detectarea automată a tipului de conținut (cărți vs obiecte)
function detecteazaTipContinut($textDetectat, $labels = []) {
    // Cuvinte cheie specifice cărților
    $cuvinteCheieCarti = [
        'isbn', 'editura', 'publisher', 'author', 'autor',
        'publicat', 'published', 'edition', 'ediție', 'editie',
        'volum', 'volume', 'capitolul', 'chapter', 'pagini',
        'pages', 'traducere', 'translation', 'roman', 'novel',
        'poezii', 'poetry', 'bestseller', 'librărie', 'library',
        'copyright', '©', 'all rights reserved', 'printed in',
        'tipărit', 'tipografia', 'copertă', 'cover'
    ];

    // Pattern-uri regex specifice cărților
    $patternCarti = [
        '/ISBN[\s\-:]*[\d\-X]+/i',
        '/\b(?:19|20)\d{2}\b.*(?:Editura|Publisher|Edition)/i',
        '/Copyright\s*©?\s*\d{4}/i',
        '/All rights reserved/i',
        '/Ediția\s+[IVX\d]+/i',
        '/Traducere\s+de/i'
    ];

    $scorCarti = 0;
    $textLower = strtolower($textDetectat);

    // Verificăm cuvintele cheie
    foreach ($cuvinteCheieCarti as $cuvant) {
        if (strpos($textLower, strtolower($cuvant)) !== false) {
            $scorCarti += 2;
        }
    }

    // Verificăm pattern-urile
    foreach ($patternCarti as $pattern) {
        if (preg_match($pattern, $textDetectat)) {
            $scorCarti += 3;
        }
    }

    // Verificăm labels de la Vision API
    $labelNames = array_map('strtolower', $labels);
    if (in_array('book', $labelNames) || in_array('books', $labelNames)) {
        $scorCarti += 10; // Foarte probabil cărți
    }
    if (in_array('library', $labelNames) || in_array('bookshelf', $labelNames) ||
        in_array('bookcase', $labelNames) || in_array('shelf', $labelNames) ||
        in_array('shelving', $labelNames)) {
        $scorCarti += 5;
    }
    if (in_array('publication', $labelNames) || in_array('text', $labelNames) ||
        in_array('novel', $labelNames) || in_array('book cover', $labelNames)) {
        $scorCarti += 3;
    }

    // Verificăm densitatea textului
    $numarCuvinte = str_word_count($textDetectat);
    if ($numarCuvinte > 20) { // Redus pragul
        $scorCarti += 3;
    }

    // Decidem tipul bazat pe scor - prag mai mic
    return [
        'tip' => $scorCarti >= 3 ? 'carti' : 'obiecte',
        'scor' => $scorCarti,
        'incredere' => min($scorCarti / 15, 1.0)
    ];
}

// Funcție pentru obținerea Access Token
function getAccessToken($keyFilePath) {
    $keyFileContent = file_get_contents($keyFilePath);
    $keyData = json_decode($keyFileContent, true);

    $jwt = [
        'iss' => $keyData['client_email'],
        'scope' => 'https://www.googleapis.com/auth/cloud-vision',
        'aud' => $keyData['token_uri'],
        'exp' => time() + 3600,
        'iat' => time()
    ];

    $header = json_encode(['typ' => 'JWT', 'alg' => 'RS256']);
    $payload = json_encode($jwt);

    $base64Header = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
    $base64Payload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));

    $signatureInput = $base64Header . '.' . $base64Payload;

    openssl_sign($signatureInput, $signature, $keyData['private_key'], OPENSSL_ALGO_SHA256);
    $base64Signature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

    $jwtToken = $signatureInput . '.' . $base64Signature;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $keyData['token_uri']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $jwtToken
    ]));

    $response = curl_exec($ch);
    curl_close($ch);

    $tokenData = json_decode($response, true);
    return $tokenData['access_token'] ?? null;
}

// Funcție pentru apel Vision API
function callVisionAPI($imageContent, $accessToken) {
    $base64Image = base64_encode($imageContent);

    $requestBody = [
        'requests' => [
            [
                'image' => ['content' => $base64Image],
                'features' => [
                    ['type' => 'LABEL_DETECTION', 'maxResults' => 10],
                    ['type' => 'OBJECT_LOCALIZATION', 'maxResults' => 10],
                    ['type' => 'TEXT_DETECTION', 'maxResults' => 50]
                ]
            ]
        ]
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://vision.googleapis.com/v1/images:annotate');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestBody));

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        throw new Exception('API Error: ' . $response);
    }

    return json_decode($response, true);
}

// Procesare principală
try {
    // Inițializăm flag-urile globale
    $GLOBALS['este_procesare_carti'] = false;
    global $elemente_text, $colt_stanga_sus, $colt_dreapta_sus, $colt_stanga_jos, $colt_dreapta_jos;
    $elemente_text = [];
    $colt_stanga_sus = null;
    $colt_dreapta_sus = null;
    $colt_stanga_jos = null;
    $colt_dreapta_jos = null;
    
    $id_obiect = $_POST['id_obiect'] ?? 0;
    $id_colectie = $_POST['id_colectie'] ?? 1;

    if (!$id_obiect) {
        throw new Exception('ID obiect invalid');
    }

    // User_id e deja disponibil din $user
    $user_id = $user['id_utilizator'];
    $table_prefix = null;
    $colectie_proprietar_id = $user_id; // Default pe user curent

    // Obținem conexiunea centrală
    $conn_central = getCentralDbConnection();
    
    // Determinăm prefixul corect pentru colecție
    if ($id_colectie > 0) {

        // Verificăm colecția și obținem prefixul corect
        $sql_col = "SELECT c.prefix_tabele, c.id_utilizator as proprietar_id
                    FROM colectii_utilizatori c
                    LEFT JOIN partajari p ON c.id_colectie = p.id_colectie 
                         AND p.id_utilizator_partajat = ? AND p.activ = 1
                    WHERE c.id_colectie = ? 
                    AND (c.id_utilizator = ? OR p.id_partajare IS NOT NULL)";
        $stmt_col = mysqli_prepare($conn_central, $sql_col);
        mysqli_stmt_bind_param($stmt_col, "iii", $user_id, $id_colectie, $user_id);
        mysqli_stmt_execute($stmt_col);
        $result_col = mysqli_stmt_get_result($stmt_col);

        if ($row_col = mysqli_fetch_assoc($result_col)) {
            $table_prefix = $row_col['prefix_tabele'];
            $colectie_proprietar_id = $row_col['proprietar_id'];
            logDebug("Colecție găsită - prefix: $table_prefix, proprietar: $colectie_proprietar_id");

            // Dacă e colecție partajată, reconectăm la BD-ul proprietarului
            if ($colectie_proprietar_id != $user_id) {
                $sql_owner = "SELECT db_name FROM utilizatori WHERE id_utilizator = ?";
                $stmt_owner = mysqli_prepare($conn_central, $sql_owner);
                mysqli_stmt_bind_param($stmt_owner, "i", $colectie_proprietar_id);
                mysqli_stmt_execute($stmt_owner);
                $result_owner = mysqli_stmt_get_result($stmt_owner);

                if ($row_owner = mysqli_fetch_assoc($result_owner)) {
                    mysqli_close($conn_central);
                    $conn = getUserDbConnection($row_owner['db_name']);
                    logDebug("Reconectat la BD proprietar: " . $row_owner['db_name']);
                }
                mysqli_stmt_close($stmt_owner);
            }
        }
        mysqli_stmt_close($stmt_col);
        mysqli_close($conn_central);
    }

    // Determinăm tabelul corect
    if ($table_prefix) {
        $table_obiecte = $table_prefix . 'obiecte';
        $table_detectii = $table_prefix . 'detectii_obiecte';
        logDebug("Folosesc prefix din colecție: $table_obiecte, $table_detectii");
    } else if (isset($_SESSION['prefix_tabele']) && !empty($_SESSION['prefix_tabele'])) {
        // Folosim prefixul din sesiune ca fallback
        $table_prefix = $_SESSION['prefix_tabele'];
        $table_obiecte = $table_prefix . 'obiecte';
        $table_detectii = $table_prefix . 'detectii_obiecte';
        logDebug("Folosesc tabele din sesiune: $table_obiecte, $table_detectii");
    } else {
        // Folosim tabelele standard
        $table_prefix = 'user_' . $user_id . '_';
        $table_obiecte = $table_prefix . 'obiecte';
        $table_detectii = $table_prefix . 'detectii_obiecte';
        logDebug("Folosesc tabele standard: $table_obiecte, $table_detectii");
    }

    // Dacă avem $conn definit (din getUserDbConnection), o folosim, altfel folosim $conn_central
    if (!isset($conn)) {
        $conn = $conn_central;
    }

    // Verificăm că tabela există
    $check_table = mysqli_query($conn, "SHOW TABLES LIKE '$table_obiecte'");
    if (mysqli_num_rows($check_table) == 0) {
        throw new Exception("Tabela $table_obiecte nu există!");
    }

    // Obținem imaginile și datele existente, inclusiv locația și cutia
    $sql = "SELECT imagine, denumire_obiect, cantitate_obiect, eticheta_obiect, locatie, cutie FROM $table_obiecte WHERE id_obiect = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $id_obiect);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);

    // DEBUG EXTREM - să vedem exact ce se întâmplă
    logDebug("==== DEBUG PROCESARE VISION ====");
    logDebug("ID Obiect: $id_obiect");
    logDebug("Tabel: $table_obiecte");
    logDebug("Date din BD: " . json_encode($row));

    if (!$row) {
        throw new Exception('Obiect negăsit');
    }

    $imagini = array_filter(explode(',', $row['imagine']));
    if (empty($imagini)) {
        throw new Exception('Nu există imagini de procesat');
    }

    // Parsăm datele existente și FILTRĂM obiectele Vision vechi
    $denumiri_existente_raw = $row['denumire_obiect'] ? explode(', ', $row['denumire_obiect']) : [];
    $cantitati_existente_raw = $row['cantitate_obiect'] ? explode(', ', $row['cantitate_obiect']) : [];
    $etichete_existente_raw = $row['eticheta_obiect'] ? explode('; ', $row['eticheta_obiect']) : [];

    logDebug("Total obiecte găsite în BD: " . count($denumiri_existente_raw));
    logDebug("Total etichete găsite în BD: " . count($etichete_existente_raw));

    // Filtrăm - păstrăm DOAR obiectele care NU sunt Vision (nu au culoare #ff6600)
    $denumiri_existente = [];
    $cantitati_existente = [];
    $etichete_existente = [];

    for ($i = 0; $i < count($denumiri_existente_raw); $i++) {
        $denumire = isset($denumiri_existente_raw[$i]) ? trim($denumiri_existente_raw[$i]) : '';
        $eticheta = isset($etichete_existente_raw[$i]) ? trim($etichete_existente_raw[$i]) : '';

        logDebug("Verificare obiect $i: '$denumire' cu eticheta '$eticheta'");

        // Dacă NU e Vision (culoare #ff6600), păstrăm
        if (strpos($eticheta, '#ff6600') === false) {
            $denumiri_existente[] = $denumire;
            $cantitati_existente[] = isset($cantitati_existente_raw[$i]) ? trim($cantitati_existente_raw[$i]) : '1';
            $etichete_existente[] = $eticheta;
            logDebug("  -> Păstrat (manual)");
        } else {
            logDebug("  -> Eliminat (Vision)");
        }
    }

    logDebug("Păstrat " . count($denumiri_existente) . " obiecte manuale (non-Vision)");

    // Verifică cheie Google Vision
    $keyFilePath = 'google-vision-key.json';
    if (!file_exists($keyFilePath)) {
        throw new Exception('Fișierul cu cheia Google Vision nu a fost găsit');
    }

    $accessToken = getAccessToken($keyFilePath);
    if (!$accessToken) {
        throw new Exception('Nu s-a putut obține access token');
    }

    $traductor = new TraducereAutomata($conn);

    // Inițializăm managerul de context dacă avem locație și cutie
    $context_manager = null;
    $context_info = null;
    $locatie = $row['locatie'] ?? '';
    $cutie = $row['cutie'] ?? '';

    logDebug("=== INIȚIALIZARE CONTEXT ===");
    logDebug("Locație detectată: [$locatie]");
    logDebug("Cutie detectată: [$cutie]");
    logDebug("ID colecție pentru context: $id_colectie");

    if (!empty($locatie) && !empty($cutie)) {
        // Includem clasa ContextManager
        if (file_exists('includes/class.ContextManager.php')) {
            require_once 'includes/class.ContextManager.php';
            try {
                $conn_central = getCentralDbConnection();
                $context_manager = new ContextManager($conn, $conn_central);
                logDebug("✓ Context Manager ACTIV pentru: $locatie / $cutie");

                // Verificăm ce excluderi avem pentru această locație și colecție
                logDebug("Caut context pentru: locatie='$locatie', cutie='$cutie', id_colectie=$id_colectie");
                $sql_check = "SELECT id, id_colectie, obiecte_excluse, obiecte_comune FROM context_locatii WHERE locatie = ? AND cutie = ? AND (id_colectie = ? OR id_colectie IS NULL) ORDER BY (id_colectie IS NOT NULL) DESC LIMIT 1";
                $stmt_check = mysqli_prepare($conn_central, $sql_check);
                mysqli_stmt_bind_param($stmt_check, "ssi", $locatie, $cutie, $id_colectie);
                mysqli_stmt_execute($stmt_check);
                $result_check = mysqli_stmt_get_result($stmt_check);
                if ($row_check = mysqli_fetch_assoc($result_check)) {
                    logDebug("Context găsit - ID: {$row_check['id']}, ID_colectie: " . ($row_check['id_colectie'] ?? 'NULL'));
                    logDebug("Obiecte excluse: " . substr($row_check['obiecte_excluse'] ?: 'NICIUNA', 0, 200));
                    logDebug("Obiecte comune: " . substr($row_check['obiecte_comune'] ?: 'NICIUNA', 0, 200));

                    // Salvăm info pentru debug în răspuns
                    $context_info = "Context ID: {$row_check['id']}, Col: " . ($row_check['id_colectie'] ?? 'NULL') .
                        ", Excluse: " . (empty($row_check['obiecte_excluse']) ? '0' : count(explode(',', $row_check['obiecte_excluse'])));
                } else {
                    logDebug("❌ NU EXISTĂ context pentru locatie='$locatie', cutie='$cutie', id_colectie=$id_colectie");
                    $context_info = "Nu există context salvat";
                }
                mysqli_stmt_close($stmt_check);
            } catch (Exception $e) {
                logDebug("✗ Eroare la inițializarea Context Manager: " . $e->getMessage());
                $context_manager = null;
            }
        } else {
            logDebug("✗ ATENȚIE: class.ContextManager.php lipsește!");
        }
    } else {
        logDebug("✗ Nu pot activa contextul - lipsește locația sau cutia");
    }

    // Arrays pentru obiectele noi detectate
    $obiecte_noi = [];
    $cantitati_noi = [];
    $etichete_noi = [];

    // Array pentru debug - obiecte blocate
    $obiecte_blocate = [];

    // Array pentru debug - toate obiectele detectate de Vision
    $obiecte_vision_raw = [];

    // Lista de termeni generici de excluderi - HARD-CODED pentru Pod deasupra/7
    $termeni_exclusi = [
        // Engleză - netraduse
        'font', 'text', 'image', 'photo', 'picture',
        'electric wiring', 'electric supply', 'technology',
        // Română - prea generice
        'tehnologie', 'electrocasnic mare', 'sârmă', 'cablu electric',
        'bagaje și genți', 'sac', 'curea', 'bagaj'
    ];

    // FORȚARE EXCLUDERI pentru Pod deasupra/7
    if ($locatie == 'Pod deasupra' && $cutie == '7') {
        $termeni_exclusi = array_merge($termeni_exclusi, [
            'mașină', 'maşină', 'masina', 'car', 'vehicle',
            'font', 'tehnologie', 'plastic', 'electronică',
            'cablare electrică', 'alimentare cu energie electrică',
            'electricitate', 'gestionarea cablurilor'
        ]);
        logDebug("EXCLUDERI FORȚATE pentru Pod deasupra/7: " . implode(', ', $termeni_exclusi));
    }

    // Array pentru contorizare obiecte detectate per imagine
    // Format: ['mouse_img1' => 3, 'keyboard_img2' => 2]
    $obiecte_detectate = [];

    // Procesăm normal, dar detectăm automat cărțile și le denumim complet
    $user_folder = ($colectie_proprietar_id != $user_id) ? $colectie_proprietar_id : $user_id;

    logDebug("=== PROCESARE VISION CU DETECTARE AUTOMATĂ CĂRȚI ===");

    // Array pentru colectare informații despre cărți detectate
    $debug_carti = [];

    // Variabile pentru tracking-ul tipului detectat (pentru răspunsul JSON simplificat)
    $tip_detectie_global = 'obiecte'; // 'carti' sau 'obiecte'
    $aranjament_detectat = null; // 'vertical' sau 'orizontal' (doar pentru cărți)
    $numar_carti_total = 0;

    // Procesăm fiecare imagine
    $index_imagine = 0;
    foreach ($imagini as $imagine) {
        $index_imagine++; // 1, 2, 3...
        $imagine = trim($imagine);
        // Pentru colecții partajate, imaginile sunt în folderul proprietarului
        $user_folder = ($colectie_proprietar_id != $user_id) ? $colectie_proprietar_id : $user_id;
        $imagePath = "imagini_obiecte/user_{$user_folder}/" . $imagine;
        logDebug("Caut imagine în: $imagePath (proprietar: $colectie_proprietar_id, user curent: $user_id)");

        if (!file_exists($imagePath)) {
            logDebug("Imagine negăsită: $imagePath");
            continue;
        }

        try {
            $imageContent = file_get_contents($imagePath);
            $visionResponse = callVisionAPI($imageContent, $accessToken);

            if (!isset($visionResponse['responses'][0])) {
                continue;
            }

            $response = $visionResponse['responses'][0];

            $nr_etichete_imagine = 0; // Counter pentru această imagine

            // Colectăm toți termenii din această imagine pentru deduplicare
            $termeni_imagine = [];
            $termeni_imagine_en = [];

            // VERIFICĂM DACĂ SUNT CĂRȚI - detectăm automat din labels și text
            $este_imagine_carti = false;
            $text_detectat = '';
            $labels_detectate = [];

            // Colectăm labels pentru verificare
            if (isset($response['labelAnnotations'])) {
                foreach ($response['labelAnnotations'] as $label) {
                    $labels_detectate[] = strtolower($label['description']);
                }
            }

            // Verificăm dacă avem text detectat
            if (isset($response['textAnnotations']) && !empty($response['textAnnotations'])) {
                $text_detectat = $response['textAnnotations'][0]['description'] ?? '';
            }

            // Detectăm tipul de conținut
            $rezultat_detectie = detecteazaTipContinut($text_detectat, $labels_detectate);

            // FORȚĂM detectarea ca CĂRȚI dacă Vision vede "book" sau "carte"
            $labels_lower = array_map('strtolower', $labels_detectate);
            $fortat_carti = false;
            if (in_array('book', $labels_lower) || in_array('books', $labels_lower) ||
                in_array('carte', $labels_lower) || in_array('cărți', $labels_lower) ||
                in_array('carti', $labels_lower)) {
                $rezultat_detectie['tip'] = 'carti';
                $rezultat_detectie['scor'] = 99; // Scor maxim pentru debug
                $fortat_carti = true;
                logDebug("FORȚĂM detectare CĂRȚI - Vision a detectat termeni de carte în labels: " . implode(', ', $labels_lower));

                // FORȚĂM și text detectat să nu fie gol pentru a intra în procesare
                if (empty($text_detectat)) {
                    $text_detectat = 'FORȚAT_PENTRU_TEST';
                    logDebug("ATENȚIE: Nu s-a detectat text OCR, forțăm pentru test!");
                }
            }

            // Adăugăm informații de debug pentru toate imaginile
            $debug_info_imagine = [
                'imagine_nr' => $index_imagine,
                'labels_detectate' => implode(', ', array_slice($labels_detectate, 0, 10)),
                'text_lungime' => strlen($text_detectat),
                'scor_detectie' => $rezultat_detectie['scor'],
                'tip_detectat' => $rezultat_detectie['tip']
            ];
            $debug_carti[] = $debug_info_imagine;

            if ($rezultat_detectie['tip'] === 'carti' && !empty($text_detectat)) {
                logDebug("=== IMAGINE $index_imagine identificată ca imagine cu CĂRȚI ===");
                logDebug("Text detectat: " . substr($text_detectat, 0, 500));

                // Extragem coordonatele pentru fiecare cuvânt
                $elemente_cu_coordonate = [];
                if (isset($response['textAnnotations'])) {
                    // Skip primul element care e textul complet
                    for ($i = 1; $i < count($response['textAnnotations']); $i++) {
                        $annotation = $response['textAnnotations'][$i];
                        if (isset($annotation['boundingPoly']['vertices'])) {
                            $elemente_cu_coordonate[] = [
                                'text' => $annotation['description'],
                                'vertices' => $annotation['boundingPoly']['vertices']
                            ];
                        }
                    }
                }

                // Dacă avem coordonate, folosim algoritmul real cu coordonate
                if (!empty($elemente_cu_coordonate)) {
                    logDebug("Apelăm algoritmul cu " . count($elemente_cu_coordonate) . " coordonate");

                    // Construim array-ul în formatul așteptat de procesareTextCartiCuPozitii
                    $text_annotations_format = [];
                    $text_annotations_format[0] = ['description' => $text_detectat]; // textul complet

                    foreach ($elemente_cu_coordonate as $elem) {
                        $text_annotations_format[] = [
                            'description' => $elem['text'],
                            'boundingPoly' => ['vertices' => $elem['vertices']]
                        ];
                    }

                    // Folosim funcția care procesează cu coordonate
                    $rezultat_procesare = procesareTextCartiCuPozitii($text_annotations_format);

                    // Verificăm tipul de răspuns
                    if (is_array($rezultat_procesare) && isset($rezultat_procesare['carti'])) {
                        $carti_gasite = $rezultat_procesare['carti'];
                        $debug_info_imagine['orientare'] = $rezultat_procesare['info_detectie']['orientare'] ?? 'necunoscut';
                        $debug_info_imagine['numar_carti'] = $rezultat_procesare['info_detectie']['numar_carti'] ?? 0;
                        $debug_info_imagine['colturi'] = $rezultat_procesare['info_detectie']['colturi'] ?? [];
                        $debug_carti[count($debug_carti) - 1] = $debug_info_imagine;
                        logDebug("INFO DETECTIE: orientare=" . $debug_info_imagine['orientare'] .
                            ", cărți=" . $debug_info_imagine['numar_carti']);

                        // Setăm variabilele globale pentru răspunsul JSON
                        $tip_detectie_global = 'carti';
                        $aranjament_detectat = $debug_info_imagine['orientare'];
                        $numar_carti_total += count($carti_gasite);
                    } else {
                        // Fallback pentru formatul vechi
                        $carti_gasite = $rezultat_procesare;
                        if (isset($GLOBALS['info_ultima_detectare'])) {
                            $debug_info_imagine['orientare'] = $GLOBALS['info_ultima_detectare']['orientare'];
                            $debug_info_imagine['numar_carti'] = $GLOBALS['info_ultima_detectare']['numar_carti'];
                            $debug_carti[count($debug_carti) - 1] = $debug_info_imagine;
                            logDebug("INFO RECUPERATĂ: orientare=" . $GLOBALS['info_ultima_detectare']['orientare'] .
                                ", cărți=" . $GLOBALS['info_ultima_detectare']['numar_carti']);

                            // Setăm variabilele globale pentru răspunsul JSON
                            $tip_detectie_global = 'carti';
                            $aranjament_detectat = $GLOBALS['info_ultima_detectare']['orientare'];
                            $numar_carti_total += count($carti_gasite);
                        }
                    }
                } else {
                    // Fallback - procesăm simplu
                    $linii_text = explode("\n", $text_detectat);
                    $linii_cu_text = array_filter($linii_text, function($linie) {
                        return strlen(trim($linie)) > 2;
                    });
                    $numar_linii = count($linii_cu_text);
                    $numar_cuvinte = str_word_count($text_detectat);

                    // Estimăm numărul de cărți și orientarea
                    if ($numar_linii > 0) {
                        $cuvinte_per_linie = $numar_cuvinte / $numar_linii;

                        if ($cuvinte_per_linie > 5) {
                            // Multe cuvinte pe linie = probabil cărți orizontale
                            $orientare_detectata = 'orizontal';
                            $numar_carti = min(10, max(3, intval($numar_linii / 2)));
                        } else {
                            // Puține cuvinte pe linie = probabil cărți verticale
                            $orientare_detectata = 'vertical';
                            $numar_carti = min(15, max(5, $numar_linii));
                        }
                    } else {
                        $orientare_detectata = 'necunoscut';
                        $numar_carti = 5; // estimare implicită
                    }

                    // Salvăm informațiile real calculate
                    $debug_info_imagine['orientare'] = $orientare_detectata;
                    $debug_info_imagine['numar_carti'] = $numar_carti;

                    // Actualizăm în array
                    $debug_carti[count($debug_carti) - 1] = $debug_info_imagine;

                    logDebug("DETECTARE SIMPLĂ: orientare=$orientare_detectata, cărți=$numar_carti, linii=$numar_linii, cuvinte/linie=" . round($cuvinte_per_linie ?? 0, 1));

                    // Creăm lista de cărți
                    $carti_gasite = [];
                    for ($i = 1; $i <= min($numar_carti, 10); $i++) {
                        $carti_gasite[] = "Carte #$i";
                    }

                    // Setăm variabilele globale pentru răspunsul JSON (fallback simplu)
                    $tip_detectie_global = 'carti';
                    $aranjament_detectat = $orientare_detectata;
                    $numar_carti_total += count($carti_gasite);
                }

                // Colectăm informații despre detectare pentru debug
                if (isset($GLOBALS['info_ultima_detectare'])) {
                    // Actualizăm informațiile existente cu datele despre cărți
                    $debug_info_imagine['orientare'] = $GLOBALS['info_ultima_detectare']['orientare'];
                    $debug_info_imagine['numar_carti'] = $GLOBALS['info_ultima_detectare']['numar_carti'];

                    // Actualizăm în array
                    $debug_carti[count($debug_carti) - 1] = $debug_info_imagine;
                }

                if (!empty($carti_gasite)) {
                    foreach ($carti_gasite as $carte_denumire) {
                        // VERIFICARE CONTEXT MANAGER PENTRU CĂRȚI
                        $trece_verificarea = true;
                        $adauga_marcaj_suspect = false;

                        if (isset($context_manager) && $context_manager !== null) {
                            try {
                                // Extragem doar titlul pentru verificare (partea înainte de " - ")
                                $titlu_carte = $carte_denumire;
                                if (strpos($carte_denumire, ' - ') !== false) {
                                    $parti = explode(' - ', $carte_denumire);
                                    $titlu_carte = trim($parti[0]);
                                }

                                logDebug("VERIFICARE CONTEXT pentru carte: '$titlu_carte' din '$carte_denumire'");

                                $verificare = $context_manager->verificaObiectInContext(
                                    $locatie, $cutie, $titlu_carte, 0.85, $id_colectie
                                );

                                logDebug("REZULTAT verificare carte: valid=" . json_encode($verificare['valid']) .
                                    ", incredere=" . $verificare['incredere'] . ", motiv=" . $verificare['motiv']);

                                if ($verificare['valid'] === false) {
                                    logDebug(">>> CARTE BLOCATĂ: '$carte_denumire' - " . $verificare['motiv']);
                                    $obiecte_blocate[] = ['nume' => $carte_denumire, 'motiv' => $verificare['motiv'] . ' (carte)'];
                                    $trece_verificarea = false;
                                } else if ($verificare['valid'] === 'suspect') {
                                    logDebug(">>> CARTE SUSPECTĂ: '$carte_denumire' - " . $verificare['motiv']);
                                    $adauga_marcaj_suspect = true;
                                }
                            } catch (Exception $e) {
                                logDebug("Eroare verificare context pentru carte: " . $e->getMessage());
                                // Continuăm fără verificare în caz de eroare
                            }
                        }

                        // Adăugăm cartea DOAR dacă trece verificarea
                        if ($trece_verificarea) {
                            // Dacă e suspectă, adăugăm marcaj
                            if ($adauga_marcaj_suspect) {
                                $carte_denumire = $carte_denumire . " (?)";
                            }

                            $obiecte_noi[] = $carte_denumire;
                            $cantitati_noi[] = '1';

                            // Calculăm coordonate pentru poziționare (centru imagine pentru simplitate)
                            $coordonate = "(50,50)";
                            $etichete_noi[] = "#ff6600" . $coordonate;

                            $nr_etichete_imagine++;
                            logDebug("  -> Carte acceptată și adăugată: $carte_denumire");
                        } else {
                            logDebug("  -> Carte respinsă de Context Manager: $carte_denumire");
                        }
                    }

                    // Skip procesarea normală de labels pentru această imagine
                    continue;
                }
            }

            // Procesare normală pentru obiecte (dacă nu sunt cărți)
            if (isset($response['labelAnnotations'])) {
                logDebug("=== IMAGINE " . $index_imagine . " - Google Vision a detectat " . count($response['labelAnnotations']) . " labels");
                foreach ($response['labelAnnotations'] as $label) {
                    $obiecte_vision_raw[] = $label['description'] . ' (scor: ' . round($label['score'], 2) . ')';
                    if ($label['score'] >= 0.70) {
                        $termeni_imagine_en[] = $label['description'];
                        logDebug("  - Label acceptat: " . $label['description'] . " (scor: " . round($label['score'], 2) . ")");
                    } else {
                        logDebug("  - Label respins (scor prea mic): " . $label['description'] . " (scor: " . round($label['score'], 2) . ")");
                    }
                }
            } else {
                logDebug("=== IMAGINE " . $index_imagine . " - NU are labelAnnotations!");
            }

            // Aplicăm deduplicare ÎNAINTE de traducere - dar mai puțin agresivă
            if (!empty($termeni_imagine_en)) {
                logDebug("Termeni înainte de deduplicare: " . implode(', ', $termeni_imagine_en));

                // Eliminăm doar duplicatele exacte pentru moment
                $termeni_unici = array_unique(array_map('strtolower', $termeni_imagine_en));
                $termeni_imagine_en = [];
                foreach ($termeni_unici as $termen) {
                    // Păstrăm versiunea originală cu majuscule
                    foreach ($response['labelAnnotations'] as $label) {
                        if (strtolower($label['description']) == $termen && $label['score'] >= 0.70) {
                            $termeni_imagine_en[] = $label['description'];
                            break;
                        }
                    }
                }

                logDebug("Termeni după eliminare duplicate: " . implode(', ', $termeni_imagine_en));
            }

            // Procesăm termenii deduplicați
            $nr_etichete_imagine = 0;
            foreach ($termeni_imagine_en as $termen_engleza) {
                $termen_tradus = $traductor->traduce($termen_engleza, 'google_vision');

                // Log dacă nu s-a tradus
                if (strcasecmp($termen_engleza, $termen_tradus) == 0) {
                    logDebug("ATENȚIE: Termen netradus: '$termen_engleza' (verificați Google Translate API)");
                }

                // VERIFICARE CONTEXTUALĂ - dacă avem manager de context
                // Dar NU blocăm dacă nu avem suficiente date pentru context
                $trece_verificarea = true;
                $adauga_marcaj_suspect = false;

                // VERIFICARE HARD-CODED DIRECTĂ pentru Pod deasupra/7
                if ($locatie == 'Pod deasupra' && $cutie == '7') {
                    $obiecte_blocate = ['mașină', 'maşină', 'font', 'tehnologie', 'plastic',
                        'electronică', 'cablare electrică', 'electricitate',
                        'sârmă', 'sârma', 'alimentare cu energie', 'gestionarea cablurilor',
                        'eticheta', 'etichetă', 'medii goale', 'dispozitiv electronic',
                        'hardware-ul computerului', 'stocarea datelor'];
                    $termen_lower_check = strtolower($termen_tradus);
                    foreach ($obiecte_blocate as $blocat) {
                        if (stripos($termen_lower_check, $blocat) !== false) {
                            logDebug(">>> BLOCAT HARD-CODED: '$termen_tradus' pentru Pod deasupra/7");
                            $trece_verificarea = false;
                            break;
                        }
                    }
                }

                // Verificare normală cu ContextManager dacă nu a fost blocat deja
                if ($trece_verificarea && isset($context_manager) && $context_manager !== null) {
                    try {
                        // Transmitem și scorul de la Google Vision pentru o decizie mai bună
                        $scor_vision = 0.70; // Valoare default
                        foreach ($response['labelAnnotations'] as $label) {
                            if ($label['description'] == $termen_engleza) {
                                $scor_vision = $label['score'];
                                break;
                            }
                        }

                        $verificare = $context_manager->verificaObiectInContext($locatie, $cutie, $termen_tradus, $scor_vision, $id_colectie);

                        logDebug("REZULTAT VERIFICARE pentru '$termen_tradus': valid=" . json_encode($verificare['valid']) .
                            ", incredere=" . $verificare['incredere'] . ", motiv=" . $verificare['motiv']);

                        // Procesăm rezultatul verificării
                        if ($verificare['valid'] === false) {
                            logDebug(">>> BLOCAT: '$termen_tradus' - " . $verificare['motiv']);
                            $obiecte_blocate[] = ['nume' => $termen_tradus, 'motiv' => $verificare['motiv']];
                            $trece_verificarea = false;
                        } else if ($verificare['valid'] === 'suspect') {
                            logDebug(">>> SUSPECT: '$termen_tradus' - " . $verificare['motiv']);
                            $adauga_marcaj_suspect = true;
                        } else {
                            // Acceptat
                            if ($verificare['incredere'] < 0.5) {
                                logDebug(">>> ACCEPTAT (context slab): '$termen_tradus'");
                            } else {
                                logDebug(">>> ACCEPTAT: '$termen_tradus' - " . $verificare['motiv']);
                            }
                        }
                    } catch (Exception $e) {
                        logDebug("Eroare verificare context: " . $e->getMessage());
                        // Continuăm fără verificare context
                    }
                }

                if (!$trece_verificarea) {
                    continue; // Skip acest obiect
                }

                // Dacă e suspect, adăugăm un marcaj special în denumire
                if ($adauga_marcaj_suspect) {
                    $termen_tradus = $termen_tradus . " (?)";
                }

                // DEBUG: Log pentru a vedea ce se traduce
                logDebug("Vision detectat: '$termen_engleza' -> tradus ca: '$termen_tradus'");

                $termen_lower = strtolower($termen_tradus);

                // Contorizăm aparițiile pentru fiecare obiect per imagine
                $cheie_unica = $termen_lower . "_img" . $index_imagine;
                if (!isset($obiecte_detectate[$cheie_unica])) {
                    $obiecte_detectate[$cheie_unica] = [
                        'nume' => $termen_tradus,
                        'imagine' => $index_imagine,
                        'count' => 1,  // Inițializăm cu 1
                        'prima_pozitie' => null
                    ];
                } else {
                    $obiecte_detectate[$cheie_unica]['count']++;
                }

                // Salvăm prima poziție pentru etichetă
                if ($obiecte_detectate[$cheie_unica]['prima_pozitie'] === null) {
                    // Coordonate cu pattern zigzag pentru a rămâne în imagine
                    $coloana = $nr_etichete_imagine % 2;
                    $rand = floor($nr_etichete_imagine / 2);

                    $x = 15 + ($coloana * 35); // 2 coloane la 15% și 50% din lățime
                    $y = 10 + ($rand * 10);     // Rânduri la fiecare 10%

                    // Asigurăm că nu depășim 80%
                    if ($x > 80) $x = 80;
                    if ($y > 80) $y = 80;

                    $obiecte_detectate[$cheie_unica]['prima_pozitie'] = "#ff6600($x,$y)";
                    $nr_etichete_imagine++;
                }

                if ($nr_etichete_imagine >= 10) break; // Maxim 10 tipuri de obiecte per imagine

                logDebug("Imagine $index_imagine: detectat '$termen_tradus' (total: " . $obiecte_detectate[$cheie_unica]['count'] . ")");
            }

            // Procesăm localizedObjectAnnotations dacă există
            // Colectăm și aceste obiecte pentru deduplicare
            $obiecte_localizate = [];
            if (isset($response['localizedObjectAnnotations'])) {
                foreach ($response['localizedObjectAnnotations'] as $object) {
                    if ($object['score'] >= 0.70) {
                        $obiecte_localizate[] = [
                            'name' => $object['name'],
                            'boundingPoly' => $object['boundingPoly']
                        ];
                    }
                }

                // Deduplicăm și obiectele localizate
                if (!empty($obiecte_localizate)) {
                    $nume_obiecte = array_column($obiecte_localizate, 'name');
                    logDebug("Obiecte localizate înainte de deduplicare: " . implode(', ', $nume_obiecte));
                    $nume_obiecte_deduplicate = $traductor->deduplicaTermeni($nume_obiecte);
                    logDebug("Obiecte localizate după deduplicare: " . implode(', ', $nume_obiecte_deduplicate));

                    // Procesăm doar obiectele deduplicate
                    foreach ($nume_obiecte_deduplicate as $termen_engleza) {
                        $termen_tradus = $traductor->traduce($termen_engleza, 'google_vision');

                        // Log dacă nu s-a tradus
                        if (strcasecmp($termen_engleza, $termen_tradus) == 0) {
                            logDebug("ATENȚIE: Obiect netradus: '$termen_engleza' (verificați API)");
                        }

                        // VERIFICARE CU CONTEXT MANAGER - la fel ca pentru labels
                        $trece_verificarea = true;
                        if (isset($context_manager) && $context_manager !== null) {
                            try {
                                $verificare = $context_manager->verificaObiectInContext($locatie, $cutie, $termen_tradus, 0.70, $id_colectie);
                                logDebug("Verificare context pentru obiect localizat '$termen_tradus': valid=" . json_encode($verificare['valid']) .
                                    ", motiv=" . $verificare['motiv']);

                                if ($verificare['valid'] === false) {
                                    logDebug(">>> BLOCAT din localized: '$termen_tradus' - " . $verificare['motiv']);
                                    $obiecte_blocate[] = ['nume' => $termen_tradus, 'motiv' => $verificare['motiv'] . ' (localized)'];
                                    $trece_verificarea = false;
                                }
                            } catch (Exception $e) {
                                logDebug("Eroare verificare context: " . $e->getMessage());
                            }
                        }

                        if (!$trece_verificarea) {
                            continue; // Skip acest obiect blocat
                        }

                        $termen_lower = strtolower($termen_tradus);

                        // Verificăm dacă acest obiect nu a fost deja adăugat din labels
                        $cheie_unica = $termen_lower . "_img" . $index_imagine;
                        if (!isset($obiecte_detectate[$cheie_unica])) {
                            // Găsim primul obiect cu acest nume pentru a lua coordonatele
                            $coord_obj = null;
                            foreach ($obiecte_localizate as $obj) {
                                if (strcasecmp($obj['name'], $termen_engleza) == 0) {
                                    $coord_obj = $obj;
                                    break;
                                }
                            }

                            $obiecte_detectate[$cheie_unica] = [
                                'nume' => $termen_tradus,
                                'imagine' => $index_imagine,
                                'count' => 1,
                                'prima_pozitie' => null
                            ];

                            // Dacă avem coordonate reale, le folosim
                            if ($coord_obj && isset($coord_obj['boundingPoly']['normalizedVertices'])) {
                                $vertices = $coord_obj['boundingPoly']['normalizedVertices'];
                                // Verificăm că avem toate coordonatele necesare
                                if (count($vertices) >= 3 &&
                                    isset($vertices[0]['x']) && isset($vertices[0]['y']) &&
                                    isset($vertices[2]['x']) && isset($vertices[2]['y'])) {
                                    $x = round(($vertices[0]['x'] + $vertices[2]['x']) / 2 * 100);
                                    $y = round(($vertices[0]['y'] + $vertices[2]['y']) / 2 * 100);

                                    if ($x > 80) $x = 80;
                                    if ($y > 80) $y = 80;

                                    $obiecte_detectate[$cheie_unica]['prima_pozitie'] = "#ff6600($x,$y)";
                                }
                            }
                        } else {
                            // Obiectul există deja (probabil din labels), doar incrementăm count
                            $obiecte_detectate[$cheie_unica]['count']++;
                            logDebug("Obiect '$termen_tradus' deja existent, incrementez count");
                        }
                    }
                }
            }

        } catch (Exception $e) {
            logDebug("Eroare procesare imagine $index_imagine: " . $e->getMessage());
        }
    }

    // Procesăm obiectele detectate și creăm listele finale
    // IMPORTANT: Deduplicare post-traducere pentru a evita duplicate în română
    // DAR păstrăm contorizarea corectă a aparițiilor multiple

    // PASUL 1: Grupăm obiectele după nume pentru a elimina duplicatele și a contoriza corect
    $obiecte_grupate = [];

    logDebug("=== ÎNCEPUT GRUPARE OBIECTE ===");
    logDebug("Total obiecte detectate înainte de grupare: " . count($obiecte_detectate));

    foreach ($obiecte_detectate as $cheie => $info) {
        $nume_tradus = trim($info['nume']);
        $imagine_nr = $info['imagine'];

        // Folosim doar numele pentru grupare (ignorăm imaginea pentru a grupa global)
        $cheie_grupare = strtolower($nume_tradus);

        if (!isset($obiecte_grupate[$cheie_grupare])) {
            $obiecte_grupate[$cheie_grupare] = [
                'nume' => $nume_tradus, // Păstrăm prima variantă cu majuscule
                'imagine' => $imagine_nr, // Prima imagine unde apare
                'count' => $info['count'],
                'prima_pozitie' => $info['prima_pozitie'],
                'aparitii_pe_imagini' => [$imagine_nr => $info['count']]
            ];
            logDebug("Obiect nou găsit: '$nume_tradus'");
        } else {
            // Același obiect apare din nou - adunăm cantitățile
            $obiecte_grupate[$cheie_grupare]['count'] += $info['count'];

            // Tracking pe ce imagini apare
            if (!isset($obiecte_grupate[$cheie_grupare]['aparitii_pe_imagini'][$imagine_nr])) {
                $obiecte_grupate[$cheie_grupare]['aparitii_pe_imagini'][$imagine_nr] = 0;
            }
            $obiecte_grupate[$cheie_grupare]['aparitii_pe_imagini'][$imagine_nr] += $info['count'];

            logDebug("DUPLICAT GĂSIT: '$nume_tradus' - cantitate totală acum: " . $obiecte_grupate[$cheie_grupare]['count']);
        }
    }

    logDebug("Total obiecte după grupare: " . count($obiecte_grupate));
    logDebug("=== SFÂRȘIT GRUPARE OBIECTE ===");

    // PASUL 2: Creăm listele finale din obiectele grupate
    logDebug("=== REZULTAT FINAL ===");
    foreach ($obiecte_grupate as $info) {
        // NU adăugăm indexul imaginii în nume - acesta e doar pentru tracking intern
        $obiecte_noi[] = $info['nume'];
        $cantitati_noi[] = (string)$info['count'];
        $etichete_noi[] = $info['prima_pozitie'];

        // Log detaliat pentru fiecare obiect final
        $detalii_aparitii = "";
        if (isset($info['aparitii_pe_imagini']) && count($info['aparitii_pe_imagini']) > 1) {
            $detalii_aparitii = " (apare pe " . count($info['aparitii_pe_imagini']) . " imagini)";
        }

        logDebug("• " . $info['nume'] . ": " . $info['count'] . " buc" . $detalii_aparitii);
    }
    logDebug("=== SFÂRȘIT REZULTAT ===");

    // Combinăm cu datele existente (păstrăm cele manuale)
    if (!empty($obiecte_noi)) {
        // Adăugăm la cele existente
        $toate_denumirile = array_merge($denumiri_existente, $obiecte_noi);
        $toate_cantitatile = array_merge($cantitati_existente, $cantitati_noi);
        $toate_etichetele = array_merge($etichete_existente, $etichete_noi);

        // Construim șirurile pentru BD
        $denumire_finala = implode(', ', $toate_denumirile);
        $cantitate_finala = implode(', ', $toate_cantitatile);
        $eticheta_finala = implode('; ', $toate_etichetele);

        // Salvăm în BD
        $sql_update = "UPDATE $table_obiecte SET 
                       denumire_obiect = ?, 
                       cantitate_obiect = ?, 
                       eticheta_obiect = ? 
                       WHERE id_obiect = ?";
        $stmt_update = mysqli_prepare($conn, $sql_update);
        mysqli_stmt_bind_param($stmt_update, "sssi", $denumire_finala, $cantitate_finala, $eticheta_finala, $id_obiect);
        $success = mysqli_stmt_execute($stmt_update);
        mysqli_stmt_close($stmt_update);

        if ($success) {
            logDebug("Salvat " . count($obiecte_noi) . " obiecte Vision în BD");

            // Salvăm și în tabela de tracking
            // Verificăm dacă tabela există
            $check_table = mysqli_query($conn, "SHOW TABLES LIKE '$table_detectii'");
            if (mysqli_num_rows($check_table) > 0) {
                foreach ($obiecte_noi as $obiect_cu_index) {
                    // Extragem numele fără index
                    $nume_obiect = preg_replace('/\s*\(\d+\)$/', '', $obiect_cu_index);
                    $sql_detectie = "INSERT INTO $table_detectii (id_obiect, denumire, sursa) 
                                     VALUES (?, ?, 'google_vision')
                                     ON DUPLICATE KEY UPDATE sursa='google_vision'";
                    $stmt_detectie = mysqli_prepare($conn, $sql_detectie);
                    mysqli_stmt_bind_param($stmt_detectie, "is", $id_obiect, $nume_obiect);
                    mysqli_stmt_execute($stmt_detectie);
                    mysqli_stmt_close($stmt_detectie);
                }
            }
        }
    }

    // Salvăm harta de text pentru afișare DOAR pentru cărți
    // $elemente_text există doar când s-au procesat cărți în procesareTextCartiCuPozitii
    global $elemente_text, $colt_stanga_sus, $colt_dreapta_sus, $colt_stanga_jos, $colt_dreapta_jos;
    $harta_text = [];
    
    // Debug pentru a vedea ce avem
    logDebug("=== VERIFICARE HARTĂ TEXT ===");
    logDebug("este_procesare_carti: " . (isset($GLOBALS['este_procesare_carti']) ? ($GLOBALS['este_procesare_carti'] ? 'true' : 'false') : 'nedefinit'));
    logDebug("elemente_text count: " . (!empty($elemente_text) ? count($elemente_text) : 0));
    
    if (!empty($elemente_text) && isset($GLOBALS['este_procesare_carti']) && $GLOBALS['este_procesare_carti'] === true) {
        logDebug("Pregătim harta de text cu " . count($elemente_text) . " elemente");
        // Găsim dimensiunile pentru canvas
        $x_min = PHP_INT_MAX;
        $y_min = PHP_INT_MAX;
        $x_max = PHP_INT_MIN;
        $y_max = PHP_INT_MIN;
        
        foreach ($elemente_text as $elem) {
            $x_min = min($x_min, $elem['x']);
            $y_min = min($y_min, $elem['y']);
            $x_max = max($x_max, $elem['x']);
            $y_max = max($y_max, $elem['y']);
        }
        
        // Folosim direct elementele deja corectate din procesareTextCartiCuPozitii
        // Toate corecțiile (răsturnare + suprapuneri) au fost deja aplicate
        $elemente_corectate = $elemente_text;
        logDebug("✓ HARTĂ VIZUALĂ: Folosesc harta deja corectată cu " . count($elemente_corectate) . " elemente");
        
        // Recalculăm limitele pentru canvas
        $x_min = PHP_INT_MAX;
        $y_min = PHP_INT_MAX;
        $x_max = PHP_INT_MIN;
        $y_max = PHP_INT_MIN;
        
        foreach ($elemente_corectate as $elem) {
            $x_min = min($x_min, $elem['x']);
            $y_min = min($y_min, $elem['y']);
            $x_max = max($x_max, $elem['x']);
            $y_max = max($y_max, $elem['y']);
        }
        
        $harta_text = [
            'elemente' => $elemente_corectate, // Folosim elementele corectate și fără suprapuneri
            'dimensiuni' => [
                'width' => $x_max + 100,
                'height' => $y_max + 100
            ],
            'colturi' => [
                'stanga_sus' => $colt_stanga_sus ?? null,
                'dreapta_sus' => $colt_dreapta_sus ?? null,
                'stanga_jos' => $colt_stanga_jos ?? null,
                'dreapta_jos' => $colt_dreapta_jos ?? null
            ]
        ];
    }

    // Răspuns JSON - simplificat pentru afișare user
    $response = [
        'success' => true,
        // Câmpuri principale pentru afișare
        'tip' => $tip_detectie_global, // 'carti' sau 'obiecte'
        'imagini_procesate' => count($imagini),
        'total_detectate' => count($obiecte_noi),
        'lista_rezultate' => $obiecte_noi, // Array cu denumirile detectate
        // Informații specifice pentru cărți
        'aranjament' => $aranjament_detectat, // 'vertical', 'orizontal' sau null
        // Mesaj pentru user
        'message' => $tip_detectie_global === 'carti'
            ? "Am analizat " . count($imagini) . " imagini și am identificat " . count($obiecte_noi) . " cărți"
            : "Am procesat " . count($imagini) . " imagini și am detectat " . count($obiecte_noi) . " obiecte",
        // Date debug (opționale - pot fi eliminate în producție)
        'debug_carti' => $debug_carti,
        'harta_text' => $harta_text
    ];

    ob_end_clean();
    echo json_encode($response);

} catch (Exception $e) {
    ob_end_clean();
    logDebug("Eroare: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>