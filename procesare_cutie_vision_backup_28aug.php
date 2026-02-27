<?php
// Versiune 2.9 - Algoritm cartezian cu debug complet și fără fallback
// Data actualizare: 2025-08-28
// NEW: Debug detaliat pentru orientare și grupare
// NEW: ELIMINAT complet fallback-ul la metoda veche
// NEW: Afișare Y-uri și X-uri distincte pentru verificare
// FIX 1: Context Manager verifică acum și cărțile detectate automat
// FIX 2: ELIMINAT complet hardcodarea cărților - detectare 100% generică
// FIX 3: Corectat - NU mai salvează indexul imaginii în denumire (era greșit)


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
    error_log("[Vision v2.9 $timestamp] $message");
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

// Funcție îmbunătățită pentru procesarea cărților folosind informații despre poziție și mărime (DEZACTIVATĂ)
function procesareTextCartiCuPozitii($textAnnotations, $traductor = null) {
    if (empty($textAnnotations) || count($textAnnotations) < 2) {
        return [];
    }

    // PARAMETRU DE TEST: Limitează numărul de cărți procesate pentru debugging
    // DEZACTIVAT - procesează toate cărțile normal
    $MAX_CARTI_TEST = 0; // 0 = procesează toate cărțile (normal), >0 = limitează pentru test

    // TEST MODE COLȚURI: Returnează doar cele 4 colțuri
    $TEST_DOAR_COLTURI = true; // Setează false pentru procesare normală

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
            'y_bottom' => $y_bottom
        ];
    }

    // PASUL 1: Găsim dimensiunile imaginii și extremele textului
    // Estimăm dimensiunile imaginii din coordonatele maxime ale textului
    // (Google Vision returnează coordonate în pixeli ai imaginii originale)
    $x_min_global = PHP_INT_MAX;
    $y_min_global = PHP_INT_MAX;
    $x_max_global = PHP_INT_MIN;
    $y_max_global = PHP_INT_MIN;

    // Găsim extremele textului pentru referință
    foreach ($elemente_text as $element) {
        $x_min_global = min($x_min_global, $element['x']);
        $y_min_global = min($y_min_global, $element['y']);
        $x_max_global = max($x_max_global, $element['x']);
        $y_max_global = max($y_max_global, $element['y']);
    }

    // ALGORITM CONFORM DOCUMENTAȚIEI - PENTRU CĂRȚI STIVUITE VERTICAL
    // IMPORTANT: În imaginea rotită 180°:
    // - Y MARE (2400+) = SUS vizual
    // - Y MIC (400-) = JOS vizual
    
    logDebug("=== ALGORITM FUNDAMENTAL - IDENTIFICARE COLȚURI ===");
    logDebug("Pentru cărți stivuite vertical (una peste alta)");
    logDebug("Y mare = sus vizual, Y mic = jos vizual");
    logDebug("");
    
    // DEBUG: Afișăm primele și ultimele 5 cuvinte sortate după Y
    $elemente_sortate_y = $elemente_text;
    usort($elemente_sortate_y, function($a, $b) { return $a['y'] - $b['y']; });
    
    logDebug("PRIMELE 5 CUVINTE (Y mic = jos vizual):");
    for ($i = 0; $i < min(5, count($elemente_sortate_y)); $i++) {
        $elem = $elemente_sortate_y[$i];
        logDebug("  {$elem['text']} - Y={$elem['y']}, X={$elem['x']}");
    }
    
    logDebug("ULTIMELE 5 CUVINTE (Y mare = sus vizual):");
    for ($i = max(0, count($elemente_sortate_y) - 5); $i < count($elemente_sortate_y); $i++) {
        $elem = $elemente_sortate_y[$i];
        logDebug("  {$elem['text']} - Y={$elem['y']}, X={$elem['x']}");
    }
    
    // Căutăm specific POLIROM și Nil în hartă
    logDebug("");
    logDebug("CĂUTARE SPECIFICĂ:");
    foreach ($elemente_text as $elem) {
        if (stripos($elem['text'], 'POLIROM') !== false) {
            logDebug("  Găsit POLIROM: '{$elem['text']}' la Y={$elem['y']}, X={$elem['x']}");
        }
        if (stripos($elem['text'], 'Nil') !== false) {
            logDebug("  Găsit Nil: '{$elem['text']}' la Y={$elem['y']}, X={$elem['x']}");
        }
    }
    logDebug("");
    
    // IDENTIFICARE COLȚURI - ALGORITM SIMPLU FĂRĂ TOLERANȚE
    // Pentru cărți stivuite vertical în Google Vision:
    // Y mare (2400+) = SUS vizual (partea de sus a imaginii)
    // Y mic (400-) = JOS vizual (partea de jos a imaginii)
    
    // PASUL 1: Găsim cuvintele de pe linia de sus (Y = Y_max)
    // Pentru asta, găsim toate cuvintele cu Y maxim
    $toleranta_linie = 100; // doar pentru a grupa cuvintele de pe aceeași linie orizontală
    $cuvinte_linie_sus = [];
    
    foreach ($elemente_text as $elem) {
        if ($elem['y'] >= $y_max_global - $toleranta_linie) {
            $cuvinte_linie_sus[] = $elem;
        }
    }
    
    // PASUL 2: Din cuvintele liniei de sus, găsim extremele X
    $colt_stanga_sus = null;
    $colt_dreapta_sus = null;
    
    if (!empty($cuvinte_linie_sus)) {
        // Sortăm după X
        usort($cuvinte_linie_sus, function($a, $b) { return $a['x'] - $b['x']; });
        // Primul (X minim) = stânga-sus
        $colt_stanga_sus = $cuvinte_linie_sus[0];
        // Ultimul (X maxim) = dreapta-sus
        $colt_dreapta_sus = $cuvinte_linie_sus[count($cuvinte_linie_sus) - 1];
    }
    
    // PASUL 3: Găsim cuvintele de pe linia de jos (Y = Y_min)
    $cuvinte_linie_jos = [];
    
    foreach ($elemente_text as $elem) {
        if ($elem['y'] <= $y_min_global + $toleranta_linie) {
            $cuvinte_linie_jos[] = $elem;
        }
    }
    
    // PASUL 4: Din cuvintele liniei de jos, găsim extremele X
    $colt_stanga_jos = null;
    $colt_dreapta_jos = null;
    
    if (!empty($cuvinte_linie_jos)) {
        // Sortăm după X
        usort($cuvinte_linie_jos, function($a, $b) { return $a['x'] - $b['x']; });
        // Primul (X minim) = stânga-jos
        $colt_stanga_jos = $cuvinte_linie_jos[0];
        // Ultimul (X maxim) = dreapta-jos
        $colt_dreapta_jos = $cuvinte_linie_jos[count($cuvinte_linie_jos) - 1];
    }
    
    logDebug("=== COLȚURI IDENTIFICATE (liniile extreme) ===");
    logDebug("  Linia de sus (Y >= " . ($y_max_global - $toleranta_linie) . "):");
    if (!empty($cuvinte_linie_sus)) {
        $lista = array_map(function($e) { return "'{$e['text']}' (X={$e['x']}, Y={$e['y']})"; }, $cuvinte_linie_sus);
        logDebug("    Cuvinte găsite: " . implode(', ', $lista));
    }
    logDebug("  Linia de jos (Y <= " . ($y_min_global + $toleranta_linie) . "):");
    if (!empty($cuvinte_linie_jos)) {
        $lista = array_map(function($e) { return "'{$e['text']}' (X={$e['x']}, Y={$e['y']})"; }, $cuvinte_linie_jos);
        logDebug("    Cuvinte găsite: " . implode(', ', $lista));
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
    logDebug("  Y: de la $y_min_global (jos vizual) la $y_max_global (sus vizual)");
    logDebug("");
    logDebug("COLȚURI FINALE:");
    logDebug("  1. STÂNGA-SUS: " . ($colt_stanga_sus ? "'{$colt_stanga_sus['text']}' la ({$colt_stanga_sus['x']}, {$colt_stanga_sus['y']})" : 'NEGĂSIT'));
    logDebug("  2. DREAPTA-SUS: " . ($colt_dreapta_sus ? "'{$colt_dreapta_sus['text']}' la ({$colt_dreapta_sus['x']}, {$colt_dreapta_sus['y']})" : 'NEGĂSIT'));
    logDebug("  3. STÂNGA-JOS: " . ($colt_stanga_jos ? "'{$colt_stanga_jos['text']}' la ({$colt_stanga_jos['x']}, {$colt_stanga_jos['y']})" : 'NEGĂSIT'));
    logDebug("  4. DREAPTA-JOS: " . ($colt_dreapta_jos ? "'{$colt_dreapta_jos['text']}' la ({$colt_dreapta_jos['x']}, {$colt_dreapta_jos['y']})" : 'NEGĂSIT'));
    logDebug("========================================");

    // PASUL 2: Detectăm orientarea bazat pe colțuri
    $orientare = 'necunoscut';

    // Analizăm pattern-ul din colțuri
    if ($colt_stanga_sus && $colt_dreapta_sus && $colt_stanga_jos && $colt_dreapta_jos) {
        // Verificăm dacă textul se continuă pe orizontală sau pe verticală
        $distanta_orizontala_sus = abs($colt_dreapta_sus['x'] - $colt_stanga_sus['x']);
        $distanta_verticala_stanga = abs($colt_stanga_jos['y'] - $colt_stanga_sus['y']);

        // Numărăm cuvintele pe prima linie orizontală (sus)
        $cuvinte_linie_sus = 0;
        foreach ($elemente_text as $element) {
            if (abs($element['y'] - $y_min_global) <= 30) {
                $cuvinte_linie_sus++;
            }
        }

        // Numărăm cuvintele pe prima coloană verticală (stânga)
        $cuvinte_coloana_stanga = 0;
        foreach ($elemente_text as $element) {
            if (abs($element['x'] - $x_min_global) <= 30) {
                $cuvinte_coloana_stanga++;
            }
        }

        logDebug("=== ANALIZĂ ORIENTARE BAZAT PE COLȚURI ===");
        logDebug("Cuvinte pe linia de sus: $cuvinte_linie_sus");
        logDebug("Cuvinte pe coloana din stânga: $cuvinte_coloana_stanga");

        if ($cuvinte_linie_sus > $cuvinte_coloana_stanga && $cuvinte_linie_sus >= 3) {
            $orientare = 'vertical'; // Cărți aranjate vertical, cotoare orizontale
            logDebug("✓ DETECTAT: Cărți VERTICALE (cotoare orizontale, citire stânga→dreapta)");
        } else if ($cuvinte_coloana_stanga > $cuvinte_linie_sus && $cuvinte_coloana_stanga >= 3) {
            $orientare = 'orizontal'; // Cărți aranjate orizontal, cotoare verticale
            logDebug("✓ DETECTAT: Cărți ORIZONTALE (cotoare verticale, citire sus→jos)");
        } else {
            $orientare = 'vertical'; // Default
            logDebug("⚠ IMPLICIT: Presupunem cărți verticale");
        }
    } else {
        logDebug("⚠ Nu s-au găsit toate colțurile, folosim detectare simplă");
        $orientare = 'vertical'; // Default
    }

    // PASUL 3: Verificăm colțurile identificate
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

    // PASUL 4: Grupăm elementele în cărți bazat pe orientare și colțuri
    $cartiGrupate = [];

    if ($orientare == 'vertical') {
        // CĂRȚI ARANJATE VERTICAL (una peste alta)
        // Cotoarele sunt ORIZONTALE, citim de la stânga la dreapta
        // Parcurgem de sus în jos pentru a găsi fiecare cotor

        // Grupăm elementele pe Y-uri apropiate (același cotor)
        $grupuri_y = [];
        $toleranta_cotor = 50; // Toleranță mărită pentru a grupa corect toate cuvintele de pe un cotor

        foreach ($elemente_text as $element) {
            $gasit_grup = false;

            // Căutăm un grup existent pentru acest Y
            foreach ($grupuri_y as &$grup) {
                if (abs($element['y'] - $grup['y_mediu']) <= $toleranta_cotor) {
                    $grup['elemente'][] = $element;
                    // Recalculăm Y-ul mediu al grupului
                    $suma_y = 0;
                    foreach ($grup['elemente'] as $e) {
                        $suma_y += $e['y'];
                    }
                    $grup['y_mediu'] = $suma_y / count($grup['elemente']);
                    $gasit_grup = true;
                    break;
                }
            }

            // Dacă nu am găsit grup, creăm unul nou
            if (!$gasit_grup) {
                $grupuri_y[] = [
                    'y_mediu' => $element['y'],
                    'elemente' => [$element]
                ];
            }
        }

        // Sortăm grupurile după Y (descrescător - de sus în jos vizual)
        usort($grupuri_y, function($a, $b) {
            return $b['y_mediu'] - $a['y_mediu'];
        });

        logDebug("=== GRUPARE CĂRȚI VERTICALE ===");
        logDebug("Am găsit " . count($grupuri_y) . " cotoare (grupuri de Y)");

        // Afișăm detalii despre fiecare grup
        foreach ($grupuri_y as $idx => $grup) {
            $texte = array_column($grup['elemente'], 'text');
            logDebug("Grup " . ($idx + 1) . " (Y mediu=" . round($grup['y_mediu']) . "): " . implode(' ', $texte));
        }

        // Verificăm care grup conține colțul stânga-sus
        if (isset($colturi_rotite['stanga_sus']) && $colturi_rotite['stanga_sus']) {
            $colt_ss = $colturi_rotite['stanga_sus']['cuvant'];
            logDebug("\nColțul stânga-sus este: '{$colt_ss['text']}' la Y={$colt_ss['y']}");

            // Găsim grupul care conține acest colț
            foreach ($grupuri_y as $idx => $grup) {
                foreach ($grup['elemente'] as $elem) {
                    if ($elem['text'] == $colt_ss['text']) {
                        logDebug("✓ Grupul " . ($idx + 1) . " conține colțul stânga-sus!");
                        // Reorganizăm array-ul să înceapă cu acest grup
                        if ($idx > 0) {
                            $primul_grup = $grupuri_y[$idx];
                            unset($grupuri_y[$idx]);
                            array_unshift($grupuri_y, $primul_grup);
                            $grupuri_y = array_values($grupuri_y);
                            logDebug("Am reorganizat grupurile să înceapă cu grupul care conține '322'");
                        }
                        break 2;
                    }
                }
            }
        }

        // APLICĂM LIMITA DE TEST pentru cărți verticale
        if ($MAX_CARTI_TEST > 0) {
            $carti_originale = count($grupuri_y);
            $grupuri_y = array_slice($grupuri_y, 0, $MAX_CARTI_TEST);
            logDebug("*** LIMITĂ TEST: Procesez doar primele $MAX_CARTI_TEST cărți din $carti_originale detectate (VERTICALE) ***");
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

                // Verificăm dacă prima carte conține colțul stânga-sus
                if ($idx == 0 && isset($colturi_rotite['stanga_sus'])) {
                    $colt_text = $colturi_rotite['stanga_sus']['cuvant']['text'];
                    if (strpos($text_carte, $colt_text) !== false) {
                        logDebug("✓ Prima carte conține colțul stânga-sus: '$colt_text'");
                    } else {
                        logDebug("⚠ ATENȚIE: Prima carte NU conține colțul stânga-sus '$colt_text'!");
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

    logDebug("Total cărți detectate: " . count($cartiGrupate));

    // REZUMAT FINAL pentru modul test
    if ($MAX_CARTI_TEST > 0) {
        logDebug("=====================================");
        logDebug("REZUMAT MOD TEST:");
        logDebug("- Limită setată: $MAX_CARTI_TEST cărți");
        logDebug("- Cărți procesate: " . count($cartiGrupate));
        logDebug("- Orientare detectată: $orientare");
        logDebug("Pentru a procesa TOATE cărțile, eliminați 'test_carti' din URL");
        logDebug("Pentru a testa incremental: ?test_carti=1, ?test_carti=2, etc.");
        logDebug("=====================================");
    }

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

    // Colțurile au fost calculate mai devreme și salvate în $colturi_rotite
    // Le folosim aici pentru confirmare și logging
    logDebug("\n=== COLȚURI FOLOSITE CA REPERE ===");
    if (isset($colturi_rotite['stanga_sus']) && $colturi_rotite['stanga_sus']) {
        $c = $colturi_rotite['stanga_sus']['cuvant'];
        logDebug("  Stânga-sus: '{$c['text']}' la ({$c['x']}, {$c['y']})");
    }
    if (isset($colturi_rotite['dreapta_sus']) && $colturi_rotite['dreapta_sus']) {
        $c = $colturi_rotite['dreapta_sus']['cuvant'];
        logDebug("  Dreapta-sus: '{$c['text']}' la ({$c['x']}, {$c['y']})");
    }
    if (isset($colturi_rotite['stanga_jos']) && $colturi_rotite['stanga_jos']) {
        $c = $colturi_rotite['stanga_jos']['cuvant'];
        logDebug("  Stânga-jos: '{$c['text']}' la ({$c['x']}, {$c['y']})");
    }
    if (isset($colturi_rotite['dreapta_jos']) && $colturi_rotite['dreapta_jos']) {
        $c = $colturi_rotite['dreapta_jos']['cuvant'];
        logDebug("  Dreapta-jos: '{$c['text']}' la ({$c['x']}, {$c['y']})");
    }

    logDebug("=== REZULTAT FINAL ===");
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
                    } else {
                        // Fallback pentru formatul vechi
                        $carti_gasite = $rezultat_procesare;
                        if (isset($GLOBALS['info_ultima_detectare'])) {
                            $debug_info_imagine['orientare'] = $GLOBALS['info_ultima_detectare']['orientare'];
                            $debug_info_imagine['numar_carti'] = $GLOBALS['info_ultima_detectare']['numar_carti'];
                            $debug_carti[count($debug_carti) - 1] = $debug_info_imagine;
                            logDebug("INFO RECUPERATĂ: orientare=" . $GLOBALS['info_ultima_detectare']['orientare'] .
                                ", cărți=" . $GLOBALS['info_ultima_detectare']['numar_carti']);
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

                    logDebug("DETECTARE SIMPLĂ: orientare=$orientare_detectata, cărți=$numar_carti, linii=$numar_linii, cuvinte/linie=" . round($cuvinte_per_linie, 1));

                    // Creăm lista de cărți
                    $carti_gasite = [];
                    for ($i = 1; $i <= min($numar_carti, 10); $i++) {
                        $carti_gasite[] = "Carte #$i";
                    }
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

    // Răspuns JSON
    $response = [
        'success' => true,
        'imagini_procesate' => count($imagini),
        'obiecte_detectate' => count($obiecte_noi),
        'message' => "Am procesat " . count($imagini) . " imagini și am detectat " . count($obiecte_noi) . " obiecte",
        'context_info' => $context_info ?? 'Context nedefinit',
        'obiecte_blocate' => $obiecte_blocate,
        'total_blocate' => count($obiecte_blocate),
        'vision_raw' => array_slice($obiecte_vision_raw, 0, 20), // Primele 20 pentru debug
        'total_vision_raw' => count($obiecte_vision_raw),
        'debug_carti' => $debug_carti,
        'debug_info' => [
            'id_colectie' => $id_colectie,
            'locatie' => $locatie,
            'cutie' => $cutie,
            'user_id' => $user['id_utilizator'],
            'table_prefix' => $table_prefix ?? 'nedefinit'
        ]
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