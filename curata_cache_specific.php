<?php
// Curăță cache-ul pentru termenii problematici
require_once 'config.php';

// Termenii care apar netradusi
$termeni_problematici = [
    'electric wiring',
    'electric supply',
    'cable',
    'wire',
    'technology'
];

echo "<h3>Curățare cache traduceri problematice</h3>";

foreach ($termeni_problematici as $termen) {
    $termen_lower = strtolower($termen);
    
    // Șterge din cache
    $sql = "DELETE FROM traduceri_cache WHERE text_original = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "s", $termen_lower);
    mysqli_stmt_execute($stmt);
    $affected = mysqli_affected_rows($conn);
    mysqli_stmt_close($stmt);
    
    if ($affected > 0) {
        echo "✓ Șters '$termen' din cache ($affected înregistrări)<br>";
    } else {
        echo "- '$termen' nu era în cache<br>";
    }
}

echo "<br><b>Gata!</b> Acum reprocesează cu Google Vision și termenii ar trebui traduși corect.";
?>