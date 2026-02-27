<?php
/**
 * Claude Vision Service - Identificare automată obiecte din imagini
 *
 * Folosește Claude Haiku pentru identificare precisă și contextuală
 * Prioritate: Acuratețe > Completitudine > Bounding boxes
 *
 * @version 1.0.0
 * @date 27 Februarie 2026
 */

class ClaudeVisionService {

    private $api_key;
    private $api_url = 'https://api.anthropic.com/v1/messages';
    private $model = 'claude-3-haiku-20240307'; // Rapid și cost-eficient
    private $max_tokens = 4096;

    // Logging
    private $log_file;
    private $debug_mode = false;

    /**
     * Constructor
     * @param string $api_key - Anthropic API key
     * @param bool $debug - Activează logging detaliat
     */
    public function __construct($api_key = null, $debug = false) {
        $this->api_key = $api_key ?? $this->loadApiKey();
        $this->debug_mode = $debug;
        $this->log_file = dirname(__FILE__) . '/../logs/claude_vision.log';

        // Creează directorul logs dacă nu există
        $log_dir = dirname($this->log_file);
        if (!is_dir($log_dir)) {
            mkdir($log_dir, 0755, true);
        }
    }

    /**
     * Încarcă API key din configurare
     */
    private function loadApiKey() {
        $config_file = dirname(__FILE__) . '/../config_claude.php';
        if (file_exists($config_file)) {
            include $config_file;
            return defined('CLAUDE_API_KEY') ? CLAUDE_API_KEY : null;
        }
        return null;
    }

    /**
     * Analizează o imagine și identifică obiectele
     *
     * @param string $image_path - Calea către imagine (relativă sau absolută)
     * @param array $options - Opțiuni adiționale
     * @return array - Rezultatul analizei
     */
    public function analyzeImage($image_path, $options = []) {
        $start_time = microtime(true);

        // Validare imagine
        if (!file_exists($image_path)) {
            return $this->errorResponse("Imaginea nu există: $image_path");
        }

        // Citire și codare imagine
        $image_data = file_get_contents($image_path);
        if ($image_data === false) {
            return $this->errorResponse("Nu pot citi imaginea: $image_path");
        }

        $image_base64 = base64_encode($image_data);
        $media_type = $this->getMediaType($image_path);

        if (!$media_type) {
            return $this->errorResponse("Format imagine nesuportat: $image_path");
        }

        // Context din opțiuni
        $context = $options['context'] ?? '';
        $location = $options['location'] ?? '';
        $box_name = $options['box_name'] ?? '';

        // Construire prompt optimizat pentru inventariere
        $prompt = $this->buildInventoryPrompt($context, $location, $box_name);

        // Apel API Claude
        $response = $this->callClaudeAPI($image_base64, $media_type, $prompt);

        $elapsed_time = round(microtime(true) - $start_time, 2);

        if ($response['success']) {
            $response['processing_time'] = $elapsed_time;
            $this->log("Imagine analizată în {$elapsed_time}s: " . basename($image_path));
        }

        return $response;
    }

    /**
     * Analizează multiple imagini (batch processing)
     *
     * @param array $images - Array de căi către imagini
     * @param array $options - Opțiuni comune
     * @return array - Rezultatele pentru fiecare imagine
     */
    public function analyzeImagesBatch($images, $options = []) {
        $results = [];
        $total = count($images);

        $this->log("Începe procesare batch: $total imagini");

        foreach ($images as $index => $image_path) {
            $this->log("Procesare " . ($index + 1) . "/$total: " . basename($image_path));

            $result = $this->analyzeImage($image_path, $options);
            $results[] = [
                'image_path' => $image_path,
                'result' => $result
            ];

            // Pauză mică între request-uri pentru a evita rate limiting
            if ($index < $total - 1) {
                usleep(500000); // 0.5 secunde
            }
        }

        $this->log("Batch complet: $total imagini procesate");

        return $results;
    }

    /**
     * Construiește promptul optimizat pentru inventariere
     */
    private function buildInventoryPrompt($context = '', $location = '', $box_name = '') {
        $context_info = '';
        if ($context) {
            $context_info .= "Context: $context\n";
        }
        if ($location) {
            $context_info .= "Locație: $location\n";
        }
        if ($box_name) {
            $context_info .= "Cutie/Container: $box_name\n";
        }

        return <<<PROMPT
Ești un expert în inventariere și identificare de obiecte. Analizează această imagine cu atenție maximă.

$context_info

SARCINA TA:
Identifică TOATE obiectele vizibile în imagine, fiecare în parte. Fii FOARTE SPECIFIC și PRECIS.

REGULI IMPORTANTE:
1. ACURATEȚE: Identifică corect fiecare obiect. Dacă nu ești sigur, spune "probabil X" sau "pare a fi X"
2. COMPLETITUDINE: Nu rata niciun obiect vizibil, chiar dacă e parțial acoperit
3. SPECIFICITATE: Nu spune doar "unealtă" - spune "șurubelnița cu cap plat, mâner roșu"
4. CONTEXT: Folosește contextul pentru identificări mai bune (ex: în atelier → unelte)

Returnează DOAR un JSON valid cu această structură exactă:
{
  "obiecte": [
    {
      "denumire": "Numele specific al obiectului în română",
      "denumire_scurta": "Varianta scurtă pentru etichetă (max 3 cuvinte)",
      "descriere": "Descriere detaliată: culoare, material, stare, dimensiune aproximativă",
      "categorie": "Categoria: Unelte, Electronică, Cărți, Hârtii, Îmbrăcăminte, Jucării, Decorațiuni, Bucătărie, Diverse",
      "stare": "Nouă/Bună/Uzată/Deteriorată",
      "certitudine": "Sigur/Probabil/Posibil",
      "cuvinte_cheie": ["cuvânt1", "cuvânt2", "cuvânt3"],
      "pozitie_in_imagine": "Descriere poziție: stânga-sus, centru, dreapta-jos, etc."
    }
  ],
  "numar_obiecte_identificate": 5,
  "numar_obiecte_incerte": 1,
  "observatii_generale": "Observații despre imagine, vizibilitate, obiecte parțial acoperite",
  "sugestii_fotografiere": "Sugestii pentru o fotografie mai bună dacă e cazul"
}

ATENȚIE: Returnează DOAR JSON-ul, fără text înainte sau după. JSON-ul trebuie să fie valid și parsabil.
PROMPT;
    }

    /**
     * Apelează API-ul Claude
     */
    private function callClaudeAPI($image_base64, $media_type, $prompt) {
        if (!$this->api_key) {
            return $this->errorResponse("API key Claude lipsește. Configurează CLAUDE_API_KEY în config_claude.php");
        }

        $payload = [
            'model' => $this->model,
            'max_tokens' => $this->max_tokens,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'image',
                            'source' => [
                                'type' => 'base64',
                                'media_type' => $media_type,
                                'data' => $image_base64
                            ]
                        ],
                        [
                            'type' => 'text',
                            'text' => $prompt
                        ]
                    ]
                ]
            ]
        ];

        $ch = curl_init($this->api_url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->api_key,
                'anthropic-version: 2023-06-01'
            ],
            CURLOPT_TIMEOUT => 60
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($curl_error) {
            return $this->errorResponse("Eroare conexiune: $curl_error");
        }

        if ($http_code !== 200) {
            $error_data = json_decode($response, true);
            $error_msg = $error_data['error']['message'] ?? "HTTP $http_code";
            return $this->errorResponse("Eroare API Claude: $error_msg");
        }

        $data = json_decode($response, true);

        if (!isset($data['content'][0]['text'])) {
            return $this->errorResponse("Răspuns invalid de la Claude API");
        }

        $text_response = $data['content'][0]['text'];

        // Parsare JSON din răspuns
        $parsed = $this->parseJsonResponse($text_response);

        if ($parsed === null) {
            $this->log("Răspuns ne-parsabil: " . substr($text_response, 0, 500), 'WARNING');
            return $this->errorResponse("Nu am putut parsa răspunsul JSON", $text_response);
        }

        return [
            'success' => true,
            'data' => $parsed,
            'raw_response' => $text_response,
            'model' => $this->model,
            'usage' => $data['usage'] ?? null
        ];
    }

    /**
     * Parsează răspunsul JSON (cu toleranță la erori minore)
     */
    private function parseJsonResponse($text) {
        // Încearcă parsare directă
        $json = json_decode($text, true);
        if ($json !== null) {
            return $json;
        }

        // Încearcă să extragă JSON din text (dacă e înconjurat de alte caractere)
        if (preg_match('/\{[\s\S]*\}/m', $text, $matches)) {
            $json = json_decode($matches[0], true);
            if ($json !== null) {
                return $json;
            }
        }

        // Încearcă să repare JSON comun greșit
        $text_fixed = $text;
        $text_fixed = preg_replace('/,\s*}/', '}', $text_fixed); // Virgulă trailing
        $text_fixed = preg_replace('/,\s*]/', ']', $text_fixed); // Virgulă trailing în array

        $json = json_decode($text_fixed, true);
        if ($json !== null) {
            return $json;
        }

        return null;
    }

    /**
     * Determină tipul media al imaginii
     */
    private function getMediaType($image_path) {
        $extension = strtolower(pathinfo($image_path, PATHINFO_EXTENSION));
        $types = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp'
        ];
        return $types[$extension] ?? null;
    }

    /**
     * Generează răspuns de eroare standardizat
     */
    private function errorResponse($message, $raw = null) {
        $this->log("EROARE: $message", 'ERROR');
        return [
            'success' => false,
            'error' => $message,
            'raw_response' => $raw
        ];
    }

    /**
     * Logging
     */
    private function log($message, $level = 'INFO') {
        if (!$this->debug_mode && $level === 'DEBUG') {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $log_line = "[$timestamp] [$level] $message\n";

        // Rotație log (max 5MB)
        if (file_exists($this->log_file) && filesize($this->log_file) > 5 * 1024 * 1024) {
            rename($this->log_file, $this->log_file . '.old');
        }

        file_put_contents($this->log_file, $log_line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Testează conexiunea la API
     */
    public function testConnection() {
        if (!$this->api_key) {
            return [
                'success' => false,
                'error' => 'API key nu este configurat'
            ];
        }

        // Test simplu cu un mesaj text
        $ch = curl_init($this->api_url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $this->model,
                'max_tokens' => 50,
                'messages' => [
                    ['role' => 'user', 'content' => 'Răspunde doar cu "OK" dacă funcționezi.']
                ]
            ]),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->api_key,
                'anthropic-version: 2023-06-01'
            ],
            CURLOPT_TIMEOUT => 30
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code === 200) {
            return [
                'success' => true,
                'message' => 'Conexiune reușită la Claude API',
                'model' => $this->model
            ];
        }

        $error_data = json_decode($response, true);
        return [
            'success' => false,
            'error' => $error_data['error']['message'] ?? "HTTP $http_code"
        ];
    }

    /**
     * Estimează costul pentru un număr de imagini
     */
    public function estimateCost($num_images, $avg_tokens_per_image = 1500) {
        // Prețuri Claude Haiku (aproximative, februarie 2026)
        $input_cost_per_1m = 0.25;  // $0.25 per 1M input tokens
        $output_cost_per_1m = 1.25; // $1.25 per 1M output tokens

        // Estimare tokens per imagine
        // Input: ~1000 tokens pentru imagine + ~500 pentru prompt
        // Output: ~500-1000 tokens pentru răspuns JSON
        $input_tokens = $num_images * 1500;
        $output_tokens = $num_images * 800;

        $input_cost = ($input_tokens / 1000000) * $input_cost_per_1m;
        $output_cost = ($output_tokens / 1000000) * $output_cost_per_1m;
        $total_cost = $input_cost + $output_cost;

        return [
            'num_images' => $num_images,
            'estimated_input_tokens' => $input_tokens,
            'estimated_output_tokens' => $output_tokens,
            'estimated_cost_usd' => round($total_cost, 4),
            'cost_per_image_usd' => round($total_cost / $num_images, 6),
            'note' => 'Estimare aproximativă, costul real poate varia'
        ];
    }
}

/**
 * Funcție helper pentru utilizare rapidă
 */
function analyzeImageWithClaude($image_path, $options = []) {
    $service = new ClaudeVisionService();
    return $service->analyzeImage($image_path, $options);
}
