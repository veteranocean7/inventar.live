<?php
require_once dirname(__DIR__) . '/config_central.php';

// ===============================
// Funcții pentru autentificare
// ===============================

/**
 * Generează un token securizat
 */
function generateSecureToken($length = 32) {
    return bin2hex(random_bytes($length));
}

/**
 * Hash-uiește parola
 */
function hashPassword($password) {
    return password_hash($password, PASSWORD_BCRYPT);
}

/**
 * Verifică parola
 */
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

/**
 * Creează sesiune nouă
 */
function createSession($id_utilizator, $conn_central) {
    // Generează token unic
    $token = generateSecureToken();
    
    // Setează data expirării
    $data_expirare = date('Y-m-d H:i:s', time() + COOKIE_EXPIRY);
    
    // Obține informații despre client
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    // Inserează în baza de date
    $sql = "INSERT INTO sesiuni (id_utilizator, token_sesiune, ip_address, user_agent, data_expirare) 
            VALUES (?, ?, ?, ?, ?)";
    
    $stmt = mysqli_prepare($conn_central, $sql);
    mysqli_stmt_bind_param($stmt, "issss", $id_utilizator, $token, $ip_address, $user_agent, $data_expirare);
    
    if (mysqli_stmt_execute($stmt)) {
        // Setează cookie
        setcookie(
            COOKIE_NAME,
            $token,
            time() + COOKIE_EXPIRY,
            COOKIE_PATH,
            COOKIE_DOMAIN,
            COOKIE_SECURE,
            COOKIE_HTTPONLY
        );
        
        return $token;
    }
    
    return false;
}

/**
 * Verifică sesiunea curentă
 */
function checkSession() {
    if (!isset($_COOKIE[COOKIE_NAME])) {
        return false;
    }
    
    $token = $_COOKIE[COOKIE_NAME];
    $conn_central = getCentralDbConnection();
    
    // Verifică token-ul în baza de date
    $sql = "SELECT s.*, u.* 
            FROM sesiuni s 
            JOIN utilizatori u ON s.id_utilizator = u.id_utilizator 
            WHERE s.token_sesiune = ? 
            AND s.activa = 1 
            AND s.data_expirare > NOW() 
            AND u.activ = 1";
    
    $stmt = mysqli_prepare($conn_central, $sql);
    mysqli_stmt_bind_param($stmt, "s", $token);
    mysqli_stmt_execute($stmt);
    
    $result = mysqli_stmt_get_result($stmt);
    
    if ($row = mysqli_fetch_assoc($result)) {
        // Actualizează ultima activitate
        $update_sql = "UPDATE utilizatori SET data_ultima_logare = NOW() WHERE id_utilizator = ?";
        $update_stmt = mysqli_prepare($conn_central, $update_sql);
        mysqli_stmt_bind_param($update_stmt, "i", $row['id_utilizator']);
        mysqli_stmt_execute($update_stmt);
        
        mysqli_close($conn_central);
        return $row;
    }
    
    mysqli_close($conn_central);
    return false;
}

/**
 * Distruge sesiunea curentă
 */
function destroySession() {
    if (isset($_COOKIE[COOKIE_NAME])) {
        $token = $_COOKIE[COOKIE_NAME];
        $conn_central = getCentralDbConnection();
        
        // Marchează sesiunea ca inactivă
        $sql = "UPDATE sesiuni SET activa = 0 WHERE token_sesiune = ?";
        $stmt = mysqli_prepare($conn_central, $sql);
        mysqli_stmt_bind_param($stmt, "s", $token);
        mysqli_stmt_execute($stmt);
        
        mysqli_close($conn_central);
        
        // Șterge cookie-ul
        setcookie(
            COOKIE_NAME,
            '',
            time() - 3600,
            COOKIE_PATH,
            COOKIE_DOMAIN,
            COOKIE_SECURE,
            COOKIE_HTTPONLY
        );
    }
}

/**
 * Înregistrează eveniment în log
 */
function logAuthEvent($id_utilizator, $tip_eveniment, $detalii = null) {
    $conn_central = getCentralDbConnection();
    
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    $sql = "INSERT INTO log_autentificare (id_utilizator, tip_eveniment, ip_address, user_agent, detalii) 
            VALUES (?, ?, ?, ?, ?)";
    
    $stmt = mysqli_prepare($conn_central, $sql);
    mysqli_stmt_bind_param($stmt, "issss", $id_utilizator, $tip_eveniment, $ip_address, $user_agent, $detalii);
    mysqli_stmt_execute($stmt);
    
    mysqli_close($conn_central);
}

/**
 * Validează email
 */
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

/**
 * Validează număr de telefon
 */
function validatePhone($phone) {
    // Elimină spații și caractere speciale
    $phone = preg_replace('/[^0-9]/', '', $phone);
    
    // Verifică lungimea (minim 10 cifre)
    return strlen($phone) >= 10;
}

/**
 * Creează nume bază de date pentru utilizator
 */
function generateUserDbName($id_utilizator) {
    return 'inventar_user_' . $id_utilizator;
}

/**
 * Verifică dacă un utilizator are acces la o cutie specifică
 * Pentru partajare selectivă
 */
function checkBoxAccess($user_id, $colectie_id, $cutie, $locatie = '') {
    $conn_central = getCentralDbConnection();
    
    // Verifică dacă este proprietarul colecției
    $sql_owner = "SELECT id_utilizator FROM colectii_utilizatori WHERE id_colectie = ?";
    $stmt = mysqli_prepare($conn_central, $sql_owner);
    mysqli_stmt_bind_param($stmt, "i", $colectie_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($row = mysqli_fetch_assoc($result)) {
        if ($row['id_utilizator'] == $user_id) {
            mysqli_stmt_close($stmt);
            mysqli_close($conn_central);
            return true; // Proprietarul are acces la toate cutiile
        }
    }
    mysqli_stmt_close($stmt);
    
    // Verifică partajarea
    $sql_share = "SELECT tip_partajare, cutii_partajate, tip_acces 
                  FROM partajari 
                  WHERE id_colectie = ? AND id_utilizator_partajat = ? AND activ = 1";
    
    $stmt = mysqli_prepare($conn_central, $sql_share);
    mysqli_stmt_bind_param($stmt, "ii", $colectie_id, $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($row = mysqli_fetch_assoc($result)) {
        $tip_partajare = $row['tip_partajare'];
        $cutii_partajate = $row['cutii_partajate'];
        $tip_acces = $row['tip_acces'];
        
        mysqli_stmt_close($stmt);
        mysqli_close($conn_central);
        
        // Pentru partajare completă, are acces la toate cutiile
        if ($tip_partajare == 'completa') {
            return ['access' => true, 'tip_acces' => $tip_acces];
        }
        
        // Pentru partajare selectivă, verifică dacă cutia este în listă
        if ($tip_partajare == 'selectiva' && !empty($cutii_partajate)) {
            $cutii_array = json_decode($cutii_partajate, true);
            if (is_array($cutii_array)) {
                // Construiește identificatorul cutiei
                $cutie_id = $cutie . '|' . $locatie;
                
                // Verifică dacă cutia este în lista de cutii partajate
                if (in_array($cutie_id, $cutii_array)) {
                    return ['access' => true, 'tip_acces' => $tip_acces];
                }
            }
        }
    }
    
    mysqli_stmt_close($stmt);
    mysqli_close($conn_central);
    
    return ['access' => false, 'tip_acces' => null];
}

?>