# ALGORITM COMPLET PENTRU IDENTIFICAREA CĂRȚILOR PRIN GOOGLE VISION
## Documentație Definitivă - 28 August 2025

### PRINCIPII FUNDAMENTALE

## 1. FLUX GENERAL DE PROCESARE

### 1.1 Declanșare și Verificare Inițială
1. La declanșarea Google Vision, sistemul verifică dacă sunt cărți în imagine
2. Dacă DA → apelează din nou Google Vision pentru TEXT_DETECTION
3. Google Vision returnează o hartă de text cu cuvinte încadrate în dreptunghiuri imaginare cu coordonate carteziene

### 1.2 Sistemul de Coordonate Google Vision
- **Origine**: colțul stânga-sus al imaginii (0,0)
- **Axa X**: crește spre dreapta
- **Axa Y**: crește spre jos
- Fiecare cuvânt detectat are coordonate (X, Y) pentru poziția sa

## 2. IDENTIFICAREA COLȚURILOR EXTREME

### 2.1 Definiție Colțuri Extreme
**Colțurile extreme** sunt cele 4 cuvinte text aflate la limitele absolute ale hărții de text:
- **STÂNGA-SUS**: cuvântul cu X minim și Y minim
- **DREAPTA-SUS**: cuvântul cu X maxim și Y minim  
- **STÂNGA-JOS**: cuvântul cu X minim și Y maxim
- **DREAPTA-JOS**: cuvântul cu X maxim și Y maxim

### 2.2 Algoritm Identificare Colțuri
```
1. Parcurge toate cuvintele detectate
2. Găsește:
   - X_min = cea mai mică valoare X din toate cuvintele
   - X_max = cea mai mare valoare X din toate cuvintele
   - Y_min = cea mai mică valoare Y din toate cuvintele
   - Y_max = cea mai mare valoare Y din toate cuvintele
   
3. Identifică colțurile:
   - STÂNGA-SUS = cuvântul cu X ≈ X_min și Y ≈ Y_min (toleranță ±10px)
   - DREAPTA-SUS = cuvântul cu X ≈ X_max și Y ≈ Y_min (toleranță ±10px)
   - STÂNGA-JOS = cuvântul cu X ≈ X_min și Y ≈ Y_max (toleranță ±10px)
   - DREAPTA-JOS = cuvântul cu X ≈ X_max și Y ≈ Y_max (toleranță ±10px)
```

**IMPORTANT**: Dincolo de aceste 4 cuvinte NU MAI EXISTĂ niciun alt cuvânt sau caracter identificat!

## 3. DETERMINAREA ARANJAMENTULUI CĂRȚILOR

### 3.1 Tipuri de Aranjamente
1. **ARANJAMENT VERTICAL** (cărți stivă)
   - Cărțile sunt puse una peste cealaltă
   - Cotoarele sunt pe orizontală
   - Se citește de la stânga la dreapta pe fiecare cotor
   
2. **ARANJAMENT ORIZONTAL** (cărți alăturate)
   - Cărțile sunt puse una lângă cealaltă
   - Cotoarele sunt pe verticală
   - Se citește de sus în jos pe fiecare cotor

### 3.2 Algoritm Determinare Aranjament
```
1. Consideră harta de text ca o matrice
2. Calculează:
   - Nr_cuvinte_verticală = numărul mediu de cuvinte pe verticală
   - Nr_cuvinte_orizontală = numărul mediu de cuvinte pe orizontală
   
3. Determinare:
   IF (Nr_cuvinte_verticală > Nr_cuvinte_orizontală) THEN
      Aranjament = VERTICAL
   ELSE
      Aranjament = ORIZONTAL
   END IF
```

### 3.3 Calcul Detaliat Număr Cuvinte
```
Pentru Nr_cuvinte_verticală:
1. Împarte spațiul pe benzi verticale (ex: 10 benzi)
2. Pentru fiecare bandă, numără cuvintele cu X în acel interval
3. Calculează media

Pentru Nr_cuvinte_orizontală:
1. Împarte spațiul pe benzi orizontale (ex: 10 benzi)  
2. Pentru fiecare bandă, numără cuvintele cu Y în acel interval
3. Calculează media
```

## 4. PROCESARE ARANJAMENT VERTICAL

### 4.1 Caracteristici Aranjament Vertical
- Cărțile sunt stivuite una peste alta
- Cotoarele sunt orizontale
- Prima carte = cea mai de sus (vizual)
- Ultima carte = cea mai de jos (vizual)

### 4.2 Reguli de Identificare Cotoare
```
PRIMA CARTE (vârful stivei):
- Primul cuvânt = OBLIGATORIU colțul STÂNGA-SUS
- Ultimul cuvânt = OBLIGATORIU colțul DREAPTA-SUS
- Cuvintele intermediare = toate cu Y între Y_stânga-sus și Y_dreapta-sus (±toleranță)

ULTIMA CARTE (baza stivei):
- Primul cuvânt = OBLIGATORIU colțul STÂNGA-JOS
- Ultimul cuvânt = OBLIGATORIU colțul DREAPTA-JOS
- Cuvintele intermediare = toate cu Y între Y_stânga-jos și Y_dreapta-jos (±toleranță)

CĂRȚI INTERMEDIARE:
Pentru fiecare cotor intermediar:
1. Primul cuvânt:
   - Are Y mai mic decât cotorul de deasupra
   - Este primul din marginea stângă (X minim pe acel rând)
   
2. Ultimul cuvânt:
   - Are același Y ca primul cuvânt (±toleranță)
   - Este ultimul din marginea dreaptă (X maxim pe acel rând)
   
3. Cuvintele intermediare:
   - Se identifică folosind o linie imaginară pe Y
   - Toleranță Y: ±10-30px
   - Sortare după X pentru ordinea corectă de citire
```

### 4.3 Algoritm Complet Aranjament Vertical
```
1. Identifică prima carte (folosind colțurile SUS)
2. Identifică ultima carte (folosind colțurile JOS)

3. Pentru cărțile intermediare:
   Y_curent = Y_dreapta-sus - increment
   
   WHILE (Y_curent > Y_stânga-jos):
      a. Găsește toate cuvintele cu Y ≈ Y_curent (±toleranță)
      b. Sortează după X
      c. Primul = cel cu X minim
      d. Ultimul = cel cu X maxim
      e. Construiește textul cotorului
      f. Y_curent = Y_curent - increment
   END WHILE

4. Pentru fiecare carte identificată:
   - Adaugă "(1)" la final (cantitate default)
   - Dacă aceeași carte apare de mai multe ori → incrementează "(n)"
```

## 5. PROCESARE ARANJAMENT ORIZONTAL

### 5.1 Caracteristici Aranjament Orizontal
- Cărțile sunt alăturate una lângă alta
- Cotoarele sunt verticale
- Prima carte = cea mai din stânga
- Ultima carte = cea mai din dreapta

### 5.2 Reguli de Identificare Cotoare
```
PRIMA CARTE (stânga):
- Primul cuvânt = OBLIGATORIU colțul STÂNGA-SUS
- Ultimul cuvânt = OBLIGATORIU colțul STÂNGA-JOS
- Cuvintele intermediare = toate cu X între X_stânga-sus și X_stânga-jos (±toleranță)

ULTIMA CARTE (dreapta):
- Primul cuvânt = OBLIGATORIU colțul DREAPTA-SUS
- Ultimul cuvânt = OBLIGATORIU colțul DREAPTA-JOS
- Cuvintele intermediare = toate cu X între X_dreapta-sus și X_dreapta-jos (±toleranță)

CĂRȚI INTERMEDIARE:
Pentru fiecare cotor intermediar:
1. Primul cuvânt:
   - Are X mai mare decât cotorul din stânga
   - Este primul de sus (Y minim pe acea coloană)
   
2. Ultimul cuvânt:
   - Are același X ca primul cuvânt (±toleranță)
   - Este ultimul de jos (Y maxim pe acea coloană)
   
3. Cuvintele intermediare:
   - Se identifică folosind o linie imaginară pe X
   - Toleranță X: ±10-30px
   - Sortare după Y pentru ordinea corectă de citire
```

### 5.3 Algoritm Complet Aranjament Orizontal
```
1. Identifică prima carte (folosind colțurile STÂNGA)
2. Identifică ultima carte (folosind colțurile DREAPTA)

3. Pentru cărțile intermediare:
   X_curent = X_stânga-jos + increment
   
   WHILE (X_curent < X_dreapta-sus):
      a. Găsește toate cuvintele cu X ≈ X_curent (±toleranță)
      b. Sortează după Y
      c. Primul = cel cu Y minim
      d. Ultimul = cel cu Y maxim
      e. Construiește textul cotorului
      f. X_curent = X_curent + increment
   END WHILE

4. Pentru fiecare carte identificată:
   - Adaugă "(1)" la final (cantitate default)
   - Dacă aceeași carte apare de mai multe ori → incrementează "(n)"
```

## 6. TOLERANȚE ȘI PARAMETRI

### 6.1 Toleranțe Recomandate
- **Toleranță colțuri**: ±10px pentru identificarea colțurilor extreme
- **Toleranță linie cotor**: ±10-30px pentru gruparea cuvintelor pe același cotor
- **Increment scanare**: 50-100px pentru parcurgerea între cotoare

### 6.2 Parametri Ajustabili
```php
define('TOLERANTA_COLTURI', 10);        // px
define('TOLERANTA_COTOR', 30);          // px  
define('INCREMENT_SCANARE', 50);        // px
define('MIN_CUVINTE_COTOR', 2);         // minim 2 cuvinte pentru un cotor valid
define('MAX_DISTANTA_INTRE_COTOARE', 200); // px
```

## 7. CAZURI SPECIALE ȘI EXCEPȚII

### 7.1 Cărți Duplicate
- Dacă același text de carte este detectat de mai multe ori
- Se contorizează și se afișează: "Titlu carte (3)"

### 7.2 Cotor cu Un Singur Cuvânt
- Acceptabil doar pentru cărți foarte subțiri
- Validare: verifică dacă există spațiu suficient pentru o carte

### 7.3 Text Rotit sau Înclinat
- Aplicare corecție de rotație înainte de procesare
- Detectare unghi prin analiza liniilor de text

## 8. OUTPUT FINAL

### Format Standard
```
Pentru aranjament vertical:
"Titlu1 Autor1 Editura1 (1), Titlu2 Autor2 (1), Titlu3 (2)"

Pentru aranjament orizontal:
"Titlu1 Autor1 (1), Titlu2 Editura2 (1), Titlu3 Autor3 Editura3 (1)"
```

### Reguli de Formatare
1. Fiecare carte = text complet de pe cotor
2. Separare între cărți = virgulă și spațiu
3. Cantitate între paranteze la final
4. NU se adaugă index de imagine (toate sunt pe aceeași imagine)

## 9. VALIDARE ȘI VERIFICARE

### Criterii de Validare
1. **Colțuri valide**: toate cele 4 colțuri trebuie identificate
2. **Minim 1 carte**: cel puțin o carte validă identificată
3. **Text coerent**: minim 2 cuvinte per carte (excepție: cărți foarte subțiri)
4. **Fără suprapuneri**: cotoarele nu se suprapun

### Verificare Finală
```
IF (număr_cărți_detectate == 0) THEN
   LOG("Nu s-au detectat cărți valide")
   RETURN []
   
IF (număr_cărți_detectate > 50) THEN
   LOG("Prea multe cărți detectate, posibil eroare")
   RETURN primele_50_cărți
   
RETURN cărți_validate
```

## 10. IMPLEMENTARE ÎN PHP

### Structura de Date Recomandată
```php
class CarteDetectata {
    public $text_complet;      // "Titlu Autor Editura"
    public $cuvinte = [];       // array de cuvinte cu coordonate
    public $cotor_y;            // coordonata Y pentru vertical
    public $cotor_x;            // coordonata X pentru orizontal
    public $cantitate = 1;      // număr exemplare
}

class RezultatDetectie {
    public $aranjament;         // 'vertical' sau 'orizontal'
    public $carti = [];         // array de CarteDetectata
    public $colturi = [];       // cele 4 colțuri extreme
    public $nr_total_cuvinte;   // pentru debug
}
```

## ACEASTA ESTE REGULA DE BAZĂ DEFINITIVĂ!
**NU MODIFICA** această logică fundamentală fără confirmare explicită.
**ALGORITMUL ESTE COMPLET** și acoperă toate scenariile.

---
*Document creat: 28 August 2025*
*Status: REGULĂ FUNDAMENTALĂ - IMPLEMENTARE OBLIGATORIE*
*Versiune: 1.0 FINALĂ*