# DOCUMENTARE COMPLETĂ - SISTEM MULTI-COLECȚII INVENTAR.LIVE

## STATUS IMPLEMENTARE - 6 AUGUST 2025

### ✅ FUNCȚIONALITĂȚI COMPLETE (100% funcționale)

1. **Adăugare imagini în colecții secundare**
   - Fișiere actualizate: `adauga_obiect.php`, `adauga_imagini.php`
   - Sesiune persistentă folosind `$_SESSION['upload_colectie_id']`

2. **Ștergere imagini și cutii**
   - Fișiere actualizate: `sterge_imagine.php`, `sterge_cutie.php`
   - ID colecție transmis dinamic din JavaScript

3. **Navigare și editare în etichete_imagine.php**
   - Navigare între imagini funcțională
   - Google Vision API funcțional
   - Salvare etichete în tabelul corect

4. **Actualizare câmpuri obiecte**
   - Fișier actualizat: `actualizeaza_obiect.php`
   - Suport complet multi-tenant

### ⚠️ FUNCȚIONALITĂȚI PARȚIAL IMPLEMENTATE

1. **Partajare obiecte** (TOCMAI ACTUALIZAT)
   - Fișier: `ajax_partajare.php`
   - Status: Actualizat pentru a folosi prefixul corect
   - Necesită testare

2. **Export/Import**
   - Fișiere: `export_import.php`, `import_handler.php`
   - Status: Necesită verificare pentru multi-colecții

### 🔴 FUNCȚIONALITĂȚI NEACTUALIZATE

1. **Detalii obiect**
   - Fișier: `detalii_obiect.php`
   - Necesită adăugare suport multi-tenant

2. **Culori categorii**
   - Fișier: `culori_categorii.php`
   - Necesită verificare prefix tabele

## ARHITECTURA SESIUNILOR

### Variabile de sesiune folosite:
```php
$_SESSION['id_colectie_curenta']    // Colecția activă în navigare
$_SESSION['id_colectie_selectata']  // Colecția selectată pentru operații
$_SESSION['upload_colectie_id']     // Colecția pentru upload (persistentă)
$_SESSION['prefix_tabele']          // Prefixul tabelelor pentru colecția activă
```

### Prioritate determinare prefix:
1. ID colecție din POST/GET
2. `$_SESSION['id_colectie_selectata']`
3. `$_SESSION['id_colectie_curenta']`
4. `$user['id_colectie_principala']`

## PATTERN STANDARD PENTRU ADĂUGARE SUPORT MULTI-TENANT

```php
// LA ÎNCEPUT - după session_start() și include 'config.php'
if (file_exists('includes/auth_functions.php')) {
    require_once 'includes/auth_functions.php';
    
    $user = checkSession();
    if (!$user) {
        // Handle error
        exit;
    }
    
    mysqli_close($conn);
    $conn = getUserDbConnection($user['db_name']);
    
    // Determină colecția și prefixul
    $id_colectie = $_POST['id_colectie'] ?? $_SESSION['id_colectie_selectata'] ?? $_SESSION['id_colectie_curenta'] ?? null;
    
    if ($id_colectie) {
        $conn_central = getCentralDbConnection();
        $sql = "SELECT prefix_tabele FROM colectii_utilizatori WHERE id_colectie = ? AND id_utilizator = ?";
        $stmt = mysqli_prepare($conn_central, $sql);
        mysqli_stmt_bind_param($stmt, "ii", $id_colectie, $user['id_utilizator']);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if ($row = mysqli_fetch_assoc($result)) {
            $table_prefix = $row['prefix_tabele'];
        } else {
            $table_prefix = $user['prefix_tabele'] ?? 'user_' . $user['id_utilizator'] . '_';
        }
        mysqli_stmt_close($stmt);
        mysqli_close($conn_central);
    } else {
        $table_prefix = $_SESSION['prefix_tabele'] ?? $user['prefix_tabele'] ?? 'user_' . $user['id_utilizator'] . '_';
    }
    
    $user_id = $user['id_utilizator'];
} else {
    // Fallback pentru sistem non multi-tenant
    $table_prefix = $GLOBALS['table_prefix'] ?? '';
    $user_id = getCurrentUserId();
}
```

## JAVASCRIPT - TRANSMITERE ID COLECȚIE

### Pattern pentru AJAX requests:
```javascript
// Obține ID-ul colecției din tab-ul activ
const tabActiv = document.querySelector('.tab.active');
if (tabActiv) {
    const idColectie = tabActiv.getAttribute('data-colectie');
    if (idColectie) {
        formData.append('id_colectie', idColectie);
    }
}
```

### Pattern pentru URL parameters:
```javascript
// Obține ID-ul colecției din URL
const urlParams = new URLSearchParams(window.location.search);
const idColectie = urlParams.get('colectie');
if (idColectie) {
    url += `&colectie=${idColectie}`;
}
```

## FIȘIERE CE MAI NECESITĂ ACTUALIZARE

### Prioritate MAXIMĂ:
1. `detalii_obiect.php` - vizualizare detalii obiect
2. `culori_categorii.php` - gestionare culori pentru categorii

### Prioritate MEDIE:
3. `export_import.php` - export date din colecții
4. `import_handler.php` - import date în colecții

### Prioritate MICĂ:
5. Fișiere de administrare și rapoarte

## TESTING CHECKLIST

- [ ] Adăugare imagini în colecție nouă
- [ ] Ștergere imagine din colecție nouă
- [ ] Ștergere cutie din colecție nouă
- [ ] Navigare între imagini în etichete_imagine.php
- [ ] Procesare Google Vision în colecție nouă
- [ ] Salvare etichete în colecție nouă
- [ ] Partajare obiecte din colecție nouă
- [ ] Export date din colecție nouă
- [ ] Import date în colecție nouă

## PROBLEME CUNOSCUTE

1. **Sesiuni pierdute între request-uri**
   - Soluție: Folosirea `$_SESSION['upload_colectie_id']` pentru persistență

2. **JavaScript cu valori PHP statice**
   - Soluție: Obținere dinamică din DOM (data-attributes)

3. **Verificare acces inconsistentă**
   - Soluție: Implementare funcție uniformă checkCollectionAccess()

## URMĂTORII PAȘI RECOMANDAȚI

1. **Testare completă** a funcționalităților actualizate
2. **Actualizare** `detalii_obiect.php` și `culori_categorii.php`
3. **Unificare logică** de determinare prefix într-o funcție comună
4. **Optimizare performanță** - cache pentru prefix-uri
5. **Documentare API** pentru toate endpoint-urile AJAX