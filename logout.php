<?php
require_once 'includes/auth_functions.php';

// Obține informații despre utilizator înainte de a distruge sesiunea
$user = checkSession();

if ($user) {
    // Log eveniment
    logAuthEvent($user['id_utilizator'], 'logout', "Delogare inițiată de utilizator");
}

// Distruge sesiunea
destroySession();

// Redirecționează la login
header('Location: login.php');
exit;
?>