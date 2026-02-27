# ALGORITM FUNDAMENTAL - IDENTIFICARE COLȚURI PENTRU CĂRȚI
## Documentație Critică - 27 August 2025

### A. LOGICA PENTRU ARANJAMENT STIVĂ (cărți una peste alta pe înălțime)

#### 1. COLȚUL STÂNGA-SUS
- **Y-ul CEL MAI MIC** din toate cuvintele
- **X-ul CEL MAI MIC** dintre cuvintele cu acest Y minim
- Exemplu din imagine: "322" (primul cuvânt de pe prima carte)

#### 2. COLȚUL DREAPTA-SUS  
- **Același Y** sau **imediat următorul Y** după Y-ul minim
- **X-ul CEL MAI MARE** dintre toate cuvintele cu acest Y
- Exemplu din imagine: "TOPH" sau "furia" (ultimul cuvânt de pe prima carte)

#### 3. COLȚUL STÂNGA-JOS
- **Y-ul CEL MAI MARE** din toate cuvintele  
- **X-ul CEL MAI MIC** dintre cuvintele cu acest Y maxim
- Exemplu din imagine: "POLIROM" (primul cuvânt de pe ultima carte)

#### 4. COLȚUL DREAPTA-JOS
- **Y-ul CEL MAI MARE** (același cu stânga-jos sau foarte apropiat)
- **X-ul CEL MAI MARE** dintre toate cuvintele cu acest Y maxim
- Este ultimul cuvânt de pe ultima carte

### OBSERVAȚII CRITICE:
1. **NU folosim distanța euclidiană** pentru colțuri - aceasta duce la erori
2. **Prioritizăm Y-ul** pentru identificare (Y minim pentru sus, Y maxim pentru jos)
3. **X-ul diferențiază** între stânga (X minim) și dreapta (X maxim)
4. **Toleranță minimă pe Y** pentru a grupa cuvintele de pe același cotor

### PSEUDOCOD PENTRU IMPLEMENTARE:
```
1. Găsește Y_min și Y_max din toate cuvintele
2. Pentru Y_min:
   - Stânga-sus = cuvântul cu X_min
   - Dreapta-sus = cuvântul cu X_max
3. Pentru Y_max:
   - Stânga-jos = cuvântul cu X_min
   - Dreapta-jos = cuvântul cu X_max
4. Dacă există mici diferențe de Y (toleranță), grupează cuvintele
```

### B. LOGICA PENTRU ARANJAMENT ORIZONTAL (cărți una lângă cealaltă)

**Pentru cărți aranjate orizontal, X și Y își schimbă rolurile:**

#### 1. COLȚUL STÂNGA-SUS
- **X-ul CEL MAI MIC** din toate cuvintele
- **Y-ul CEL MAI MIC** dintre cuvintele cu acest X minim
- Este primul cuvânt de sus de pe prima carte (din stânga)

#### 2. COLȚUL DREAPTA-SUS
- **X-ul CEL MAI MARE** din toate cuvintele
- **Y-ul CEL MAI MIC** dintre cuvintele cu acest X maxim
- Este primul cuvânt de sus de pe ultima carte (din dreapta)

#### 3. COLȚUL STÂNGA-JOS
- **X-ul CEL MAI MIC** din toate cuvintele
- **Y-ul CEL MAI MARE** dintre cuvintele cu acest X minim
- Este ultimul cuvânt de jos de pe prima carte (din stânga)

#### 4. COLȚUL DREAPTA-JOS
- **X-ul CEL MAI MARE** din toate cuvintele
- **Y-ul CEL MAI MARE** dintre cuvintele cu acest X maxim
- Este ultimul cuvânt de jos de pe ultima carte (din dreapta)

### OBSERVAȚII PENTRU ARANJAMENT ORIZONTAL:
1. **Cotoarele sunt VERTICALE** (de sus în jos)
2. **X-ul diferențiază** între cărți diferite
3. **Y-ul diferențiază** între poziția pe același cotor

### PSEUDOCOD UNIVERSAL:
```
1. Detectează orientarea (vertical sau orizontal)
2. Pentru VERTICAL (stivă):
   - Prioritate: Y pentru separare cărți, X pentru capete cotor
3. Pentru ORIZONTAL (alături):
   - Prioritate: X pentru separare cărți, Y pentru capete cotor
4. Aplică toleranță mică pentru variații minore
```

### ACEASTA ESTE BAZA ALGORITMULUI!
**NU MODIFICA** această logică fundamentală fără confirmare explicită.
**ALGORITMUL ESTE UNIVERSAL** - nu depinde de cuvinte specifice.

---
*Document creat: 27 August 2025*
*Autor: Claude - bazat pe explicația critică a utilizatorului*
*Status: LOGICĂ FUNDAMENTALĂ - NU MODIFICA*
*Actualizat: Adăugat logica pentru aranjament orizontal*