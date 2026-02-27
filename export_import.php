<?php
require_once 'includes/auth_functions.php';
require_once 'config.php';

// Verifică autentificarea
$user = checkSession();
if (!$user) {
    header('Location: login.php');
    exit;
}

// Verifică dacă utilizatorul are baza de date configurată
if (empty($user['db_name'])) {
    header('Location: setup_user_db.php');
    exit;
}

// Reconectează la baza de date a utilizatorului
mysqli_close($conn);
$conn = getUserDbConnection($user['db_name']);
if (!$conn) {
    die("Eroare la conectarea la baza de date personală.");
}

// Setează prefix pentru tabele
$table_prefix = $user['prefix_tabele'] ?? 'user_' . $user['id_utilizator'] . '_';

$errors = [];
$success = false;

// Procesare export
if (isset($_GET['export'])) {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="inventar_export_' . date('Y-m-d_H-i-s') . '.sql"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Header comentariu
    echo "-- Inventar.live Export\n";
    echo "-- User: " . $user['email'] . "\n";
    echo "-- Date: " . date('Y-m-d H:i:s') . "\n";
    echo "-- Tables: {$table_prefix}obiecte, {$table_prefix}detectii_obiecte\n\n";
    
    // Export tabel obiecte
    echo "-- Table structure for `{$table_prefix}obiecte`\n";
    echo "DROP TABLE IF EXISTS `{$table_prefix}obiecte`;\n";
    
    $create_table = mysqli_query($conn, "SHOW CREATE TABLE `{$table_prefix}obiecte`");
    if ($row = mysqli_fetch_row($create_table)) {
        echo $row[1] . ";\n\n";
    }
    
    // Export date obiecte
    echo "-- Data for table `{$table_prefix}obiecte`\n";
    $result = mysqli_query($conn, "SELECT * FROM `{$table_prefix}obiecte`");
    while ($row = mysqli_fetch_assoc($result)) {
        $columns = array_keys($row);
        $values = array_map(function($val) use ($conn) {
            return $val === null ? 'NULL' : "'" . mysqli_real_escape_string($conn, $val) . "'";
        }, array_values($row));
        
        echo "INSERT INTO `{$table_prefix}obiecte` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $values) . ");\n";
    }
    
    echo "\n";
    
    // Export tabel detectii_obiecte dacă există
    $check_table = mysqli_query($conn, "SHOW TABLES LIKE '{$table_prefix}detectii_obiecte'");
    if (mysqli_num_rows($check_table) > 0) {
        echo "-- Table structure for `{$table_prefix}detectii_obiecte`\n";
        echo "DROP TABLE IF EXISTS `{$table_prefix}detectii_obiecte`;\n";
        
        $create_table = mysqli_query($conn, "SHOW CREATE TABLE `{$table_prefix}detectii_obiecte`");
        if ($row = mysqli_fetch_row($create_table)) {
            echo $row[1] . ";\n\n";
        }
        
        // Export date detectii_obiecte
        echo "-- Data for table `{$table_prefix}detectii_obiecte`\n";
        $result = mysqli_query($conn, "SELECT * FROM `{$table_prefix}detectii_obiecte`");
        while ($row = mysqli_fetch_assoc($result)) {
            $columns = array_keys($row);
            $values = array_map(function($val) use ($conn) {
                return $val === null ? 'NULL' : "'" . mysqli_real_escape_string($conn, $val) . "'";
            }, array_values($row));
            
            echo "INSERT INTO `{$table_prefix}detectii_obiecte` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $values) . ");\n";
        }
    }
    
    exit;
}

// Procesare import - direct prin import_handler.php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['import_file'])) {
    // Redirecționează formularul către import_handler.php
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <script src="js/notifications.js"></script>
        <link rel="stylesheet" href="css/notifications.css">
    </head>
    <body>
        <form id="importForm" action="import_handler.php" method="POST" enctype="multipart/form-data" style="display: none;">
            <input type="file" name="import_file" id="hiddenFile">
            <?php if (isset($_POST['truncate_before_import'])): ?>
            <input type="hidden" name="truncate_before_import" value="<?php echo $_POST['truncate_before_import']; ?>">
            <?php endif; ?>
        </form>
        
        <script>
        // Transfer fișierul la formularul ascuns
        const fileInput = document.getElementById('hiddenFile');
        const dt = new DataTransfer();
        
        // Creează un obiect File din datele primite
        <?php
        $fileContent = file_get_contents($_FILES['import_file']['tmp_name']);
        $base64 = base64_encode($fileContent);
        ?>
        
        const base64Data = '<?php echo $base64; ?>';
        const byteCharacters = atob(base64Data);
        const byteNumbers = new Array(byteCharacters.length);
        for (let i = 0; i < byteCharacters.length; i++) {
            byteNumbers[i] = byteCharacters.charCodeAt(i);
        }
        const byteArray = new Uint8Array(byteNumbers);
        const blob = new Blob([byteArray], { type: 'application/sql' });
        const file = new File([blob], '<?php echo $_FILES['import_file']['name']; ?>', { type: 'application/sql' });
        
        dt.items.add(file);
        fileInput.files = dt.files;
        
        // Trimite formularul prin AJAX
        const formData = new FormData(document.getElementById('importForm'));
        
        fetch('import_handler.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                console.log('Import success response:', data); // Debug
                console.log('Prefix utilizator curent: <?php echo $table_prefix; ?>'); // Debug prefix
                
                // Verifică dacă s-au importat efectiv date
                if (data.details && data.details.imported === 0 && data.details.inserts_found === 0) {
                    showWarning('Import finalizat, dar nu s-au găsit date în fișier! Verifică dacă fișierul conține INSERT-uri.', 8000);
                } else {
                    showSuccess(data.message);
                }
                
                if (data.details) {
                    let details = 'Detalii import: ';
                    if (data.details.inserts_found !== undefined) {
                        details += 'INSERT-uri găsite: ' + data.details.inserts_found + '. ';
                    }
                    if (data.details.imported !== undefined) {
                        details += 'Importate cu succes: ' + data.details.imported + '. ';
                    }
                    if (data.details.skipped) details += 'Comenzi ignorate: ' + data.details.skipped + '. ';
                    if (data.details.prefix_updated) {
                        details += 'Prefix actualizat de la ' + data.details.old_prefix + ' la ' + data.details.new_prefix + '. ';
                    }
                    if (data.details.truncated) details += 'Datele existente au fost șterse. ';
                    if (data.details.duplicates) details += '\nDuplicate găsite: ' + data.details.duplicates + ' (datele existau deja).';
                    
                    // Debug queries
                    if (data.details.debug_queries) {
                        console.log('Primele INSERT-uri procesate:', data.details.debug_queries);
                    }
                    if (data.details.content_preview) {
                        console.log('Preview conținut după procesare:', data.details.content_preview);
                    }
                    if (data.details.total_queries_before_filter !== undefined) {
                        console.log('Total queries găsite:', data.details.total_queries_before_filter);
                    }
                    if (data.details.first_queries) {
                        console.log('Primele queries:', data.details.first_queries);
                    }
                    
                    setTimeout(() => showInfo(details, 6000), 500);
                }
                setTimeout(() => {
                    window.location.href = 'export_import.php';
                }, 3000);
            } else {
                console.log('Import error response:', data); // Debug
                let fullMessage = data.message;
                
                if (data.details) {
                    if (data.details.info) {
                        fullMessage += '\n\n' + data.details.info;
                    }
                    if (data.details.detected_tables && data.details.detected_tables.length > 0) {
                        fullMessage += '\n\nTabele găsite în fișier:\n• ' + data.details.detected_tables.join('\n• ');
                    }
                    if (data.details.expected_prefix) {
                        fullMessage += '\n\nPrefix așteptat: ' + data.details.expected_prefix;
                    }
                    if (data.details.inserts_found !== undefined) {
                        fullMessage += '\n\nINSERT-uri găsite în fișier: ' + data.details.inserts_found;
                    }
                    if (data.details.imported !== undefined || data.details.failed !== undefined) {
                        fullMessage += '\n\nRezultat: ' + (data.details.imported || 0) + ' importate, ' + (data.details.failed || 0) + ' eșuate';
                    }
                }
                
                showError(fullMessage, 15000); // Afișează pentru 15 secunde
                setTimeout(() => {
                    window.location.href = 'export_import.php';
                }, 15000);
            }
        })
        .catch(error => {
            showError('Eroare la procesarea importului: ' + error.message);
            setTimeout(() => {
                window.location.href = 'export_import.php';
            }, 3000);
        });
        </script>
    </body>
    </html>
    <?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Export/Import - Inventar.live</title>
    <link rel="stylesheet" href="css/notifications.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            -webkit-box-sizing: border-box;
            -moz-box-sizing: border-box;
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
        
        .section {
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
            margin-bottom: 20px;
        }
        
        .section-title {
            color: #333;
            font-size: 20px;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #ff6600;
        }
        
        .section-description {
            color: #666;
            margin-bottom: 20px;
            line-height: 1.6;
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-export {
            background-color: #4CAF50;
            color: white;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }
        
        .btn-export:hover {
            background-color: #45a049;
            transform: translateY(-1px);
            box-shadow: 0 3px 8px rgba(0,0,0,0.3);
        }
        
        .btn-import {
            background-color: #ff6600;
            color: white;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }
        
        .btn-import:hover {
            background-color: #e55500;
            transform: translateY(-1px);
            box-shadow: 0 3px 8px rgba(0,0,0,0.3);
        }
        
        .btn-secondary {
            background-color: #666;
            color: white;
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
        
        .file-input-wrapper {
            position: relative;
            display: inline-block;
            cursor: pointer;
            margin-right: 10px;
        }
        
        .file-input-wrapper input[type="file"] {
            position: absolute;
            left: -9999px;
        }
        
        .file-input-label {
            padding: 12px 24px;
            background-color: #6c757d;
            color: white;
            border-radius: 5px;
            font-size: 16px;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .file-input-label:hover {
            background-color: #5a6268;
        }
        
        .info-box {
            background-color: rgba(255, 102, 0, 0.1);
            border: 2px solid #ff6600;
            border-radius: 3px;
            padding: 15px;
            margin-bottom: 20px;
            position: relative;
        }
        
        .info-box h4 {
            color: #ff6600;
            margin-bottom: 10px;
        }
        
        .info-box ul {
            margin-left: 20px;
            color: #555;
        }
        
        .table-info {
            background-color: rgba(255, 255, 255, 0.7);
            padding: 10px;
            border-radius: 3px;
            margin-bottom: 20px;
            font-family: monospace;
            color: #555;
            border: 1px solid #ddd;
        }
        
        /* Fix specific pentru Chrome Mobile */
        @media only screen and (max-width: 768px) {
            .container {
                width: calc(100vw - 20px) !important;
                max-width: calc(100vw - 20px) !important;
                margin-left: auto !important;
                margin-right: auto !important;
            }
        }
        
        /* Responsive design pentru tablete */
        @media screen and (max-width: 768px) {
            body {
                padding: 10px;
                margin: 0;
                -webkit-text-size-adjust: 100%; /* Previne zoom-ul automat în Safari/Chrome */
            }
            
            .container {
                width: 100% !important;
                max-width: none !important; /* "none" în loc de "100%" pentru Chrome */
                padding: 0 5px !important;
                margin: 0 !important;
                -webkit-box-sizing: border-box;
                box-sizing: border-box;
            }
            
            .header {
                margin: 0 0 20px 0;
                padding: 20px 15px;
                /* Păstrăm border-radius și stilul distinctiv */
            }
            
            .header h1 {
                font-size: 22px;
            }
            
            .header .subtitle {
                font-size: 14px;
            }
            
            .section {
                margin: 0 0 15px 0;
                padding: 20px 15px;
                /* Păstrăm toate borderele și stilul */
            }
            
            .section-title {
                font-size: 18px;
                margin-bottom: 15px;
            }
            
            .section-description {
                font-size: 14px;
            }
            
            /* Butoane responsive */
            .btn {
                width: 100%;
                margin-bottom: 10px;
                padding: 14px;
                font-size: 15px;
            }
            
            /* Form elements full width */
            input[type="file"],
            select,
            input[type="checkbox"] {
                width: 100%;
                margin-bottom: 10px;
            }
            
            /* Checkbox wrapper */
            label {
                display: block;
                margin-bottom: 10px;
            }
            
            /* Progress bar responsive */
            #progressContainer {
                margin: 15px 0;
            }
            
            /* Select colecție dropdown */
            select {
                padding: 10px;
                font-size: 14px;
            }
        }
        
        /* Pentru ecrane foarte mici (telefoane) */
        @media screen and (max-width: 480px) {
            body {
                padding: 8px;
                margin: 0;
                -webkit-text-size-adjust: 100%;
            }
            
            .container {
                width: 100% !important;
                max-width: none !important; /* "none" în loc de "100%" pentru Chrome */
                padding: 0 2px !important;
                margin: 0 !important;
                -webkit-box-sizing: border-box;
                box-sizing: border-box;
            }
            
            .header {
                margin: 0 0 15px 0;
                padding: 15px 12px;
                border-top-width: 5px;
            }
            
            .header h1 {
                font-size: 20px;
            }
            
            .header .subtitle {
                font-size: 12px;
            }
            
            .section {
                padding: 15px 12px;
                border-top-width: 5px;
                /* Menține stilul inventar.live cu grid pattern */
            }
            
            .section-title {
                font-size: 16px;
                margin-bottom: 12px;
                padding-bottom: 8px;
            }
            
            .section-description {
                font-size: 13px;
                line-height: 1.5;
            }
            
            .btn {
                padding: 12px;
                font-size: 14px;
            }
            
            /* Back link */
            .back-link {
                font-size: 14px;
                padding: 8px;
            }
            
            /* Alert messages */
            .alert {
                padding: 12px;
                font-size: 13px;
            }
            
            /* Form labels */
            label {
                font-size: 14px;
            }
            
            /* File upload area */
            input[type="file"] {
                font-size: 13px;
                padding: 8px;
            }
            
            /* Progress text */
            #progressText {
                font-size: 13px;
            }
            
            /* Import results */
            #importResults {
                font-size: 13px;
                padding: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="index.php" class="back-link">
            <span>←</span> Înapoi la inventar
        </a>
        
        <div class="header">
            <h1>Export/Import Date</h1>
            <div class="subtitle">Gestionează backup-ul datelor tale</div>
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
        
        <div class="info-box">
            <h4>ℹ️ Informații importante:</h4>
            <ul>
                <li>Tabelele tale au prefixul: <strong><?php echo htmlspecialchars($table_prefix); ?></strong></li>
                <li>Export-ul include: <strong><?php echo htmlspecialchars($table_prefix); ?>obiecte</strong> și <strong><?php echo htmlspecialchars($table_prefix); ?>detectii_obiecte</strong></li>
                <li>Fișierele export sunt în format SQL standard</li>
                <li>Fișierele cu CREATE TABLE sau din phpMyAdmin sunt acceptate</li>
                <li>Prefixul tabelelor va fi ajustat automat dacă e necesar</li>
            </ul>
        </div>
        
        <!-- Export -->
        <div class="section">
            <h2 class="section-title">📤 Export Date</h2>
            <p class="section-description">
                Descarcă o copie completă a datelor tale de inventar în format SQL. 
                Această copie poate fi folosită pentru backup sau pentru a transfera datele pe alt cont.
            </p>
            <div class="table-info">
                Export tabele: <?php echo htmlspecialchars($table_prefix); ?>obiecte, <?php echo htmlspecialchars($table_prefix); ?>detectii_obiecte
            </div>
            <a href="?export=1" class="btn btn-export">Descarcă Export SQL</a>
        </div>
        
        <!-- Import -->
        <div class="section">
            <h2 class="section-title">📥 Import Date</h2>
            <p class="section-description">
                Încarcă un fișier SQL exportat anterior pentru a restaura datele tale de inventar.
                <strong>Atenție:</strong> Această operație va înlocui toate datele existente!
            </p>
            <form method="POST" enctype="multipart/form-data" onsubmit="return confirmImport(this);">
                <div class="file-input-wrapper">
                    <label for="import_file" class="file-input-label">
                        Alege fișier SQL
                    </label>
                    <input type="file" id="import_file" name="import_file" accept=".sql" required onchange="updateFileName(this)">
                </div>
                <span id="file-name" style="color: #666;"></span>
                <br><br>
                <button type="submit" class="btn btn-import">Importă Date</button>
                <br><br>
                <label style="display: inline-flex; align-items: center; gap: 8px; color: #666; cursor: pointer;">
                    <input type="checkbox" name="truncate_before_import" value="1" style="width: 18px; height: 18px; cursor: pointer;">
                    <span>Șterge datele existente înainte de import</span>
                </label>
            </form>
        </div>
        
        <!-- Link înapoi -->
        <div class="section" style="text-align: center;">
            <a href="profil.php" class="btn btn-secondary">Înapoi la Profil</a>
        </div>
    </div>
    
    <script>
        function updateFileName(input) {
            const fileName = input.files[0]?.name || '';
            document.getElementById('file-name').textContent = fileName;
        }
        
        function confirmImport(form) {
            const truncate = form.truncate_before_import?.checked;
            let message = 'Sigur doriți să importați?';
            if (truncate) {
                message += '\n\n⚠️ ATENȚIE: Toate datele existente vor fi ȘTERSE permanent!';
            } else {
                message += '\n\nDatele duplicate vor fi actualizate.';
            }
            return confirm(message);
        }
        
        // Funcție pentru afișarea mesajelor info
        function showInfo(message, duration = 4000) {
            if (typeof showNotification === 'function') {
                showNotification(message, 'info', duration);
            }
        }
    </script>
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