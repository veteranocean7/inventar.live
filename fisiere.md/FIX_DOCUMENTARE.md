# DOCUMENTARE FIX MULTI-COLECȚII

## PROBLEMA IDENTIFICATĂ
Când se adaugă imagini în colecții secundare (Garaj, Birou etc.), doar ultima imagine rămâne salvată în baza de date. Imaginile anterioare dispar.

## CAUZA ROOT
1. **Sesiunea nu persistă corect** între request-uri succesive
2. **Prefixul de tabel se pierde** după prima adăugare
3. **Verificarea duplicatelor** se face în tabelul greșit

## FLUXUL ACTUAL (DEFECT)
```
1. User selectează colecția "Garaj" → $_SESSION['id_colectie_selectata'] = 2
2. Prima imagine → se salvează corect în user_1_garaj_obiecte
3. A doua imagine → sesiunea se pierde/resetează → salvează în tabelul principal
4. Rezultat: doar ultima imagine apare în colecția Garaj
```

## SOLUȚIA NECESARĂ

### 1. Fix în `adauga_obiect.php`
```php
// LA ÎNCEPUT - după session_start()
// Păstrăm colecția selectată persistent
if (isset($_POST['id_colectie']) && $_POST['id_colectie'] > 0) {
    $_SESSION['id_colectie_selectata'] = $_POST['id_colectie'];
    $_SESSION['id_colectie_curenta'] = $_POST['id_colectie'];
}

// Folosim ÎNTOTDEAUNA sesiunea salvată pentru determinarea prefixului
$id_colectie = $_SESSION['id_colectie_selectata'] ?? $_SESSION['id_colectie_curenta'] ?? 0;
```

### 2. Fix în `adauga_imagini.php`
```php
// Salvăm persistent în AMBELE variabile de sesiune
if ($id_colectie) {
    $_SESSION['id_colectie_curenta'] = $id_colectie;
    $_SESSION['id_colectie_selectata'] = $id_colectie;
    // Salvăm și într-o variabilă de sesiune specifică pentru upload
    $_SESSION['upload_colectie_id'] = $id_colectie;
}
```

### 3. Debugging necesar
Adaugă în `adauga_obiect.php`:
```php
error_log("=== ADAUGA_OBIECT DEBUG ===");
error_log("POST id_colectie: " . ($_POST['id_colectie'] ?? 'null'));
error_log("SESSION selectata: " . ($_SESSION['id_colectie_selectata'] ?? 'null'));
error_log("SESSION curenta: " . ($_SESSION['id_colectie_curenta'] ?? 'null'));
error_log("Prefix folosit: $table_prefix");
error_log("Cutie: $cutie, Locatie: $locatie");
```

## FIȘIERE CE TREBUIE MODIFICATE

1. **adauga_obiect.php** - logica principală de salvare
2. **adauga_imagini.php** - persistența sesiunii
3. **index.php** - navigarea între colecții
4. **etichete_imagine.php** - ✅ DEJA FIXAT
5. **sterge_imagine.php** - ✅ DEJA FIXAT
6. **sterge_cutie.php** - ✅ DEJA FIXAT
7. **actualizeaza_obiect.php** - ✅ DEJA FIXAT

## TEST PLAN
1. Selectează colecția "Garaj"
2. Adaugă prima imagine → verifică în DB: `SELECT * FROM user_1_garaj_obiecte`
3. Adaugă a doua imagine → verifică din nou
4. Ambele imagini trebuie să existe în același rând, separate prin virgulă

## NOTĂ IMPORTANTĂ
Problema NU este în JavaScript sau în interfață. Problema este în PHP backend - sesiunea nu persistă corect ID-ul colecției între request-uri succesive.