<?php
/**
 * API Inventar pentru PWA Offline Sync
 * Faza 2: Export date în format JSON pentru IndexedDB
 * Versiune: 1.0.0
 */

header('Content-Type: application/json; charset=utf-8');

// Previne caching pentru API
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Include configurații și autentificare
require_once 'includes/auth_functions.php';
require_once 'config.php';

// Verifică autentificarea
$user = checkSession();
if (!$user) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Neautentificat',
        'code' => 'UNAUTHORIZED'
    ]);
    exit;
}

// Obține acțiunea
$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'sync':
        handleSync($user);
        break;

    case 'colectii':
        handleColectii($user);
        break;

    case 'obiect':
        handleObiect($user);
        break;

    case 'status':
        handleStatus($user);
        break;

    default:
        echo json_encode([
            'success' => false,
            'error' => 'Acțiune necunoscută',
            'code' => 'UNKNOWN_ACTION'
        ]);
}

/**
 * Handler principal pentru sincronizare - returnează toate datele necesare
 */
function handleSync($user) {
    try {
        $conn = getUserDbConnection($user['db_name']);
        $conn_central = getCentralDbConnection();

        // Determină colecția curentă
        $colectie_id = $_GET['colectie'] ?? $_SESSION['id_colectie_curenta'] ?? $user['id_colectie_principala'];

        // Obține prefixul pentru colecție
        $table_prefix = getCollectionPrefix($conn_central, $colectie_id, $user['id_utilizator']);

        if (!$table_prefix) {
            throw new Exception('Nu ai acces la această colecție');
        }

        // Obține obiectele
        $obiecte = getObiecte($conn, $table_prefix, $colectie_id);

        // Obține colecțiile utilizatorului
        $colectii = getColectii($conn_central, $user['id_utilizator']);

        // Obține detecțiile Google Vision pentru marcare
        $detectii = getDetectii($conn, $table_prefix);

        mysqli_close($conn);
        mysqli_close($conn_central);

        echo json_encode([
            'success' => true,
            'user_id' => $user['id_utilizator'],
            'colectie_id' => (int)$colectie_id,
            'obiecte' => $obiecte,
            'colectii' => $colectii,
            'detectii' => $detectii,
            'timestamp' => time(),
            'count' => count($obiecte)
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
            'code' => 'SYNC_ERROR'
        ]);
    }
}

/**
 * Obține prefixul tabelelor pentru o colecție
 */
function getCollectionPrefix($conn_central, $colectie_id, $user_id) {
    $sql = "SELECT c.prefix_tabele
            FROM colectii_utilizatori c
            LEFT JOIN partajari p ON c.id_colectie = p.id_colectie
                AND p.id_utilizator_partajat = ? AND p.activ = 1
            WHERE c.id_colectie = ?
            AND (c.id_utilizator = ? OR p.id_partajare IS NOT NULL)";

    $stmt = mysqli_prepare($conn_central, $sql);
    mysqli_stmt_bind_param($stmt, "iii", $user_id, $colectie_id, $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if ($row = mysqli_fetch_assoc($result)) {
        mysqli_stmt_close($stmt);
        return $row['prefix_tabele'];
    }

    mysqli_stmt_close($stmt);
    return null;
}

/**
 * Obține toate obiectele din inventar
 */
function getObiecte($conn, $table_prefix, $colectie_id) {
    $obiecte = [];

    $sql = "SELECT * FROM `{$table_prefix}obiecte` ORDER BY data_adaugare DESC, locatie, cutie";
    $result = mysqli_query($conn, $sql);

    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $obiecte[] = [
                'id_obiect' => (int)$row['id_obiect'],
                'denumire_obiect' => $row['denumire_obiect'],
                'cantitate_obiect' => $row['cantitate_obiect'],
                'cutie' => $row['cutie'],
                'locatie' => $row['locatie'],
                'categorie' => $row['categorie'],
                'eticheta' => $row['eticheta'],
                'descriere_categorie' => $row['descriere_categorie'] ?? '',
                'eticheta_obiect' => $row['eticheta_obiect'] ?? '',
                'imagine' => $row['imagine'],
                'imagine_obiect' => $row['imagine_obiect'] ?? '',
                'data_adaugare' => $row['data_adaugare'],
                'obiecte_partajate' => $row['obiecte_partajate'] ?? '',
                'colectie_id' => (int)$colectie_id
            ];
        }
        mysqli_free_result($result);
    }

    return $obiecte;
}

/**
 * Obține colecțiile utilizatorului
 */
function getColectii($conn_central, $user_id) {
    $colectii = [];

    // Colecții proprii
    $sql_proprii = "SELECT id_colectie, nume_colectie, prefix_tabele, este_principala, este_publica, data_creare
                    FROM colectii_utilizatori
                    WHERE id_utilizator = ?
                    ORDER BY este_principala DESC, data_creare";

    $stmt = mysqli_prepare($conn_central, $sql_proprii);
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    while ($row = mysqli_fetch_assoc($result)) {
        $colectii[] = [
            'id_colectie' => (int)$row['id_colectie'],
            'nume_colectie' => $row['nume_colectie'],
            'prefix_tabele' => $row['prefix_tabele'],
            'este_principala' => (bool)$row['este_principala'],
            'este_publica' => (bool)$row['este_publica'],
            'tip' => 'proprie'
        ];
    }
    mysqli_stmt_close($stmt);

    // Colecții partajate
    $sql_partajate = "SELECT c.id_colectie, c.nume_colectie, c.prefix_tabele, p.tip_acces,
                      u.nume, u.prenume
                      FROM partajari p
                      JOIN colectii_utilizatori c ON p.id_colectie = c.id_colectie
                      JOIN utilizatori u ON c.id_utilizator = u.id_utilizator
                      WHERE p.id_utilizator_partajat = ? AND p.activ = 1";

    $stmt = mysqli_prepare($conn_central, $sql_partajate);
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    while ($row = mysqli_fetch_assoc($result)) {
        $colectii[] = [
            'id_colectie' => (int)$row['id_colectie'],
            'nume_colectie' => $row['nume_colectie'] . ' (' . $row['prenume'] . ')',
            'prefix_tabele' => $row['prefix_tabele'],
            'tip_acces' => $row['tip_acces'],
            'proprietar' => $row['prenume'] . ' ' . $row['nume'],
            'tip' => 'partajata'
        ];
    }
    mysqli_stmt_close($stmt);

    return $colectii;
}

/**
 * Obține detecțiile Google Vision pentru marcare vizuală
 */
function getDetectii($conn, $table_prefix) {
    $detectii = [];

    // Verifică dacă tabela există
    $check = mysqli_query($conn, "SHOW TABLES LIKE '{$table_prefix}detectii_obiecte'");
    if (mysqli_num_rows($check) == 0) {
        return $detectii;
    }

    $sql = "SELECT id_obiect, denumire, sursa FROM `{$table_prefix}detectii_obiecte` WHERE sursa = 'google_vision'";
    $result = mysqli_query($conn, $sql);

    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $detectii[] = [
                'id_obiect' => (int)$row['id_obiect'],
                'denumire' => $row['denumire'],
                'sursa' => $row['sursa']
            ];
        }
        mysqli_free_result($result);
    }

    return $detectii;
}

/**
 * Handler pentru lista colecțiilor
 */
function handleColectii($user) {
    try {
        $conn_central = getCentralDbConnection();
        $colectii = getColectii($conn_central, $user['id_utilizator']);
        mysqli_close($conn_central);

        echo json_encode([
            'success' => true,
            'colectii' => $colectii
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
}

/**
 * Handler pentru un singur obiect
 */
function handleObiect($user) {
    $id = $_GET['id'] ?? null;

    if (!$id) {
        echo json_encode([
            'success' => false,
            'error' => 'ID obiect lipsește'
        ]);
        return;
    }

    try {
        $conn = getUserDbConnection($user['db_name']);
        $conn_central = getCentralDbConnection();

        $colectie_id = $_GET['colectie'] ?? $_SESSION['id_colectie_curenta'] ?? $user['id_colectie_principala'];
        $table_prefix = getCollectionPrefix($conn_central, $colectie_id, $user['id_utilizator']);

        if (!$table_prefix) {
            throw new Exception('Nu ai acces la această colecție');
        }

        $sql = "SELECT * FROM `{$table_prefix}obiecte` WHERE id_obiect = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        if ($row = mysqli_fetch_assoc($result)) {
            echo json_encode([
                'success' => true,
                'obiect' => $row
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'error' => 'Obiect negăsit'
            ]);
        }

        mysqli_stmt_close($stmt);
        mysqli_close($conn);
        mysqli_close($conn_central);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
}

/**
 * Handler pentru status sincronizare
 */
function handleStatus($user) {
    echo json_encode([
        'success' => true,
        'user_id' => $user['id_utilizator'],
        'user_name' => $user['prenume'] . ' ' . $user['nume'],
        'colectie_curenta' => $_SESSION['id_colectie_curenta'] ?? $user['id_colectie_principala'],
        'server_time' => time(),
        'server_time_formatted' => date('Y-m-d H:i:s')
    ]);
}
