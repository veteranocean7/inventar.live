<?php
require_once 'includes/auth_functions.php';

// Verifică dacă utilizatorul este deja autentificat
if (checkSession()) {
    header('Location: index.php');
    exit;
}

$errors = [];

// Procesare formular login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $parola = $_POST['parola'] ?? '';

    if (empty($email) || empty($parola)) {
        $errors[] = "Email și parolă sunt obligatorii";
    } else {
        $conn_central = getCentralDbConnection();

        // Caută utilizatorul
        $sql = "SELECT * FROM utilizatori WHERE email = ? AND activ = 1";
        $stmt = mysqli_prepare($conn_central, $sql);
        mysqli_stmt_bind_param($stmt, "s", $email);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        if ($user = mysqli_fetch_assoc($result)) {
            // Verifică parola
            if (verifyPassword($parola, $user['parola_hash'])) {
                // Creează sesiune
                if (createSession($user['id_utilizator'], $conn_central)) {
                    // Log eveniment
                    logAuthEvent($user['id_utilizator'], 'login', "Autentificare reușită");

                    // Verifică dacă baza de date a utilizatorului există
                    if (empty($user['db_name'])) {
                        header('Location: setup_user_db.php');
                    } else {
                        header('Location: index.php');
                    }
                    exit;
                } else {
                    $errors[] = "Eroare la crearea sesiunii";
                }
            } else {
                $errors[] = "Email sau parolă incorectă";
                logAuthEvent($user['id_utilizator'], 'failed_login', "Parolă incorectă");
            }
        } else {
            $errors[] = "Email sau parolă incorectă";
            logAuthEvent(null, 'failed_login', "Email inexistent: $email");
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
    <title>Autentificare - Inventar.live</title>

    <!-- PWA Meta Tags -->
    <meta name="theme-color" content="#007BFF">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Inventar">
    <link rel="manifest" href="manifest.json">
    <link rel="icon" href="favicon.ico" sizes="any">
    <link rel="icon" type="image/svg+xml" href="icons/logo-inventar.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="icons/icon-32x32.png">
    <link rel="apple-touch-icon" sizes="180x180" href="icons/icon-180x180.png">

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

        .login-container {
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            width: 100%;
            max-width: 400px;
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

        input[type="email"],
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

        .btn-login {
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

        .btn-login:hover {
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

        .register-link {
            text-align: center;
            margin-top: 20px;
            color: #666;
        }

        .register-link a {
            color: #ff6600;
            text-decoration: none;
        }

        .register-link a:hover {
            text-decoration: underline;
        }

        .forgot-password {
            text-align: right;
            margin-top: 10px;
        }

        .forgot-password a {
            color: #666;
            text-decoration: none;
            font-size: 14px;
        }

        .forgot-password a:hover {
            color: #ff6600;
        }

        .info-section {
            background-color: #f8f8f8;
            padding: 20px;
            margin: -20px -40px 30px -40px;
            border-left: 4px solid #ff6600;
        }

        .info-section h2 {
            color: #333;
            font-size: 18px;
            margin-bottom: 15px;
            font-weight: 600;
        }

        .info-section .feature {
            margin-bottom: 12px;
            padding-left: 25px;
            position: relative;
            color: #555;
            line-height: 1.5;
        }

        .info-section .feature:before {
            content: "✓";
            position: absolute;
            left: 0;
            color: #ff6600;
            font-weight: bold;
            font-size: 16px;
        }

        .info-section .feature:last-child {
            margin-bottom: 0;
        }

        .info-section .highlight {
            color: #ff6600;
            font-weight: 500;
        }
    </style>
</head>
<body>
<div class="login-container">
    <div class="logo">
        <h1>Inventar<span>.live</span></h1>
        <div class="tagline">Organizează simplu, găsește rapid</div>
    </div>

    <div class="info-section">
        <div class="feature">
            Faci poză cutiei cu obiecte și le găsești instant când ai nevoie. Opțional, inventar.live le poate detecta automat și îți va spune oricând unde sunt.
        </div>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="error-message">
            <?php foreach ($errors as $error): ?>
                <div><?php echo htmlspecialchars($error); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="">
        <div class="form-group">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required autofocus>
        </div>

        <div class="form-group">
            <label for="parola">Parolă</label>
            <input type="password" id="parola" name="parola" required>
        </div>

        <button type="submit" class="btn-login">Autentifică-te</button>

        <div class="forgot-password">
            <a href="forgot_password.php">Ai uitat parola?</a>
        </div>
    </form>

    <div class="register-link">
        Nu ai cont? <a href="register.php">Înregistrează-te</a>
    </div>
</div>

<!-- PWA Install Assistant -->
<script src="js/pwa-install-assistant.js"></script>
<script>
// Înregistrare Service Worker
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function() {
        navigator.serviceWorker.register('/sw.js')
            .then(function(registration) {
                console.log('[PWA] Service Worker înregistrat:', registration.scope);
            })
            .catch(function(error) {
                console.error('[PWA] Eroare la înregistrare SW:', error);
            });
    });
}
</script>
</body>
</html>