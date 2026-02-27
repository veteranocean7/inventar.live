<?php
// Script complet de debug pentru Context Manager
session_start();
include 'config.php';

// Verifică autentificarea
if (file_exists('includes/auth_functions.php')) {
    require_once 'includes/auth_functions.php';
    $user = checkSession();
    if (!$user) {
        die("Neautentificat");
    }
} else {
    $user = getCurrentUser();
    if (!$user) {
        die("Neautentificat");
    }
}

$user_id = $user['id_utilizator'];
$table_prefix = 'user_' . $user_id . '_';

?>
<!DOCTYPE html>
<html>
<head>
    <title>Debug Context Manager</title>
    <style>
        body { font-family: monospace; margin: 20px; }
        .success { color: green; }
        .error { color: red; }
        .warning { color: orange; }
        .box { border: 1px solid #ccc; padding: 10px; margin: 10px 0; }
        table { border-collapse: collapse; width: 100%; }
        td, th { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #f0f0f0; }
    </style>
</head>
<body>
<h2>Debug Complet Context Manager</h2>

<?php
$conn_central = getCentralDbConnection();

// 1. Verificare structură tabelă
echo "<div class='box'>";
echo "<h3>1. Structură Tabelă context_locatii</h3>";
$result = mysqli_query($conn_central, "DESCRIBE context_locatii");
if ($result) {
    echo "<table>";
    echo "<tr><th>Câmp</th><th>Tip</th><th>Null</th><th>Key</th><th>Default</th></tr>";
    $has_id_colectie = false;
    while ($row = mysqli_fetch_assoc($result)) {
        echo "<tr>";
        echo "<td>{$row['Field']}</td>";
        echo "<td>{$row['Type']}</td>";
        echo "<td>{$row['Null']}</td>";
        echo "<td>{$row['Key']}</td>";
        echo "<td>{$row['Default']}</td>";
        echo "</tr>";
        if ($row['Field'] == 'id_colectie') {
            $has_id_colectie = true;
        }
    }
    echo "</table>";
    
    if (!$has_id_colectie) {
        echo "<p class='error'>❌ LIPSEȘTE coloana id_colectie!</p>";
        echo "<form method='post' action='fix_context_table.php'>";
        echo "<button type='submit'>Repară Tabela</button>";
        echo "</form>";
    } else {
        echo "<p class='success'>✅ Coloana id_colectie există</p>";
    }
} else {
    echo "<p class='error'>Eroare: " . mysqli_error($conn_central) . "</p>";
}
echo "</div>";

// 2. Contexte existente
echo "<div class='box'>";
echo "<h3>2. Toate Contextele Existente</h3>";
$sql = "SELECT * FROM context_locatii ORDER BY locatie, cutie";
$result = mysqli_query($conn_central, $sql);
if ($result && mysqli_num_rows($result) > 0) {
    echo "<table>";
    echo "<tr><th>ID</th><th>Locație</th><th>Cutie</th><th>Colecție</th><th>Obiecte Excluse</th><th>Obiecte Comune</th><th>Încredere</th></tr>";
    while ($row = mysqli_fetch_assoc($result)) {
        $excluse = $row['obiecte_excluse'] ? substr($row['obiecte_excluse'], 0, 50) . '...' : '-';
        $comune = $row['obiecte_comune'] ? substr($row['obiecte_comune'], 0, 50) . '...' : '-';
        echo "<tr>";
        echo "<td>{$row['id']}</td>";
        echo "<td>{$row['locatie']}</td>";
        echo "<td>{$row['cutie']}</td>";
        echo "<td>" . ($row['id_colectie'] ?? 'NULL') . "</td>";
        echo "<td title='" . htmlspecialchars($row['obiecte_excluse']) . "'>{$excluse}</td>";
        echo "<td title='" . htmlspecialchars($row['obiecte_comune']) . "'>{$comune}</td>";
        echo "<td>{$row['incredere']}</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p class='warning'>Nu există contexte salvate</p>";
}
echo "</div>";

// 3. Obiecte Vision din BD
echo "<div class='box'>";
echo "<h3>3. Obiecte Vision Detectate (primele 20)</h3>";
$sql = "SELECT id_obiect, denumire_obiect, locatie, cutie, eticheta_obiect 
        FROM `{$table_prefix}obiecte` 
        WHERE eticheta_obiect LIKE '%#ff6600%'
        ORDER BY id_obiect DESC
        LIMIT 20";
$result = mysqli_query($conn, $sql);
if ($result && mysqli_num_rows($result) > 0) {
    echo "<table>";
    echo "<tr><th>ID</th><th>Denumire</th><th>Locație</th><th>Cutie</th><th>Vision?</th></tr>";
    while ($row = mysqli_fetch_assoc($result)) {
        $is_vision = strpos($row['eticheta_obiect'], '#ff6600') !== false;
        echo "<tr>";
        echo "<td>{$row['id_obiect']}</td>";
        echo "<td>{$row['denumire_obiect']}</td>";
        echo "<td>{$row['locatie']}</td>";
        echo "<td>{$row['cutie']}</td>";
        echo "<td>" . ($is_vision ? "✅ DA" : "❌ NU") . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>Nu există obiecte Vision detectate</p>";
}
echo "</div>";

// 4. Test live pentru o cutie specifică
if (isset($_GET['test_locatie']) && isset($_GET['test_cutie'])) {
    $test_locatie = $_GET['test_locatie'];
    $test_cutie = $_GET['test_cutie'];
    
    echo "<div class='box'>";
    echo "<h3>4. Test Live pentru $test_locatie / $test_cutie</h3>";
    
    // Verificăm contextul
    $sql = "SELECT * FROM context_locatii WHERE locatie = ? AND cutie = ?";
    $stmt = mysqli_prepare($conn_central, $sql);
    mysqli_stmt_bind_param($stmt, "ss", $test_locatie, $test_cutie);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($row = mysqli_fetch_assoc($result)) {
        echo "<p><strong>Context găsit:</strong></p>";
        echo "<ul>";
        echo "<li>ID: {$row['id']}</li>";
        echo "<li>Obiecte excluse: " . ($row['obiecte_excluse'] ?: 'niciunul') . "</li>";
        echo "<li>Obiecte comune: " . ($row['obiecte_comune'] ?: 'niciunul') . "</li>";
        echo "<li>Încredere: {$row['incredere']}</li>";
        echo "</ul>";
        
        // Testăm hook-ul
        echo "<p><strong>Test adăugare excludere:</strong></p>";
        require_once 'hook_stergere_vision.php';
        $test_obiect = 'test_' . time();
        actualizareContextExcluderi($conn_central, $test_locatie, $test_cutie, $test_obiect, 1);
        
        // Verificăm dacă s-a adăugat
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $row_after = mysqli_fetch_assoc($result);
        
        if (strpos($row_after['obiecte_excluse'], $test_obiect) !== false) {
            echo "<p class='success'>✅ Hook funcționează! Obiectul '$test_obiect' a fost adăugat la excluderi.</p>";
        } else {
            echo "<p class='error'>❌ Hook NU funcționează! Obiectul '$test_obiect' nu a fost adăugat.</p>";
        }
        
    } else {
        echo "<p class='warning'>Nu există context pentru această locație/cutie</p>";
    }
    
    mysqli_stmt_close($stmt);
    echo "</div>";
}

// 5. Formular pentru test
echo "<div class='box'>";
echo "<h3>5. Testează o Cutie Specifică</h3>";
echo "<form method='get'>";
echo "Locație: <input type='text' name='test_locatie' value='Pod deasupra'> ";
echo "Cutie: <input type='text' name='test_cutie' value='7'> ";
echo "<button type='submit'>Testează</button>";
echo "</form>";
echo "</div>";

// 6. Istoric corecții
echo "<div class='box'>";
echo "<h3>6. Istoric Corecții (ultimele 10)</h3>";
$sql = "SELECT * FROM context_corectii ORDER BY data_corectie DESC LIMIT 10";
$result = mysqli_query($conn_central, $sql);
if ($result && mysqli_num_rows($result) > 0) {
    echo "<table>";
    echo "<tr><th>ID</th><th>User</th><th>Locație</th><th>Cutie</th><th>Obiect</th><th>Acțiune</th><th>Data</th><th>Procesat</th></tr>";
    while ($row = mysqli_fetch_assoc($result)) {
        echo "<tr>";
        echo "<td>{$row['id']}</td>";
        echo "<td>{$row['id_utilizator']}</td>";
        echo "<td>{$row['locatie']}</td>";
        echo "<td>{$row['cutie']}</td>";
        echo "<td>{$row['obiect_original']}</td>";
        echo "<td>{$row['actiune']}</td>";
        echo "<td>{$row['data_corectie']}</td>";
        echo "<td>" . ($row['procesat'] ? 'DA' : 'NU') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>Nu există istoric de corecții sau tabela nu există</p>";
}
echo "</div>";

mysqli_close($conn_central);
?>

</body>
</html>