# Sistem de Învățare Automată pentru Google Vision
**Status: COMPLET FUNCȚIONAL** ✅  
**Data implementării: 21 August 2025**

## Prezentare Generală
Sistemul de învățare automată permite aplicației să îmbunătățească continuu acuratețea detecției obiectelor prin Google Vision, învățând din feedback-ul utilizatorilor când aceștia șterg obiecte detectate incorect.

## Problema Rezolvată
Google Vision detecta frecvent obiecte false pozitive (ex: "Mașină", "Tehnologie", "Font") în locații unde acestea nu puteau exista fizic (ex: pod, dulap). Sistemul învață automat din corecțiile utilizatorilor și exclude aceste obiecte în procesările viitoare.

### Rezultate Demonstrate
- **Înainte**: 20+ obiecte false detectate într-o cutie
- **După 1 ciclu de învățare**: 4-6 obiecte (majoritatea corecte)  
- **După 2-3 cicluri**: 0 obiecte false, doar obiecte reale

## Arhitectura Sistemului

### 1. Componente Principale

#### **hook_stergere_vision.php**
- Funcție principală: `inregistreazaStergereVision()`
- Detectează când un obiect Vision (marcat cu #ff6600) este șters
- Înregistrează ștergerea în `context_corectii` pentru audit
- Actualizează `context_locatii` cu obiectul exclus
- Funcționează pentru toți utilizatorii (user_X_obiecte)

#### **actualizeaza_obiect.php** (modificat)
- Integrat hook la linia ~354-395
- Detectează automat ștergerile din liste de obiecte
- Compară lista veche cu cea nouă pentru a identifica obiectele șterse
- Apelează hook-ul doar pentru obiecte Vision (#ff6600)

#### **includes/class.ContextManager.php**
- Verifică obiectele detectate împotriva contextului
- Respectă lista `obiecte_excluse` din `context_locatii`
- Blochează obiectele care au fost marcate ca false pozitive

### 2. Flux de Funcționare

```
1. DETECȚIE INIȚIALĂ
   procesare_cutie_vision.php → Google Vision API → Obiecte detectate
                                                   ↓
                                           ContextManager verifică
                                                   ↓
                                           Obiecte filtrate afișate

2. FEEDBACK UTILIZATOR
   Utilizator șterge obiect fals → actualizeaza_obiect.php
                                           ↓
                                    Hook detectează ștergere
                                           ↓
                                    Verifică dacă e Vision (#ff6600)
                                           ↓
                                    inregistreazaStergereVision()

3. ÎNVĂȚARE
   Hook Vision → context_corectii (istoric)
              → context_locatii.obiecte_excluse (efect imediat)
              → actualizareContextExcluderi()

4. APLICARE ÎNVĂȚARE
   Următoarea scanare → ContextManager verifică obiecte_excluse
                      → Obiectele învățate sunt blocate automat
```

### 3. Structura Bazei de Date

#### Tabela `context_locatii` (BD centrală)
```sql
- locatie: VARCHAR(100)
- cutie: VARCHAR(50)  
- obiecte_excluse: TEXT (listă separată cu virgule)
- obiecte_comune: TEXT
- incredere: DECIMAL(3,2)
- ultima_actualizare: TIMESTAMP
```

#### Tabela `context_corectii` (BD centrală)
```sql
- id_utilizator: INT
- locatie: VARCHAR(100)
- cutie: VARCHAR(50)
- obiect_original: VARCHAR(200) (obiectul șters)
- actiune: ENUM('sters', 'modificat')
- data_corectie: TIMESTAMP
- procesat: BOOLEAN
```

#### Tabela `user_X_obiecte` (BD utilizator)
```sql
- eticheta_obiect: TEXT (conține #ff6600 pentru obiecte Vision)
- denumire_obiect: TEXT (listă obiecte)
- locatie: VARCHAR(100)
- cutie: VARCHAR(50)
```

## Caracteristici Cheie

### ✅ Învățare Automată
- Nu necesită intervenție manuală
- Învață din comportamentul natural al utilizatorilor
- Se îmbunătățește continuu cu fiecare utilizare

### ✅ Multi-tenant
- Funcționează pentru toți utilizatorii
- Folosește prefix dinamic `user_X_` 
- Context partajat între utilizatori pentru învățare mai rapidă

### ✅ Identificare Precisă
- Obiecte Vision marcate cu culoarea #ff6600
- Curăță denumirile de indexuri (ex: "Sârmă(2)" → "Sârmă")
- Comparație case-insensitive pentru robusteță

### ✅ Feedback Imediat
- Excluderile se aplică instant
- Nu necesită reprocesare sau antrenare
- Efect vizibil din următoarea scanare

## Exemple de Utilizare

### Caz Real Documentat
**Locație**: Pod deasupra, Cutie 7
1. **Prima scanare**: 20+ obiecte false (Mașină, Font, Tehnologie, etc.)
2. **Utilizator șterge** obiectele false
3. **Sistem învață** și adaugă în excluderi
4. **A doua scanare**: 4 obiecte (3 corecte, 1 fals)
5. **După ștergere finală**: 0 obiecte false detectate

**Locație**: Cabana grădinarului, Cutie de grătar
1. **Detectate**: 6 obiecte (5 false, 1 corect)
2. **După ștergere**: Sistem a învățat toate cele 5 excluderi
3. **Re-scanare**: 0 obiecte detectate (toate erau false)

## Monitorizare și Debug

### Pentru Verificare Funcționare
```php
// Verificare obiecte excluse pentru o locație
SELECT obiecte_excluse FROM context_locatii 
WHERE locatie = 'Pod deasupra' AND cutie = '7';

// Verificare istoric corecții
SELECT * FROM context_corectii 
WHERE id_utilizator = 1 
ORDER BY data_corectie DESC;
```

### Indicatori de Succes
- Număr descrescător de obiecte detectate per locație
- Creșterea listei `obiecte_excluse` în timp
- Reducerea intervențiilor manuale necesare

## Întreținere

### Curățare Periodică (opțional)
```sql
-- Ștergere corecții procesate mai vechi de 6 luni
DELETE FROM context_corectii 
WHERE procesat = TRUE 
AND data_corectie < DATE_SUB(NOW(), INTERVAL 6 MONTH);
```

### Resetare Context (dacă e necesar)
```sql
-- Resetare excluderi pentru o locație
UPDATE context_locatii 
SET obiecte_excluse = '', incredere = 0.5 
WHERE locatie = 'X' AND cutie = 'Y';
```

## Limitări Cunoscute
1. Învățarea este per locație/cutie - nu se generalizează automat
2. Necesită cel puțin o ștergere manuală pentru a învăța
3. Nu distinge între variante ale aceluiași obiect (ex: "cablu" vs "cablu electric")

## Îmbunătățiri Viitoare Posibile
1. **Învățare cross-location**: Aplicare automată a excluderilor similare în locații asemănătoare
2. **Sugestii proactive**: Sistem care sugerează excluderi bazat pe pattern-uri
3. **Rapoarte de învățare**: Dashboard cu statistici despre ce a învățat sistemul
4. **Export/Import context**: Partajare rapidă a învățării între instalări

## Concluzie
Sistemul de învățare automată transformă Google Vision dintr-un instrument cu multe false pozitive într-unul extrem de precis, adaptându-se continuu la nevoile specifice ale fiecărui utilizator și locație. Implementarea este robustă, scalabilă și necesită zero configurare din partea utilizatorilor.