# Ghid de Instalare - Inventar.live

## Cerințe Sistem

### Server
- PHP 7.4 sau mai nou
- MySQL 5.7+ sau MariaDB 10.3+
- Apache/Nginx cu mod_rewrite activat
- Composer pentru gestionarea dependențelor
- Minim 512MB RAM recomandat

### Extensii PHP necesare
- mysqli
- gd (pentru procesare imagini)
- json
- curl

## Pași de Instalare

### 1. Pregătirea Serverului
```bash
# Clonează sau copiază fișierele în directorul web
cd /var/www/html/
# sau în directorul tău web root

# Setează permisiunile
chmod 755 .
chmod 777 imagini_obiecte/
chmod 777 imagini_decupate/
```

### 2. Configurarea Bazei de Date

Creează o bază de date nouă și rulează următoarele scripturi SQL:

```sql
-- Creează baza de date
CREATE DATABASE inventar_live CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Creează tabela principală
CREATE TABLE IF NOT EXISTS obiecte (
    id_obiect INT AUTO_INCREMENT PRIMARY KEY,
    denumire_obiect TEXT,
    cantitate_obiect TEXT,
    cutie VARCHAR(255),
    locatie VARCHAR(255),
    categorie TEXT,
    eticheta TEXT,
    descriere_categorie TEXT,
    eticheta_obiect TEXT,
    imagine TEXT,
    imagine_obiect TEXT,
    data_adaugare TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cutie_locatie (cutie, locatie),
    INDEX idx_categorie (categorie(255))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Creează tabela pentru tracking
CREATE TABLE IF NOT EXISTS detectii_obiecte (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_obiect INT NOT NULL,
    denumire VARCHAR(255) NOT NULL,
    sursa ENUM('manual', 'google_vision') DEFAULT 'manual',
    data_detectie TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_obiect) REFERENCES obiecte(id_obiect) ON DELETE CASCADE,
    INDEX idx_obiect_denumire (id_obiect, denumire),
    INDEX idx_sursa (sursa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 3. Configurarea Aplicației

Creează fișierul `config.php` cu următorul conținut:

```php
<?php
// Configurare bază de date
$host = 'localhost';
$user = 'your_db_user';
$pass = 'your_db_password';
$db = 'inventar_live';

// Conexiune la baza de date
$conn = mysqli_connect($host, $user, $pass, $db);
mysqli_set_charset($conn, "utf8mb4");

// Configurări aplicație
define('SITE_URL', 'https://inventar.live');
define('UPLOAD_DIR', 'imagini_obiecte/');
define('CROP_DIR', 'imagini_decupate/');
?>
```

### 4. Instalarea Google Vision API (Opțional)

#### 4.1 Instalează Composer și dependențele
```bash
# Instalează Composer local
curl -sS https://getcomposer.org/installer | php

# Instalează biblioteca Google Vision
php composer.phar require google/cloud-vision
```

#### 4.2 Configurează cheia API
1. Creează un proiect în [Google Cloud Console](https://console.cloud.google.com)
2. Activează Vision API
3. Creează un Service Account și descarcă cheia JSON
4. Salvează cheia ca `google-vision-key.json` în root

#### 4.3 Protejează cheia API
Adaugă în `.htaccess`:
```apache
<Files "google-vision-key.json">
    Order allow,deny
    Deny from all
</Files>
```

### 5. Verificare Instalare

1. Accesează `https://your-domain.com/test_basic.php` pentru a verifica conexiunea la DB
2. Accesează `https://your-domain.com/test_vision_config.php` pentru a verifica Google Vision (dacă e instalat)

### 6. Securitate

1. Șterge fișierele de test după verificare
2. Setează permisiuni restrictive pentru `config.php`
3. Activează HTTPS pe server
4. Configurează backup automat pentru baza de date

## Probleme Comune

### Eroare: "Conexiune eșuată la baza de date"
- Verifică credențialele în `config.php`
- Asigură-te că MySQL/MariaDB rulează
- Verifică permisiunile utilizatorului DB

### Eroare: "Nu se pot încărca imagini"
- Verifică permisiunile pentru directoarele de imagini
- Verifică limita `upload_max_filesize` în php.ini
- Asigură-te că extensia GD este activată

### Eroare: "Google Vision nu funcționează"
- Verifică dacă `vendor/` există
- Verifică permisiunile pentru `google-vision-key.json`
- Verifică dacă billing este activat în Google Cloud

---
*Pentru suport tehnic, contactați echipa de dezvoltare*