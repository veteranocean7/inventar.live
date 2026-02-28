---
name: deploy-inventar
description: Deploy fișiere pe serverul inventar.live via FTP. Folosește pentru a urca modificări pe producție.
user-invocable: true
allowed-tools: Bash, Read, Glob
argument-hint: "[fisier.php] sau [all] pentru toate modificările"
---

# Deploy inventar.live

Acest skill face deploy pe serverul inventar.live via FTP.

## Credențiale FTP

- **Host:** ftp.inventar.live
- **User:** inventar
- **Password:** Se citește din FileZilla.xml (base64: ZmhVJjV3bWNpTDdm)

## Comenzi disponibile

### Deploy fișier specific:
```bash
/deploy-inventar fisier.php
/deploy-inventar includes/claude_vision_service.php
```

### Deploy toate fișierele modificate (git):
```bash
/deploy-inventar all
```

### Deploy director:
```bash
/deploy-inventar cron/
```

## Procedură Deploy

Când primești comanda `/deploy-inventar $ARGUMENTS`:

1. **Dacă $ARGUMENTS este "all":**
   - Rulează `git status --porcelain` pentru a vedea fișierele modificate
   - Pentru fiecare fișier modificat (exclus cele din .gitignore), fă upload

2. **Dacă $ARGUMENTS este un fișier sau director:**
   - Verifică că fișierul există local
   - Fă upload pe server

3. **Comandă FTP pentru upload:**
   ```bash
   NETRC=$(mktemp)
   echo -e "machine ftp.inventar.live\nlogin inventar\npassword fhU&5wmciL7f" > "$NETRC"
   curl -s -n --netrc-file "$NETRC" -T "FISIER_LOCAL" "ftp://ftp.inventar.live/public_html/CALE_REMOTE"
   rm -f "$NETRC"
   ```

4. **Verifică upload-ul** - listează fișierul pe server pentru confirmare

5. **Raportează** ce fișiere au fost urcate

## Fișiere EXCLUSE din deploy automat

NU urca niciodată aceste fișiere (conțin credențiale sau date locale):
- config.php
- config_central.php
- config_claude.php
- google-vision-key.json
- *.log
- vendor/
- imagini_obiecte/
- imagini_decupate/
- .git/
- .idea/

## Exemplu output așteptat

```
Deploy inventar.live
====================
Fișiere de urcat: 3

✓ includes/claude_vision_service.php (14KB)
✓ cron/procesare_automata_imagini.php (12KB)
✓ adauga_obiect.php (15KB)

Deploy complet! 3 fișiere urcate.
```
