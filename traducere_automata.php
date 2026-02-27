<?php
/**
 * Sistem de traducere automată cu cache persistent
 * Pentru Google Vision labels și alte texte
 */

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once 'config.php';
require_once 'includes/auth_functions.php';

// Include configurația pentru API-urile Google
if (file_exists('api_GV_config.php')) {
    require_once 'api_GV_config.php';
}

class TraducereAutomata {
    private $conn;
    private $cache_table = 'traduceri_cache';
    private $api_key;
    
    public function __construct($conn) {
        $this->conn = $conn;
        // Citește API key din fișier sau config
        $this->api_key = $this->getGoogleTranslateApiKey();
        $this->initializeCacheTable();
    }
    
    /**
     * Inițializează tabela de cache dacă nu există
     */
    private function initializeCacheTable() {
        $sql = "CREATE TABLE IF NOT EXISTS {$this->cache_table} (
            id INT AUTO_INCREMENT PRIMARY KEY,
            text_original VARCHAR(500) NOT NULL UNIQUE,
            text_tradus VARCHAR(500) NOT NULL,
            limba_sursa VARCHAR(10) DEFAULT 'en',
            limba_destinatie VARCHAR(10) DEFAULT 'ro',
            context VARCHAR(100) DEFAULT NULL,
            confidence FLOAT DEFAULT 1.0,
            sursa_traducere ENUM('google_translate', 'dictionar_local', 'manual') DEFAULT 'google_translate',
            data_adaugare TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            data_ultima_folosire TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            numar_folosiri INT DEFAULT 1,
            INDEX idx_text (text_original),
            INDEX idx_context (context),
            INDEX idx_folosire (data_ultima_folosire)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        mysqli_query($this->conn, $sql);
    }
    
    /**
     * Obține API key pentru Google Translate
     */
    private function getGoogleTranslateApiKey() {
        // Folosim același Service Account ca pentru Vision API
        // Fișierul google-vision-key.json funcționează pentru ambele servicii
        if (file_exists('google-vision-key.json')) {
            return $this->getServiceAccountAccessToken();
        }
        
        // Fallback - verifică dacă avem API key definit manual
        if (defined('GOOGLE_TRANSLATE_API_KEY') && GOOGLE_TRANSLATE_API_KEY !== 'YOUR_API_KEY_HERE') {
            return GOOGLE_TRANSLATE_API_KEY;
        }
        
        // Fallback - returnează null și vom folosi dicționarul local
        return null;
    }
    
    /**
     * Obține Access Token folosind Service Account din google-vision-key.json
     * Acest token funcționează atât pentru Vision cât și pentru Translate API
     */
    private function getServiceAccountAccessToken() {
        $keyFilePath = 'google-vision-key.json';
        if (!file_exists($keyFilePath)) {
            return null;
        }
        
        $keyData = json_decode(file_get_contents($keyFilePath), true);
        
        // JWT Header
        $header = [
            'alg' => 'RS256',
            'typ' => 'JWT'
        ];
        
        // JWT Claim Set - include scope pentru Translation API
        $now = time();
        $claim = [
            'iss' => $keyData['client_email'],
            'scope' => 'https://www.googleapis.com/auth/cloud-translation',
            'aud' => 'https://oauth2.googleapis.com/token',
            'exp' => $now + 3600,
            'iat' => $now
        ];
        
        // Encode JWT
        $encodedHeader = rtrim(strtr(base64_encode(json_encode($header)), '+/', '-_'), '=');
        $encodedClaim = rtrim(strtr(base64_encode(json_encode($claim)), '+/', '-_'), '=');
        $jwt = $encodedHeader . '.' . $encodedClaim;
        
        // Sign JWT
        $privateKey = openssl_pkey_get_private($keyData['private_key']);
        if (!$privateKey) {
            return null;
        }
        
        $signature = '';
        openssl_sign($jwt, $signature, $privateKey, 'sha256');
        $encodedSignature = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
        $jwt .= '.' . $encodedSignature;
        
        // Request access token
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt
        ]));
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        $result = json_decode($response, true);
        return $result['access_token'] ?? null;
    }
    
    /**
     * Traduce un text folosind cache-ul sau API-ul
     */
    public function traduce($text, $context = null, $limba_sursa = 'en', $limba_destinatie = 'ro') {
        if (empty($text)) return '';
        
        // Normalizează textul pentru cache
        $text_normalized = strtolower(trim($text));
        
        // 1. Verifică în cache
        $traducere = $this->getCachedTranslation($text_normalized, $context);
        if ($traducere !== false) {
            $this->updateUsageStats($text_normalized);
            return $traducere;
        }
        
        // 2. Folosește Google Translate API dacă e disponibil (PRIORITAR)
        if ($this->api_key) {
            $traducere = $this->googleTranslate($text, $limba_sursa, $limba_destinatie);
            if ($traducere !== false) {
                $this->saveToCache($text_normalized, $traducere, 'google_translate', $context);
                return $traducere;
            }
        }
        
        // 3. Verifică în dicționarul local doar ca FALLBACK
        $traducere = $this->checkLocalDictionary($text);
        if ($traducere !== false) {
            $this->saveToCache($text_normalized, $traducere, 'dictionar_local', $context);
            return $traducere;
        }
        
        // 4. Fallback - încearcă traducere bazată pe cuvinte cunoscute
        $traducere = $this->fallbackTranslation($text);
        if ($traducere !== $text) {
            $this->saveToCache($text_normalized, $traducere, 'dictionar_local', $context);
            return $traducere;
        }
        
        // 5. Returnează textul original dacă nu poate fi tradus
        return $text;
    }
    
    /**
     * Obține traducerea din cache
     */
    private function getCachedTranslation($text, $context = null) {
        $sql = "SELECT text_tradus FROM {$this->cache_table} 
                WHERE text_original = ? 
                AND (context = ? OR (context IS NULL AND ? IS NULL))
                LIMIT 1";
        
        $stmt = mysqli_prepare($this->conn, $sql);
        mysqli_stmt_bind_param($stmt, "sss", $text, $context, $context);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if ($row = mysqli_fetch_assoc($result)) {
            mysqli_stmt_close($stmt);
            return $row['text_tradus'];
        }
        
        mysqli_stmt_close($stmt);
        return false;
    }
    
    /**
     * Actualizează statisticile de utilizare
     */
    private function updateUsageStats($text) {
        $sql = "UPDATE {$this->cache_table} 
                SET numar_folosiri = numar_folosiri + 1,
                    data_ultima_folosire = CURRENT_TIMESTAMP
                WHERE text_original = ?";
        
        $stmt = mysqli_prepare($this->conn, $sql);
        mysqli_stmt_bind_param($stmt, "s", $text);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
    
    /**
     * Salvează traducerea în cache
     */
    private function saveToCache($text_original, $text_tradus, $sursa = 'google_translate', $context = null, $confidence = 1.0) {
        $sql = "INSERT INTO {$this->cache_table} 
                (text_original, text_tradus, context, sursa_traducere, confidence) 
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    text_tradus = VALUES(text_tradus),
                    sursa_traducere = VALUES(sursa_traducere),
                    confidence = VALUES(confidence),
                    numar_folosiri = numar_folosiri + 1";
        
        $stmt = mysqli_prepare($this->conn, $sql);
        mysqli_stmt_bind_param($stmt, "ssssd", $text_original, $text_tradus, $context, $sursa, $confidence);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
    
    /**
     * Apelează Google Translate API
     */
    private function googleTranslate($text, $source = 'en', $target = 'ro') {
        if (!$this->api_key) return false;
        
        // Verificăm dacă avem access token (Service Account) sau API key
        $isAccessToken = strlen($this->api_key) > 100; // Access tokens sunt mai lungi
        
        $url = 'https://translation.googleapis.com/language/translate/v2';
        
        if ($isAccessToken) {
            // Folosim Bearer token pentru Service Account
            $requestBody = [
                'q' => $text,
                'source' => $source,
                'target' => $target,
                'format' => 'text'
            ];
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestBody));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $this->api_key,
                'Content-Type: application/json'
            ]);
        } else {
            // Folosim API key tradițional (dacă e definit manual)
            $params = [
                'key' => $this->api_key,
                'q' => $text,
                'source' => $source,
                'target' => $target,
                'format' => 'text'
            ];
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($params));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        }
        
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            $result = json_decode($response, true);
            if (isset($result['data']['translations'][0]['translatedText'])) {
                return $result['data']['translations'][0]['translatedText'];
            }
        }
        
        // Log error pentru debugging (doar dacă e activat debug mode)
        if ($httpCode !== 200 && defined('VISION_DEBUG_MODE') && VISION_DEBUG_MODE) {
            error_log('[Translate API Error] HTTP ' . $httpCode . ': ' . $response);
        }
        
        return false;
    }
    
    /**
     * Verifică în dicționarul local existent
     */
    private function checkLocalDictionary($text) {
        // Include dicționarul din procesare_cutie_vision.php
        $text_lower = strtolower(trim($text));
        
        // Dicționar de fraze
        $phraseDictionary = [
            'electrical cable' => 'cablu electric',
            'electric wiring' => 'cablaj electric',
            'electric supply' => 'alimentare electrică',
            'power cable' => 'cablu de alimentare',
            'electronic device' => 'dispozitiv electronic',
            'computer mouse' => 'mouse de computer',
            'mobile phone' => 'telefon mobil',
            'hand tool' => 'unealtă manuală',
            'power tool' => 'unealtă electrică',
            'office supplies' => 'rechizite de birou',
            'storage box' => 'cutie de depozitare',
            'plastic box' => 'cutie de plastic',
            'land vehicle' => 'vehicul terestru',
            'motor vehicle' => 'vehicul cu motor',
            'mini cooper' => 'Mini Cooper',
            'luggage and bags' => 'bagaje și genți',
            'luggage' => 'bagaj',
            'home appliance' => 'electrocasnic',
            'major appliance' => 'electrocasnic mare',
            'bag' => 'geantă',
            'belt' => 'curea',
            // Adaugă mai multe după necesitate
        ];
        
        if (isset($phraseDictionary[$text_lower])) {
            return $phraseDictionary[$text_lower];
        }
        
        // Dicționar de cuvinte simple - are prioritate față de Google Translate
        $wordDictionary = [
            'box' => 'cutie',
            'cable' => 'cablu',
            'wire' => 'fir',
            'tool' => 'unealtă',
            'device' => 'dispozitiv',
            'plastic' => 'plastic',
            'metal' => 'metalic',
            'electronic' => 'electronic',
            'electrical' => 'electric',
            'computer' => 'computer',
            'mouse' => 'mouse',
            'keyboard' => 'tastatură',
            'phone' => 'telefon',
            'pen' => 'pix',
            'pencil' => 'creion',
            'paper' => 'hârtie',
            'car' => 'mașină',
            'vehicle' => 'vehicul',
            'tire' => 'anvelopă',
            'tyre' => 'anvelopă',
            'wheel' => 'roată',
            'motor' => 'motor',
            'land' => 'terestru',
            'teacup' => 'ceașcă de ceai',
            'saucer' => 'farfurioară',
            'cup' => 'ceașcă',
            'mug' => 'cană',
            'coffee' => 'cafea',
            'tea' => 'ceai',
            'tableware' => 'veselă',
            'drinkware' => 'pahare și căni',
            'serveware' => 'veselă de servit',
            'dishware' => 'veselă',
            'coffee cup' => 'ceașcă de cafea',
            'automotive' => 'auto',
            'automobile' => 'automobil',
            'palm' => 'palmier',
            'tree' => 'copac',
            'plant' => 'plantă',
            // Adaugă mai multe după necesitate
        ];
        
        if (isset($wordDictionary[$text_lower])) {
            return $wordDictionary[$text_lower];
        }
        
        return false;
    }
    
    /**
     * Traducere fallback bazată pe cuvinte cunoscute
     */
    private function fallbackTranslation($text) {
        $words = explode(' ', strtolower($text));
        $translated = [];
        $hasTranslation = false;
        
        foreach ($words as $word) {
            $wordTranslation = $this->checkLocalDictionary($word);
            if ($wordTranslation !== false) {
                $translated[] = $wordTranslation;
                $hasTranslation = true;
            } else {
                $translated[] = $word;
            }
        }
        
        return $hasTranslation ? implode(' ', $translated) : $text;
    }
    
    /**
     * Deduplică și grupează termeni similari cu logică ierarhică părinte-componentă
     */
    public function deduplicaTermeni($termeni) {
        if (empty($termeni)) return [];
        
        // Normalizăm toți termenii pentru analiză
        $termeniNormalizati = array_map(function($t) {
            return strtolower(trim($t));
        }, $termeni);
        
        // NOUĂ LOGICĂ: Deduplicare contextuală bazată pe ierarhii obiect-componentă
        $ierarhii = $this->getIerarhiiObiectComponente();
        
        // Identificăm obiectele principale și componentele lor
        $obiecte_principale = [];
        $componente_de_exclus = [];
        
        foreach ($ierarhii as $obiect_principal => $componente) {
            if (in_array(strtolower($obiect_principal), $termeniNormalizati)) {
                // Am găsit un obiect principal
                $obiecte_principale[] = $obiect_principal;
                
                // Marcăm componentele sale pentru excludere
                foreach ($componente as $componenta) {
                    $componente_de_exclus[] = strtolower($componenta);
                }
            }
        }
        
        // Dacă am găsit obiecte principale, filtrăm componentele
        if (!empty($obiecte_principale)) {
            $termeniFiltrati = [];
            
            foreach ($termeni as $index => $termen) {
                $termen_lower = $termeniNormalizati[$index];
                
                // Verificăm dacă e componentă
                $este_componenta = false;
                foreach ($componente_de_exclus as $componenta) {
                    if ($termen_lower == $componenta || strpos($termen_lower, $componenta) !== false) {
                        $este_componenta = true;
                        break;
                    }
                }
                
                // Păstrăm doar dacă NU e componentă
                if (!$este_componenta) {
                    $termeniFiltrati[] = $termen;
                }
            }
            
            // Dacă după filtrare avem prea puține obiecte, păstrăm lista originală
            if (count($termeniFiltrati) < count($termeni) / 3) {
                // Am filtrat prea mult, folosim abordarea mai conservatoare
                $termeni = $termeni;
            } else {
                $termeni = $termeniFiltrati;
            }
        }
        
        // Lista COMPLETĂ de termeni auto care trebuie excluși dacă apare cu un vehicul
        $toateTermeniiAuto = [
            // Vehicule generice
            'land vehicle', 'motor vehicle', 'vehicle', 
            'vehicul terestru', 'vehicul cu motor', 'vehicul',
            // Părți mecanice
            'wheel', 'tire', 'automotive wheel system',
            'roată', 'anvelopă', 'sistem roată auto',
            // Caroserie și exterior
            'automotive exterior', 'hardtop', 'fender', 'hubcap', 'bumper',
            'exterior auto', 'acoperiș rigid', 'aripă', 'capac de roată', 'bară de protecție',
            // Alte părți
            'door', 'window', 'hood', 'trunk', 'automotive lighting',
            'ușă', 'geam', 'capotă', 'portbagaj', 'iluminat auto'
        ];
        
        // Verificăm dacă avem termeni auto în listă
        $avemTermeniAuto = false;
        foreach ($termeniNormalizati as $termen) {
            if (in_array($termen, $toateTermeniiAuto)) {
                $avemTermeniAuto = true;
                break;
            }
        }
        
        // Dacă avem termeni auto dar nu am găsit un vehicul principal,
        // filtrăm să păstrăm doar cel mai generic/important
        if ($avemTermeniAuto) {
            // Ierarhie pentru termenii auto (când nu avem "car")
            $ierarhieAuto = [
                'motor vehicle' => 10,
                'vehicul cu motor' => 10,
                'land vehicle' => 9,
                'vehicul terestru' => 9,
                'vehicle' => 8,
                'vehicul' => 8,
                'automotive exterior' => 7,
                'exterior auto' => 7
            ];
            
            $celMaiImportant = null;
            $scorMaxim = -1;
            
            foreach ($termeniNormalizati as $index => $termen) {
                if (isset($ierarhieAuto[$termen]) && $ierarhieAuto[$termen] > $scorMaxim) {
                    $scorMaxim = $ierarhieAuto[$termen];
                    $celMaiImportant = $termeni[$index];
                }
            }
            
            if ($celMaiImportant) {
                return [$celMaiImportant];
            }
        }
        
        // Analizăm semantic termenii pentru a detecta relații părinte-componentă
        // Trimitem termenii normalizați (lowercase) pentru comparație
        $relatiiDetectate = $this->analizeazaRelatiiSemanticeDinamic($termeniNormalizati);
        
        // Aplicăm relațiile detectate pentru a filtra componentele
        $termeniFiltrati = [];
        $componenteDeExclus = [];
        $parintiGasiti = [];
        
        // Identificăm componentele de exclus și părinții găsiți
        foreach ($relatiiDetectate as $parinte => $componente) {
            if (in_array($parinte, $termeniNormalizati)) {
                // Componentele sunt deja lowercase din analizeazaRelatiiSemanticeDinamic
                $componenteDeExclus = array_merge($componenteDeExclus, $componente);
                $parintiGasiti[] = $parinte;
            }
        }
        
        // Dacă avem mai mulți părinți din aceeași categorie, păstrăm doar cel mai specific
        if (count($parintiGasiti) > 1) {
            // Definim ierarhia de specificitate pentru vehicule
            $ierarhieSpecificitate = [
                // Mai specific -> mai generic
                'mini cooper' => 10,
                'car' => 9,
                'automobile' => 8,
                'motor vehicle' => 7,
                'land vehicle' => 6,
                'vehicle' => 5,
                'mașină' => 9,
                'automobil' => 8,
                'autoturism' => 9,
                'vehicul cu motor' => 7,
                'vehicul terestru' => 6,
                'vehicul' => 5,
            ];
            
            // Găsim părintele cel mai specific
            $parinteCelMaiSpecific = null;
            $scorMaxim = -1;
            
            foreach ($parintiGasiti as $parinte) {
                $scor = $ierarhieSpecificitate[$parinte] ?? 0;
                if ($scor > $scorMaxim) {
                    $scorMaxim = $scor;
                    $parinteCelMaiSpecific = $parinte;
                }
            }
            
            // Excludem toți părinții mai puțin specifici
            foreach ($parintiGasiti as $parinte) {
                if ($parinte !== $parinteCelMaiSpecific) {
                    $componenteDeExclus[] = $parinte;
                }
            }
        }
        
        // Filtrăm termenii, excludem componentele și părinții redundanți
        foreach ($termeni as $index => $termen) {
            $termen_lower = $termeniNormalizati[$index];
            
            // Sărim componentele excluse - componenteDeExclus este deja lowercase
            if (in_array($termen_lower, $componenteDeExclus)) {
                continue;
            }
            
            $termeniFiltrati[] = $termen;
        }
        
        // Grupuri de sinonime simple (fără părți componente)
        $grupuriSinonime = [
            // Apă și mediu acvatic
            'water' => ['sea', 'ocean', 'water', 'lake', 'river', 'aqua', 'marine'],
            'apă' => ['mare', 'ocean', 'apă', 'lac', 'râu', 'acvatic', 'marin'],
            
            // Containere
            'container' => ['box', 'container', 'package', 'packaging', 'storage'],
            'cutie' => ['cutie', 'container', 'ambalaj', 'pachet', 'depozitare'],
            
            // Plante
            'palmier' => ['palmier', 'palmă', 'arbore tropical'],
            'palm' => ['palm tree', 'palm', 'tropical tree'],
            'tree' => ['tree', 'plant'],
            'copac' => ['copac', 'arbore', 'plantă'],
            
            // Cabluri și fire  
            'cablu' => ['cablu electric', 'cablare electrică', 'sârmă', 'fir', 'cablaj'],
            'cable' => ['electrical cable', 'electric wiring', 'wire', 'wiring', 'cord'],
            
            // Stocare date
            'stocare' => ['stocarea datelor', 'stocarea datelor pe calculator', 'disc compact', 'dvd', 'cd'],
            'storage' => ['data storage', 'computer storage', 'compact disc', 'dvd', 'cd'],
            
            // Tehnologie generică
            'tehnologie' => ['tehnologie', 'electronică', 'dispozitiv electronic'],
            'technology' => ['technology', 'electronics', 'electronic device'],
        ];
        
        // Aplicăm gruparea de sinonime pe termenii rămași
        $termeniFinali = [];
        $termeniProcesati = [];
        
        foreach ($termeniFiltrati as $termen) {
            $termen_lower = strtolower(trim($termen));
            
            // Verificăm dacă face parte dintr-un grup de sinonime
            $gasitInGrup = false;
            foreach ($grupuriSinonime as $grupPrincipal => $sinonime) {
                $sinonime_lower = array_map('strtolower', $sinonime);
                
                if (in_array($termen_lower, $sinonime_lower)) {
                    // Dacă nu am adăugat deja acest grup
                    if (!isset($termeniFinali[$grupPrincipal])) {
                        // Găsim cel mai specific termen din grup (primul din listă)
                        foreach ($sinonime as $sinonim) {
                            if (in_array(strtolower($sinonim), array_map('strtolower', $termeniFiltrati))) {
                                $termeniFinali[$grupPrincipal] = $sinonim;
                                break;
                            }
                        }
                        if (!isset($termeniFinali[$grupPrincipal])) {
                            $termeniFinali[$grupPrincipal] = $termen;
                        }
                    }
                    $termeniProcesati[] = $termen_lower;
                    $gasitInGrup = true;
                    break;
                }
            }
            
            // Dacă nu face parte din niciun grup, îl adăugăm direct
            if (!$gasitInGrup && !in_array($termen_lower, $termeniProcesati)) {
                // Verificăm similaritate cu termenii existenți
                $similar = false;
                foreach ($termeniFinali as $existing) {
                    if ($this->areSimilar($termen_lower, strtolower($existing))) {
                        $similar = true;
                        break;
                    }
                }
                
                if (!$similar) {
                    $termeniFinali[$termen_lower] = $termen;
                    $termeniProcesati[] = $termen_lower;
                }
            }
        }
        
        // Returnăm valorile cu prima literă majusculă
        $rezultat = array_map(function($t) {
            return ucfirst($t);
        }, array_values($termeniFinali));
        
        return array_slice($rezultat, 0, 7); // Limităm la 7 termeni
    }
    
    /**
     * Analizează dinamic relațiile semantice părinte-componentă folosind baza de date
     * Detectează automat când un termen poate fi părinte pentru alte termene
     */
    protected function analizeazaRelatiiSemanticeDinamic($termeni) {
        $relatii = [];
        
        // Obținem datele din baza de date în loc de dicționarul static
        $sql = "SELECT DISTINCT 
                    tc.termen,
                    tc.categorie,
                    tc.tip,
                    tc.scor_specificitate,
                    vc.prioritate as prioritate_categorie
                FROM vision_termeni_categorii tc
                JOIN vision_categorii vc ON tc.categorie = vc.categorie
                WHERE vc.activ = 1
                ORDER BY vc.prioritate DESC, tc.scor_specificitate DESC";
        
        $result = mysqli_query($this->conn, $sql);
        
        if (!$result) {
            // Fallback la metoda veche dacă tabelele nu există
            return $this->analizeazaRelatiiSemanticeDinamicFallback($termeni);
        }
        
        // Organizăm datele pe categorii
        $patterns = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $categorie = $row['categorie'];
            if (!isset($patterns[$categorie])) {
                $patterns[$categorie] = [
                    'parinti' => [],
                    'componente' => [],
                    'prioritate' => $row['prioritate_categorie']
                ];
            }
            
            $termen_lower = strtolower($row['termen']);
            if ($row['tip'] == 'parinte') {
                $patterns[$categorie]['parinti'][$termen_lower] = $row['scor_specificitate'];
            } else {
                $patterns[$categorie]['componente'][] = $termen_lower;
            }
        }
        
        // Analizăm termenii pentru fiecare categorie
        // Colectăm TOȚI părinții și TOATE componentele din TOATE categoriile
        $todosParinti = [];
        $todosComponente = [];
        
        foreach ($patterns as $categorie => $config) {
            foreach ($termeni as $termen) {
                $termen_lower = strtolower($termen);
                if (isset($config['parinti'][$termen_lower])) {
                    $todosParinti[$termen_lower] = $config['parinti'][$termen_lower];
                }
                if (in_array($termen_lower, $config['componente'])) {
                    $todosComponente[] = $termen_lower;
                }
            }
        }
        
        // Dacă avem părinți, asociem TOATE componentele găsite
        if (!empty($todosParinti)) {
            // Găsim părintele cel mai specific
            arsort($todosParinti);
            $parintePrincipal = key($todosParinti);
            
            // Colectăm TOȚI termenii care nu sunt părinți
            $todosNonParinti = [];
            foreach ($termeni as $termen) {
                $termen_lower = strtolower($termen);
                if (!isset($todosParinti[$termen_lower])) {
                    $todosNonParinti[] = $termen_lower;
                }
            }
            
            // Asociem TOȚI termenii non-părinți ca și componente
            if (!empty($todosNonParinti)) {
                $relatii[$parintePrincipal] = $todosNonParinti;
                $this->invataRelatiiNoi($parintePrincipal, $todosNonParinti);
            }
            
            // Adăugăm și relații pentru părinții secundari
            foreach ($todosParinti as $parinte => $scor) {
                if ($parinte !== $parintePrincipal) {
                    // Părinții secundari devin componente ale părintelui principal
                    if (!isset($relatii[$parintePrincipal])) {
                        $relatii[$parintePrincipal] = [];
                    }
                    $relatii[$parintePrincipal][] = $parinte;
                }
            }
        }
        
        // Detectare contextuală bazată pe co-ocurență
        // Dacă detectăm termeni foarte specifici împreună, îi grupăm
        $coOcurente = [
            [
                'parinti' => ['mini cooper', 'car'],
                'componente' => ['wheel', 'tire', 'door', 'roată', 'anvelopă', 'ușă']
            ],
            [
                'parinti' => ['bicycle', 'bike', 'bicicletă'],
                'componente' => ['wheel', 'pedal', 'handlebar', 'roată', 'pedală', 'ghidon']
            ],
            [
                'parinti' => ['laptop', 'notebook'],
                'componente' => ['keyboard', 'screen', 'trackpad', 'tastatură', 'ecran']
            ]
        ];
        
        foreach ($coOcurente as $grup) {
            foreach ($grup['parinti'] as $parinte) {
                if (in_array(strtolower($parinte), $termeni)) {
                    $componenteGasite = array_intersect($termeni, array_map('strtolower', $grup['componente']));
                    if (!empty($componenteGasite)) {
                        $relatii[strtolower($parinte)] = $componenteGasite;
                    }
                }
            }
        }
        
        // Detectare bazată pe semantică generală
        // Dacă un termen conține altul ca substring, poate fi o relație
        foreach ($termeni as $potentialParinte) {
            foreach ($termeni as $potentialComponenta) {
                if ($potentialParinte !== $potentialComponenta) {
                    // Ex: "car door" → "car" este părinte, "door" este componentă
                    if (strpos($potentialComponenta, $potentialParinte . ' ') === 0 ||
                        strpos($potentialComponenta, ' ' . $potentialParinte) !== false) {
                        if (!isset($relatii[$potentialParinte])) {
                            $relatii[$potentialParinte] = [];
                        }
                        $relatii[$potentialParinte][] = $potentialComponenta;
                    }
                }
            }
        }
        
        return $relatii;
    }
    
    /**
     * Învață relații noi părinte-componentă din detecții
     */
    private function invataRelatiiNoi($parinte, $componente) {
        foreach ($componente as $componenta) {
            // Verificăm dacă relația există deja
            $sql_check = "SELECT id, numar_aparitii FROM vision_relatii_invatate 
                         WHERE termen_parinte = ? AND termen_componenta = ?";
            $stmt = mysqli_prepare($this->conn, $sql_check);
            mysqli_stmt_bind_param($stmt, "ss", $parinte, $componenta);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            
            if ($row = mysqli_fetch_assoc($result)) {
                // Actualizăm numărul de apariții
                $sql_update = "UPDATE vision_relatii_invatate 
                              SET numar_aparitii = numar_aparitii + 1,
                                  confidence = LEAST(1.0, confidence + 0.05)
                              WHERE id = ?";
                $stmt_update = mysqli_prepare($this->conn, $sql_update);
                mysqli_stmt_bind_param($stmt_update, "i", $row['id']);
                mysqli_stmt_execute($stmt_update);
                mysqli_stmt_close($stmt_update);
            } else {
                // Inserăm relație nouă
                $limba = $this->detecteazaLimba($parinte);
                $sql_insert = "INSERT INTO vision_relatii_invatate 
                              (termen_parinte, termen_componenta, limba, confidence) 
                              VALUES (?, ?, ?, 0.3)";
                $stmt_insert = mysqli_prepare($this->conn, $sql_insert);
                mysqli_stmt_bind_param($stmt_insert, "sss", $parinte, $componenta, $limba);
                mysqli_stmt_execute($stmt_insert);
                mysqli_stmt_close($stmt_insert);
            }
            mysqli_stmt_close($stmt);
        }
    }
    
    /**
     * Detectează limba unui termen
     */
    private function detecteazaLimba($termen) {
        // Verificăm dacă conține caractere specifice limbii române
        if (preg_match('/[ăâîșțĂÂÎȘȚ]/u', $termen)) {
            return 'ro';
        }
        return 'en';
    }
    
    /**
     * Fallback la metoda veche dacă tabelele nu există
     */
    private function analizeazaRelatiiSemanticeDinamicFallback($termeni) {
        // Aici păstrăm codul vechi ca fallback
        return [];
    }
    
    /**
     * Adaugă termeni noi în baza de date pentru învățare
     */
    public function adaugaTermeniNoi($termeni, $categorie = null) {
        if (!$categorie) {
            // Încearcă să detecteze categoria automat
            $categorie = $this->detecteazaCategorie($termeni);
        }
        
        foreach ($termeni as $termen) {
            $limba = $this->detecteazaLimba($termen);
            $tip = $this->detecteazaTipTermen($termen, $termeni);
            
            $sql = "INSERT IGNORE INTO vision_termeni_categorii 
                   (termen, categorie, tip, limba) 
                   VALUES (?, ?, ?, ?)";
            $stmt = mysqli_prepare($this->conn, $sql);
            mysqli_stmt_bind_param($stmt, "ssss", $termen, $categorie, $tip, $limba);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }
    
    /**
     * Detectează automat categoria bazată pe termeni
     */
    private function detecteazaCategorie($termeni) {
        // Caută termeni cunoscuți pentru a determina categoria
        $sql = "SELECT categorie, COUNT(*) as matches 
                FROM vision_termeni_categorii 
                WHERE termen IN ('" . implode("','", array_map('strtolower', $termeni)) . "')
                GROUP BY categorie 
                ORDER BY matches DESC 
                LIMIT 1";
        
        $result = mysqli_query($this->conn, $sql);
        if ($row = mysqli_fetch_assoc($result)) {
            return $row['categorie'];
        }
        
        return 'general'; // Categorie default
    }
    
    /**
     * Detectează dacă un termen e părinte sau componentă
     */
    private function detecteazaTipTermen($termen, $todosTermenii) {
        // Euristici simple pentru detecție
        $termenLower = strtolower($termen);
        
        // Termeni care indică de obicei componente
        $indicatoriComponente = ['wheel', 'door', 'window', 'button', 'handle', 'leg', 'roată', 'ușă', 'buton'];
        foreach ($indicatoriComponente as $indicator) {
            if (strpos($termenLower, $indicator) !== false) {
                return 'componenta';
            }
        }
        
        // Termeni care indică de obicei părinți
        $indicatoriParinte = ['car', 'vehicle', 'table', 'chair', 'house', 'mașină', 'vehicul', 'masă', 'scaun', 'casă'];
        foreach ($indicatoriParinte as $indicator) {
            if (strpos($termenLower, $indicator) !== false) {
                return 'parinte';
            }
        }
        
        // Default: dacă e mai generic, probabil e părinte
        return (strlen($termen) < 10) ? 'parinte' : 'componenta';
    }
    
    /**
     * Verifică dacă doi termeni sunt similari
     */
    private function areSimilar($term1, $term2) {
        // Verifică dacă unul conține pe celălalt
        if (strpos($term1, $term2) !== false || strpos($term2, $term1) !== false) {
            return true;
        }
        
        // Calculează similaritatea Levenshtein
        $distance = levenshtein($term1, $term2);
        $maxLen = max(strlen($term1), strlen($term2));
        $similarity = 1 - ($distance / $maxLen);
        
        return $similarity > 0.8; // 80% similaritate
    }
    
    /**
     * Returnează ierarhiile obiect-componentă pentru deduplicare contextuală
     */
    private function getIerarhiiObiectComponente() {
        return [
            // VEHICULE
            'car' => ['wheel', 'tire', 'door', 'window', 'mirror', 'hood', 'trunk', 'fender', 'bumper', 'headlight'],
            'mașină' => ['roată', 'anvelopă', 'ușă', 'geam', 'oglindă', 'capotă', 'portbagaj', 'aripă', 'bară', 'far'],
            'bicycle' => ['wheel', 'pedal', 'handlebar', 'seat', 'chain', 'brake'],
            'bicicletă' => ['roată', 'pedală', 'ghidon', 'șa', 'lanț', 'frână'],
            
            // ELECTRONICE  
            'laptop' => ['keyboard', 'screen', 'touchpad', 'monitor', 'display', 'speaker'],
            'computer' => ['keyboard', 'mouse', 'monitor', 'screen', 'speaker'],
            'calculator' => ['tastatură', 'mouse', 'monitor', 'ecran', 'difuzor'],
            'phone' => ['screen', 'display', 'camera', 'button', 'speaker'],
            'telefon' => ['ecran', 'display', 'cameră', 'buton', 'difuzor'],
            
            // MOBILIER
            'table' => ['leg', 'top', 'drawer', 'surface'],
            'masă' => ['picior', 'blat', 'sertar', 'suprafață'],
            'chair' => ['leg', 'seat', 'back', 'armrest'],
            'scaun' => ['picior', 'șezut', 'spătar', 'cotieră'],
            
            // CUTII ȘI CONTAINERE
            'box' => ['lid', 'cover', 'bottom', 'side'],
            'cutie' => ['capac', 'fund', 'perete', 'latură'],
        ];
    }
    
    /**
     * Obține statistici despre cache
     */
    public function getCacheStats() {
        $stats = [];
        
        // Total traduceri în cache
        $result = mysqli_query($this->conn, "SELECT COUNT(*) as total FROM {$this->cache_table}");
        $stats['total'] = mysqli_fetch_assoc($result)['total'];
        
        // Traduceri pe surse
        $result = mysqli_query($this->conn, "SELECT sursa_traducere, COUNT(*) as count 
                                              FROM {$this->cache_table} 
                                              GROUP BY sursa_traducere");
        while ($row = mysqli_fetch_assoc($result)) {
            $stats['surse'][$row['sursa_traducere']] = $row['count'];
        }
        
        // Cele mai folosite traduceri
        $result = mysqli_query($this->conn, "SELECT text_original, text_tradus, numar_folosiri 
                                              FROM {$this->cache_table} 
                                              ORDER BY numar_folosiri DESC 
                                              LIMIT 10");
        $stats['top_folosite'] = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $stats['top_folosite'][] = $row;
        }
        
        return $stats;
    }
    
    /**
     * Curăță cache-ul vechi (opțional)
     */
    public function cleanOldCache($days = 90) {
        $sql = "DELETE FROM {$this->cache_table} 
                WHERE data_ultima_folosire < DATE_SUB(NOW(), INTERVAL ? DAY)
                AND numar_folosiri < 5";
        
        $stmt = mysqli_prepare($this->conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $days);
        mysqli_stmt_execute($stmt);
        $deleted = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);
        
        return $deleted;
    }
}

// Endpoint pentru AJAX requests
// Procesăm doar dacă fișierul este accesat direct, nu când e inclus
if (basename($_SERVER['SCRIPT_NAME']) == 'traducere_automata.php' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    $user = checkSession();
    if (!$user) {
        echo json_encode(['success' => false, 'error' => 'Neautorizat']);
        exit;
    }
    
    // Reconectează la baza de date a utilizatorului
    mysqli_close($conn);
    $conn = getUserDbConnection($user['db_name']);
    
    $traductor = new TraducereAutomata($conn);
    
    switch ($_POST['action']) {
        case 'traduce':
            $text = $_POST['text'] ?? '';
            $context = $_POST['context'] ?? null;
            $rezultat = $traductor->traduce($text, $context);
            echo json_encode(['success' => true, 'traducere' => $rezultat]);
            break;
            
        case 'traduce_multiplu':
            $texte = json_decode($_POST['texte'] ?? '[]', true);
            $context = $_POST['context'] ?? null;
            $rezultate = [];
            foreach ($texte as $text) {
                $rezultate[] = [
                    'original' => $text,
                    'tradus' => $traductor->traduce($text, $context)
                ];
            }
            echo json_encode(['success' => true, 'traduceri' => $rezultate]);
            break;
            
        case 'deduplica':
            $termeni = json_decode($_POST['termeni'] ?? '[]', true);
            $rezultat = $traductor->deduplicaTermeni($termeni);
            echo json_encode(['success' => true, 'termeni_unici' => $rezultat]);
            break;
            
        case 'stats':
            $stats = $traductor->getCacheStats();
            echo json_encode(['success' => true, 'stats' => $stats]);
            break;
            
        case 'clean_cache':
            $days = intval($_POST['days'] ?? 90);
            $deleted = $traductor->cleanOldCache($days);
            echo json_encode(['success' => true, 'deleted' => $deleted]);
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Acțiune necunoscută']);
    }
    exit;
}
?>