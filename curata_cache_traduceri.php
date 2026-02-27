<?php
// Curăță cache-ul de traduceri pentru termenii generici
require_once 'config.php';

$termeni_de_sters = [
    'electric wiring', 'electric supply', 'wire', 'cable',
    'major appliance', 'home appliance', 'luggage and bags',
    'luggage', 'bag', 'belt', 'electrocasnic mare',
    'bagaje și genți', 'curea', 'sac', 'fir', 'cablu electric'
];

$sql = "DELETE FROM traduceri_cache WHERE text_original IN ('" . implode("','", array_map('strtolower', $termeni_de_sters)) . "')";
$result = mysqli_query($conn, $sql);

if ($result) {
    $affected = mysqli_affected_rows($conn);
    echo "Șters $affected traduceri din cache.<br>";
    echo "Acum Vision va folosi Google Translate API pentru acești termeni.";
} else {
    echo "Eroare: " . mysqli_error($conn);
}
?>