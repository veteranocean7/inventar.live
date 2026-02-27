# VERSIONING.md - Sistem de Versionare Internă inventar.live

## 1. CONVENȚIE DE VERSIONARE

### Format adoptat: YYYY.MM.BUILD
- **YYYY.MM** - An și lună (ex: 2025.08)
- **BUILD** - Număr incremental pentru modificări minore (ex: 2025.08.1, 2025.08.2)

### Exemple:
- `2025.01` - Versiune majoră din ianuarie 2025
- `2025.01.1` - Prima actualizare/fix din ianuarie
- `2025.08` - Versiune majoră din august 2025

## 2. IMPLEMENTARE TEHNICĂ

### 2.1 Fișier version.php (de creat)
```php
<?php
// version.php - Informații versiune pentru uz intern
define('APP_VERSION', '2025.08');
define('APP_BUILD', 1);
define('APP_RELEASE_DATE', '2025-08-09');

// Istoric funcționalități majore
define('VERSION_HISTORY', [
    '2025.01' => [
        'date' => '2025-01-15',
        'features' => [
            'Sistem partajare colecții',
            'Multi-tenant architecture',
            'Colecții multiple per utilizator'
        ]
    ],
    '2025.05' => [
        'date' => '2025-05-20',
        'features' => [
            'Integrare Google Vision API',
            'Fix editare colecții partajate',
            'Optimizări performanță'
        ]
    ],
    '2025.08' => [
        'date' => '2025-08-09',
        'features' => [
            'Sistem complet de împrumut',
            'Partajare selectivă (per cutie)',
            'Notificări în timp real',
            'Badge notificări pe avatar'
        ]
    ]
]);

// Funcție pentru debugging
function getSystemInfo() {
    return [
        'app_version' => APP_VERSION,
        'build' => APP_BUILD,
        'php_version' => PHP_VERSION,
        'mysql_version' => mysqli_get_server_info(getCentralDbConnection()),
        'last_update' => APP_RELEASE_DATE
    ];
}
?>
```

### 2.2 Tabel pentru tracking în bază de date
```sql
-- Rulează în inventar_central
CREATE TABLE IF NOT EXISTS system_versions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    version VARCHAR(20) NOT NULL,
    build INT DEFAULT 1,
    description TEXT,
    changes_summary TEXT,
    files_modified TEXT,
    sql_executed TEXT,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    applied_by INT,
    FOREIGN KEY (applied_by) REFERENCES utilizatori(id_utilizator)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inserare versiuni existente
INSERT INTO system_versions (version, description, changes_summary) VALUES
('2025.01', 'Lansare sistem partajare', 'Multi-tenant, colecții multiple, partajare basic'),
('2025.05', 'AI Integration', 'Google Vision API, fix colecții partajate'),
('2025.08', 'Sistem împrumut', 'Împrumut complet, partajare selectivă, notificări');
```

### 2.3 Cache Busting pentru CSS/JS
```php
// În header-ul paginilor PHP
<?php require_once 'version.php'; ?>

<!-- În secțiunea <head> -->
<link rel="stylesheet" href="css/style.css?v=<?php echo APP_VERSION; ?>">
<script src="js/app.js?v=<?php echo APP_VERSION; ?>"></script>
```

### 2.4 Comentariu în HTML pentru debugging
```php
<!-- În footer.php sau la sfârșitul paginilor -->
<!-- 
    inventar.live 
    Version: <?php echo APP_VERSION; ?> 
    Build: <?php echo APP_BUILD; ?>
    Generated: <?php echo date('Y-m-d H:i:s'); ?>
-->
```

## 3. GIT TAGS

### Convenție pentru Git tags
```bash
# Pentru versiuni majore
git tag -a v2025.01 -m "Sistem partajare și multi-tenant"
git tag -a v2025.05 -m "Google Vision API integration"
git tag -a v2025.08 -m "Borrowing system and selective sharing"

# Push tags
git push origin --tags

# Vizualizare tags
git tag -l "v*"
```

## 4. ISTORIC VERSIUNI

### 2025.08 (9 August 2025) - CURRENT
**Funcționalități adăugate:**
- ✅ Sistem complet de împrumut obiecte
- ✅ Partajare selectivă la nivel de cutie
- ✅ Notificări pentru cereri primite/răspunsuri
- ✅ Badge notificare pe avatar
- ✅ Tab Împrumuturi cu management cereri
- ✅ Protecție date personale (nume ascunse)

**Fișiere modificate:**
- index.php (formular împrumut, notificări)
- impartasiri.php (tab împrumuturi, partajare selectivă)
- ajax_imprumut.php (NOU)
- ajax_partajare.php (funcții cutii)
- includes/auth_functions.php (checkBoxAccess)

**Modificări BD:**
- Tabel nou: `cereri_imprumut`
- Coloane noi în `partajari`: `tip_partajare`, `cutii_partajate`

---

### 2025.05 (20 Mai 2025)
**Funcționalități adăugate:**
- ✅ Integrare Google Vision API
- ✅ Detectare automată obiecte
- ✅ Fix complet pentru editare în colecții partajate
- ✅ Optimizări sugestii și căutare

**Fișiere modificate:**
- detectare_google_vision.php
- actualizeaza_obiect.php
- sterge_imagine.php
- obtine_sugestii.php

---

### 2025.01 (15 Ianuarie 2025)
**Funcționalități adăugate:**
- ✅ Arhitectură multi-tenant
- ✅ Colecții multiple per utilizator
- ✅ Sistem basic de partajare
- ✅ Tab-uri pentru navigare între colecții

**Fișiere modificate:**
- Restructurare completă aplicație
- ajax_colectii.php (NOU)
- includes/auth_functions.php (NOU)

**Modificări BD:**
- Creare `inventar_central`
- Migrare la baze de date individuale

---

### 2024.11 (Noiembrie 2024)
**Versiune inițială:**
- Upload și organizare imagini
- Etichetare vizuală
- Categorii cu culori
- Export/Import basic

## 5. PROCEDURĂ PENTRU VERSIUNE NOUĂ

### Checklist pentru release:
```markdown
- [ ] Actualizează APP_VERSION în version.php
- [ ] Incrementează APP_BUILD
- [ ] Actualizează APP_RELEASE_DATE
- [ ] Adaugă în VERSION_HISTORY
- [ ] Inserează în tabel system_versions
- [ ] Documentează în VERSIONING.md
- [ ] Actualizează CLAUDE.md
- [ ] Git commit cu mesaj descriptiv
- [ ] Git tag pentru versiune majoră
- [ ] Upload fișiere pe server
- [ ] Verificare funcționalitate
```

### Script pentru actualizare versiune
```php
<?php
// update_version.php - Rulează local înainte de deploy
function updateVersion($newVersion, $description) {
    // Actualizează version.php
    $versionFile = 'version.php';
    $content = file_get_contents($versionFile);
    $content = preg_replace(
        "/define\('APP_VERSION', '.*?'\)/",
        "define('APP_VERSION', '$newVersion')",
        $content
    );
    $content = preg_replace(
        "/define\('APP_RELEASE_DATE', '.*?'\)/",
        "define('APP_RELEASE_DATE', '" . date('Y-m-d') . "')",
        $content
    );
    file_put_contents($versionFile, $content);
    
    echo "✓ Version updated to $newVersion\n";
    echo "✓ Description: $description\n";
    echo "✓ Don't forget to update VERSION_HISTORY!\n";
}

// Utilizare: php update_version.php 2025.09 "New features"
?>
```

## 6. DEBUGGING CU VERSIUNI

### Pagină internă pentru verificare (version_info.php)
```php
<?php
session_start();
require_once 'includes/auth_functions.php';
require_once 'version.php';

// Verifică dacă e admin/developer
$user = checkSession();
if (!$user || $user['id_utilizator'] != 1) { // Doar admin
    die('Access denied');
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>System Version Info</title>
    <style>
        body { font-family: monospace; padding: 20px; }
        .info-box { background: #f0f0f0; padding: 15px; margin: 10px 0; }
        .version { font-size: 24px; color: #667eea; }
    </style>
</head>
<body>
    <h1>inventar.live - System Information</h1>
    
    <div class="info-box">
        <div class="version">Version: <?php echo APP_VERSION; ?></div>
        <div>Build: <?php echo APP_BUILD; ?></div>
        <div>Release Date: <?php echo APP_RELEASE_DATE; ?></div>
    </div>
    
    <div class="info-box">
        <h3>Environment:</h3>
        <pre><?php print_r(getSystemInfo()); ?></pre>
    </div>
    
    <div class="info-box">
        <h3>Version History:</h3>
        <pre><?php print_r(VERSION_HISTORY); ?></pre>
    </div>
</body>
</html>
```

## 7. BENEFICII ALE ACESTUI SISTEM

1. **Tracking clar** - Știi exact ce versiune rulează unde
2. **Debugging rapid** - Identifici ușor probleme legate de versiune
3. **Cache control** - Forțezi reîncărcarea după update-uri
4. **Istoric documentat** - Vezi evoluția aplicației
5. **Rollback posibil** - Poți reveni la versiuni anterioare cu Git tags
6. **Transparență internă** - Echipa știe ce s-a schimbat și când

## 8. NOTE IMPORTANTE

- Versiunea NU se afișează utilizatorilor în interfață
- Este doar pentru uz intern și debugging
- Se poate verifica în comentarii HTML sau pagină dedicată
- Git tags permit revenire rapidă la versiuni anterioare
- Fișierul version.php trebuie inclus în .gitignore după creare inițială

---
*Document creat: 9 August 2025*
*Pentru: Sistemul intern de versionare inventar.live*