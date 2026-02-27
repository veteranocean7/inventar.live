<?php
// Include doar strictul necesar
require_once 'config_central.php';
require_once 'includes/auth_functions.php';

// Verifică sesiunea
$user = checkSession();
if (!$user) {
    header('Location: login.php');
    exit;
}

// Verifică dacă utilizatorul are deja tabele create
if ($user['tabele_create'] == 1 && !empty($user['prefix_tabele'])) {
    header('Location: index.php');
    exit;
}

$errors = [];
$success = false;
$step = $_GET['step'] ?? 'choose';

// Procesare formular
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'create_new') {
        $conn_central = getCentralDbConnection();
        $prefix = 'user_' . $user['id_utilizator'] . '_';
        
        // Array cu toate query-urile necesare
        $queries = [
            "CREATE TABLE IF NOT EXISTS `{$prefix}obiecte` (
                id_obiect INT AUTO_INCREMENT PRIMARY KEY,
                denumire_obiect TEXT,
                cantitate_obiect TEXT,
                cutie VARCHAR(255),
                locatie VARCHAR(255),
                categorie TEXT,
                eticheta TEXT,
                descriere_categorie TEXT,
                eticheta_obiect TEXT,
                imagine TEXT,
                imagine_obiect TEXT,
                obiecte_partajate TEXT,
                data_adaugare TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_cutie_locatie (cutie, locatie),
                INDEX idx_categorie (categorie(255))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            
            "CREATE TABLE IF NOT EXISTS `{$prefix}detectii_obiecte` (
                id INT AUTO_INCREMENT PRIMARY KEY,
                id_obiect INT NOT NULL,
                denumire VARCHAR(255) NOT NULL,
                sursa ENUM('manual', 'google_vision') DEFAULT 'manual',
                data_detectie TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (id_obiect) REFERENCES `{$prefix}obiecte`(id_obiect) ON DELETE CASCADE,
                INDEX idx_obiect_denumire (id_obiect, denumire),
                INDEX idx_sursa (sursa)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        ];
        
        $all_success = true;
        foreach ($queries as $query) {
            if (!mysqli_query($conn_central, $query)) {
                $errors[] = "Eroare la crearea tabelelor: " . mysqli_error($conn_central);
                $all_success = false;
                break;
            }
        }
        
        if ($all_success) {
            // Începe tranzacție pentru a asigura consistența datelor
            mysqli_begin_transaction($conn_central);
            
            try {
                // 1. Creează înregistrarea în colectii_utilizatori pentru colecția principală
                $nume_colectie = "Inventarul meu";
                $insert_colectie = "INSERT INTO colectii_utilizatori 
                                   (id_utilizator, nume_colectie, prefix_tabele, este_principala, data_creare) 
                                   VALUES (?, ?, ?, 1, NOW())";
                $stmt_col = mysqli_prepare($conn_central, $insert_colectie);
                mysqli_stmt_bind_param($stmt_col, "iss", $user['id_utilizator'], $nume_colectie, $prefix);
                
                if (!mysqli_stmt_execute($stmt_col)) {
                    throw new Exception("Eroare la crearea colecției: " . mysqli_error($conn_central));
                }
                
                $id_colectie_principala = mysqli_insert_id($conn_central);
                mysqli_stmt_close($stmt_col);
                
                // 2. Actualizează datele utilizatorului cu ID-ul colecției principale
                $update_sql = "UPDATE utilizatori SET 
                              tabele_create = 1, 
                              prefix_tabele = ?, 
                              db_name = 'inventar_central',
                              id_colectie_principala = ?
                              WHERE id_utilizator = ?";
                $stmt = mysqli_prepare($conn_central, $update_sql);
                mysqli_stmt_bind_param($stmt, "sii", $prefix, $id_colectie_principala, $user['id_utilizator']);
                
                if (!mysqli_stmt_execute($stmt)) {
                    throw new Exception("Eroare la actualizarea utilizatorului: " . mysqli_error($conn_central));
                }
                mysqli_stmt_close($stmt);
                
                // Commit tranzacția
                mysqli_commit($conn_central);
                $success = true;
                
                // Creează directoarele pentru imagini
                $dirs = [
                    "imagini_obiecte/user_" . $user['id_utilizator'],
                    "imagini_decupate/user_" . $user['id_utilizator']
                ];
                
                foreach ($dirs as $dir) {
                    if (!file_exists($dir)) {
                        mkdir($dir, 0777, true);
                    }
                }
                
                // Setează colecția în sesiune
                $_SESSION['id_colectie_curenta'] = $id_colectie_principala;
                $_SESSION['id_colectie_selectata'] = $id_colectie_principala;
                $_SESSION['prefix_tabele'] = $prefix;
                
                // IMPORTANT: Forțează reîncărcarea datelor utilizatorului din baza de date
                // pentru a avea valorile actualizate
                $refresh_sql = "SELECT * FROM utilizatori WHERE id_utilizator = ?";
                $refresh_stmt = mysqli_prepare($conn_central, $refresh_sql);
                mysqli_stmt_bind_param($refresh_stmt, "i", $user['id_utilizator']);
                mysqli_stmt_execute($refresh_stmt);
                $refresh_result = mysqli_stmt_get_result($refresh_stmt);
                if ($refresh_row = mysqli_fetch_assoc($refresh_result)) {
                    // Actualizează datele în sesiune
                    $_SESSION['user_data'] = $refresh_row;
                    $_SESSION['user_data']['id_colectie_principala'] = $id_colectie_principala;
                    $_SESSION['user_data']['prefix_tabele'] = $prefix;
                    $_SESSION['user_data']['tabele_create'] = 1;
                }
                mysqli_stmt_close($refresh_stmt);
                
                // Curăță orice date vechi din sesiune care ar putea cauza confuzie
                unset($_SESSION['colectie_proprietar_id']);
                unset($_SESSION['tip_acces_colectie']);
                unset($_SESSION['tip_partajare']);
                unset($_SESSION['cutii_partajate']);
                
            } catch (Exception $e) {
                mysqli_rollback($conn_central);
                $errors[] = $e->getMessage();
                $all_success = false;
            }
        }
        
        mysqli_close($conn_central);
        
        // Dacă totul a mers bine, redirecționează automat
        if ($success) {
            header('Location: index.php?new_setup=1');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configurare Cont - Inventar.live</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: Arial, sans-serif;
            background-color: #f5f5f5;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }
        
        .setup-container {
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            width: 100%;
            max-width: 600px;
        }
        
        .logo {
            text-align: center;
            margin-bottom: 30px;
            background-color: #e0e0e0;
            background-image:
                linear-gradient(rgba(160, 160, 160, 0.4) 1px, transparent 1px),
                linear-gradient(90deg, rgba(160, 160, 160, 0.4) 1px, transparent 1px);
            background-size: 15px 15px;
            padding: 20px;
            border-radius: 3px;
            border: 2px solid #555;
            border-top-width: 7px;
            box-shadow:
                0 2px 5px rgba(0,0,0,0.2),
                inset 0 -1px 0 rgba(0,0,0,0.1),
                inset 0 1px 0 rgba(255,255,255,0.6);
            margin: -40px -40px 30px -40px;
        }
        
        .logo h1 {
            color: #333;
            font-size: 28px;
            margin: 0;
        }
        
        .logo span {
            color: #ff6600;
        }
        
        .choice-button {
            width: 100%;
            padding: 30px;
            border: 2px solid #ddd;
            border-radius: 10px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            background: none;
            font-size: 16px;
            margin: 10px 0;
        }
        
        .choice-button:hover {
            border-color: #ff6600;
            background-color: #fff5f0;
        }
        
        .btn-continue {
            display: inline-block;
            padding: 14px 30px;
            background-color: #ff6600;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            font-size: 16px;
            font-weight: 600;
            transition: background-color 0.3s;
            text-align: center;
            width: 100%;
        }
        
        .btn-continue:hover {
            background-color: #e55500;
        }
        
        .status-icon {
            font-size: 60px;
            margin: 20px 0;
            text-align: center;
            color: #4CAF50;
        }
        
        .message {
            text-align: center;
            font-size: 18px;
            color: #666;
            margin-bottom: 20px;
        }
        
        .error-message {
            background-color: #fee;
            color: #c33;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }
    </style>
</head>
<body>
    <div class="setup-container">
        <div class="logo">
            <h1>Inventar<span>.live</span></h1>
        </div>
        
        <?php if ($success): ?>
            <div class="status-icon">✓</div>
            <h2 style="text-align: center;">Contul tău a fost configurat cu succes!</h2>
            <p class="message">
                Spațiul tău personal de inventar a fost creat și este gata de utilizare.
            </p>
            <a href="index.php" class="btn-continue">Începe să folosești Inventar.live</a>
            
        <?php elseif (!empty($errors)): ?>
            <h2 style="text-align: center; color: #c33;">Eroare la configurare</h2>
            <div class="error-message">
                <?php foreach ($errors as $error): ?>
                    <div><?php echo htmlspecialchars($error); ?></div>
                <?php endforeach; ?>
            </div>
            <a href="setup_user_db.php" class="btn-continue">Încearcă din nou</a>
            
        <?php else: ?>
            <h2 style="text-align: center; margin-bottom: 10px;">Bine ai venit!</h2>
            <p style="text-align: center; color: #666; margin-bottom: 30px;">
                Să configurăm spațiul tău personal de inventar
            </p>
            
            <form method="POST">
                <button type="submit" name="action" value="create_new" class="choice-button">
                    <h3>🆕 Creează un inventar nou</h3>
                    <p>Începe de la zero cu un inventar gol</p>
                </button>
            </form>
            
            <p style="text-align: center; color: #999; margin-top: 20px; font-size: 14px;">
                Opțiunea de import date existente va fi disponibilă în curând
            </p>
        <?php endif; ?>
    </div>
</body>
</html>