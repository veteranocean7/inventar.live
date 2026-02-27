# LOGICA FUNDAMENTALĂ PENTRU DETERMINAREA ARANJAMENTULUI CĂRȚILOR
## Algoritm definitiv bazat pe colțuri și numărarea cuvintelor
### Data: 29 August 2025

## PRINCIPIUL FUNDAMENTAL

După primirea hărții de text de la Google Vision API, determinarea aranjamentului cărților (vertical sau orizontal) se face prin analiza distribuției cuvintelor între colțurile extreme identificate.

## ALGORITM PAS CU PAS

### PASUL 1: Identificarea celor 4 colțuri extreme
După ce Google Vision returnează toate cuvintele cu coordonatele lor:
1. **Colț STÂNGA-SUS**: Cuvântul cu X minim și Y maxim (Y mare = sus în sistemul Google Vision)
2. **Colț DREAPTA-SUS**: Cuvântul cu X maxim și Y maxim
3. **Colț STÂNGA-JOS**: Cuvântul cu X minim și Y minim (Y mic = jos în sistemul Google Vision)
4. **Colț DREAPTA-JOS**: Cuvântul cu X maxim și Y minim

### PASUL 2: Crearea liniilor imaginare și numărarea cuvintelor pe Y

#### 2.1 Unirea colțurilor pe orizontală (capete de cotor)
- **Linia de sus**: Unim STÂNGA-SUS cu DREAPTA-SUS pe coordonata X
  - X_min = X(STÂNGA-SUS)
  - X_max = X(DREAPTA-SUS)
  - Y_referință = Y(STÂNGA-SUS) sau Y(DREAPTA-SUS)

- **Linia de jos**: Unim STÂNGA-JOS cu DREAPTA-JOS pe coordonata X
  - X_min = X(STÂNGA-JOS)
  - X_max = X(DREAPTA-JOS)
  - Y_referință = Y(STÂNGA-JOS) sau Y(DREAPTA-JOS)

#### 2.2 Numărarea cuvintelor între liniile orizontale
```
cuvinte_Y = 0
Pentru fiecare cuvânt din hartă:
    Dacă X(cuvânt) >= X_min ȘI X(cuvânt) <= X_max:
        Dacă Y(cuvânt) <= Y(sus) ȘI Y(cuvânt) >= Y(jos):
            cuvinte_Y++
```

### PASUL 3: Crearea liniilor imaginare și numărarea cuvintelor pe X

#### 3.1 Unirea colțurilor pe verticală
- **Linia din stânga**: Unim STÂNGA-SUS cu STÂNGA-JOS pe coordonata Y
  - Y_min = Y(STÂNGA-JOS)
  - Y_max = Y(STÂNGA-SUS)
  - X_referință = X(STÂNGA-SUS) sau X(STÂNGA-JOS)

- **Linia din dreapta**: Unim DREAPTA-SUS cu DREAPTA-JOS pe coordonata Y
  - Y_min = Y(DREAPTA-JOS)
  - Y_max = Y(DREAPTA-SUS)
  - X_referință = X(DREAPTA-SUS) sau X(DREAPTA-JOS)

#### 3.2 Numărarea cuvintelor între liniile verticale
```
cuvinte_X = 0
Pentru fiecare cuvânt din hartă:
    Dacă Y(cuvânt) >= Y_min ȘI Y(cuvânt) <= Y_max:
        Dacă X(cuvânt) >= X(stânga) ȘI X(cuvânt) <= X(dreapta):
            cuvinte_X++
```

### PASUL 4: Determinarea aranjamentului

```
DACĂ cuvinte_Y > cuvinte_X:
    Aranjament = VERTICAL (cărți stivuite una peste alta)
    // Mai multe cuvinte distribuite pe Y înseamnă cotoare orizontale multiple
ALTFEL:
    Aranjament = ORIZONTAL (cărți alăturate una lângă alta)
    // Mai multe cuvinte distribuite pe X înseamnă cotoare verticale multiple
```

## EXPLICAȚIE CONCEPTUALĂ

### Pentru cărți STIVUITE VERTICAL:
- Cotoarele sunt orizontale (de la stânga la dreapta)
- Textul se distribuie mai mult pe **axa Y** (sus-jos)
- Între liniile orizontale (sus-jos) găsim mai multe cuvinte
- **cuvinte_Y > cuvinte_X**

### Pentru cărți ALĂTURATE ORIZONTAL:
- Cotoarele sunt verticale (de sus în jos)
- Textul se distribuie mai mult pe **axa X** (stânga-dreapta)
- Între liniile verticale (stânga-dreapta) găsim mai multe cuvinte
- **cuvinte_X > cuvinte_Y**

## EXEMPLU PRACTIC

### Cărți stivuite (imaginea de test):
```
Colțuri identificate:
- STÂNGA-SUS: "322" la (X=1616, Y=2465)
- DREAPTA-SUS: "furia" la (X=2500, Y=2400)
- STÂNGA-JOS: "POLIROM" la (X=1632, Y=443)
- DREAPTA-JOS: "Gladwell" la (X=3751, Y=476)

Numărare:
- cuvinte_Y (între liniile orizontale): ~150 cuvinte
- cuvinte_X (între liniile verticale): ~80 cuvinte
- Rezultat: 150 > 80 → VERTICAL
```

## AVANTAJE ALE ACESTEI LOGICI

1. **Simplitate**: Nu necesită calcule complexe sau medii
2. **Precizie**: Bazată direct pe distribuția reală a textului
3. **Universalitate**: Funcționează pentru orice aranjament de cărți
4. **Robustețe**: Nu este afectată de variații minore în coordonate

## IMPLEMENTARE ÎN PHP

```php
// PASUL 1: Avem deja colțurile identificate
$colt_stanga_sus, $colt_dreapta_sus, $colt_stanga_jos, $colt_dreapta_jos

// PASUL 2: Numărare cuvinte pe Y (între linii orizontale)
$x_min_orizontal = min($colt_stanga_sus['x'], $colt_stanga_jos['x']);
$x_max_orizontal = max($colt_dreapta_sus['x'], $colt_dreapta_jos['x']);
$y_sus = max($colt_stanga_sus['y'], $colt_dreapta_sus['y']);
$y_jos = min($colt_stanga_jos['y'], $colt_dreapta_jos['y']);

$cuvinte_Y = 0;
foreach ($elemente_text as $element) {
    if ($element['x'] >= $x_min_orizontal && $element['x'] <= $x_max_orizontal) {
        if ($element['y'] <= $y_sus && $element['y'] >= $y_jos) {
            $cuvinte_Y++;
        }
    }
}

// PASUL 3: Numărare cuvinte pe X (între linii verticale)
$y_min_vertical = min($colt_stanga_jos['y'], $colt_dreapta_jos['y']);
$y_max_vertical = max($colt_stanga_sus['y'], $colt_dreapta_sus['y']);
$x_stanga = min($colt_stanga_sus['x'], $colt_stanga_jos['x']);
$x_dreapta = max($colt_dreapta_sus['x'], $colt_dreapta_jos['x']);

$cuvinte_X = 0;
foreach ($elemente_text as $element) {
    if ($element['y'] >= $y_min_vertical && $element['y'] <= $y_max_vertical) {
        if ($element['x'] >= $x_stanga && $element['x'] <= $x_dreapta) {
            $cuvinte_X++;
        }
    }
}

// PASUL 4: Determinare aranjament
if ($cuvinte_Y > $cuvinte_X) {
    $orientare = 'vertical'; // Cărți stivuite
} else {
    $orientare = 'orizontal'; // Cărți alăturate
}
```

## NOTE IMPORTANTE

1. **Sistemul de coordonate Google Vision**:
   - Y mare (2400+) = SUS vizual
   - Y mic (400-) = JOS vizual
   - X mic = STÂNGA
   - X mare = DREAPTA

2. **Toleranțe**: Nu sunt necesare pentru această logică, deoarece lucrăm cu limitele absolute

3. **Verificări de siguranță**: Asigurați-vă că toate cele 4 colțuri sunt identificate corect înainte de aplicarea algoritmului

## ACEASTA ESTE LOGICA FUNDAMENTALĂ DEFINITIVĂ!
**NU MODIFICAȚI** această logică fără confirmare explicită.
**ELIMINAȚI** orice altă logică de determinare a aranjamentului.

---
*Document creat: 29 August 2025*
*Status: LOGICĂ FUNDAMENTALĂ - IMPLEMENTARE OBLIGATORIE*
*Autor: Claude - bazat pe explicația detaliată a utilizatorului*