# Fluxul Complet al Informației - inventar.live

**Document creat:** 27 Februarie 2026
**Scop:** Documentarea completă a fluxurilor de date în aplicație

---

## 1. ARHITECTURĂ MULTI-TENANT

```
┌─────────────────────────────────────┐
│      inventar_central (DB)          │  ← Meta-date, utilizatori, sesiuni
├─────────────────────────────────────┤
│  inventar_user_1 (DB per user)      │  ← Date obiecte User 1
│  inventar_user_2 (DB per user)      │  ← Date obiecte User 2
│  ...                                │
└─────────────────────────────────────┘
```

Fiecare utilizator are propria bază de date pentru izolare completă a datelor.

---

## 2. FLUXUL DE AUTENTIFICARE

```
┌─────────────────────────────────────────────────────────┐
│         AUTENTIFICARE - SISTEM MULTI-TENANT             │
└─────────────────────────────────────────────────────────┘

REGISTRATION:
  register.php → validare date → generateUserDbName() →
  INSERT utilizatori → createSession() → setcookie() →
  setup_user_db.php (creează DB per user)

LOGIN:
  login.php → validateEmail() → verifyPassword() →
  createSession() (generează token) → setcookie() →
  index.php (redirect)

SESSION CHECK:
  checkSession() → verifică cookie →
  query sesiuni cu token → verifică expirare →
  returnează user object

LOGOUT:
  logout.php → destroySession() → UPDATE sesiuni SET activa=0 →
  DELETE cookie → login.php (redirect)
```

**Tabele implicate (inventar_central):**
- `utilizatori` - id, email, parola_hash, db_name
- `sesiuni` - token, id_utilizator, ip, expirare
- `log_autentificare` - tracking evenimente

---

## 3. FLUXUL CRUD OBIECTE

```
┌─────────────────────────────────────────────────────────┐
│         FLUXUL CRUD - GESTIONARE OBIECTE                │
└─────────────────────────────────────────────────────────┘

CREARE (CREATE):
  index.php (form) → adauga_obiect.php (POST) →
  validateSession() → verifică acces colecție →
  procesare imagini upload → INSERT {prefix}obiecte →
  creare thumbnail → salvare în imagini_obiecte/user_{id}/

CITIRE (READ):
  index.php → api_inventar.php?action=sync (AJAX) →
  SELECT * FROM {prefix}obiecte ORDER BY data_adaugare DESC →
  returnează JSON → afișare în UI

ACTUALIZARE (UPDATE):
  index.php (modal edit) → actualizeaza_obiect.php (POST) →
  validateSession() → verifică acces →
  UPDATE {prefix}obiecte SET ... →
  dacă schimb imagine: șterge veche, salvează nouă

ȘTERGERE (DELETE):
  Șterge imagine: sterge_imagine.php
  Șterge cutie: sterge_cutie.php (toate obiectele din cutie)
  DELETE FROM {prefix}obiecte WHERE ...
```

**Tabele implicate (inventar_user_{id}):**
- `{prefix}obiecte` - id_obiect, denumire, categoria, cutie, locatie, imagine, etc.
- `{prefix}obiecte_imagini` - imagini multiple per obiect

---

## 4. FLUXUL GOOGLE VISION AI

```
┌─────────────────────────────────────────────────────────┐
│    GOOGLE VISION - DETECTARE AUTOMATĂ                   │
└─────────────────────────────────────────────────────────┘

UPLOAD ȘI ANALIZĂ:
  adauga_obiect.php → save imagine →
  trigger JS → detectare_google_vision.php (POST)

PROCESARE VISION API:
  detectare_google_vision.php →
  load google-vision-key.json (Service Account) →
  ImageAnnotatorClient → annotateImage()

  Features folosite:
  ├─ OBJECT_LOCALIZATION (detectare obiecte cu bounding box)
  ├─ LABEL_DETECTION (etichete descriptive)
  └─ TEXT_DETECTION (OCR pentru cărți/etichete)

DETECTARE CĂRȚI (procesare_cutie_vision.php v3.0):
  Algoritm grupare: numără linii/coloane distincte →
  Pattern matching: TITLU → AUTOR → EDITURA →
  Output: array cu cărți detectate

TRADUCERE AUTOMATĂ:
  traducere_automata.php →
  1. Verifică cache (traduceri_cache)
  2. Cache MISS: Google Translate API
  3. Salvează în cache cu metadata

SALVARE REZULTATE:
  INSERT INTO {prefix}detectii_vision
  UPDATE {prefix}obiecte SET detectare_vision_marcat = 1
```

**Fișiere cheie:**
- `google-vision-key.json` - credențiale Service Account
- `detectare_google_vision.php` - API call principal
- `procesare_cutie_vision.php` - algoritm detectare cărți
- `traducere_automata.php` - traducere EN→RO

---

## 5. FLUXUL PWA/OFFLINE

```
┌─────────────────────────────────────────────────────────┐
│    PWA + OFFLINE MODE - SINCRONIZARE DATE               │
└─────────────────────────────────────────────────────────┘

SERVICE WORKER (sw.js v2.1.0):
  ├─ INSTALL: precache static assets
  ├─ ACTIVATE: cleanup old caches
  └─ FETCH: interceptare cereri

  Cache Strategies:
  ├─ STATIC (html, css, js): stale-while-revalidate
  ├─ API (api_inventar.php): network-first → offline-fallback
  └─ IMAGES: cache-first, max 100 imagini × 5MB

INDEXEDDB (idb-manager.js):
  Database: inventar_offline (v1)

  Stores:
  ├─ obiecte (keyPath: id_obiect)
  ├─ colectii (keyPath: id_colectie)
  ├─ imagini_cache (keyPath: url)
  ├─ sync_queue (operații offline în așteptare)
  └─ metadata (last_sync_time, current_colectie)

SINCRONIZARE (offline-sync.js):

  SERVER → IDB:
  api_inventar.php?action=sync →
  salvare în IDB.Obiecte.saveAll()

  OPERAȚII OFFLINE (offline-operations.js):
  A) ONLINE: fetch normal → syncLocalAfterOperation()
  B) OFFLINE: queueOfflineOperation() → salvare în sync_queue →
     când online: retry automat

OPERAȚII OFFLINE-CAPABLE:
  ├─ UPDATE_OBIECT: actualizeaza_obiect.php
  ├─ DELETE_OBIECT: sterge imagine
  ├─ DELETE_CUTIE: sterge cutie întreagă
  └─ UPDATE_COLECTIE: schimb colecție

UI PENDING (pending-operations-ui.js):
  Display: "2 operații în așteptare"
  Manual sync button disponibil
```

**Fișiere cheie:**
- `sw.js` - Service Worker
- `js/idb-manager.js` - IndexedDB manager
- `js/offline-sync.js` - sincronizare date
- `js/offline-operations.js` - queue operații offline
- `js/pending-operations-ui.js` - UI operații pending
- `api_inventar.php` - API endpoint pentru sync

---

## 6. FLUXUL DE PARTAJARE/COLABORARE

```
┌─────────────────────────────────────────────────────────┐
│    COLECȚII MULTIPLE + PARTAJARE                        │
└─────────────────────────────────────────────────────────┘

COLECȚII MULTIPLE (ajax_colectii.php):

  Creare colecție:
  INSERT INTO colectii_utilizatori →
  CREATE TABLE {prefix}obiecte (nou prefix)

  Schimb colecție:
  UPDATE $_SESSION['id_colectie_curenta'] →
  reload index.php

PARTAJARE (ajax_partajare.php):

  User A selectează User B →
  INSERT INTO partajari (
    id_colectie, id_utilizator_partajat,
    tip_acces: 'citire' | 'scriere',
    nivel_partajare: 'familie' | 'public'
  ) →
  INSERT notificare → email User B

  Control acces:
  - PROPRIETAR: acces complet
  - PARTENER SCRIERE: poate modifica/adăuga/șterge
  - PARTENER CITIRE: read-only
  - PUBLIC: căutare anonimă
```

**Tabele implicate:**
- `colectii_utilizatori` - colecții per user
- `partajari` - permisiuni partajare
- `notificari_partajare` - notificări
- `colectii_publice` - colecții publice

---

## 7. FLUXUL DE ÎMPRUMUTURI

```
┌─────────────────────────────────────────────────────────┐
│    SISTEM ÎMPRUMUTURI                                   │
└─────────────────────────────────────────────────────────┘

CERERE ÎMPRUMUT (ajax_imprumut.php):
  User A → trimitereCerereImprumut() →
  INSERT INTO cereri_imprumut (
    id_solicitant, id_proprietar,
    id_obiect, status='in_asteptare',
    data_inceput, data_sfarsit
  ) →
  notificare email → User B (proprietar)

RĂSPUNS CERERE:
  User B → raspundeCerere() →
  UPDATE cereri_imprumut SET
    status = 'aprobata' | 'respinsa'

RETURNARE:
  confirmaTransfer() →
  UPDATE status = 'returnata' →
  INSERT INTO istoric_imprumuturi (rating)
```

**Tabele implicate:**
- `cereri_imprumut` - cereri active
- `mesaje_utilizatori` - chat între parteneri
- `istoric_imprumuturi` - istoric + rating
- `setari_confidentialitate` - preferințe user

---

## 8. STRUCTURA BAZEI DE DATE

### Baza de date CENTRALĂ (inventar_central)

```sql
utilizatori
├─ id_utilizator (PK)
├─ email (UNIQUE)
├─ parola_hash
├─ db_name (inventar_user_{id})
├─ id_colectie_principala (FK)
└─ rating_mediu

colectii_utilizatori
├─ id_colectie (PK)
├─ id_utilizator (FK)
├─ nume_colectie
├─ prefix_tabele (UNIQUE)
├─ este_principala
└─ este_publica

partajari
├─ id_partajare (PK)
├─ id_colectie (FK)
├─ id_utilizator_partajat (FK)
├─ tip_acces ('citire'|'scriere')
└─ nivel_partajare ('familie'|'public')

sesiuni
├─ id_sesiune (PK)
├─ id_utilizator (FK)
├─ token_sesiune (UNIQUE)
├─ data_expirare
└─ activa

cereri_imprumut
├─ id_cerere (PK)
├─ id_solicitant (FK)
├─ id_proprietar (FK)
├─ id_obiect
├─ status ('in_asteptare'|'aprobata'|'respinsa'|'returnata')
└─ date_range

notificari_partajare
├─ id_notificare (PK)
├─ id_utilizator_destinatar (FK)
├─ tip_notificare
├─ mesaj
└─ citita
```

### Baze de date PER-USER (inventar_user_{id})

```sql
{prefix}obiecte
├─ id_obiect (PK)
├─ denumire_obiect
├─ categoria
├─ descriere
├─ cantitate_obiect
├─ cutie
├─ locatie
├─ locatie_x, locatie_y (GPS)
├─ imagine_obiect
├─ eticheta_color
├─ data_adaugare (INDEX)
├─ id_colectie (FK)
└─ INDEX(locatie, cutie)

{prefix}obiecte_imagini
├─ id_imagine (PK)
├─ id_obiect (FK)
├─ cale_imagine
└─ cale_thumbnail

{prefix}traduceri_cache
├─ text_original (UNIQUE)
├─ text_tradus
├─ limba_sursa/destinatie
├─ numar_folosiri
└─ data_ultima_folosire

{prefix}detectii_vision
├─ id_detectie (PK)
├─ id_obiect (FK)
├─ label
├─ confidence
└─ data_detectie
```

---

## 9. DIAGRAMA COMPLETĂ FLUX DATE

```
        ┌─────────────────────────────────┐
        │         UTILIZATOR              │
        │   Browser / PWA / Mobile        │
        └───────────────┬─────────────────┘
                        │
        ┌───────────────▼─────────────────┐
        │      LAYER AUTENTIFICARE        │
        │   includes/auth_functions.php   │
        │   checkSession() / verifyToken  │
        └───────────────┬─────────────────┘
                        │
        ┌───────────────▼─────────────────┐
        │      REQUEST ROUTING            │
        ├─────────────────────────────────┤
        │ adauga_obiect.php      (POST)   │
        │ actualizeaza_obiect.php (POST)  │
        │ ajax_colectii.php      (AJAX)   │
        │ ajax_partajare.php     (AJAX)   │
        │ ajax_imprumut.php      (AJAX)   │
        │ api_inventar.php       (API)    │
        │ detectare_google_vision.php     │
        │ traducere_automata.php          │
        │ sterge_cutie.php                │
        └───────────────┬─────────────────┘
                        │
    ┌───────────────────┼───────────────────┐
    │                   │                   │
    ▼                   ▼                   ▼
┌─────────────┐  ┌─────────────┐  ┌─────────────────────┐
│   MySQL     │  │   FILES     │  │   GOOGLE APIs       │
│   Central   │  │   Storage   │  │                     │
│   + User    │  │             │  │ Vision API          │
│             │  │ imagini_    │  │ (Object Detection)  │
│ utilizatori │  │ obiecte/    │  │                     │
│ colectii    │  │ user_{id}/  │  │ Translate API       │
│ partajari   │  │             │  │ (EN → RO)           │
│ sesiuni     │  │ Thumbnails  │  │                     │
│ obiecte     │  │             │  │ Service Account:    │
│ detectii    │  │             │  │ google-vision-key   │
└─────────────┘  └─────────────┘  └─────────────────────┘
        │
        └────────────────────────────────────┐
                                             │
        ┌────────────────────────────────────▼───┐
        │      INDEXEDDB (Client-Side Cache)     │
        │      inventar_offline database         │
        ├────────────────────────────────────────┤
        │ obiecte      (sync din server)         │
        │ colectii     (lista colecții)          │
        │ sync_queue   (operații offline)        │
        │ metadata     (last_sync_time)          │
        └────────────────────────────────────────┘
                        ▲
                        │
        ┌───────────────┴─────────────────┐
        │      SERVICE WORKER (sw.js)     │
        │      Cache + Offline Support    │
        └─────────────────────────────────┘
```

---

## 10. FIȘIERE PRINCIPALE ȘI ROLUL LOR

| Fișier | Rol |
|--------|-----|
| `index.php` | Pagina principală, afișare obiecte |
| `login.php` | Autentificare |
| `register.php` | Înregistrare cont nou |
| `config.php` | Configurare DB |
| `api_inventar.php` | API JSON pentru PWA sync |
| `adauga_obiect.php` | Adăugare obiect nou |
| `actualizeaza_obiect.php` | Actualizare obiect |
| `sterge_cutie.php` | Ștergere cutie/obiecte |
| `detectare_google_vision.php` | Apel Google Vision API |
| `traducere_automata.php` | Traducere automată |
| `procesare_cutie_vision.php` | Algoritm detectare cărți |
| `ajax_colectii.php` | CRUD colecții |
| `ajax_partajare.php` | Partajare colecții |
| `ajax_imprumut.php` | Sistem împrumuturi |
| `sw.js` | Service Worker PWA |
| `js/idb-manager.js` | IndexedDB manager |
| `js/offline-sync.js` | Sincronizare offline |
| `js/offline-operations.js` | Queue operații offline |
| `includes/auth_functions.php` | Funcții autentificare |
| `includes/email_notifications.php` | Notificări email |

---

## 11. CONFIGURAȚII CHEIE

```php
// SESSION
COOKIE_NAME = 'inventar_session_token'
COOKIE_EXPIRY = 30 zile
COOKIE_SECURE = true (HTTPS)
COOKIE_HTTPONLY = true

// DATABASE
CENTRAL_DB = inventar_central
USER_DB_PREFIX = inventar_user_

// FILE UPLOAD
MAX_IMAGE_SIZE = 5MB
ALLOWED_FORMATS = jpg, jpeg, png, gif, webp
UPLOAD_DIR = imagini_obiecte/user_{id}/
THUMBNAIL_SIZE = 200x200px

// PWA
SW_CACHE_VERSION = 2.1
IDB_DB_NAME = inventar_offline
MAX_IMAGES_CACHE = 100
SYNC_MAX_RETRIES = 5
```

---

*Document generat: 27 Februarie 2026*
