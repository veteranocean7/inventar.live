<?php
/**
 * Sistem de învățare contextuală pentru Google Vision
 * Analizează obiectele existente și creează contexte pentru fiecare locație/cutie
 * Rulează periodic (cron job) pentru a învăța din corecțiile utilizatorilor
 */

// Verificăm dacă sesiunea este deja pornită
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'config.php';
require_once 'includes/auth_functions.php';

// Acest script poate rula și din cron
$is_cron = (php_sapi_name() === 'cli');

if (!$is_cron) {
    $user = checkSession();
    if (!$user || $user['id_utilizator'] != 1) { // Doar admin
        die("Acces restricționat");
    }
}

/**
 * Clasă pentru gestionarea contextului
 */
class ContextManager {
    private $conn;
    private $conn_central;
    
    public function __construct($conn, $conn_central) {
        $this->conn = $conn;
        $this->conn_central = $conn_central;
        $this->initializeTables();
    }
    
    /**
     * Creează tabelele necesare dacă nu există
     */
    private function initializeTables() {
        // Tabelă pentru contexte învățate
        $sql = "CREATE TABLE IF NOT EXISTS context_locatii (
            id INT AUTO_INCREMENT PRIMARY KEY,
            locatie VARCHAR(255) NOT NULL,
            cutie VARCHAR(255) NOT NULL,
            tip_context VARCHAR(100),
            obiecte_comune TEXT,
            obiecte_excluse TEXT,
            incredere FLOAT DEFAULT 0.5,
            numar_exemple INT DEFAULT 0,
            ultima_actualizare TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_locatie_cutie (locatie, cutie)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        mysqli_query($this->conn_central, $sql);
        
        // Tabelă pentru pattern-uri învățate
        $sql = "CREATE TABLE IF NOT EXISTS context_patterns (
            id INT AUTO_INCREMENT PRIMARY KEY,
            pattern_nume VARCHAR(100) UNIQUE,
            obiecte_tipice TEXT,
            obiecte_incompatibile TEXT,
            descriere TEXT,
            INDEX idx_pattern (pattern_nume)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        mysqli_query($this->conn_central, $sql);
        
        // Populăm cu pattern-uri inițiale
        $this->initializePatterns();
    }
    
    /**
     * Inițializează pattern-uri comune
     */
    private function initializePatterns() {
        $patterns = [
            [
                'nume' => 'atelier',
                'tipice' => 'ciocan,șurubelniță,patent,cheie,piuliță,șurub,burghiu,pilă,fierăstrău,cleşte,nivelă,ruletă,creion,marker,cablu,sârmă,bandă,adeziv',
                'incompatibile' => 'balenă,ocean,palmier,elefant,girafă,avion,navă,tren',
                'descriere' => 'Unelte și materiale pentru lucru manual'
            ],
            [
                'nume' => 'bucătărie',
                'tipice' => 'farfurie,ceașcă,pahar,lingură,furculiță,cuțit,oală,tigaie,castron,tavă,cană,ibric,mixer,blender,prăjitor',
                'incompatibile' => 'ciocan,șurubelniță,laptop,mouse,tastatură,monitor',
                'descriere' => 'Obiecte de bucătărie și ustensile'
            ],
            [
                'nume' => 'birou',
                'tipice' => 'laptop,calculator,mouse,tastatură,monitor,creion,pix,hârtie,dosar,capsator,perforator,marker,notițe,agendă,calendar',
                'incompatibile' => 'ciocan,farfurie,oală,tigaie',
                'descriere' => 'Obiecte de birou și papetărie'
            ],
            [
                'nume' => 'garaj',
                'tipice' => 'mașină,roată,anvelopă,ulei,antigel,cheie,cricul,trusă,cablu,lanțuri,pompă,bujie,filtru,baterie',
                'incompatibile' => 'farfurie,ceașcă,laptop,caiet',
                'descriere' => 'Piese auto și unelte pentru mașină'
            ],
            [
                'nume' => 'dormitor',
                'tipice' => 'pernă,pătură,cearșaf,haină,tricou,pantalon,șosete,curea,geantă,rucsac,încălțăminte,parfum',
                'incompatibile' => 'ciocan,șurubelniță,ulei motor,anvelopă',
                'descriere' => 'Îmbrăcăminte și textile'
            ],
            [
                'nume' => 'baie',
                'tipice' => 'săpun,șampon,pastă dinți,periuță,prosop,hârtie igienică,detergent,burete,perie,uscător',
                'incompatibile' => 'laptop,ciocan,farfurie,mașină',
                'descriere' => 'Produse de igienă și curățenie'
            ]
        ];
        
        foreach ($patterns as $pattern) {
            $sql = "INSERT IGNORE INTO context_patterns 
                    (pattern_nume, obiecte_tipice, obiecte_incompatibile, descriere) 
                    VALUES (?, ?, ?, ?)";
            $stmt = mysqli_prepare($this->conn_central, $sql);
            mysqli_stmt_bind_param($stmt, "ssss", 
                $pattern['nume'], 
                $pattern['tipice'], 
                $pattern['incompatibile'], 
                $pattern['descriere']
            );
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }
    
    /**
     * Analizează toate bazele de date ale utilizatorilor pentru a învăța contexte
     */
    public function analizeazaToateContextele($is_cron = false) {
        echo "=== ANALIZĂ CONTEXTE - " . date('Y-m-d H:i:s') . " ===\n";
        
        $total_contexte = 0;
        $users_to_process = [];
        
        if ($is_cron) {
            // Mod CRON - procesăm toți utilizatorii activi
            $sql = "SELECT * FROM utilizatori WHERE activ = 1";
            $result = mysqli_query($this->conn_central, $sql);
            while ($u = mysqli_fetch_assoc($result)) {
                $users_to_process[] = $u;
            }
            echo "Mod CRON: procesez " . count($users_to_process) . " utilizatori activi\n";
        } else {
            // Mod MANUAL - doar utilizatorul curent
            $user = checkSession();
            if (!$user) {
                echo "Eroare: Nu pot obține sesiunea utilizatorului\n";
                return 0;
            }
            $users_to_process[] = $user;
        }
        
        // Procesăm fiecare utilizator
        foreach ($users_to_process as $user) {
            echo "\nAnalizez utilizator #" . $user['id_utilizator'] . " (" . $user['nume'] . ")...\n";
        
        // Conectăm la baza de date a utilizatorului
        // Pentru sistemul multi-tenant, folosim funcția corectă
        $conn_user = getUserDbConnection($user['db_name']);
        if (!$conn_user) {
            echo "Eroare: Nu pot conecta la baza de date a utilizatorului\n";
            return 0;
        }
        
        mysqli_set_charset($conn_user, "utf8mb4");
        
        // Obținem toate colecțiile utilizatorului
        $sql_colectii = "SELECT * FROM colectii_utilizatori WHERE id_utilizator = ?";
        $stmt = mysqli_prepare($this->conn_central, $sql_colectii);
        mysqli_stmt_bind_param($stmt, "i", $user['id_utilizator']);
        mysqli_stmt_execute($stmt);
        $result_colectii = mysqli_stmt_get_result($stmt);
        
        while ($colectie = mysqli_fetch_assoc($result_colectii)) {
            $table_prefix = $colectie['prefix_tabele'];
            $table_obiecte = $table_prefix . 'obiecte';
            
            // Verificăm dacă tabela există
            $check = mysqli_query($conn_user, "SHOW TABLES LIKE '$table_obiecte'");
            if (mysqli_num_rows($check) == 0) continue;
            
            // Analizăm obiectele grupate pe locație și cutie
            // IMPORTANT: denumire_obiect conține mai multe obiecte separate prin virgulă
            $sql_analiza = "SELECT 
                locatie, 
                cutie,
                GROUP_CONCAT(denumire_obiect SEPARATOR ', ') as obiecte
                FROM $table_obiecte
                WHERE locatie IS NOT NULL AND cutie IS NOT NULL
                GROUP BY locatie, cutie";
            
            $result_analiza = mysqli_query($conn_user, $sql_analiza);
            
            while ($row = mysqli_fetch_assoc($result_analiza)) {
                // Numărăm obiectele reale din string-ul concatenat
                $obiecte_array = $this->parseazaObiecte($row['obiecte']);
                $nr_obiecte_real = count($obiecte_array);
                
                // Procesăm doar dacă avem minim 3 obiecte
                if ($nr_obiecte_real >= 3) {
                    echo "   → " . $row['locatie'] . " / " . $row['cutie'] . 
                         " (" . $nr_obiecte_real . " obiecte detectate)\n";
                    
                    $context = $this->analizeazaContext(
                        $row['locatie'], 
                        $row['cutie'], 
                        $row['obiecte']
                    );
                    
                    if ($context) {
                        $this->salveazaContext($context);
                        $total_contexte++;
                    }
                }
            }
        }
        
            mysqli_close($conn_user);
        } // închid foreach pentru users_to_process
        
        echo "\n=== FINALIZAT: $total_contexte contexte analizate ===\n";
        
        return $total_contexte;
    }
    
    /**
     * Analizează un set de obiecte pentru a determina contextul
     */
    private function analizeazaContext($locatie, $cutie, $obiecte_string) {
        // Extragem și curățăm obiectele
        $obiecte = $this->parseazaObiecte($obiecte_string);
        if (count($obiecte) < 3) return null; // Minim 3 obiecte pentru context valid
        
        // Determinăm tipul de context bazat pe cuvinte cheie
        $tip_context = $this->detecteazaTipContext($locatie, $cutie, $obiecte);
        
        // Filtrăm obiectele pentru a păstra doar cele relevante
        $obiecte_relevante = $this->filtreazaObiecteRelevante($obiecte);
        
        return [
            'locatie' => $locatie,
            'cutie' => $cutie,
            'tip_context' => $tip_context,
            'obiecte_comune' => $obiecte_relevante,
            'numar_exemple' => count($obiecte)
        ];
    }
    
    /**
     * Parsează și curăță lista de obiecte
     */
    private function parseazaObiecte($obiecte_string) {
        $obiecte = [];
        
        // Separăm pe virgulă și curățăm
        $parts = explode(',', $obiecte_string);
        
        foreach ($parts as $part) {
            // Eliminăm indexul (1), (2) etc și spațiile
            $obiect = preg_replace('/\s*\(\d+\)\s*/', '', trim($part));
            
            if (!empty($obiect) && strlen($obiect) > 2) {
                $obiecte[] = strtolower($obiect);
            }
        }
        
        return array_unique($obiecte);
    }
    
    /**
     * Detectează tipul de context bazat pe locație și obiecte
     */
    private function detecteazaTipContext($locatie, $cutie, $obiecte) {
        $locatie_lower = strtolower($locatie);
        $obiecte_text = implode(' ', $obiecte);
        
        // Verificăm pattern-urile cunoscute
        $sql = "SELECT pattern_nume, obiecte_tipice FROM context_patterns";
        $result = mysqli_query($this->conn_central, $sql);
        
        $best_match = null;
        $best_score = 0;
        
        while ($pattern = mysqli_fetch_assoc($result)) {
            $score = 0;
            
            // Verificăm dacă locația conține pattern-ul
            if (strpos($locatie_lower, $pattern['pattern_nume']) !== false) {
                $score += 10;
            }
            
            // Verificăm câte obiecte tipice găsim
            $obiecte_tipice = explode(',', $pattern['obiecte_tipice']);
            foreach ($obiecte_tipice as $obiect_tipic) {
                if (strpos($obiecte_text, trim($obiect_tipic)) !== false) {
                    $score++;
                }
            }
            
            if ($score > $best_score) {
                $best_score = $score;
                $best_match = $pattern['pattern_nume'];
            }
        }
        
        return $best_match ?: 'general';
    }
    
    /**
     * Filtrează obiectele pentru a păstra doar cele relevante
     */
    private function filtreazaObiecteRelevante($obiecte) {
        // Eliminăm termeni prea generici
        $termeni_generici = ['obiect', 'lucru', 'articol', 'item', 'produs', 'material'];
        
        $obiecte_filtrate = [];
        foreach ($obiecte as $obiect) {
            $este_generic = false;
            
            foreach ($termeni_generici as $termen) {
                if (strpos($obiect, $termen) !== false) {
                    $este_generic = true;
                    break;
                }
            }
            
            if (!$este_generic) {
                $obiecte_filtrate[] = $obiect;
            }
        }
        
        // Păstrăm maximum 20 de obiecte reprezentative
        return array_slice($obiecte_filtrate, 0, 20);
    }
    
    /**
     * Salvează contextul în baza de date
     */
    private function salveazaContext($context) {
        $obiecte_comune = implode(',', $context['obiecte_comune']);
        
        // Obținem id_colectie din sesiune sau din context
        $id_colectie = $context['id_colectie'] ?? 
                      $_SESSION['id_colectie_curenta'] ?? 
                      $_SESSION['id_colectie_selectata'] ?? 
                      null;
        
        $sql = "INSERT INTO context_locatii 
                (locatie, cutie, id_colectie, tip_context, obiecte_comune, numar_exemple, incredere) 
                VALUES (?, ?, ?, ?, ?, ?, 0.5)
                ON DUPLICATE KEY UPDATE
                    obiecte_comune = VALUES(obiecte_comune),
                    numar_exemple = numar_exemple + VALUES(numar_exemple),
                    incredere = LEAST(1.0, incredere + 0.1),
                    ultima_actualizare = CURRENT_TIMESTAMP";
        
        $stmt = mysqli_prepare($this->conn_central, $sql);
        mysqli_stmt_bind_param($stmt, "ssissi", 
            $context['locatie'],
            $context['cutie'],
            $id_colectie,
            $context['tip_context'],
            $obiecte_comune,
            $context['numar_exemple']
        );
        
        $success = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        
        if ($success) {
            echo "  ✓ Context salvat: " . $context['locatie'] . "/" . $context['cutie'] . 
                 " (tip: " . $context['tip_context'] . ")\n";
        }
        
        return $success;
    }
    
    /**
     * Verifică dacă un obiect se potrivește cu contextul
     */
    public function verificaObiectInContext($locatie, $cutie, $obiect, $scor_incredere = 0.7) {
        // Căutăm contextul pentru locație/cutie
        $sql = "SELECT * FROM context_locatii 
                WHERE locatie = ? AND cutie = ? 
                ORDER BY incredere DESC 
                LIMIT 1";
        
        $stmt = mysqli_prepare($this->conn_central, $sql);
        mysqli_stmt_bind_param($stmt, "ss", $locatie, $cutie);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if ($context = mysqli_fetch_assoc($result)) {
            $obiecte_comune = explode(',', $context['obiecte_comune']);
            $obiect_lower = strtolower($obiect);
            
            // Verificăm dacă obiectul e similar cu cele din context
            foreach ($obiecte_comune as $obiect_comun) {
                $similaritate = similar_text($obiect_lower, trim($obiect_comun), $percent);
                if ($percent > 60) {
                    return [
                        'valid' => true,
                        'incredere' => $context['incredere'],
                        'motiv' => 'Similar cu obiectele din acest context'
                    ];
                }
            }
            
            // VERIFICARE INTELIGENTĂ: Detectăm modificatori care schimbă contextul
            $modificatori_ok = ['jucărie', 'miniatură', 'model', 'logo', 'desen', 'tablou', 
                               'poster', 'carte', 'broșură', 'figurină', 'machetă', 'puzzle',
                               'toy', 'miniature', 'model', 'drawing', 'book', 'figure'];
            
            foreach ($modificatori_ok as $modificator) {
                if (stripos($obiect_lower, $modificator) !== false) {
                    // E ok - e o reprezentare, nu obiectul real
                    return [
                        'valid' => true,
                        'incredere' => $context['incredere'] * 0.8, // Puțin mai puțină încredere
                        'motiv' => "Reprezentare/jucărie acceptată în context"
                    ];
                }
            }
            
            // Verificăm dacă e în lista de excluse pentru tipul de context
            if ($context['tip_context']) {
                $sql_pattern = "SELECT obiecte_incompatibile FROM context_patterns 
                               WHERE pattern_nume = ?";
                $stmt2 = mysqli_prepare($this->conn_central, $sql_pattern);
                mysqli_stmt_bind_param($stmt2, "s", $context['tip_context']);
                mysqli_stmt_execute($stmt2);
                $result2 = mysqli_stmt_get_result($stmt2);
                
                if ($pattern = mysqli_fetch_assoc($result2)) {
                    $obiecte_incompatibile = explode(',', $pattern['obiecte_incompatibile']);
                    
                    foreach ($obiecte_incompatibile as $incompatibil) {
                        if (strpos($obiect_lower, trim($incompatibil)) !== false) {
                            // VERIFICARE SUPLIMENTARĂ: E doar suspect, nu imposibil
                            // Dacă scorul de încredere de la Vision e foarte mare, acceptăm
                            if ($scor_incredere > 0.85) {
                                return [
                                    'valid' => true,
                                    'incredere' => $context['incredere'] * 0.5,
                                    'motiv' => 'Obiect neobișnuit dar detectat cu încredere mare'
                                ];
                            }
                            
                            // Altfel, marcăm ca suspect dar nu respingem complet
                            return [
                                'valid' => 'suspect',
                                'incredere' => $context['incredere'] * 0.3,
                                'motiv' => 'Obiect neobișnuit pentru contextul ' . $context['tip_context']
                            ];
                        }
                    }
                }
            }
        }
        
        // Dacă nu avem context, acceptăm cu încredere moderată
        return [
            'valid' => true,
            'incredere' => 0.5,
            'motiv' => 'Context necunoscut - acceptat implicit'
        ];
    }
}

// Execuție
$conn_central = getCentralDbConnection();
$manager = new ContextManager($conn, $conn_central);

if ($is_cron || isset($_GET['run'])) {
    // Rulare analiză - cron procesează toți, manual doar utilizatorul curent
    $rezultat = $manager->analizeazaToateContextele($is_cron);
    
    if (!$is_cron) {
        echo "<pre>";
        echo "Analiză completă!\n";
        echo "Contexte procesate: $rezultat\n";
        echo "</pre>";
    }
} else {
    // Interfață web pentru admin
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Manager Contexte - Inventar.live</title>
        <style>
            body { font-family: Arial, sans-serif; padding: 20px; }
            .container { max-width: 1200px; margin: 0 auto; }
            h1 { color: #667eea; }
            .btn { 
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                padding: 10px 20px;
                border: none;
                border-radius: 5px;
                cursor: pointer;
                text-decoration: none;
                display: inline-block;
            }
            table { width: 100%; border-collapse: collapse; margin-top: 20px; }
            th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
            th { background: #f5f5f5; }
            .tag { 
                display: inline-block;
                padding: 3px 8px;
                margin: 2px;
                background: #e0e0e0;
                border-radius: 3px;
                font-size: 12px;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>🧠 Manager Contexte Inteligente</h1>
            
            <p>Sistemul analizează obiectele din toate colecțiile pentru a învăța contexte și a îmbunătăți detectarea Google Vision.</p>
            
            <a href="?run=1" class="btn">▶ Rulează Analiza Acum</a>
            
            <h2>Contexte Învățate</h2>
            <table>
                <thead>
                    <tr>
                        <th>Locație</th>
                        <th>Cutie</th>
                        <th>Tip Context</th>
                        <th>Obiecte Comune</th>
                        <th>Încredere</th>
                        <th>Ultima Actualizare</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $sql = "SELECT * FROM context_locatii ORDER BY ultima_actualizare DESC LIMIT 50";
                    $result = mysqli_query($conn_central, $sql);
                    
                    while ($row = mysqli_fetch_assoc($result)) {
                        $obiecte = explode(',', $row['obiecte_comune']);
                        $obiecte_html = '';
                        foreach (array_slice($obiecte, 0, 5) as $obiect) {
                            $obiecte_html .= '<span class="tag">' . htmlspecialchars($obiect) . '</span>';
                        }
                        if (count($obiecte) > 5) {
                            $obiecte_html .= '<span class="tag">+' . (count($obiecte) - 5) . ' altele</span>';
                        }
                        
                        $incredere_procent = round($row['incredere'] * 100);
                        $incredere_color = $incredere_procent > 70 ? 'green' : ($incredere_procent > 40 ? 'orange' : 'red');
                        
                        echo "<tr>";
                        echo "<td>" . htmlspecialchars($row['locatie']) . "</td>";
                        echo "<td>" . htmlspecialchars($row['cutie']) . "</td>";
                        echo "<td><strong>" . htmlspecialchars($row['tip_context']) . "</strong></td>";
                        echo "<td>$obiecte_html</td>";
                        echo "<td><span style='color: $incredere_color'>$incredere_procent%</span></td>";
                        echo "<td>" . $row['ultima_actualizare'] . "</td>";
                        echo "</tr>";
                    }
                    ?>
                </tbody>
            </table>
            
            <h2>Pattern-uri Definite</h2>
            <table>
                <thead>
                    <tr>
                        <th>Pattern</th>
                        <th>Descriere</th>
                        <th>Obiecte Tipice</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $sql = "SELECT * FROM context_patterns ORDER BY pattern_nume";
                    $result = mysqli_query($conn_central, $sql);
                    
                    while ($row = mysqli_fetch_assoc($result)) {
                        echo "<tr>";
                        echo "<td><strong>" . htmlspecialchars($row['pattern_nume']) . "</strong></td>";
                        echo "<td>" . htmlspecialchars($row['descriere']) . "</td>";
                        echo "<td>" . htmlspecialchars(substr($row['obiecte_tipice'], 0, 100)) . "...</td>";
                        echo "</tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </body>
    </html>
    <?php
}

mysqli_close($conn_central);
?>