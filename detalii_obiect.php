<?php
// detalii_obiect.php (conținut doar pentru fereastră modala)
include 'config.php';

$table_prefix = $GLOBALS['table_prefix'] ?? '';
$user_id = getCurrentUserId();

$id_obiect = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id_obiect <= 0) {
    exit("<p>ID obiect invalid.</p>");
}

$sql = "SELECT * FROM {$table_prefix}obiecte WHERE id_obiect = ?";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, 'i', $id_obiect);
mysqli_stmt_execute($stmt);
$rezultat = mysqli_stmt_get_result($stmt);

if (!$obiect = mysqli_fetch_assoc($rezultat)) {
    exit("<p>Obiectul nu a fost găsit.</p>");
}

mysqli_stmt_close($stmt);
mysqli_close($conn);
?>

<div class="modal-content">
    <h2>Detalii pentru: <?php echo htmlspecialchars($obiect['categorie']); ?></h2>

    <?php if (!empty($obiect['imagine'])): ?>
        <img src="imagini_obiecte/user_<?php echo $user_id; ?>/<?php echo htmlspecialchars($obiect['imagine']); ?>" alt="Imagine obiect" style="max-width:100%; max-height:300px; object-fit:contain; margin-bottom:15px;">
    <?php endif; ?>

    <p><strong>Categorie:</strong> <?php echo htmlspecialchars($obiect['categorie']); ?></p>
    <p><strong>Descriere:</strong> <?php echo nl2br(htmlspecialchars($obiect['descriere_obiect'])); ?></p>
    <p><strong>Etichetă (culoare):</strong> <span style="display:inline-block; width:20px; height:20px; background-color:<?php echo htmlspecialchars($obiect['eticheta']); ?>; border:1px solid #ccc;"></span></p>
    <p><strong>Cutie:</strong> <?php echo htmlspecialchars($obiect['cutie']); ?></p>
    <p><strong>Locație:</strong> <?php echo htmlspecialchars($obiect['locatie']); ?></p>
    <p><strong>Data încărcării:</strong> <?php echo htmlspecialchars($obiect['data_upload']); ?></p>

    <button onclick="inchideModala()" class="btn" style="margin-top: 20px;">Închide</button>
</div>
