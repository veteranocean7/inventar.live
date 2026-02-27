# Claude Vision Service - Identificare Automată Obiecte

**Versiune:** 1.0.0
**Data:** 27 Februarie 2026
**Status:** Implementat, necesită configurare API key

---

## Prezentare Generală

Serviciul Claude Vision înlocuiește/completează Google Vision pentru identificarea obiectelor din imagini.

### De ce Claude Haiku în loc de Google Vision?

| Aspect | Google Vision | Claude Haiku |
|--------|--------------|--------------|
| **Acuratețe** | Labels generice ("Tool") | Descrieri specifice ("Șurubelnița Phillips cu mâner roșu") |
| **Context** | Nu înțelege context | Înțelege ("unelte de atelier") |
| **Cost** | ~$1.50/1000 img | ~$0.25/1000 img |
| **Inventariere** | Manuală | Automată cu JSON structurat |

---

## Fișiere Implicate

```
public_html/
├── includes/
│   └── claude_vision_service.php    # Serviciul principal
├── config_claude.php                # Configurare API (EXCLUS din git!)
├── sql/
│   └── claude_vision_queue.sql      # Schema DB pentru queue
├── test_claude_vision.php           # Script de test
└── logs/
    └── claude_vision.log            # Log-uri (EXCLUS din git)
```

---

## Configurare

### 1. Obține API Key Anthropic

1. Accesează https://console.anthropic.com/
2. Creează cont sau autentifică-te
3. Mergi la **API Keys** → **Create Key**
4. Copiază key-ul (format: `sk-ant-api03-...`)

### 2. Configurează în aplicație

Editează `config_claude.php`:

```php
define('CLAUDE_API_KEY', 'sk-ant-api03-XXXXXXXX...');
```

### 3. Aplică schema DB

Rulează `sql/claude_vision_queue.sql` în phpMyAdmin pe baza de date `inventar_central`.

### 4. Testează

```bash
php test_claude_vision.php
```

Sau accesează în browser (doar pe localhost): `http://localhost/test_claude_vision.php`

---

## Utilizare

### Analiză simplă a unei imagini

```php
require_once 'includes/claude_vision_service.php';

$service = new ClaudeVisionService();
$result = $service->analyzeImage('imagini_obiecte/user_1/foto.jpg', [
    'context' => 'Unelte de atelier',
    'location' => 'Garaj',
    'box_name' => 'Cutie roșie'
]);

if ($result['success']) {
    foreach ($result['data']['obiecte'] as $obiect) {
        echo $obiect['denumire'] . " - " . $obiect['categorie'] . "\n";
    }
}
```

### Procesare batch (mai multe imagini)

```php
$service = new ClaudeVisionService();

$imagini = [
    'imagini_obiecte/user_1/foto1.jpg',
    'imagini_obiecte/user_1/foto2.jpg',
    'imagini_obiecte/user_1/foto3.jpg'
];

$results = $service->analyzeImagesBatch($imagini, [
    'context' => 'Inventar atelier'
]);
```

### Test conexiune

```php
$service = new ClaudeVisionService();
$test = $service->testConnection();

if ($test['success']) {
    echo "Conexiune OK!";
}
```

### Estimare costuri

```php
$service = new ClaudeVisionService();
$cost = $service->estimateCost(100); // 100 imagini

echo "Cost estimat: $" . $cost['estimated_cost_usd'];
// Output: Cost estimat: $0.0575
```

---

## Structura Răspunsului

```json
{
  "success": true,
  "data": {
    "obiecte": [
      {
        "denumire": "Șurubelnița Phillips",
        "denumire_scurta": "Șurubelnița",
        "descriere": "Mâner galben plastic, vârf uzat, aproximativ 15cm lungime",
        "categorie": "Unelte",
        "stare": "Uzată",
        "certitudine": "Sigur",
        "cuvinte_cheie": ["șurubelnița", "phillips", "unelte", "atelier"],
        "pozitie_in_imagine": "Stânga-sus"
      },
      {
        "denumire": "Ciocan cu mâner de lemn",
        "denumire_scurta": "Ciocan",
        "descriere": "Cap metalic ruginit, mâner de lemn natural, ~30cm",
        "categorie": "Unelte",
        "stare": "Uzată",
        "certitudine": "Sigur",
        "cuvinte_cheie": ["ciocan", "unelte", "metal", "lemn"],
        "pozitie_in_imagine": "Centru"
      }
    ],
    "numar_obiecte_identificate": 2,
    "numar_obiecte_incerte": 0,
    "observatii_generale": "Cutie cu unelte vechi de atelier, bine iluminată",
    "sugestii_fotografiere": null
  },
  "processing_time": 2.34,
  "model": "claude-3-haiku-20240307",
  "usage": {
    "input_tokens": 1847,
    "output_tokens": 523
  }
}
```

---

## Categorii Predefinite

Serviciul clasifică obiectele în aceste categorii:

- **Unelte** - șurubelnițe, ciocane, clești, etc.
- **Electronică** - cabluri, încărcătoare, dispozitive
- **Cărți** - cărți, reviste, documente
- **Hârtii** - documente, facturi, notițe
- **Îmbrăcăminte** - haine, încălțăminte
- **Jucării** - jucării, jocuri
- **Decorațiuni** - ornamente, tablouri
- **Bucătărie** - vase, ustensile
- **Diverse** - obiecte neclasificate

---

## Procesare Nocturnă (Planificat)

### Flux propus:

```
ZIUA:
User face fotografii → Upload în aplicație →
Salvare în DB cu status "pending"

NOAPTEA (Cron 02:00):
Select imagini pending → Trimite batch la Claude →
Primește JSON structurat → Salvează rezultate →
Marchează "completed"

DIMINEAȚA:
User vede notificare "12 obiecte identificate" →
Verifică și confirmă/corectează
```

### Cron job (de implementat):

```bash
# În crontab
0 2 * * * php /path/to/cron/procesare_automata_imagini.php
```

---

## Costuri Estimate

| Volum | Cost Estimat |
|-------|--------------|
| 100 imagini | ~$0.06 |
| 500 imagini | ~$0.29 |
| 1000 imagini | ~$0.58 |
| 5000 imagini | ~$2.88 |

**Notă:** Costurile sunt estimate pentru Claude Haiku. Pot varia în funcție de dimensiunea imaginilor și lungimea răspunsurilor.

---

## Comparație cu Google Vision

### Exemplu practic:

**Imagine:** Cutie cu unelte vechi de atelier

| Google Vision | Claude Haiku |
|---------------|--------------|
| "Tool" (0.92) | "Șurubelnița Phillips cu mâner galben, vârf uzat" |
| "Hand tool" (0.88) | "Ciocan cu mâner de lemn, cap ruginit, ~30cm" |
| "Hardware" (0.75) | "Clește universal, mânere izolate roșii" |

**Concluzie:** Claude oferă descrieri specifice, utile pentru căutare și inventariere.

---

## TODO

- [ ] Implementare cron job pentru procesare nocturnă
- [ ] UI pentru vizualizare status procesare
- [ ] Sistem de confirmare/corecție de către user
- [ ] Learning din corecțiile user-ului
- [ ] Integrare completă în fluxul de adăugare obiecte
- [ ] Notificări când procesarea e completă

---

## Troubleshooting

### "API key nu este configurat"

→ Editează `config_claude.php` și adaugă API key-ul

### "Eroare conexiune"

→ Verifică că ai acces la internet și că API key-ul e valid

### "Nu pot citi imaginea"

→ Verifică permisiunile fișierului și calea corectă

### "JSON invalid în răspuns"

→ Imaginea poate fi prea complexă. Verifică log-urile în `logs/claude_vision.log`

---

*Documentație creată: 27 Februarie 2026*
