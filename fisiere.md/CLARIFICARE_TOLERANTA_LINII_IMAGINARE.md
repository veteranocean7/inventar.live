# CLARIFICARE CONCEPTUALĂ - TOLERANȚĂ ȘI LINII IMAGINARE
## Pentru algoritmul de identificare cărți prin Google Vision
### Data: 28 August 2025

## CONCEPT FUNDAMENTAL: LINII IMAGINARE ȘI TOLERANȚĂ

### Pentru CĂRȚI STIVUITE VERTICAL (una peste alta)

#### Caracteristici geometrice:
- **Aranjament fizic**: Cărțile sunt culcate orizontal, stivuite una peste alta
- **Cotoare**: Sunt **ORIZONTALE** (se citesc de la stânga la dreapta)
- **Textul pe cotor**: Se distribuie pe **axa X** (orizontal)

#### Concept de LINIE IMAGINARĂ:
- **Linia imaginară** este o linie **ORIZONTALĂ** trasată prin mijlocul fiecărui cotor
- Această linie are o **coordonată Y fixă** (înălțimea cotorului în imagine)
- Fiecare carte/cotor are propria sa linie imaginară la Y specific

#### Aplicarea TOLERANȚEI:
```
Pentru fiecare cotor cu linia imaginară la Y_cotor:
- Colectăm TOATE cuvintele cu Y în intervalul: [Y_cotor - toleranță, Y_cotor + toleranță]
- Toleranța standard: ±30-80px pe axa Y
- Aceasta acoperă variațiile de înălțime ale textului pe cotor (text mai mare/mic, aliniere)
```

#### Exemplu concret:
```
Cotor la Y=2400 (primul cotor de sus):
- Linia imaginară: Y=2400
- Cu toleranță 50px: colectăm cuvinte cu Y între 2350 și 2450
- Cuvintele găsite: "322", "de", "vorbe", "memorabile", "ale", "lui", "PETRE", "ȚUȚEA"
- Sortăm pe X: formăm textul complet de la stânga la dreapta
```

### Pentru CĂRȚI ALĂTURATE ORIZONTAL (una lângă alta)

#### Caracteristici geometrice:
- **Aranjament fizic**: Cărțile stau vertical, alăturate una lângă alta
- **Cotoare**: Sunt **VERTICALE** (se citesc de sus în jos)
- **Textul pe cotor**: Se distribuie pe **axa Y** (vertical)

#### Concept de LINIE IMAGINARĂ:
- **Linia imaginară** este o linie **VERTICALĂ** trasată prin mijlocul fiecărui cotor
- Această linie are o **coordonată X fixă** (poziția laterală a cotorului)
- Fiecare carte/cotor are propria sa linie imaginară la X specific

#### Aplicarea TOLERANȚEI:
```
Pentru fiecare cotor cu linia imaginară la X_cotor:
- Colectăm TOATE cuvintele cu X în intervalul: [X_cotor - toleranță, X_cotor + toleranță]
- Toleranța standard: ±30-80px pe axa X
- Aceasta acoperă variațiile laterale ale textului pe cotor
```

## DIFERENȚA FAȚĂ DE ALGORITMUL ACTUAL

### Problemă în implementarea curentă:
Algoritmul actual folosește **Y mediu dinamic** care se recalculează după fiecare adăugare:
```php
$grup['y_mediu'] = $suma_y / count($grup['elemente']);
```
Aceasta poate cauza "deriva" grupului și includerea greșită a cuvintelor de pe cotoare vecine.

### Abordare corectă recomandată:
1. **Identifică Y-urile distincte** (cu toleranță mică pentru a le grupa)
2. **Fixează linia imaginară** pentru fiecare cotor
3. **Colectează cuvintele** folosind toleranța față de linia fixă
4. **Nu recalcula** poziția liniei în timpul colectării

## PARAMETRI RECOMANDAȚI

### Pentru cărți standard (15-30mm grosime cotor):
- **Toleranță identificare Y distinct**: 20-30px
- **Toleranță colectare cuvinte**: 50-80px
- **Distanță minimă între cotoare**: 100-150px

### Pentru cărți subțiri (5-15mm grosime):
- **Toleranță identificare Y distinct**: 15-20px
- **Toleranță colectare cuvinte**: 30-50px
- **Distanță minimă între cotoare**: 50-100px

## ALGORITM ÎMBUNĂTĂȚIT PROPUS

```pseudocode
// PASUL 1: Identifică liniile imaginare (Y-uri distincte pentru cotoare)
y_cotoare = []
toleranta_identificare = 30

pentru fiecare element în text:
    gasit = false
    pentru fiecare y_cotor în y_cotoare:
        dacă |element.y - y_cotor| < toleranta_identificare:
            gasit = true
            break
    dacă nu gasit:
        y_cotoare.adaugă(element.y)

// PASUL 2: Pentru fiecare linie imaginară, colectează cuvintele
toleranta_colectare = 80
pentru fiecare y_cotor în y_cotoare:
    cuvinte_cotor = []
    pentru fiecare element în text:
        dacă |element.y - y_cotor| <= toleranta_colectare:
            cuvinte_cotor.adaugă(element)
    
    // PASUL 3: Sortează cuvintele pe X (stânga-dreapta)
    sortează(cuvinte_cotor, după: x)
    
    // PASUL 4: Creează textul cărții
    text_carte = concatenare(cuvinte_cotor)
```

## BENEFICII ALE ACESTEI ABORDĂRI

1. **Stabilitate**: Liniile imaginare sunt fixe, nu se modifică dinamic
2. **Precizie**: Evită "alunecarea" între cotoare vecine
3. **Predictibilitate**: Rezultate consistente pentru aceeași imagine
4. **Claritate conceptuală**: Ușor de înțeles și debugat

## CONCLUZIE

Conceptul de **linii imaginare fixe** cu **toleranță aplicată consistent** este fundamental pentru gruparea corectă a textului de pe cotoarele cărților. Această abordare respectă geometria fizică a aranjamentului și produce rezultate predictibile și corecte.

---
*Document creat: 28 August 2025*
*Autor: Claude - clarificare conceptuală pentru algoritmul de detecție cărți*
*Status: CONCEPT FUNDAMENTAL - Pentru integrare în algoritm*