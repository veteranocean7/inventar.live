# Integrare Google Vision API - Documentație Tehnică

## Prezentare Generală
Integrarea Google Vision API permite detectarea automată a obiectelor din imagini, cu traducere automată în limba română.

## Arhitectură

### Flux de procesare:
1. **Inițiere** - Utilizatorul apasă butonul Vision (portocaliu)
2. **Confirmare** - Modal de confirmare cu estimare timp
3. **Procesare** - Trimitere imagini către Google Vision API
4. **Traducere** - Conversie automată engleză → română
5. **Salvare** - Stocare în baza de date cu tracking sursă
6. **Afișare** - Marcare vizuală distinctă (portocaliu)

## Componente Principale

### 1. Frontend (`etichete_imagine.php`)

#### Buton Vision:
```javascript
// Stilizare portocalie consistentă
.vision-toggle {
    background-color: #ff6600;
    color: white;
}
```

#### Modal-uri:
- **Confirmare** - Solicită confirmarea procesării
- **Rezultate** - Afișează statistici procesare

### 2. Backend (`procesare_cutie_vision_v3.php`)

#### Funcția de traducere:
```php
function translateToRomanian($text) {
    $dictionary = [
        'box' => 'cutie',
        'electrical cable' => 'cablu electric',
        // ... 100+ traduceri
    ];
    
    // Logică de traducere cuvânt cu cuvânt
    // pentru fraze neînregistrate
}
```

#### Procesare API:
```php
// Configurare client
$imageAnnotator = new ImageAnnotatorClient([
    'credentials' => 'google-vision-key.json'
]);

// Detectare etichete
$features = [
    new Feature([
        'type' => Type::LABEL_DETECTION,
        'max_results' => 10
    ])
];
```

### 3. Baza de Date

#### Salvare în `obiecte`:
- `denumire_obiect`: "cutie (1), creion (2)"
- `cantitate_obiect`: "1, 1"

#### Tracking în `detectii_obiecte`:
- `sursa`: 'google_vision'
- Permite raportare și filtrare

## Configurare

### 1. Obținere cheie API:
1. Creați proiect în [Google Cloud Console](https://console.cloud.google.com)
2. Activați Vision API
3. Creați Service Account
4. Descărcați cheia JSON

### 2. Instalare dependențe:
```bash
php composer.phar require google/cloud-vision
```

### 3. Protecție cheie:
```apache
# În .htaccess
<Files "google-vision-key.json">
    Order allow,deny
    Deny from all
</Files>
```

## Costuri și Limite

### Gratuit:
- 1000 procesări/lună
- Suficient pentru ~30-50 cutii

### După limita gratuită:
- $1.50 per 1000 imagini (label detection)
- Recomandare: Setați alertă la $10 în Google Cloud

## Optimizări Implementate

### 1. Procesare în lot:
- Toate imaginile dintr-o cutie sunt procesate odată
- Reduce numărul de apeluri API

### 2. Traducere locală:
- Dicționar integrat pentru termeni comuni
- Evită apeluri suplimentare API

### 3. Deduplicare:
- Filtrare automată obiecte duplicate
- Păstrare doar primele 3 etichete relevante

## Debugging și Monitorizare

### Loguri:
```php
function logDebug($message) {
    error_log("[Vision Debug V3] " . $message);
}
```

### Verificare status:
- Check `error_log` pentru erori PHP
- Verificați [Google Cloud Console](https://console.cloud.google.com) pentru statistici API

## Probleme Comune și Soluții

### "Quota exceeded"
- **Cauză**: Limita lunară depășită
- **Soluție**: Activați billing sau așteptați luna următoare

### "Nu detectează anumite obiecte"
- **Cauză**: Calitate imagine sau unghi nepotrivit
- **Soluție**: Refaceți fotografia cu iluminare mai bună

### "Traduceri incorecte"
- **Cauză**: Termen lipsă din dicționar
- **Soluție**: Adăugați în funcția `translateToRomanian()`

## Îmbunătățiri Viitoare

1. **Cache rezultate** - Evitare reprocesare imagini identice
2. **OCR text** - Detectare text de pe etichete produse
3. **Detectare culori** - Pentru sortare automată
4. **Machine Learning custom** - Antrenare model specific pentru inventar

---
*Document actualizat: 29 iulie 2025*