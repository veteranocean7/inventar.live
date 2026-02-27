<?php
/**
 * Clasă pentru gestionarea contextului - DOAR CLASA, fără interfață HTML
 */
class ContextManager {
    private $conn;
    private $conn_central;
    
    public function __construct($conn, $conn_central) {
        $this->conn = $conn;
        $this->conn_central = $conn_central;
        $this->initializeTables();
    }
    
    private function initializeTables() {
        // Verificăm dacă tabelele există deja
        $check1 = mysqli_query($this->conn_central, "SHOW TABLES LIKE 'context_locatii'");
        $check2 = mysqli_query($this->conn_central, "SHOW TABLES LIKE 'context_patterns'");
        
        if (mysqli_num_rows($check1) == 0) {
            $sql = "CREATE TABLE IF NOT EXISTS context_locatii (
                id INT AUTO_INCREMENT PRIMARY KEY,
                locatie VARCHAR(255) NOT NULL,
                cutie VARCHAR(255) NOT NULL,
                tip_context VARCHAR(100),
                obiecte_comune TEXT,
                obiecte_excluse TEXT,
                modificatori_acceptati TEXT,
                incredere FLOAT DEFAULT 0.5,
                scor_minim_vision FLOAT DEFAULT 0.7,
                numar_exemple INT DEFAULT 0,
                ultima_actualizare TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_locatie_cutie (locatie, cutie)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            mysqli_query($this->conn_central, $sql);
        }
        
        if (mysqli_num_rows($check2) == 0) {
            $sql = "CREATE TABLE IF NOT EXISTS context_patterns (
                id INT AUTO_INCREMENT PRIMARY KEY,
                pattern_nume VARCHAR(100) UNIQUE,
                obiecte_tipice TEXT,
                obiecte_incompatibile TEXT,
                descriere TEXT,
                INDEX idx_pattern (pattern_nume)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            mysqli_query($this->conn_central, $sql);
            $this->initializePatterns();
        }
    }
    
    private function initializePatterns() {
        $patterns = [
            [
                'nume' => 'atelier',
                'tipice' => 'ciocan,șurubelniță,patent,cheie,piuliță,șurub,burghiu,pilă,fierăstrău,cleşte,nivelă,ruletă,creion,marker,cablu,sârmă,bandă,adeziv',
                'incompatibile' => 'balenă,ocean,palmier,elefant,girafă',
                'descriere' => 'Unelte și materiale pentru lucru manual'
            ],
            [
                'nume' => 'bucătărie',
                'tipice' => 'farfurie,ceașcă,pahar,lingură,furculiță,cuțit,oală,tigaie,castron,tavă,cană,ibric,mixer,blender,prăjitor',
                'incompatibile' => 'ciocan,șurubelniță',
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
            if ($stmt) {
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
    }
    
    public function verificaObiectInContext($locatie, $cutie, $obiect, $scor_incredere = 0.7, $id_colectie = null) {
        $sql = "SELECT * FROM context_locatii 
                WHERE locatie = ? AND cutie = ? AND (id_colectie = ? OR id_colectie IS NULL)
                ORDER BY (id_colectie IS NOT NULL) DESC, incredere DESC 
                LIMIT 1";
        
        $stmt = mysqli_prepare($this->conn_central, $sql);
        mysqli_stmt_bind_param($stmt, "ssi", $locatie, $cutie, $id_colectie);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if ($context = mysqli_fetch_assoc($result)) {
            // Mai întâi verificăm dacă obiectul a fost exclus anterior (șters de utilizator)
            if (!empty($context['obiecte_excluse'])) {
                $obiecte_excluse = explode(',', $context['obiecte_excluse']);
                $obiect_lower = strtolower($obiect);
                
                foreach ($obiecte_excluse as $exclus) {
                    if (stripos($obiect_lower, trim($exclus)) !== false) {
                        return [
                            'valid' => false,
                            'incredere' => 0.1,
                            'motiv' => 'Obiect exclus anterior de utilizator'
                        ];
                    }
                }
            }
            
            $obiecte_comune = explode(',', $context['obiecte_comune']);
            $obiect_lower = strtolower($obiect);
            
            // Verificăm dacă obiectul e similar cu cele din context
            $gasit_similar = false;
            foreach ($obiecte_comune as $obiect_comun) {
                $obiect_comun_clean = strtolower(trim($obiect_comun));
                // Eliminăm numărul din paranteză pentru comparație
                $obiect_comun_clean = preg_replace('/\s*\(\d+\)$/', '', $obiect_comun_clean);
                
                // Verificare exactă sau similaritate
                if ($obiect_lower == $obiect_comun_clean) {
                    return [
                        'valid' => true,
                        'incredere' => $context['incredere'],
                        'motiv' => 'Obiect cunoscut în acest context'
                    ];
                }
                
                similar_text($obiect_lower, $obiect_comun_clean, $percent);
                if ($percent > 70) { // Prag mai strict
                    $gasit_similar = true;
                }
            }
            
            if ($gasit_similar) {
                return [
                    'valid' => true,
                    'incredere' => $context['incredere'] * 0.9,
                    'motiv' => 'Similar cu obiectele din context'
                ];
            }
            
            // Detectăm modificatori care schimbă contextul (jucării, miniaturi etc)
            $modificatori_ok = ['jucărie', 'miniatură', 'model', 'logo', 'desen', 'tablou', 
                               'poster', 'carte', 'broșură', 'figurină', 'machetă', 'puzzle',
                               'toy', 'miniature', 'model', 'drawing', 'book', 'figure'];
            
            foreach ($modificatori_ok as $modificator) {
                if (stripos($obiect_lower, $modificator) !== false) {
                    return [
                        'valid' => true,
                        'incredere' => $context['incredere'] * 0.8,
                        'motiv' => "Reprezentare/jucărie acceptată în context"
                    ];
                }
            }
            
            // Dacă nu am găsit obiectul în cele comune, e suspect
            // DAR nu-l respingem complet - poate e ceva nou valid
            if (!$gasit_similar && $context['incredere'] > 0.7) {
                // Context solid cu multe exemple - obiectul e suspect
                return [
                    'valid' => 'suspect', 
                    'incredere' => 0.3,
                    'motiv' => 'Obiect necunoscut în acest context stabilit'
                ];
            }
            
            // Verificăm incompatibilitățile
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
                            if ($scor_incredere > 0.85) {
                                return [
                                    'valid' => true,
                                    'incredere' => $context['incredere'] * 0.5,
                                    'motiv' => 'Obiect neobișnuit dar detectat cu încredere mare'
                                ];
                            }
                            
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
    
    // Restul metodelor vor fi adăugate din creator_context.php când e nevoie
}
?>