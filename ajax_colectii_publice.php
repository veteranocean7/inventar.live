<?php
/**
 * Returnează lista colecțiilor publice disponibile
 * Inventar.live - August 2025
 */

require_once 'includes/auth_functions.php';
require_once 'config.php';

// Verifică autentificarea
$user = checkSession();
if (!$user) {
    http_response_code(401);
    die(json_encode(['success' => false, 'message' => 'Neautentificat']));
}

// Setează header pentru JSON
header('Content-Type: application/json');

// Obține conexiunea la baza centrală
$conn_central = getCentralDbConnection();

// Obține toate colecțiile publice (exclus cele proprii)
$sql = "SELECT c.*, u.nume, u.prenume, u.email
        FROM colectii_utilizatori c
        JOIN utilizatori u ON c.id_utilizator = u.id_utilizator
        WHERE c.este_publica = 1 
        AND c.id_utilizator != ?
        ORDER BY u.prenume, u.nume, c.nume_colectie";

$stmt = mysqli_prepare($conn_central, $sql);
mysqli_stmt_bind_param($stmt, "i", $user['id_utilizator']);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$colectii = [];
while ($row = mysqli_fetch_assoc($result)) {
    $colectii[] = [
        'id_colectie' => $row['id_colectie'],
        'nume_colectie' => $row['nume_colectie'],
        'prenume' => $row['prenume'],
        'nume' => $row['nume'],
        'este_principala' => $row['este_principala'],
        'nr_obiecte' => 0 // Poate fi calculat ulterior dacă e necesar
    ];
}

mysqli_stmt_close($stmt);
mysqli_close($conn_central);

echo json_encode([
    'success' => true,
    'colectii' => $colectii
]);
?>