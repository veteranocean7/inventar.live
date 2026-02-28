# Configurare Cron Job - Claude Vision Service

**Data:** 28 Februarie 2026

## Opțiunea 1: cPanel (Recomandat)

### Pași:

1. **Accesează cPanel:**
   - URL: https://inventar.live/cpanel
   - Sau: https://server73.romania-webhosting.com:2083
   - Credențiale: aceleași ca FTP (inventar / parola din FileZilla.xml)

2. **Găsește "Cron Jobs":**
   - În cPanel, caută "Cron Jobs" sau "Scheduled Tasks"
   - Se găsește de obicei în secțiunea "Advanced"

3. **Adaugă un nou Cron Job:**
   - **Common Settings:** Once Per Day (sau custom)
   - **Minute:** 0
   - **Hour:** 2
   - **Day:** * (every day)
   - **Month:** * (every month)
   - **Weekday:** * (every weekday)

4. **Command (IMPORTANT - copiază exact):**
   ```
   /usr/bin/php /home/inventar/public_html/cron/procesare_automata_imagini.php >> /home/inventar/public_html/logs/cron_procesare.log 2>&1
   ```

5. **Salvează** și verifică că apare în lista de cron jobs

---

## Opțiunea 2: Serviciu Extern Gratuit (cron-job.org)

Dacă nu ai acces la cPanel sau preferi un serviciu extern:

### Pași:

1. **Creează cont pe [cron-job.org](https://cron-job.org)**
   - Este gratuit pentru utilizare de bază
   - Nu necesită card de credit

2. **Creează un nou Cron Job:**
   - Click pe "CREATE CRONJOB"

3. **Configurare:**
   - **Title:** Inventar.live - Claude Vision Processing
   - **URL:**
     ```
     https://inventar.live/cron/trigger.php?token=inv3nt4r_cl4ud3_v1s10n_2026
     ```
   - **Schedule:** Custom
     - Execution schedule: `0 2 * * *` (zilnic la 02:00)
   - **Timezone:** Europe/Bucharest
   - **Notifications:** Opțional - primești email la erori

4. **Salvează**

### Notă despre securitate:
URL-ul trigger.php conține un token secret. Dacă vrei să schimbi token-ul:
1. Editează `/cron/trigger.php` pe server
2. Schimbă valoarea `CRON_SECRET_TOKEN`
3. Actualizează URL-ul în cron-job.org

---

## Opțiunea 3: Alt Server cu Cron

Dacă ai acces SSH la un alt server (de exemplu serverul Contabo DFD):

```bash
# Adaugă în crontab
crontab -e

# Adaugă linia:
0 2 * * * curl -s "https://inventar.live/cron/trigger.php?token=inv3nt4r_cl4ud3_v1s10n_2026" > /dev/null 2>&1
```

---

## Verificare Funcționare

### 1. Test manual:
```bash
curl "https://inventar.live/cron/trigger.php?token=inv3nt4r_cl4ud3_v1s10n_2026"
```

### 2. Verifică log-urile:
- Accesează via FTP: `/public_html/logs/cron_procesare.log`
- Sau creează un script PHP pentru vizualizare

### 3. Verifică baza de date:
```sql
-- În phpMyAdmin, pe baza inventar_central:
SELECT * FROM procesare_imagini_queue ORDER BY data_adaugare DESC LIMIT 10;
SELECT * FROM claude_vision_stats ORDER BY data_stat DESC LIMIT 7;
```

---

## Troubleshooting

### "EROARE conexiune Claude API: Overloaded"
- API-ul Claude era temporar supraîncărcat
- Scriptul va reîncerca automat la următoarea rulare
- Nu necesită intervenție

### "Tabela procesare_imagini_queue nu există"
- Rulează `sql/claude_vision_queue.sql` în phpMyAdmin

### "API key nu este configurat"
- Verifică `config_claude.php` pe server
- Trebuie să conțină un API key valid de la Anthropic

### Scriptul nu rulează deloc
- Verifică calea PHP: `/usr/bin/php` sau `/usr/local/bin/php`
- Verifică permisiunile pe script: trebuie să fie executabil

---

## Fișiere Implicate

| Fișier | Rol |
|--------|-----|
| `cron/procesare_automata_imagini.php` | Scriptul principal de procesare |
| `cron/trigger.php` | Trigger HTTP pentru servicii externe |
| `logs/cron_procesare.log` | Log-uri de execuție |
| `config_claude.php` | Configurare API key |

---

*Documentație creată: 28 Februarie 2026*
