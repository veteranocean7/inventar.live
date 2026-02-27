<?php
require_once 'includes/auth_functions.php';

// Verifică dacă utilizatorul este deja autentificat
if (checkSession()) {
    header('Location: index.php');
    exit;
}

$errors = [];
$success = false;

// Procesare formular înregistrare
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nume = trim($_POST['nume'] ?? '');
    $prenume = trim($_POST['prenume'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $telefon = trim($_POST['telefon'] ?? '');
    $parola = $_POST['parola'] ?? '';
    $confirma_parola = $_POST['confirma_parola'] ?? '';
    
    // Validări
    if (empty($nume)) {
        $errors[] = "Numele este obligatoriu";
    }
    
    if (empty($prenume)) {
        $errors[] = "Prenumele este obligatoriu";
    }
    
    if (!validateEmail($email)) {
        $errors[] = "Adresa de email nu este validă";
    }
    
    if (!empty($telefon) && !validatePhone($telefon)) {
        $errors[] = "Numărul de telefon nu este valid";
    }
    
    if (strlen($parola) < 6) {
        $errors[] = "Parola trebuie să aibă minim 6 caractere";
    }
    
    if ($parola !== $confirma_parola) {
        $errors[] = "Parolele nu coincid";
    }
    
    // Dacă nu sunt erori, încearcă înregistrarea
    if (empty($errors)) {
        $conn_central = getCentralDbConnection();
        
        // Verifică dacă email-ul există deja
        $check_sql = "SELECT id_utilizator FROM utilizatori WHERE email = ?";
        $check_stmt = mysqli_prepare($conn_central, $check_sql);
        mysqli_stmt_bind_param($check_stmt, "s", $email);
        mysqli_stmt_execute($check_stmt);
        $check_result = mysqli_stmt_get_result($check_stmt);
        
        if (mysqli_num_rows($check_result) > 0) {
            $errors[] = "Această adresă de email este deja înregistrată";
        } else {
            // Hash parola
            $parola_hash = hashPassword($parola);
            
            // Începe tranzacția
            mysqli_begin_transaction($conn_central);
            
            try {
                // Inserează utilizatorul
                $insert_sql = "INSERT INTO utilizatori (nume, prenume, email, telefon, parola_hash) 
                              VALUES (?, ?, ?, ?, ?)";
                $insert_stmt = mysqli_prepare($conn_central, $insert_sql);
                mysqli_stmt_bind_param($insert_stmt, "sssss", $nume, $prenume, $email, $telefon, $parola_hash);
                
                if (!mysqli_stmt_execute($insert_stmt)) {
                    throw new Exception("Eroare la înregistrarea utilizatorului");
                }
                
                $id_utilizator = mysqli_insert_id($conn_central);
                
                // Actualizează numele bazei de date
                $db_name = generateUserDbName($id_utilizator);
                $update_sql = "UPDATE utilizatori SET db_name = ? WHERE id_utilizator = ?";
                $update_stmt = mysqli_prepare($conn_central, $update_sql);
                mysqli_stmt_bind_param($update_stmt, "si", $db_name, $id_utilizator);
                
                if (!mysqli_stmt_execute($update_stmt)) {
                    throw new Exception("Eroare la actualizarea utilizatorului");
                }
                
                // Commit tranzacția
                mysqli_commit($conn_central);
                
                // Log eveniment
                logAuthEvent($id_utilizator, 'register', "Înregistrare reușită");
                
                // Creează sesiune și autentifică utilizatorul
                if (createSession($id_utilizator, $conn_central)) {
                    // Redirecționează către pagina de creare bază de date
                    header('Location: setup_user_db.php');
                    exit;
                }
                
                $success = true;
                
            } catch (Exception $e) {
                mysqli_rollback($conn_central);
                $errors[] = "Eroare la înregistrare: " . $e->getMessage();
            }
        }
        
        mysqli_close($conn_central);
    }
}
?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Înregistrare - Inventar.live</title>
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
        
        .register-container {
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            width: 100%;
            max-width: 450px;
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
        
        .logo .tagline {
            color: #999;
            font-size: 14px;
            margin-top: 5px;
            font-weight: normal;
            text-shadow: 
                0 1px 0 rgba(255,255,255,0.8),
                0 -1px 0 rgba(0,0,0,0.1);
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 5px;
            color: #555;
            font-weight: 500;
        }
        
        input[type="text"],
        input[type="email"],
        input[type="tel"],
        input[type="password"] {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
            transition: border-color 0.3s;
        }
        
        input:focus {
            outline: none;
            border-color: #ff6600;
        }
        
        .btn-register {
            width: 100%;
            padding: 14px;
            background-color: #ff6600;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.3s;
        }
        
        .btn-register:hover {
            background-color: #e55500;
        }
        
        .error-message {
            background-color: #fee;
            color: #c33;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        
        .success-message {
            background-color: #efe;
            color: #3c3;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        
        .login-link {
            text-align: center;
            margin-top: 20px;
            color: #666;
        }
        
        .login-link a {
            color: #ff6600;
            text-decoration: none;
        }
        
        .login-link a:hover {
            text-decoration: underline;
        }
        
        .form-row {
            display: flex;
            gap: 15px;
        }
        
        .form-row .form-group {
            flex: 1;
        }
        
        .optional {
            color: #999;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="register-container">
        <div class="logo">
            <h1>Inventar<span>.live</span></h1>
            <div class="tagline">Organizează simplu, găsește rapid</div>
        </div>
        
        <?php if (!empty($errors)): ?>
            <div class="error-message">
                <?php foreach ($errors as $error): ?>
                    <div><?php echo htmlspecialchars($error); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="success-message">
                Înregistrare reușită! Vei fi redirecționat...
            </div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <div class="form-row">
                <div class="form-group">
                    <label for="nume">Nume</label>
                    <input type="text" id="nume" name="nume" value="<?php echo htmlspecialchars($_POST['nume'] ?? ''); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="prenume">Prenume</label>
                    <input type="text" id="prenume" name="prenume" value="<?php echo htmlspecialchars($_POST['prenume'] ?? ''); ?>" required>
                </div>
            </div>
            
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
            </div>
            
            <div class="form-group">
                <label for="telefon">Telefon <span class="optional">(opțional)</span></label>
                <input type="tel" id="telefon" name="telefon" value="<?php echo htmlspecialchars($_POST['telefon'] ?? ''); ?>" placeholder="07xxxxxxxx">
            </div>
            
            <div class="form-group">
                <label for="parola">Parolă</label>
                <input type="password" id="parola" name="parola" required minlength="6">
            </div>
            
            <div class="form-group">
                <label for="confirma_parola">Confirmă parola</label>
                <input type="password" id="confirma_parola" name="confirma_parola" required>
            </div>
            
            <button type="submit" class="btn-register">Înregistrează-te</button>
        </form>
        
        <div class="login-link">
            Ai deja cont? <a href="login.php">Autentifică-te</a>
        </div>
    </div>
</body>
</html>