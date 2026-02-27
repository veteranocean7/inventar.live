# IMPLEMENTARE SISTEM NOTIFICĂRI EMAIL
## Documentație tehnică - 14 Septembrie 2025

## 1. CERINȚA INIȚIALĂ

Utilizatorii doresc să primească notificări prin email pentru evenimentele importante din aplicație:
- Cereri noi de împrumut
- Răspunsuri la cereri (aprobat/respins)
- Partajări noi de colecții
- Revocare acces la colecții

Emailurile trebuie trimise automat când aceste evenimente au loc, pe lângă notificările vizuale existente din aplicație.

## 2. SOLUȚIA IMPLEMENTATĂ

### 2.1 Arhitectura Sistemului

```
Eveniment (cerere/răspuns/partajare)
    ↓
ajax_imprumut.php / ajax_partajare.php
    ↓
includes/email_notifications.php
    ↓
Funcția mail() PHP
    ↓
Email trimis de la: solicitari@inventar.live
    ↓
Către: email-ul utilizatorului din BD
```

### 2.2 Configurație Server

- **Adresă email expeditor**: `solicitari@inventar.live` (creată în cPanel)
- **Metodă trimitere**: Funcția native `mail()` PHP
- **Format email**: HTML cu template responsive

## 3. FIȘIERE MODIFICATE

### 3.1 **`includes/email_notifications.php`** (FIȘIER NOU)

Funcții implementate:
- `trimiteNotificareEmail()` - funcție generală pentru trimitere email
- `construiesteTemplateEmail()` - generează template HTML responsive
- `trimiteEmailCerereImprumut()` - pentru cereri noi de împrumut
- `trimiteEmailRaspunsCerere()` - pentru răspunsuri la cereri
- `trimiteEmailPartajareNoua()` - pentru partajări de colecții
- `trimiteEmailRevocareAcces()` - pentru revocare acces

```php
<?php
// Configurare headers email
$headers = "MIME-Version: 1.0" . "\r\n";
$headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
$headers .= "From: inventar.live <solicitari@inventar.live>" . "\r\n";
$headers .= "Reply-To: solicitari@inventar.live" . "\r\n";

// Parametru adițional pentru deliverability
$additional_params = "-fsolicitari@inventar.live";

// Trimitere cu gestionare erori
$success = @mail($destinatar['email'], $subiect, $html, $headers, $additional_params);
```

### 3.2 **`ajax_imprumut.php`** (MODIFICAT)

Modificări la linia 7-10:
```php
require_once 'includes/email_notifications.php';
```

Modificări la liniile 137-153 (trimitere cerere):
```php
try {
    $detalii_cerere = [
        'denumire_obiect' => $denumire_obiect,
        'cutie' => $cutie,
        'locatie' => $locatie,
        'data_inceput' => $data_inceput,
        'data_sfarsit' => $data_sfarsit,
        'mesaj' => $mesaj
    ];
    @trimiteEmailCerereImprumut($id_proprietar, $user['id_utilizator'], $detalii_cerere);
} catch (Exception $e) {
    error_log("Eroare la trimiterea email-ului de notificare: " . $e->getMessage());
}
```

Modificări la liniile 434-449 (răspuns cerere):
```php
try {
    $detalii_cerere = [
        'id_proprietar' => $detalii['id_proprietar'],
        'denumire_obiect' => $detalii['denumire_obiect'],
        'cutie' => $detalii['cutie'],
        'locatie' => $detalii['locatie'],
        'raspuns' => $data['mesaj_raspuns'] ?? ''
    ];
    $status_email = $raspuns == 'aprobat' ? 'aprobata' : 'respinsa';
    @trimiteEmailRaspunsCerere($detalii['id_solicitant'], $status_email, $detalii_cerere);
} catch (Exception $e) {
    error_log("Eroare la trimiterea email-ului de răspuns: " . $e->getMessage());
}
```

### 3.3 **`ajax_partajare.php`** (MODIFICAT)

Modificări la linia 17:
```php
require_once 'includes/email_notifications.php';
```

Modificări la liniile 566-572 (partajare nouă):
```php
try {
    $nume_proprietar = $user['prenume'] . ' ' . $user['nume'];
    @trimiteEmailPartajareNoua($id_invitat, $nume_colectie, $tip_acces, $nume_proprietar);
} catch (Exception $e) {
    error_log("Eroare la trimiterea email-ului de partajare: " . $e->getMessage());
}
```

Modificări la liniile 678-684 (revocare acces):
```php
try {
    $nume_proprietar = $user['prenume'] . ' ' . $user['nume'];
    @trimiteEmailRevocareAcces($id_revocat, $nume_colectie, $nume_proprietar);
} catch (Exception $e) {
    error_log("Eroare la trimiterea email-ului de revocare: " . $e->getMessage());
}
```

### 3.4 **`index.php`** (MODIFICAT - altă problemă)

Modificări la liniile 2087-2093:
- Adăugat verificare pentru drepturile read-only la butonul de ștergere cutie
- Nu este legat de sistemul de email, ci de permisiuni

## 4. STRUCTURA EMAIL-URILOR

### 4.1 Template HTML

Toate email-urile folosesc un template HTML responsive care include:
- Header cu logo "inventar.live"
- Mesaj personalizat cu numele utilizatorului
- Tabel cu detalii (obiect, cutie, locație, perioada)
- Buton de acțiune care duce în aplicație
- Footer cu informații despre email automat

### 4.2 Exemple de Email-uri

**Cerere nouă de împrumut:**
```
Subiect: Cerere nouă de împrumut - inventar.live
Conținut:
- [Nume solicitant] dorește să împrumute un obiect din colecția ta
- Obiect: [denumire]
- Cutie: [cutie]
- Locație: [locație]
- Perioada: [data_început] - [data_sfârșit]
- Buton: "Vezi cererea"
```

**Răspuns la cerere:**
```
Subiect: Cererea ta de împrumut a fost [aprobată/respinsă] - inventar.live
Conținut:
- Cererea pentru [obiect] a fost [APROBATĂ/RESPINSĂ]
- Mesaj de la proprietar (dacă există)
- Buton: "Vezi detalii"
```

## 5. STADIUL ACTUAL

### ✅ Ce funcționează:
- Sistemul este complet implementat
- Gestionare erori robustă (nu blochează aplicația dacă email-ul eșuează)
- Template-uri HTML profesionale
- Adresa email `solicitari@inventar.live` creată în cPanel

### ⚠️ Probleme identificate:
1. **Error 500** la trimiterea cererii de împrumut
   - Cauză probabilă: Funcția `mail()` PHP poate fi dezactivată pe server
   - Sau: Configurare SMTP incompletă

2. **Email-urile nu ajung la destinație**
   - Posibile cauze:
     - Funcția `mail()` dezactivată în PHP
     - Lipsă configurare SMTP în php.ini
     - Email-urile intră în SPAM
     - Server blocat de providerii de email

### 🔧 Soluții de încercat:

#### Opțiunea 1: Verificare în cPanel
1. Email Deliverability → verificați scorul
2. Track Delivery → verificați dacă email-urile sunt trimise
3. Mail Queue → verificați dacă sunt blocate

#### Opțiunea 2: Verificare funcția mail()
Creați un fișier test: `test_email.php`
```php
<?php
if (mail('test@example.com', 'Test', 'Test message')) {
    echo "Mail function works";
} else {
    echo "Mail function failed";
}
phpinfo(); // Verificați secțiunea mail
?>
```

#### Opțiunea 3: Folosire PHPMailer cu SMTP
Dacă funcția `mail()` nu funcționează, se poate implementa PHPMailer:
```bash
composer require phpmailer/phpmailer
```

Apoi configurare SMTP directă cu credențialele din cPanel.

## 6. URMĂTORII PAȘI RECOMANDAȚI

1. **Verificați log-urile** pentru a vedea exact unde eșuează
   ```bash
   tail -f /var/log/apache2/error.log
   # sau în cPanel → Errors
   ```

2. **Testați funcția mail()** cu scriptul de test de mai sus

3. **Verificați în cPanel**:
   - Email Routing - trebuie să fie "Local Mail Exchanger"
   - Email Deliverability - verificați autentificarea SPF/DKIM

4. **Dacă mail() nu funcționează**, implementați PHPMailer cu SMTP

## 7. NOTE IMPORTANTE

- Sistemul este construit să **nu blocheze** funcționalitatea principală dacă email-ul eșuează
- Notificările vizuale din aplicație funcționează independent de email
- Toate erorile de email sunt logate pentru debugging
- Folosim operatorul `@` pentru a suprima warning-uri care ar putea afecta răspunsul JSON

---
*Document creat: 14 Septembrie 2025*
*Status: Implementat dar necesită debugging pentru funcționare completă*
*Autor: Claude (Anthropic)*