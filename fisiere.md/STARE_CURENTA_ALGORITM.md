# STARE CURENTĂ ALGORITM - 27 August 2025

## CE ȘTIM CU SIGURANȚĂ

### 1. Coordonate Google Vision (CONFIRMAT)
- **Y mare (2400+) = SUS vizual** (partea de sus a imaginii)
- **Y mic (400-) = JOS vizual** (partea de jos a imaginii)
- Imaginea pare rotită 180° față de sistemul de coordonate standard

### 2. Cuvintele de Colț CORECTE (din versiunea care a funcționat)
- **Stânga-sus**: "322" 
- **Dreapta-sus**: "TOPH" (sau "furia")
- **Stânga-jos**: "POLIROM" 
- **Dreapta-jos**: "Nil" (sau "Gladwell")

### 3. Coordonate Reale din Text Map
```
"322": X=1616, Y=2465 (Y mare = sus)
"TOPH": X=2207, Y=1983
"POLIROM": X=1632, Y=443 (Y mic = jos)
"Nil": X=3751, Y=476
```

## PROBLEMA ACTUALĂ

Codul curent (liniile 405-430 din procesare_cutie_vision.php) folosește:
- Pentru SUS: caută cuvinte cu Y >= (Y_max - 300)
- Pentru JOS: caută cuvinte cu Y <= (Y_min + 300)

Dar încă nu găsește corect colțurile.

## CE TREBUIE FĂCUT MÂINE

1. **Verifică dacă logica este inversată corect**
   - Y mare = sus (confirmat)
   - Folosim toleranța corect?

2. **Testează cu valori concrete**
   - Y_max în date = ~2465 (unde e "322")
   - Y_min în date = ~443 (unde e "POLIROM")
   - Toleranța de 300 acoperă intervalele corecte?

3. **Verifică sortarea după X**
   - Pentru linia de sus: X mic = stânga, X mare = dreapta
   - Pentru linia de jos: la fel

## COD ACTUAL (simplificat)

```php
// Linia de sus (Y apropiat de max)
$cuvinte_sus = [];
foreach ($elemente_text as $elem) {
    if ($elem['y'] >= $y_max - 300) {
        $cuvinte_sus[] = $elem;
    }
}
usort($cuvinte_sus, function($a, $b) { return $a['x'] - $b['x']; });
$colt_stanga_sus = $cuvinte_sus[0]; // X minim
$colt_dreapta_sus = $cuvinte_sus[count($cuvinte_sus) - 1]; // X maxim

// Similar pentru linia de jos...
```

## NOTE IMPORTANTE

1. **NU modifica logica fundamentală** din ALGORITM_COLTURI_CARTI.md
2. **Algoritmul trebuie să fie universal** - nu hardcodat pentru cuvinte specifice
3. **Versiunea care a mers** identifica corect colțurile - trebuie să revenim la acea logică

---
*Salvat pentru continuare mâine*
*Problemă: cod amestecat vechi/nou, logică pierdută*
*Soluție: reconstrucție de la zero folosind documentația*