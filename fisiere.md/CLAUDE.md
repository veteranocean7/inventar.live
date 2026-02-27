# Inventar.live - Instrucțiuni pentru Claude

## Preferințe Dezvoltare

- Pentru crearea sau modificarea tabelelor din baza de date prefer comenzile directe SQL pe care le pot aplica în phpMyAdmin
- Folosește întotdeauna căi relative, nu absolute
- Nu face presupuneri, verifică mai întâi contextul

---

## Documentație Proiect

### Documentație Tehnică

| Document | Conținut | Când să citești |
|----------|----------|-----------------|
| `FLUX_INFORMATIE.md` | **Fluxul complet al informației** - Arhitectură, autentificare, CRUD, AI Vision, PWA/Offline, partajare, DB schema | La început de sesiune sau pentru înțelegerea aplicației |
| `CLAUDE_VISION_SERVICE.md` | **Claude Vision Service** - Identificare automată obiecte cu Claude Haiku, procesare nocturnă, cost-eficient | Când lucrezi la identificarea automată a obiectelor |
| `PWA_IMPLEMENTATION.md` | **Implementare PWA completă** - Service Worker, IndexedDB, Offline sync, Install assistant, Icon personalizat | Când lucrezi la funcționalități PWA/offline |
| `DATABASE_SCHEMA.md` | Schema bazei de date | Când lucrezi cu baza de date |
| `STATUS_AUGUST_2025.md` | Status implementări | Pentru a vedea ce e implementat |
| `GOOGLE_VISION_INTEGRATION.md` | Integrare Google Vision AI (legacy) | Referință pentru sistemul vechi |

### PWA - Progressive Web App

**Status:** Faza 3 Completă (23 Ianuarie 2026)

**Funcționalități implementate:**
- ✅ Instalabilă pe Android și iOS
- ✅ Funcționează offline (cache + IndexedDB)
- ✅ Sincronizare automată când revine conexiunea
- ✅ Queue pentru operații offline (Background Sync)
- ✅ Modal asistență instalare (Android + iOS)
- ✅ Icon personalizat "Cutie cu Grid"

**Fișiere cheie PWA:**
```
public_html/
├── manifest.json          # Manifest PWA
├── sw.js                  # Service Worker v2.1.0
├── favicon.ico            # Favicon
├── offline.html           # Pagina offline
├── api_inventar.php       # API pentru sync
├── icons/
│   ├── logo-inventar.svg  # Icon SVG sursă
│   └── icon-*.png         # Iconuri toate dimensiunile
└── js/
    ├── idb-manager.js         # IndexedDB manager
    ├── offline-sync.js        # Sincronizare date
    ├── offline-operations.js  # Queue operații offline
    ├── pending-operations-ui.js # UI operații în așteptare
    └── pwa-install-assistant.js # Modal instalare PWA
```

**Detalii complete:** Vezi `PWA_IMPLEMENTATION.md`

---

## Structură Proiect

```
public_html/
├── index.php              # Pagina principală (listă obiecte)
├── login.php              # Autentificare
├── config.php             # Configurare DB
├── api_inventar.php       # API JSON pentru PWA
├── css/
│   ├── style.css          # Stiluri principale
│   └── style-telefon.css  # Responsive mobile
├── js/                    # JavaScript (vezi PWA mai sus)
├── icons/                 # Iconuri PWA
├── imagini_obiecte/       # Fotografii obiecte
├── imagini_decupate/      # Thumbnails decupate
└── fisiere.md/            # Documentație
```

---

## GitHub Repositories

**Status:** Configurat pe ambele conturi (27 Februarie 2026)

| Cont | Repository | Vizibilitate | URL |
|------|------------|--------------|-----|
| veteranocean7 | inventar.live | Privat | https://github.com/veteranocean7/inventar.live |
| CornelVeteran7 | inventar.live | Privat | https://github.com/CornelVeteran7/inventar.live |

**Remote-uri configurate:**
- `origin` → veteranocean7 (principal)
- `backup` → CornelVeteran7 (backup)

### OBLIGATORIU: Push pe AMBELE conturi la fiecare update!

La fiecare commit, codul trebuie propagat pe ambele profiluri GitHub pentru backup redundant.

**Alias configurat:** Folosește comanda simplificată:
```bash
git pushall
```

Echivalent cu: `git push origin main && git push backup main`

**Credențiale GitHub:** Vezi `GitHub_profile_Cornel.md` (în același director - fisiere.md/)
- Conține token-uri, comenzi pentru creare repos, și configurare git
- ATENȚIE: Fișierul este exclus din git (.gitignore) pentru securitate

**Fișiere excluse din git (.gitignore):**
- `config.php`, `config_*.php` - configurări DB
- `google-vision-key.json` - credențiale Google
- `api_GV_config.php` - config Google Vision
- `vendor/` - dependențe (regenerabil cu `composer install`)
- `imagini_obiecte/`, `imagini_decupate/` - date utilizator

---

## Deploy

**Server:** inventar.live
**Protocol:** FTP
**Credențiale:** Vezi FileZilla.xml din TID4K

**Comandă deploy fișier:**
```bash
curl -s -T "fisier.php" "ftp://ftp.inventar.live/public_html/fisier.php" --user "inventar:PASSWORD"
```

---

## Simbolul Aplicației - "Cutie cu Grid"

Simbolul caracteristic al aplicației este o cutie de depozitare cu pattern de grid, folosit în:
- `.user-avatar-box` - avatarul utilizatorului logat
- `.global-grid-box` - iconul din header pentru partajare
- Icon PWA (`icons/logo-inventar.svg`)

**Design CSS:**
```css
background-color: #e0e0e0;
background-image:
    linear-gradient(rgba(160, 160, 160, 0.4) 1px, transparent 1px),
    linear-gradient(90deg, rgba(160, 160, 160, 0.4) 1px, transparent 1px);
background-size: 8px 8px;
border: 2px solid #555;
border-top-width: 4px;
```

---

*Ultima actualizare: 27 Februarie 2026*
