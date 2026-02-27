# ANALIZĂ VERSIUNE CURENTĂ - 27 August 2025, seară

## PROBLEMELE DIN CODUL ACTUAL

### 1. Logică încurcată cu cadrane și rotiri (linii 463-503)
```php
// Găsim cuvintele din fiecare cadran
$cadran_stanga_sus = null;
// ... cod complex cu cadrane ...

// APLICĂM ROTIREA pentru cărți verticale
$colt_stanga_sus = $cadran_stanga_jos;   // Rotire inversă
$colt_dreapta_sus = $cadran_stanga_sus;
$colt_dreapta_jos = $cadran_dreapta_sus;
$colt_stanga_jos = $cadran_dreapta_jos;
```
**PROBLEMĂ**: Amestecă colțurile în mod neintuitiv și confuz.

### 2. Dublă calculare a extremelor
- Prima dată la liniile 333-339
- A doua oară la liniile 446-456
**PROBLEMĂ**: Cod redundant și confuz.

### 3. Distanță euclidiană cu coordonate nedefinite (linii 355-377)
```php
$dist_stanga_sus = sqrt(pow($element['x'] - $imagine_x_min, 2) + pow($element['y'] - $imagine_y_min, 2));
```
**PROBLEMĂ**: Variabilele `$imagine_x_min`, `$imagine_x_max` etc. nu sunt definite corect.

### 4. Nu respectă logica documentată
În loc să urmeze algoritmul simplu din ALGORITM_COLTURI_CARTI.md, codul face calcule complicate cu cadrane și rotiri.

## CE AR TREBUI SĂ FACĂ (conform documentației)

### Pentru cărți stivuite vertical (imaginea ta):

1. **Găsește Y_min și Y_max** din toate cuvintele
   - Y_max ≈ 2465 (sus vizual - unde e "322")
   - Y_min ≈ 443 (jos vizual - unde e "POLIROM")

2. **Pentru linia de sus** (Y apropiat de Y_max):
   - Găsește toate cuvintele cu Y ≥ Y_max - toleranță
   - Sortează după X
   - Primul (X_min) = STÂNGA-SUS → "322"
   - Ultimul (X_max) = DREAPTA-SUS → "TOPH" sau "furia"

3. **Pentru linia de jos** (Y apropiat de Y_min):
   - Găsește toate cuvintele cu Y ≤ Y_min + toleranță
   - Sortează după X
   - Primul (X_min) = STÂNGA-JOS → "POLIROM"
   - Ultimul (X_max) = DREAPTA-JOS → "Nil" sau "Gladwell"

## CODUL CORECT (simplificat)

```php
// 1. Găsim extremele Y
$y_min = PHP_INT_MAX;
$y_max = PHP_INT_MIN;
foreach ($elemente_text as $elem) {
    $y_min = min($y_min, $elem['y']);
    $y_max = max($y_max, $elem['y']);
}

// 2. Găsim cuvintele de pe linia de sus (Y mare = sus vizual)
$toleranta = 100; // sau 150
$cuvinte_sus = [];
foreach ($elemente_text as $elem) {
    if ($elem['y'] >= $y_max - $toleranta) {
        $cuvinte_sus[] = $elem;
    }
}
usort($cuvinte_sus, function($a, $b) { return $a['x'] - $b['x']; });

// 3. Găsim cuvintele de pe linia de jos (Y mic = jos vizual)
$cuvinte_jos = [];
foreach ($elemente_text as $elem) {
    if ($elem['y'] <= $y_min + $toleranta) {
        $cuvinte_jos[] = $elem;
    }
}
usort($cuvinte_jos, function($a, $b) { return $a['x'] - $b['x']; });

// 4. Identificăm colțurile
$colt_stanga_sus = $cuvinte_sus[0] ?? null;  // "322"
$colt_dreapta_sus = end($cuvinte_sus) ?: null;  // "TOPH"
$colt_stanga_jos = $cuvinte_jos[0] ?? null;  // "POLIROM"
$colt_dreapta_jos = end($cuvinte_jos) ?: null;  // "Nil"
```

## CONCLUZIE

Versiunea curentă are prea mult cod complicat cu cadrane și rotiri care nu funcționează corect. 

Trebuie să revenim la logica simplă și clară:
- **Y mare = sus vizual**
- **Y mic = jos vizual**
- **Sortare după X pentru stânga/dreapta**

---
*Pentru refacere mâine de la zero cu logica corectă*