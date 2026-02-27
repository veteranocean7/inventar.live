<?php
require_once 'includes/auth_functions.php';

// Verifică autentificarea
$user = checkSession();
if (!$user) {
    header('Location: login.php');
    exit;
}

$conn_central = getCentralDbConnection();
$errors = [];
$success = false;

// Procesare formular actualizare date
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_profile'])) {
        $nume = trim($_POST['nume'] ?? '');
        $prenume = trim($_POST['prenume'] ?? '');
        $telefon = trim($_POST['telefon'] ?? '');
        
        // Validări
        if (empty($nume) || empty($prenume)) {
            $errors[] = "Numele și prenumele sunt obligatorii";
        } else {
            // Actualizează datele
            $sql = "UPDATE utilizatori SET nume = ?, prenume = ?, telefon = ? WHERE id_utilizator = ?";
            $stmt = mysqli_prepare($conn_central, $sql);
            mysqli_stmt_bind_param($stmt, "sssi", $nume, $prenume, $telefon, $user['id_utilizator']);
            
            if (mysqli_stmt_execute($stmt)) {
                $success = "Datele au fost actualizate cu succes!";
                // Actualizează sesiunea
                $_SESSION['user']['nume'] = $nume;
                $_SESSION['user']['prenume'] = $prenume;
                $_SESSION['user']['telefon'] = $telefon;
                $user['nume'] = $nume;
                $user['prenume'] = $prenume;
                $user['telefon'] = $telefon;
            } else {
                $errors[] = "Eroare la actualizarea datelor";
            }
            mysqli_stmt_close($stmt);
        }
    } elseif (isset($_POST['update_password'])) {
        $parola_veche = $_POST['parola_veche'] ?? '';
        $parola_noua = $_POST['parola_noua'] ?? '';
        $confirma_parola = $_POST['confirma_parola'] ?? '';
        
        // Validări
        if (empty($parola_veche) || empty($parola_noua) || empty($confirma_parola)) {
            $errors[] = "Toate câmpurile pentru parolă sunt obligatorii";
        } elseif ($parola_noua !== $confirma_parola) {
            $errors[] = "Parolele noi nu coincid";
        } elseif (strlen($parola_noua) < 6) {
            $errors[] = "Parola nouă trebuie să aibă minim 6 caractere";
        } else {
            // Verifică parola veche
            if (verifyPassword($parola_veche, $user['parola_hash'])) {
                // Actualizează parola
                $parola_hash = hashPassword($parola_noua);
                $sql = "UPDATE utilizatori SET parola_hash = ? WHERE id_utilizator = ?";
                $stmt = mysqli_prepare($conn_central, $sql);
                mysqli_stmt_bind_param($stmt, "si", $parola_hash, $user['id_utilizator']);
                
                if (mysqli_stmt_execute($stmt)) {
                    $success = "Parola a fost schimbată cu succes!";
                    logAuthEvent($user['id_utilizator'], 'password_change', "Parolă schimbată cu succes");
                } else {
                    $errors[] = "Eroare la schimbarea parolei";
                }
                mysqli_stmt_close($stmt);
            } else {
                $errors[] = "Parola veche este incorectă";
            }
        }
    }
}

mysqli_close($conn_central);
?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profil - Inventar.live</title>
    <link rel="stylesheet" href="css/notifications.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: Arial, sans-serif;
            background-color: #f5f5f5;
            padding: 20px;
        }
        
        .container {
            max-width: 800px;
            margin: 0 auto;
        }
        
        .header {
            background-color: #e0e0e0;
            background-image:
                linear-gradient(rgba(160, 160, 160, 0.4) 1px, transparent 1px),
                linear-gradient(90deg, rgba(160, 160, 160, 0.4) 1px, transparent 1px);
            background-size: 15px 15px;
            padding: 30px;
            border-radius: 3px;
            border: 2px solid #555;
            border-top-width: 7px;
            box-shadow:
                0 2px 5px rgba(0,0,0,0.2),
                inset 0 -1px 0 rgba(0,0,0,0.1),
                inset 0 1px 0 rgba(255,255,255,0.6);
            margin-bottom: 30px;
            text-align: center;
        }
        
        .header h1 {
            color: #333;
            font-size: 28px;
            margin-bottom: 5px;
        }
        
        .header .subtitle {
            color: #666;
            font-size: 16px;
        }
        
        .profile-section {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .section-title {
            color: #333;
            font-size: 20px;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #ff6600;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            color: #555;
            font-weight: 500;
        }
        
        .form-group input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
            transition: border-color 0.3s;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #ff6600;
        }
        
        .form-group input[readonly] {
            background-color: #f5f5f5;
            cursor: not-allowed;
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .btn-primary {
            background-color: #ff6600;
            color: white;
        }
        
        .btn-primary:hover {
            background-color: #e55500;
            transform: translateY(-1px);
            box-shadow: 0 2px 5px rgba(255,102,0,0.3);
        }
        
        .btn-secondary {
            background-color: #666;
            color: white;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-secondary:hover {
            background-color: #555;
        }
        
        .alert {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        /* Responsive design pentru tablete */
        @media screen and (max-width: 768px) {
            body {
                padding: 10px;
            }
            
            .container {
                width: 100%;
                padding: 0 5px;
            }
            
            .header {
                margin: 0 0 20px 0;
                padding: 20px 15px;
                /* Păstrăm stilul distinctiv cu grid pattern */
            }
            
            .header h1 {
                font-size: 22px;
            }
            
            .subtitle {
                font-size: 14px;
            }
            
            .profile-section {
                margin: 0 0 15px 0;
                padding: 20px 15px;
                /* Păstrăm toate borderele și stilul */
            }
            
            .section-title {
                font-size: 18px;
                margin-bottom: 15px;
            }
            
            .form-row {
                grid-template-columns: 1fr;
                gap: 10px;
            }
            
            .form-group label {
                font-size: 14px;
            }
            
            .form-group input {
                padding: 10px;
                font-size: 14px;
            }
            
            .btn {
                width: 100%;
                padding: 14px;
                font-size: 15px;
                margin-top: 10px;
            }
            
            .info-text {
                font-size: 13px;
            }
            
            .alert {
                padding: 12px;
                font-size: 14px;
                margin: 0 0 15px 0;
            }
        }
        
        /* Pentru ecrane foarte mici (telefoane) */
        @media screen and (max-width: 480px) {
            body {
                padding: 8px;
            }
            
            .container {
                padding: 0 2px;
            }
            
            .header {
                margin: 0 0 15px 0;
                padding: 15px 12px;
                border-top-width: 5px;
            }
            
            .header h1 {
                font-size: 20px;
            }
            
            .subtitle {
                font-size: 12px;
            }
            
            .profile-section {
                padding: 15px 12px;
                border-top-width: 5px;
                /* Menține stilul inventar.live */
            }
            
            .section-title {
                font-size: 16px;
                margin-bottom: 12px;
                padding-bottom: 8px;
            }
            
            .form-group label {
                font-size: 13px;
                margin-bottom: 4px;
            }
            
            .form-group input {
                padding: 8px;
                font-size: 13px;
            }
            
            .btn {
                padding: 12px;
                font-size: 14px;
            }
            
            .info-text {
                font-size: 12px;
                line-height: 1.4;
            }
            
            /* Back link */
            .back-link {
                font-size: 14px;
                padding: 8px;
            }
            
            .alert {
                padding: 10px;
                font-size: 13px;
            }
            
            /* Spacing adjustments */
            .form-row {
                margin-bottom: 10px;
            }
            
            .form-group {
                margin-bottom: 10px;
            }
        }
        
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            color: #ff6600;
            text-decoration: none;
            margin-bottom: 20px;
            transition: color 0.3s;
        }
        
        .back-link:hover {
            color: #e55500;
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="index.php" class="back-link">
            <span>←</span> Înapoi la inventar
        </a>
        
        <div class="header">
            <h1>Profil utilizator</h1>
            <div class="subtitle"><?php echo htmlspecialchars($user['email']); ?></div>
        </div>
        
        <?php if ($success): ?>
            <div class="alert alert-success">
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $error): ?>
                    <div><?php echo htmlspecialchars($error); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <!-- Date personale -->
        <div class="profile-section">
            <h2 class="section-title">Date personale</h2>
            <form method="POST">
                <div class="form-row">
                    <div class="form-group">
                        <label for="nume">Nume</label>
                        <input type="text" id="nume" name="nume" value="<?php echo htmlspecialchars($user['nume'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="prenume">Prenume</label>
                        <input type="text" id="prenume" name="prenume" value="<?php echo htmlspecialchars($user['prenume'] ?? ''); ?>" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" id="email" value="<?php echo htmlspecialchars($user['email']); ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label for="telefon">Telefon</label>
                        <input type="tel" id="telefon" name="telefon" value="<?php echo htmlspecialchars($user['telefon'] ?? ''); ?>">
                    </div>
                </div>
                
                <button type="submit" name="update_profile" class="btn btn-primary">Salvează modificările</button>
            </form>
        </div>
        
        <!-- Schimbare parolă -->
        <div class="profile-section">
            <h2 class="section-title">Schimbare parolă</h2>
            <form method="POST">
                <div class="form-group">
                    <label for="parola_veche">Parola actuală</label>
                    <input type="password" id="parola_veche" name="parola_veche" required>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="parola_noua">Parola nouă</label>
                        <input type="password" id="parola_noua" name="parola_noua" required minlength="6">
                    </div>
                    <div class="form-group">
                        <label for="confirma_parola">Confirmă parola nouă</label>
                        <input type="password" id="confirma_parola" name="confirma_parola" required>
                    </div>
                </div>
                
                <button type="submit" name="update_password" class="btn btn-primary">Schimbă parola</button>
            </form>
        </div>
        
        <!-- Link către export/import -->
        <div class="profile-section">
            <h2 class="section-title">Gestionare date</h2>
            <p style="margin-bottom: 20px; color: #666;">
                Exportă sau importă datele tale de inventar pentru backup sau transfer.
            </p>
            <a href="export_import.php" class="btn btn-secondary">Mergi la Export/Import</a>
        </div>
    </div>
    <script src="js/notifications.js"></script>
    <?php if ($success): ?>
    <script>
        showSuccess('<?php echo addslashes($success); ?>');
    </script>
    <?php endif; ?>
    
    <?php if (!empty($errors)): ?>
    <script>
        <?php foreach ($errors as $error): ?>
        showError('<?php echo addslashes($error); ?>');
        <?php endforeach; ?>
    </script>
    <?php endif; ?>
</body>
</html>