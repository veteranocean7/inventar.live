<?php
/**
 * Pagina pentru gestionarea partajărilor
 * Inventar.live - August 2025
 */

require_once 'includes/auth_functions.php';
require_once 'config.php';

// Verifică autentificarea
$user = checkSession();
if (!$user) {
    header('Location: login.php');
    exit;
}

// Obține conexiunea la baza centrală
$conn_central = getCentralDbConnection();

// Obține colecțiile utilizatorului pentru selectare
$sql_colectii = "SELECT * FROM colectii_utilizatori 
                 WHERE id_utilizator = ? 
                 ORDER BY este_principala DESC, nume_colectie";
$stmt = mysqli_prepare($conn_central, $sql_colectii);
mysqli_stmt_bind_param($stmt, "i", $user['id_utilizator']);
mysqli_stmt_execute($stmt);
$result_colectii = mysqli_stmt_get_result($stmt);

$colectii_proprii = [];
while ($row = mysqli_fetch_assoc($result_colectii)) {
    $colectii_proprii[] = $row;
}
mysqli_stmt_close($stmt);

// Obține membrii familiei cu care partajez
$sql_membri = "SELECT DISTINCT p.*, u.nume, u.prenume, u.email, c.nume_colectie
               FROM partajari p
               JOIN utilizatori u ON p.id_utilizator_partajat = u.id_utilizator
               JOIN colectii_utilizatori c ON p.id_colectie = c.id_colectie
               WHERE c.id_utilizator = ? AND p.activ = 1
               ORDER BY u.prenume, u.nume";
$stmt = mysqli_prepare($conn_central, $sql_membri);
mysqli_stmt_bind_param($stmt, "i", $user['id_utilizator']);
mysqli_stmt_execute($stmt);
$result_membri = mysqli_stmt_get_result($stmt);

$membri_familie = [];
while ($row = mysqli_fetch_assoc($result_membri)) {
    // Decodifică cutiile partajate dacă există
    if (!empty($row['cutii_partajate'])) {
        $row['cutii_array'] = json_decode($row['cutii_partajate'], true);
    } else {
        $row['cutii_array'] = [];
    }
    $membri_familie[] = $row;
}
mysqli_stmt_close($stmt);

// Obține colecțiile partajate cu mine
$sql_partajate = "SELECT c.*, p.tip_acces, u.nume, u.prenume
                  FROM partajari p
                  JOIN colectii_utilizatori c ON p.id_colectie = c.id_colectie
                  JOIN utilizatori u ON c.id_utilizator = u.id_utilizator
                  WHERE p.id_utilizator_partajat = ? AND p.activ = 1
                  ORDER BY u.prenume, u.nume, c.nume_colectie";
$stmt = mysqli_prepare($conn_central, $sql_partajate);
mysqli_stmt_bind_param($stmt, "i", $user['id_utilizator']);
mysqli_stmt_execute($stmt);
$result_partajate = mysqli_stmt_get_result($stmt);

$colectii_partajate = [];
while ($row = mysqli_fetch_assoc($result_partajate)) {
    $colectii_partajate[] = $row;
}
mysqli_stmt_close($stmt);

mysqli_close($conn_central);
?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Împarte cu ceilalți - Inventar.live</title>
    <!-- Librării pentru QR Code -->
    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <style>
        @keyframes pulse {
            0% {
                transform: scale(1);
                opacity: 1;
            }
            50% {
                transform: scale(1.05);
                opacity: 0.8;
            }
            100% {
                transform: scale(1);
                opacity: 1;
            }
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            padding: 20px;
            min-height: 100vh;
        }
        
        /* Container principal */
        .partajari-container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        /* Header stil inventar.live */
        .header-box {
            background-color: #e0e0e0;
            background-image:
                linear-gradient(rgba(160, 160, 160, 0.4) 1px, transparent 1px),
                linear-gradient(90deg, rgba(160, 160, 160, 0.4) 1px, transparent 1px);
            background-size: 15px 15px;
            padding: 25px;
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
        
        .header-box h1 {
            color: #333;
            font-size: 28px;
            margin-bottom: 10px;
        }
        
        .header-box .subtitle {
            color: #666;
            font-size: 14px;
        }
        
        /* Secțiuni stil inventar.live */
        .section-partajare {
            background-color: #e0e0e0;
            background-image:
                linear-gradient(rgba(160, 160, 160, 0.4) 1px, transparent 1px),
                linear-gradient(90deg, rgba(160, 160, 160, 0.4) 1px, transparent 1px);
            background-size: 12px 12px;
            padding: 25px;
            border-radius: 3px;
            border: 2px solid #555;
            border-top-width: 6px;
            box-shadow:
                0 2px 5px rgba(0,0,0,0.2),
                inset 0 -1px 0 rgba(0,0,0,0.1),
                inset 0 1px 0 rgba(255,255,255,0.6);
            margin-bottom: 25px;
        }
        
        .section-title {
            font-size: 20px;
            color: #333;
            font-weight: bold;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid rgba(85, 85, 85, 0.3);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .section-title .icon {
            font-size: 24px;
            margin-right: 10px;
        }
        
        /* Form invitare */
        .form-invitare {
            display: flex;
            gap: 15px;
            margin-bottom: 25px;
            align-items: flex-end;
        }
        
        .form-group {
            flex: 1;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            color: #666;
            font-size: 14px;
        }
        
        .form-group input, 
        .form-group select {
            width: 100%;
            padding: 10px;
            background-color: white;
            border: 2px solid #999;
            border-radius: 3px;
            font-size: 14px;
            transition: all 0.3s;
        }
        
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #ff6600;
            box-shadow: 0 0 5px rgba(255, 102, 0, 0.3);
        }
        
        .btn-invita {
            padding: 10px 25px;
            background-color: #ff6600;
            color: white;
            border: 2px solid #d55500;
            border-radius: 3px;
            font-weight: bold;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s;
            box-shadow:
                0 2px 4px rgba(0,0,0,0.2),
                inset 0 1px 0 rgba(255,255,255,0.3);
        }
        
        .btn-invita:hover {
            background-color: #e55500;
            transform: translateY(-2px);
            box-shadow:
                0 4px 8px rgba(0,0,0,0.3),
                inset 0 1px 0 rgba(255,255,255,0.3);
        }
        
        /* Media Queries pentru dispozitive mobile */
        @media screen and (max-width: 768px) {
            body {
                padding: 10px;
            }
            
            .partajari-container {
                width: 100%;
                padding: 0 5px;
            }
            
            .header-box {
                margin: 0 0 20px 0;
                padding: 20px 15px;
                /* Păstrăm border-radius pentru a menține stilul */
            }
            
            .header-box h1 {
                font-size: 22px;
            }
            
            /* Tab-uri responsive */
            .tabs-partajare {
                overflow-x: auto;
                white-space: nowrap;
                -webkit-overflow-scrolling: touch;
                scrollbar-width: thin;
                padding-bottom: 5px;
                margin-bottom: -2px;
                margin-left: 0;
                margin-right: 0;
            }
            
            .tab-partajare {
                padding: 10px 15px;
                font-size: 14px;
                flex-shrink: 0;
            }
            
            /* Secțiuni păstrând stilul cu borduri vizibile */
            .section-partajare {
                margin: 0 0 15px 0;
                /* Păstrăm border-radius și toate borderele */
                padding: 20px 15px;
                width: 100%;
                /* Borderele și stilul rămân intacte din stilul principal */
            }
            
            .section-title {
                font-size: 18px;
                flex-wrap: wrap;
            }
            
            /* Form responsive */
            .form-invitare {
                flex-direction: column;
                gap: 10px;
            }
            
            .form-group {
                width: 100%;
            }
            
            .btn-invita {
                width: 100%;
                padding: 12px;
            }
            
            /* Tabel membri responsive */
            .tabel-membri {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            
            .tabel-membri table {
                min-width: 100%;
                font-size: 14px;
            }
            
            .tabel-membri td,
            .tabel-membri th {
                padding: 8px;
            }
            
            /* Butoane acțiuni în tabel */
            .btn-action {
                padding: 5px 10px;
                font-size: 12px;
            }
            
            /* Modal responsive */
            .modal-content {
                width: 95%;
                margin: 10px;
                max-height: 90vh;
                overflow-y: auto;
            }
            
            /* Lista checkbox-uri pentru cutii */
            .checkbox-group {
                max-height: 200px;
                overflow-y: auto;
            }
        }
        
        /* Pentru ecrane foarte mici (sub 480px) */
        @media screen and (max-width: 480px) {
            body {
                padding: 8px;
            }
            
            .partajari-container {
                padding: 0 2px;
            }
            
            .header-box {
                margin: 0 0 15px 0;
                padding: 15px 12px;
            }
            
            .header-box h1 {
                font-size: 20px;
            }
            
            .header-box .subtitle {
                font-size: 12px;
            }
            
            .tabs-partajare {
                gap: 2px;
                margin: 0 0 0 0;
            }
            
            .tab-partajare {
                padding: 8px 12px;
                font-size: 13px;
            }
            
            .section-partajare {
                padding: 15px 12px;
                /* Menține borderele și border-radius pentru stilul inventar.live */
            }
            
            .section-title {
                font-size: 16px;
            }
            
            .section-title .icon {
                font-size: 20px;
            }
            
            /* Stack pentru ecrane foarte mici */
            .membru-item {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .membru-actions {
                margin-top: 10px;
                width: 100%;
                display: flex;
                gap: 5px;
            }
            
            .membru-actions button {
                flex: 1;
            }
        }
        
        /* Fix pentru scroll orizontal pe tab-uri */
        .tabs-partajare::-webkit-scrollbar {
            height: 6px;
        }
        
        .tabs-partajare::-webkit-scrollbar-track {
            background: rgba(0, 0, 0, 0.1);
            border-radius: 3px;
        }
        
        .tabs-partajare::-webkit-scrollbar-thumb {
            background: rgba(0, 0, 0, 0.3);
            border-radius: 3px;
        }
        
        .tabs-partajare::-webkit-scrollbar-thumb:hover {
            background: rgba(0, 0, 0, 0.5);
        }
        
        /* Lista membri */
        .membri-list {
            display: grid;
            gap: 15px;
        }
        
        .membru-item {
            display: flex;
            align-items: center;
            padding: 15px;
            background: white;
            border: 2px solid #ccc;
            border-radius: 3px;
            transition: all 0.3s;
        }
        
        .membru-item:hover {
            border-color: #999;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .membru-avatar {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #ff6600 0%, #ff8833 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            font-weight: bold;
            color: white;
            margin-right: 15px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }
        
        .membru-info {
            flex: 1;
        }
        
        .membru-nume {
            font-weight: 600;
            color: #333;
            margin-bottom: 3px;
        }
        
        .membru-email {
            color: #666;
            font-size: 13px;
        }
        
        .membru-colectie {
            display: inline-block;
            padding: 3px 8px;
            background: #e3f2fd;
            color: #1976d2;
            border-radius: 3px;
            font-size: 12px;
            margin-top: 5px;
        }
        
        .membru-acces {
            padding: 5px 10px;
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 13px;
            margin-right: 10px;
        }
        
        .btn-revoca {
            padding: 8px 15px;
            background-color: #d32f2f;
            color: white;
            border: 2px solid #b71c1c;
            border-radius: 3px;
            font-weight: bold;
            cursor: pointer;
            font-size: 13px;
            transition: all 0.3s;
            box-shadow:
                0 2px 4px rgba(0,0,0,0.2),
                inset 0 1px 0 rgba(255,255,255,0.3);
        }
        
        .btn-revoca:hover {
            background-color: #c62828;
            transform: translateY(-1px);
            box-shadow:
                0 3px 6px rgba(0,0,0,0.3),
                inset 0 1px 0 rgba(255,255,255,0.3);
        }
        
        /* Colecții partajate cu mine */
        .colectii-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }
        
        .colectie-card {
            background-color: #e0e0e0;
            background-image:
                linear-gradient(rgba(160, 160, 160, 0.3) 1px, transparent 1px),
                linear-gradient(90deg, rgba(160, 160, 160, 0.3) 1px, transparent 1px);
            background-size: 10px 10px;
            padding: 20px;
            border-radius: 3px;
            border: 2px solid #555;
            border-top-width: 5px;
            box-shadow:
                0 2px 5px rgba(0,0,0,0.2),
                inset 0 -1px 0 rgba(0,0,0,0.1),
                inset 0 1px 0 rgba(255,255,255,0.6);
            cursor: pointer;
            transition: all 0.3s;
            position: relative;
        }
        
        .colectie-card:hover {
            border-color: #9370DB;
            box-shadow:
                0 4px 8px rgba(147, 112, 219, 0.3),
                inset 0 -1px 0 rgba(0,0,0,0.1),
                inset 0 1px 0 rgba(255,255,255,0.6);
            transform: translateY(-2px);
        }
        
        .colectie-proprietar {
            color: #666;
            font-size: 13px;
            margin-bottom: 8px;
        }
        
        .colectie-nume {
            font-size: 18px;
            font-weight: 600;
            color: #333;
            margin-bottom: 10px;
        }
        
        .colectie-tip-acces {
            position: absolute;
            top: 15px;
            right: 15px;
            padding: 4px 8px;
            background: #e8f5e9;
            color: #2e7d32;
            border-radius: 3px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .colectie-tip-acces.scriere {
            background: #fff3e0;
            color: #e65100;
        }
        
        /* Tab-uri stil inventar.live */
        .tabs-partajare {
            display: flex;
            gap: 5px;
            margin-bottom: 0;
            border-bottom: 2px solid #555;
            padding-bottom: 0;
        }
        
        .tab-partajare {
            background-color: #e0e0e0;
            background-image:
                linear-gradient(rgba(160, 160, 160, 0.3) 1px, transparent 1px),
                linear-gradient(90deg, rgba(160, 160, 160, 0.3) 1px, transparent 1px);
            background-size: 10px 10px;
            padding: 12px 25px;
            border-radius: 8px 8px 0 0;
            border: 2px solid #ccc;
            border-bottom: none;
            cursor: pointer;
            color: #666;
            font-weight: 600;
            font-size: 15px;
            transition: all 0.3s;
            position: relative;
            margin-bottom: -2px;
        }
        
        .tab-partajare:hover {
            background-color: #d5d5d5;
            color: #333;
        }
        
        .tab-partajare.active {
            background-color: #f4f4f4;
            border-color: #555;
            border-top-width: 4px;
            color: #333;
            z-index: 10;
            box-shadow:
                0 -2px 4px rgba(0,0,0,0.1),
                inset 0 1px 0 rgba(255,255,255,0.8);
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        /* Mesaje informative */
        .info-message {
            padding: 15px;
            background: #e3f2fd;
            border-left: 4px solid #2196F3;
            border-radius: 3px;
            color: #0d47a1;
            margin-bottom: 20px;
        }
        
        .empty-state {
            text-align: center;
            padding: 50px 20px;
            color: #999;
        }
        
        .empty-state .icon {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        .empty-state-text {
            font-size: 16px;
            margin-bottom: 10px;
        }
        
        .empty-state-subtext {
            font-size: 14px;
            color: #bbb;
        }
        
        /* Link înapoi simplu */
        .back-link {
            display: inline-flex;
            align-items: center;
            color: #ff6600;
            text-decoration: none;
            margin-bottom: 20px;
            transition: color 0.3s;
            font-weight: 600;
        }
        
        .back-link:hover {
            color: #e55500;
        }
        
        .back-link span {
            margin-right: 5px;
        }
        
        /* Container pentru tab content */
        .content-container {
            background-color: #f4f4f4;
            border: 2px solid #555;
            border-top: none;
            padding: 30px;
            min-height: 400px;
        }
    </style>
</head>
<body>
    <div class="partajari-container">
        <!-- Link înapoi la inventar -->
        <a href="index.php" class="back-link">
            <span>←</span> Înapoi la inventar
        </a>
        
        <h1 style="color: #333; margin-bottom: 30px; position: relative;">
            <span style="color: #9370DB;">📤</span> Împarte cu ceilalți
            <span id="notificareChatGlobal" style="
                display: none;
                position: absolute;
                top: -5px;
                right: 10px;
                background: #ff4444;
                color: white;
                border-radius: 12px;
                padding: 4px 8px;
                font-size: 14px;
                font-weight: bold;
                animation: pulse 2s infinite;
            ">💬 Ai mesaje necitite</span>
        </h1>
        
        <!-- Tab-uri pentru navigare între secțiuni -->
        <div class="tabs-partajare">
            <button class="tab-partajare active" onclick="schimbaTab('partajez', event)">
                Partajez eu
            </button>
            <button class="tab-partajare" onclick="schimbaTab('primesc', event)">
                Partajate cu mine
            </button>
            <button class="tab-partajare" onclick="schimbaTab('imprumuturi', event)" style="position: relative;">
                Împrumuturi
                <span id="badgeImprumuturi" style="display: none; position: absolute; top: -8px; right: -8px; 
                                                   background: #ff0000; color: white; border-radius: 50%; 
                                                   width: 20px; height: 20px; line-height: 20px; 
                                                   font-size: 11px; text-align: center; font-weight: bold;">0</span>
            </button>
            <button class="tab-partajare" onclick="schimbaTab('public', event)">
                Colecții publice
            </button>
        </div>
        
        <!-- TAB 1: Partajez eu -->
        <div id="tab-partajez" class="tab-content active">
            <!-- Secțiune invitare membri familie -->
            <div class="section-partajare">
                <h2 class="section-title">
                    <span>
                        <span class="icon">👨‍👩‍👧‍👦</span>
                        Invită membri de familie
                    </span>
                </h2>
                
                <form class="form-invitare" id="formInvitare">
                    <div class="form-group">
                        <label for="emailMembru">Email membru familie</label>
                        <input type="email" id="emailMembru" placeholder="exemplu@email.com" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="colectiePartajare">Colecție de partajat</label>
                        <select id="colectiePartajare" required>
                            <option value="">Selectează colecția...</option>
                            <?php foreach ($colectii_proprii as $col): ?>
                                <option value="<?php echo $col['id_colectie']; ?>">
                                    <?php echo htmlspecialchars($col['nume_colectie']); ?>
                                    <?php if ($col['este_principala']): ?>(Principal)<?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="tipAcces">Tip acces</label>
                        <select id="tipAcces" required>
                            <option value="citire">Doar vizualizare</option>
                            <option value="scriere">Poate edita</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="tipPartajare">Tip partajare</label>
                        <select id="tipPartajare" required onchange="toggleCutiiSelectie()">
                            <option value="completa">📦 Toate cutiile din colecție</option>
                            <option value="selectiva">✅ Doar cutiile selectate</option>
                        </select>
                    </div>
                    
                    <div id="cutiiSelectie" class="form-group" style="display: none;">
                        <label>Selectează cutiile care vor fi vizibile:</label>
                        <div id="listaCutii" style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; border-radius: 3px; background: white;">
                            <div style="text-align: center; color: #666;">
                                Selectează mai întâi o colecție...
                            </div>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn-invita">
                        Trimite invitație
                    </button>
                </form>
                
                <!-- Listă membri existenți -->
                <h3 style="margin-top: 30px; margin-bottom: 15px; color: #666;">
                    Membri cu acces la colecțiile tale
                </h3>
                
                <?php if (empty($membri_familie)): ?>
                    <div class="empty-state">
                        <div class="icon">👥</div>
                        <p>Nu ai partajat încă nicio colecție</p>
                    </div>
                <?php else: ?>
                    <div class="membri-list">
                        <?php foreach ($membri_familie as $membru): ?>
                            <div class="membru-item">
                                <div class="membru-avatar">
                                    <?php echo strtoupper(substr($membru['prenume'], 0, 1)); ?>
                                </div>
                                <div class="membru-info">
                                    <div class="membru-nume">
                                        <?php echo htmlspecialchars($membru['prenume'] . ' ' . $membru['nume']); ?>
                                    </div>
                                    <div class="membru-email">
                                        <?php echo htmlspecialchars($membru['email']); ?>
                                    </div>
                                    <span class="membru-colectie">
                                        📁 <?php echo htmlspecialchars($membru['nume_colectie']); ?>
                                    </span>
                                    <?php if ($membru['tip_partajare'] == 'selectiva' && !empty($membru['cutii_array'])): ?>
                                        <div style="font-size: 12px; color: #888; margin-top: 3px;">
                                            📦 <?php echo count($membru['cutii_array']); ?> cutii selectate
                                        </div>
                                    <?php elseif ($membru['tip_partajare'] == 'completa'): ?>
                                        <div style="font-size: 12px; color: #888; margin-top: 3px;">
                                            📦 Toate cutiile
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <span class="membru-acces">
                                    <?php echo $membru['tip_acces'] == 'scriere' ? '✏️ Poate edita' : '👁️ Doar vizualizare'; ?>
                                </span>
                                <button class="btn-revoca" onclick="editarePartajare(<?php echo $membru['id_partajare']; ?>)" style="margin-right: 5px; background-color: #666;">
                                    ⚙️ Modifică
                                </button>
                                <button class="btn-revoca" onclick="revocaAcces(<?php echo $membru['id_partajare']; ?>)">
                                    Revocă acces
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Secțiune colecții publice proprii -->
            <div class="section-partajare">
                <h2 class="section-title">
                    <span>
                        <span class="icon">🌍</span>
                        Colecțiile mele publice
                    </span>
                </h2>
                
                <div class="info-message">
                    💡 Colecțiile marcate ca publice permit căutarea obiectelor pentru orice utilizator înregistrat.
                    Aceștia vor vedea obiectele tale ca "<?php echo htmlspecialchars($user['prenume']); ?> - [Nume Colecție]"
                </div>
                
                <div class="colectii-grid">
                    <?php 
                    $are_colectii_publice = false;
                    foreach ($colectii_proprii as $col): 
                        if ($col['este_publica']):
                            $are_colectii_publice = true;
                    ?>
                        <div class="colectie-card" onclick="window.location.href='index.php?c=<?php echo $col['id_colectie']; ?>'">
                            <div class="colectie-nume">
                                <?php echo htmlspecialchars($col['nume_colectie']); ?>
                            </div>
                            <div style="color: #4CAF50; font-size: 13px;">
                                ✓ Colecție publică
                            </div>
                        </div>
                    <?php 
                        endif;
                    endforeach; 
                    
                    if (!$are_colectii_publice):
                    ?>
                        <div class="empty-state" style="grid-column: 1 / -1;">
                            <div class="icon">🔒</div>
                            <div class="empty-state-text">Nu ai nicio colecție publică</div>
                            <div class="empty-state-subtext">Pentru a face o colecție publică, folosește butonul grid global din pagina principală</div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- TAB 2: Partajate cu mine -->
        <div id="tab-primesc" class="tab-content">
            <div class="section-partajare">
                <h2 class="section-title">
                    <span>
                        <span class="icon">📥</span>
                        Colecții partajate cu mine
                    </span>
                </h2>
                
                <?php if (empty($colectii_partajate)): ?>
                    <div class="empty-state">
                        <div class="icon">📭</div>
                        <p>Nu ai primit acces la nicio colecție</p>
                        <p style="font-size: 14px; color: #999; margin-top: 10px;">
                            Când cineva îți va partaja o colecție, va apărea aici
                        </p>
                    </div>
                <?php else: ?>
                    <div class="colectii-grid">
                        <?php foreach ($colectii_partajate as $col): ?>
                            <div class="colectie-card" onclick="vizualizeazaColectie(<?php echo $col['id_colectie']; ?>)">
                                <div class="colectie-tip-acces <?php echo $col['tip_acces']; ?>">
                                    <?php echo $col['tip_acces'] == 'scriere' ? 'Editare' : 'Vizualizare'; ?>
                                </div>
                                <div class="colectie-proprietar">
                                    De la: <?php echo htmlspecialchars($col['prenume'] . ' ' . $col['nume']); ?>
                                </div>
                                <div class="colectie-nume">
                                    <?php 
                                    // Afișează ca "Garaj lui [Prenume]" sau "Cutia lui [Prenume]"
                                    if ($col['este_principala']) {
                                        echo "Cutia lui " . htmlspecialchars($col['prenume']);
                                    } else {
                                        echo htmlspecialchars($col['nume_colectie']) . " lui " . htmlspecialchars($col['prenume']);
                                    }
                                    ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- TAB 3: Colecții publice -->
        <div id="tab-public" class="tab-content">
            <div class="section-partajare">
                <h2 class="section-title">
                    <span>
                        <span class="icon">🌐</span>
                        Colecții publice disponibile
                    </span>
                </h2>
                
                <div class="info-message">
                    🔍 Aici sunt afișate colecțiile publice ale altor utilizatori
                </div>
                
                <div id="colectiiPublice" class="colectii-grid">
                    <!-- Vor fi încărcate prin AJAX -->
                    <div class="empty-state" style="grid-column: 1 / -1;">
                        <div class="icon">⏳</div>
                        <p>Se încarcă colecțiile publice...</p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- TAB 4: Împrumuturi -->
        <div id="tab-imprumuturi" class="tab-content">
            <div style="display: flex; gap: 5px; margin-bottom: 20px;">
                <button class="btn-subtab active" onclick="schimbaSubtab('primite', event)" 
                        style="padding: 8px 16px; background: #ff6600; color: white; 
                               border: none; border-radius: 5px; cursor: pointer; font-weight: 600;">
                    Cereri Primite <span id="numarPrimite" style="background: #fff; color: #ff6600; 
                                                                   padding: 2px 6px; border-radius: 10px; 
                                                                   margin-left: 5px; font-size: 11px;">0</span>
                </button>
                <button class="btn-subtab" onclick="schimbaSubtab('trimise', event)"
                        style="padding: 8px 16px; background: #e0e0e0; color: #666; 
                               border: none; border-radius: 5px; cursor: pointer; font-weight: 600;">
                    Cereri Trimise
                </button>
            </div>
            
            <!-- Subtab Cereri Primite -->
            <div id="subtab-primite" class="subtab-content" style="display: block;">
                <div class="section-partajare">
                    <h2 class="section-title">
                        <span class="icon">📥</span> Cereri de împrumut primite
                    </h2>
                    <div id="cereriPrimite" style="margin-top: 20px;">
                        <!-- Se încarcă prin AJAX -->
                        <div class="empty-state">
                            <div class="icon">⏳</div>
                            <p>Se încarcă cererile...</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Subtab Cereri Trimise -->
            <div id="subtab-trimise" class="subtab-content" style="display: none;">
                <div class="section-partajare">
                    <h2 class="section-title">
                        <span class="icon">📤</span> Cereri de împrumut trimise
                    </h2>
                    <div id="cereriTrimise" style="margin-top: 20px;">
                        <!-- Se încarcă prin AJAX -->
                        <div class="empty-state">
                            <div class="icon">⏳</div>
                            <p>Se încarcă cererile...</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Sfârșit content-container -->
        </div>
    </div>
    
    <script>
        // Funcție pentru afișare modale în stil inventar.live
        function afiseazaModal(titlu, mesaj, tip = 'info', callback = null) {
            // Creează modal overlay
            const modal = document.createElement('div');
            modal.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background-color: rgba(0, 0, 0, 0.6);
                display: flex;
                justify-content: center;
                align-items: center;
                z-index: 10000;
                animation: fadeIn 0.3s ease;
            `;
            
            // Creează conținutul modalului
            const content = document.createElement('div');
            content.style.cssText = `
                background: white;
                border-radius: 10px;
                padding: 30px;
                max-width: 500px;
                min-width: 300px;
                box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
                animation: slideUp 0.4s ease;
            `;
            
            // Determină culoarea și iconița bazat pe tip
            let culoare = '#667eea';
            let icon = 'ℹ️';
            let bgColor = '#e8f0ff';
            let borderColor = '#667eea';
            
            if (tip === 'success') {
                culoare = '#4CAF50';
                icon = '✅';
                bgColor = '#e8f5e9';
                borderColor = '#4CAF50';
            } else if (tip === 'error') {
                culoare = '#f44336';
                icon = '❌';
                bgColor = '#ffebee';
                borderColor = '#f44336';
            } else if (tip === 'warning') {
                culoare = '#ff9800';
                icon = '⚠️';
                bgColor = '#fff3e0';
                borderColor = '#ff9800';
            }
            
            content.innerHTML = `
                <h2 style="color: #333; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                    <span style="font-size: 1.5em;">${icon}</span> ${titlu}
                </h2>
                <div style="background: ${bgColor}; border-left: 4px solid ${borderColor}; 
                            padding: 15px; border-radius: 4px; margin-bottom: 25px;">
                    <p style="color: #666; line-height: 1.6; margin: 0;">
                        ${mesaj}
                    </p>
                </div>
                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button id="btnModalOk" 
                            style="padding: 10px 20px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
                                   color: white; border: none; border-radius: 25px; cursor: pointer; 
                                   font-weight: 600; transition: all 0.3s ease;">
                        OK
                    </button>
                </div>
            `;
            
            modal.appendChild(content);
            document.body.appendChild(modal);
            
            // Adaugă animații CSS dacă nu există
            if (!document.querySelector('#modal-animations')) {
                const style = document.createElement('style');
                style.id = 'modal-animations';
                style.textContent = `
                    @keyframes fadeIn {
                        from { opacity: 0; }
                        to { opacity: 1; }
                    }
                    @keyframes slideUp {
                        from { transform: translateY(30px); opacity: 0; }
                        to { transform: translateY(0); opacity: 1; }
                    }
                `;
                document.head.appendChild(style);
            }
            
            // Funcție pentru închidere modal
            const inchideModal = () => {
                modal.remove();
                if (callback) callback();
            };
            
            // Event listeners
            document.getElementById('btnModalOk').addEventListener('click', inchideModal);
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    inchideModal();
                }
            });
            
            // Focus pe buton pentru a permite închidere cu Enter
            document.getElementById('btnModalOk').focus();
            document.getElementById('btnModalOk').addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    inchideModal();
                }
            });
        }
        
        // Funcție pentru confirmare (cu callback)
        function confirmaActiune(titlu, mesaj, callbackDa, callbackNu = null) {
            const modal = document.createElement('div');
            modal.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background-color: rgba(0, 0, 0, 0.6);
                display: flex;
                justify-content: center;
                align-items: center;
                z-index: 10000;
                animation: fadeIn 0.3s ease;
            `;
            
            const content = document.createElement('div');
            content.style.cssText = `
                background: white;
                border-radius: 10px;
                padding: 30px;
                max-width: 500px;
                box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
                animation: slideUp 0.4s ease;
            `;
            
            content.innerHTML = `
                <h2 style="color: #333; margin-bottom: 20px;">
                    <span style="font-size: 1.5em;">❓</span> ${titlu}
                </h2>
                <div style="background: #fff3e0; border-left: 4px solid #ff9800; 
                            padding: 15px; border-radius: 4px; margin-bottom: 25px;">
                    <p style="color: #666; line-height: 1.6; margin: 0;">
                        ${mesaj}
                    </p>
                </div>
                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button id="btnModalDa" 
                            style="padding: 10px 20px; background: #ff6600; color: white; border: none; 
                                   border-radius: 25px; cursor: pointer; font-weight: 600;">
                        Da
                    </button>
                    <button id="btnModalNu" 
                            style="padding: 10px 20px; background: #666; color: white; border: none; 
                                   border-radius: 25px; cursor: pointer; font-weight: 600;">
                        Nu
                    </button>
                </div>
            `;
            
            modal.appendChild(content);
            document.body.appendChild(modal);
            
            // Event listeners
            document.getElementById('btnModalDa').addEventListener('click', () => {
                modal.remove();
                if (callbackDa) callbackDa();
            });
            
            document.getElementById('btnModalNu').addEventListener('click', () => {
                modal.remove();
                if (callbackNu) callbackNu();
            });
            
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    modal.remove();
                    if (callbackNu) callbackNu();
                }
            });
        }
        
        // Schimbă între tab-uri
        function schimbaTab(tab, evt) {
            // Ascunde toate tab-urile
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            document.querySelectorAll('.tab-partajare').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Arată tab-ul selectat
            const tabContent = document.getElementById('tab-' + tab);
            if (tabContent) {
                tabContent.classList.add('active');
            }
            
            // Activează butonul corespunzător
            if (evt && evt.target) {
                evt.target.classList.add('active');
            } else {
                // Când e apelat programatic, găsește butonul corect
                document.querySelectorAll('.tab-partajare').forEach(btn => {
                    if (btn.getAttribute('onclick') && btn.getAttribute('onclick').includes(tab)) {
                        btn.classList.add('active');
                    }
                });
            }
            
            // Încarcă colecțiile publice când se selectează tab-ul
            if (tab === 'public') {
                incarcaColectiiPublice();
            }
            
            // Încarcă cererile de împrumut când se selectează tab-ul
            if (tab === 'imprumuturi') {
                incarcaCereriImprumuturi();
            }
        }
        
        // Toggle afișare selecție cutii
        function toggleCutiiSelectie() {
            const tipPartajare = document.getElementById('tipPartajare').value;
            const cutiiSelectie = document.getElementById('cutiiSelectie');
            
            if (tipPartajare === 'selectiva') {
                cutiiSelectie.style.display = 'block';
            } else {
                cutiiSelectie.style.display = 'none';
            }
        }
        
        // Când se schimbă colecția selectată
        document.getElementById('colectiePartajare').addEventListener('change', function() {
            // Întotdeauna reîncarcă cutiile când se schimbă colecția
            // pentru a arăta starea corectă de partajare
            incarcaCutiiColectie();
        });
        
        // Când se completează email-ul, verifică dacă există deja o partajare
        document.getElementById('emailMembru').addEventListener('blur', function() {
            if (this.value && document.getElementById('colectiePartajare').value) {
                // Reîncarcă cutiile pentru a arăta starea actuală
                incarcaCutiiColectie();
            }
        });
        
        // Încarcă cutiile pentru colecția selectată
        function incarcaCutiiColectie() {
            const idColectie = document.getElementById('colectiePartajare').value;
            const emailMembru = document.getElementById('emailMembru').value;
            const listaCutii = document.getElementById('listaCutii');
            
            if (!idColectie) {
                listaCutii.innerHTML = '<div style="text-align: center; color: #666;">Selectează mai întâi o colecție...</div>';
                return;
            }
            
            listaCutii.innerHTML = '<div style="text-align: center; color: #666;">Se încarcă cutiile...</div>';
            
            // Obține cutiile și starea actuală a partajării
            fetch('ajax_partajare.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    actiune: 'obtine_cutii_cu_stare',
                    id_colectie: idColectie,
                    email: emailMembru
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.cutii) {
                    if (data.cutii.length === 0) {
                        listaCutii.innerHTML = '<div style="text-align: center; color: #666;">Nu există cutii în această colecție</div>';
                    } else {
                        // Dacă există informații despre partajare existentă
                        // NU setăm automat valorile pentru a permite utilizatorului să le modifice
                        // Doar afișăm un mesaj informativ
                        if (data.partajare_existenta) {
                            // Adaugă un mesaj informativ despre starea actuală
                            const infoDiv = document.createElement('div');
                            infoDiv.style.cssText = 'background: #e3f2fd; padding: 10px; margin-bottom: 10px; border-radius: 3px; font-size: 12px; color: #1976d2;';
                            infoDiv.innerHTML = `ℹ️ Partajare existentă: ${data.partajare_existenta.tip_partajare === 'selectiva' ? 'Selectivă' : 'Completă'} (${data.partajare_existenta.tip_acces})`;
                            
                            // Adaugă mesajul înaintea listei de cutii
                            const container = document.getElementById('cutiiSelectie');
                            const existingInfo = container.querySelector('div[style*="e3f2fd"]');
                            if (existingInfo) {
                                existingInfo.remove();
                            }
                            container.insertBefore(infoDiv, container.firstChild.nextSibling);
                        }
                        
                        listaCutii.innerHTML = data.cutii.map(cutie => `
                            <div style="margin-bottom: 5px;">
                                <label style="display: flex; align-items: center; cursor: pointer;">
                                    <input type="checkbox" name="cutii_selectate[]" value="${cutie.id}" 
                                           ${cutie.selectat ? 'checked' : ''}
                                           style="margin-right: 8px;">
                                    <span>${cutie.display}</span>
                                </label>
                            </div>
                        `).join('');
                    }
                } else {
                    listaCutii.innerHTML = '<div style="text-align: center; color: red;">Eroare la încărcarea cutiilor</div>';
                }
            })
            .catch(error => {
                console.error('Eroare:', error);
                listaCutii.innerHTML = '<div style="text-align: center; color: red;">Eroare la încărcarea cutiilor</div>';
            });
        }
        
        // Formular invitare membru
        document.getElementById('formInvitare').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const email = document.getElementById('emailMembru').value;
            const idColectie = document.getElementById('colectiePartajare').value;
            const tipAcces = document.getElementById('tipAcces').value;
            const tipPartajare = document.getElementById('tipPartajare').value;
            
            // Colectează cutiile selectate (dacă e partajare selectivă)
            let cutiiSelectate = null;
            if (tipPartajare === 'selectiva') {
                const checkboxes = document.querySelectorAll('input[name="cutii_selectate[]"]:checked');
                if (checkboxes.length === 0) {
                    afiseazaModal('Atenție', 'Selectează cel puțin o cutie pentru partajare selectivă!', 'warning');
                    return;
                }
                cutiiSelectate = Array.from(checkboxes).map(cb => cb.value);
            }
            
            fetch('ajax_partajare.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    actiune: 'invita_membru',
                    email: email,
                    id_colectie: idColectie,
                    tip_acces: tipAcces,
                    tip_partajare: tipPartajare,
                    cutii_selectate: cutiiSelectate
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    afiseazaModal('Succes', 'Invitația a fost trimisă cu succes!', 'success', () => {
                        location.reload();
                    });
                } else {
                    afiseazaModal('Eroare', data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Eroare:', error);
                afiseazaModal('Eroare', 'A apărut o eroare la trimiterea invitației', 'error');
            });
        });
        
        // Editare partajare existentă
        function editarePartajare(idPartajare) {
            // Creează și afișează modal în stilul inventar.live
            const modal = document.createElement('div');
            modal.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background-color: rgba(0, 0, 0, 0.6);
                display: flex;
                justify-content: center;
                align-items: center;
                z-index: 10000;
                animation: fadeIn 0.3s ease;
            `;
            
            const content = document.createElement('div');
            content.style.cssText = `
                background: white;
                border-radius: 10px;
                padding: 30px;
                max-width: 500px;
                box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
                animation: slideUp 0.4s ease;
            `;
            
            content.innerHTML = `
                <h2 style="color: #333; margin-bottom: 20px;">⚙️ Modificare Partajare</h2>
                <div style="background: #fff3e0; border-left: 4px solid #ff9800; padding: 20px; border-radius: 4px; margin-bottom: 25px;">
                    <p style="color: #e65100; font-weight: 600; margin-bottom: 10px;">ℹ️ Notă importantă:</p>
                    <p style="color: #666; line-height: 1.6;">
                        Pentru a modifica setările de partajare (inclusiv cutiile selectate), 
                        <strong>nu este necesar să revoci accesul</strong>. 
                        Poți retrimite invitația cu aceleași date (email și colecție) 
                        iar sistemul va actualiza automat setările:
                    </p>
                    <ul style="color: #666; margin-top: 10px; margin-left: 20px;">
                        <li>Tipul de acces (citire/scriere)</li>
                        <li>Tipul de partajare (completă/selectivă)</li>
                        <li>Cutiile selectate pentru partajare</li>
                    </ul>
                </div>
                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button id="btnInchideModal" 
                            style="padding: 10px 20px; background: #666; color: white; border: none; 
                                   border-radius: 5px; cursor: pointer; font-weight: 600;">
                        Am înțeles
                    </button>
                </div>
            `;
            
            modal.appendChild(content);
            document.body.appendChild(modal);
            
            // Adaugă event listener pentru butonul de închidere
            document.getElementById('btnInchideModal').addEventListener('click', function() {
                modal.remove();
            });
            
            // Adaugă animații CSS dacă nu există
            if (!document.querySelector('#modal-animations')) {
                const style = document.createElement('style');
                style.id = 'modal-animations';
                style.textContent = `
                    @keyframes fadeIn {
                        from { opacity: 0; }
                        to { opacity: 1; }
                    }
                    @keyframes slideUp {
                        from { transform: translateY(30px); opacity: 0; }
                        to { transform: translateY(0); opacity: 1; }
                    }
                `;
                document.head.appendChild(style);
            }
            
            // Închide modal la click pe fundal
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    modal.remove();
                }
            });
        }
        
        // Revocă acces membru
        function revocaAcces(idPartajare) {
            confirmaActiune(
                'Confirmare revocare acces',
                'Sigur vrei să revoci accesul acestui membru?',
                () => {
                    // Continuă cu revocarea accesului
                    fetch('ajax_partajare.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            actiune: 'revoca_acces',
                            id_partajare: idPartajare
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            afiseazaModal('Succes', 'Accesul a fost revocat!', 'success', () => {
                                location.reload();
                            });
                        } else {
                            afiseazaModal('Eroare', data.message, 'error');
                        }
                    })
                    .catch(error => {
                        console.error('Eroare:', error);
                        afiseazaModal('Eroare', 'A apărut o eroare la revocarea accesului', 'error');
                    });
                }
            );
        }
        
        // Vizualizează colecție partajată
        function vizualizeazaColectie(idColectie) {
            window.location.href = 'index.php?c=' + idColectie;
        }
        
        // Încarcă colecțiile publice
        function incarcaColectiiPublice() {
            fetch('ajax_colectii_publice.php')
                .then(response => response.json())
                .then(data => {
                    const container = document.getElementById('colectiiPublice');
                    
                    if (data.colectii && data.colectii.length > 0) {
                        container.innerHTML = data.colectii.map(col => `
                            <div class="colectie-card" onclick="vizualizeazaColectie(${col.id_colectie})">
                                <div class="colectie-proprietar">
                                    ${col.prenume} ${col.nume}
                                </div>
                                <div class="colectie-nume">
                                    ${col.este_principala ? 
                                        `Cutia lui ${col.prenume}` : 
                                        `${col.nume_colectie} lui ${col.prenume}`
                                    }
                                </div>
                                <div style="color: #4CAF50; font-size: 13px; margin-top: 10px;">
                                    🌍 Colecție publică
                                </div>
                            </div>
                        `).join('');
                    } else {
                        container.innerHTML = `
                            <div class="empty-state" style="grid-column: 1 / -1;">
                                <div class="icon">🔍</div>
                                <p>Nu există colecții publice disponibile momentan</p>
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Eroare:', error);
                    document.getElementById('colectiiPublice').innerHTML = `
                        <div class="empty-state" style="grid-column: 1 / -1;">
                            <div class="icon">❌</div>
                            <p>Eroare la încărcarea colecțiilor publice</p>
                        </div>
                    `;
                });
        }
        
        // Funcții pentru gestionarea împrumuturilor
        function schimbaSubtab(subtab, evt) {
            // Ascunde toate subtab-urile
            document.querySelectorAll('.subtab-content').forEach(content => {
                content.style.display = 'none';
            });
            
            // Resetează stilul butoanelor
            document.querySelectorAll('.btn-subtab').forEach(btn => {
                btn.style.background = '#e0e0e0';
                btn.style.color = '#666';
                btn.classList.remove('active');
            });
            
            // Activează subtab-ul selectat
            document.getElementById('subtab-' + subtab).style.display = 'block';
            
            // Activează butonul corect
            if (evt && evt.target) {
                evt.target.style.background = '#ff6600';
                evt.target.style.color = 'white';
                evt.target.classList.add('active');
            } else {
                // Când e apelat programatic, găsește butonul corect
                document.querySelectorAll('.btn-subtab').forEach(btn => {
                    if (btn.getAttribute('onclick') && btn.getAttribute('onclick').includes(subtab)) {
                        btn.style.background = '#ff6600';
                        btn.style.color = 'white';
                        btn.classList.add('active');
                    }
                });
            }
            
            // Încarcă datele pentru subtab
            if (subtab === 'primite') {
                incarcaCereriPrimite();
            } else if (subtab === 'trimise') {
                incarcaCereriTrimise();
            }
        }
        
        // Încarcă cererile de împrumut când se deschide tab-ul
        function incarcaCereriImprumuturi() {
            verificaNotificariImprumuturi();
            incarcaCereriPrimite();
        }
        
        // Verifică numărul de cereri necitite pentru notificare
        function verificaNotificariImprumuturi() {
            fetch('ajax_imprumut.php?actiune=numar_cereri_necitite')
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.numar > 0) {
                        const badge = document.getElementById('badgeImprumuturi');
                        const numarPrimite = document.getElementById('numarPrimite');
                        
                        badge.textContent = data.numar;
                        badge.style.display = 'block';
                        
                        if (numarPrimite) {
                            numarPrimite.textContent = data.numar;
                        }
                    } else {
                        document.getElementById('badgeImprumuturi').style.display = 'none';
                    }
                })
                .catch(error => console.error('Eroare la verificarea notificărilor:', error));
        }
        
        // Încarcă cererile primite
        function incarcaCereriPrimite() {
            const container = document.getElementById('cereriPrimite');
            container.innerHTML = '<div class="empty-state"><div class="icon">⏳</div><p>Se încarcă...</p></div>';
            
            fetch('ajax_imprumut.php?actiune=obtine_cereri_primite')
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.cereri.length > 0) {
                        let html = '<div style="display: flex; flex-direction: column; gap: 15px;">';
                        
                        data.cereri.forEach(cerere => {
                            const statusColor = cerere.status === 'in_asteptare' ? '#ff9800' : 
                                              cerere.status === 'aprobat' ? '#4CAF50' : 
                                              cerere.status === 'imprumutat' ? '#2196F3' :
                                              cerere.status === 'returnat' ? '#9C27B0' :
                                              cerere.status === 'anulat' ? '#757575' : '#f44336';
                            
                            // Pentru CERERI PRIMITE - afișăm CREDIBILITATEA (generală + cu mine)
                            let rankingBadge = '';
                            if (cerere.scor_credibilitate !== undefined) {
                                const credibilitate = Math.round(cerere.scor_credibilitate || 100);
                                
                                // Calculăm credibilitatea personală (cu mine)
                                let credibilitatePersonala = '';
                                if (cerere.imprumuturi_de_la_mine > 0) {
                                    const procentPersonal = Math.round((cerere.returnate_la_mine / cerere.imprumuturi_de_la_mine) * 100);
                                    let culoarePersonala;
                                    if (procentPersonal >= 90) culoarePersonala = '#4CAF50';
                                    else if (procentPersonal >= 70) culoarePersonala = '#FFC107';
                                    else if (procentPersonal >= 50) culoarePersonala = '#FF9800';
                                    else culoarePersonala = '#f44336';
                                    
                                    credibilitatePersonala = `
                                        <span style="display: inline-flex; align-items: center; gap: 3px; 
                                            padding: 2px 6px; border-radius: 10px; font-size: 11px; 
                                            background: white; border: 2px solid ${culoarePersonala}; 
                                            box-shadow: 0 1px 3px rgba(0,0,0,0.1);"
                                            title="Istoric cu mine: ${cerere.returnate_la_mine}/${cerere.imprumuturi_de_la_mine} returnate">
                                            <span style="color: ${culoarePersonala}; font-weight: 600;">
                                                👤 ${procentPersonal}%
                                            </span>
                                        </span>
                                    `;
                                } else {
                                    credibilitatePersonala = `
                                        <span style="display: inline-flex; align-items: center; gap: 3px; 
                                            padding: 2px 6px; border-radius: 10px; font-size: 11px; 
                                            background: #f5f5f5; color: #999;">
                                            👤 Prima cerere
                                        </span>
                                    `;
                                }
                                
                                // Determină culoarea pentru credibilitatea generală
                                let culoareBg;
                                if (credibilitate >= 90) culoareBg = 'linear-gradient(135deg, #c8e6c9, #81c784)';
                                else if (credibilitate >= 70) culoareBg = 'linear-gradient(135deg, #fff9c4, #ffd54f)';
                                else if (credibilitate >= 50) culoareBg = 'linear-gradient(135deg, #ffe0b2, #ffb74d)';
                                else culoareBg = 'linear-gradient(135deg, #ffcdd2, #ef5350)';
                                
                                rankingBadge = `
                                    <span style="display: inline-flex; align-items: center; gap: 8px; margin-left: 10px;">
                                        <span style="display: inline-flex; align-items: center; gap: 4px; 
                                            padding: 3px 8px; border-radius: 15px; font-size: 12px; 
                                            background: ${culoareBg}; box-shadow: 0 2px 4px rgba(0,0,0,0.1);"
                                            title="Credibilitate generală: ${credibilitate}p">
                                            <span>📥</span>
                                            <span style="color: #333; font-weight: 600;">${credibilitate}p</span>
                                        </span>
                                        ${credibilitatePersonala}
                                    </span>
                                `;
                            } else {
                                rankingBadge = `
                                    <span style="display: inline-flex; align-items: center; gap: 5px; 
                                        padding: 3px 8px; border-radius: 15px; font-size: 12px; 
                                        background: linear-gradient(135deg, #f5f5f5, #e0e0e0); margin-left: 10px;">
                                        <span style="font-size: 14px;">🆕</span>
                                        <span style="color: #666;">Utilizator nou</span>
                                    </span>
                                `;
                            }
                            
                            html += `
                                <div style="background: #f9f9f9; border: 2px solid #e0e0e0; 
                                           border-radius: 10px; padding: 20px; ${!cerere.citit ? 'border-left: 5px solid #ff6600;' : ''}">
                                    <div style="display: flex; justify-content: space-between; align-items: start;">
                                        <div style="flex: 1;">
                                            <h4 style="margin: 0 0 10px 0; color: #333; display: flex; align-items: center;">
                                                ${cerere.solicitant_nume} solicită împrumut
                                                ${rankingBadge}
                                            </h4>
                                            <p style="margin: 5px 0; color: #666;">
                                                <strong>Obiect:</strong> ${cerere.denumire_obiect}<br>
                                                <strong>Din:</strong> ${cerere.nume_colectie}<br>
                                                <strong>Cutie:</strong> ${cerere.cutie} | <strong>Locație:</strong> ${cerere.locatie}<br>
                                                <strong>Perioada:</strong> ${cerere.data_inceput} - ${cerere.data_sfarsit}
                                            </p>
                                            ${cerere.mesaj ? `<p style="margin: 10px 0; padding: 10px; background: #fff; border-radius: 5px; font-style: italic; color: #555;">"${cerere.mesaj}"</p>` : ''}
                                        </div>
                                        <div style="text-align: right;">
                                            <span style="display: inline-block; padding: 5px 10px; 
                                                       background: ${statusColor}; color: white; 
                                                       border-radius: 15px; font-size: 12px; margin-bottom: 10px;">
                                                ${cerere.status === 'in_asteptare' ? 'În așteptare' : 
                                                  cerere.status === 'aprobat' ? 'Aprobat' : 
                                                  cerere.status === 'refuzat' ? 'Refuzat' :
                                                  cerere.status === 'imprumutat' ? 'Împrumutat' :
                                                  cerere.status === 'returnat' ? 'Returnat' :
                                                  cerere.status === 'anulat' ? 'Anulat' : 'Expirat'}
                                            </span>
                                            ${cerere.status === 'in_asteptare' ? `
                                                <div style="display: flex; gap: 5px; margin-top: 10px;">
                                                    <button onclick="raspundeCerere(${cerere.id_cerere}, 'aprobat')" 
                                                            style="padding: 8px 15px; background: #4CAF50; color: white; 
                                                                   border: none; border-radius: 5px; cursor: pointer; font-weight: 600;">
                                                        ✓ Aprobă
                                                    </button>
                                                    <button onclick="raspundeCerere(${cerere.id_cerere}, 'refuzat')"
                                                            style="padding: 8px 15px; background: #f44336; color: white; 
                                                                   border: none; border-radius: 5px; cursor: pointer; font-weight: 600;">
                                                        ✕ Refuză
                                                    </button>
                                                </div>
                                            ` : ''}
                                            ${cerere.status === 'aprobat' || cerere.status === 'imprumutat' ? `
                                                <div style="margin-top: 10px; position: relative;">
                                                    <button onclick="deschideChat(${cerere.id_cerere}, '${cerere.solicitant_nume}')" 
                                                            style="padding: 8px 15px; background: #4CAF50; 
                                                                   color: white; border: none; border-radius: 5px; 
                                                                   cursor: pointer; font-weight: 600;">
                                                        💬 Discută
                                                        <span id="badgeChat${cerere.id_cerere}" style="display: none; 
                                                                position: absolute; top: -8px; right: -8px; 
                                                                background: #ff0000; color: white; border-radius: 50%; 
                                                                width: 20px; height: 20px; line-height: 20px; 
                                                                font-size: 11px; text-align: center;">0</span>
                                                    </button>
                                                </div>
                                            ` : ''}
                                        </div>
                                    </div>
                                    <div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #e0e0e0; 
                                               font-size: 12px; color: #999;">
                                        Cerere primită: ${cerere.data_cerere}
                                        ${cerere.data_raspuns ? `<br>Răspuns trimis: ${cerere.data_raspuns}` : ''}
                                        ${cerere.data_predare ? `<br>Împrumutat la: ${cerere.data_predare}` : ''}
                                        ${cerere.data_returnare_efectiva ? `<br>Returnat la: ${cerere.data_returnare_efectiva}` : ''}
                                    </div>
                                </div>
                            `;
                        });
                        
                        html += '</div>';
                        container.innerHTML = html;
                        
                        // Resetează badge-ul după citire
                        document.getElementById('badgeImprumuturi').style.display = 'none';
                        document.getElementById('numarPrimite').textContent = '0';
                        
                        // Actualizează badge-urile pentru mesaje necitite în chat
                        actualizareBadgeuriChat();
                    } else {
                        container.innerHTML = `
                            <div class="empty-state">
                                <div class="icon">📭</div>
                                <p>Nu ai cereri de împrumut primite</p>
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Eroare:', error);
                    container.innerHTML = '<div class="empty-state"><div class="icon">❌</div><p>Eroare la încărcarea cererilor</p></div>';
                });
        }
        
        // Încarcă cererile trimise
        function incarcaCereriTrimise() {
            const container = document.getElementById('cereriTrimise');
            container.innerHTML = '<div class="empty-state"><div class="icon">⏳</div><p>Se încarcă...</p></div>';
            
            fetch('ajax_imprumut.php?actiune=obtine_cereri_trimise')
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.cereri.length > 0) {
                        let html = '<div style="display: flex; flex-direction: column; gap: 15px;">';
                        
                        data.cereri.forEach(cerere => {
                            const statusColor = cerere.status === 'in_asteptare' ? '#ff9800' : 
                                              cerere.status === 'aprobat' ? '#4CAF50' : 
                                              cerere.status === 'imprumutat' ? '#2196F3' :
                                              cerere.status === 'returnat' ? '#9C27B0' :
                                              cerere.status === 'anulat' ? '#757575' : '#f44336';
                            
                            // Pentru CERERI TRIMISE - afișăm DISPONIBILITATEA (generală + față de mine)
                            let rankingBadge = '';
                            if (cerere.scor_disponibilitate !== undefined) {
                                const disponibilitate = Math.round(cerere.scor_disponibilitate || 50);
                                
                                // Calculăm disponibilitatea personală (față de mine)
                                let disponibilitatePersonala = '';
                                if (cerere.cereri_catre_el > 0) {
                                    const procentPersonal = Math.round((cerere.aprobate_pentru_mine / cerere.cereri_catre_el) * 100);
                                    
                                    // Determină badge-ul pentru disponibilitatea față de mine
                                    let badge;
                                    if (procentPersonal >= 90) badge = '💎';
                                    else if (procentPersonal >= 75) badge = '🏆';
                                    else if (procentPersonal >= 60) badge = '🥇';
                                    else if (procentPersonal >= 40) badge = '🥈';
                                    else badge = '🥉';
                                    
                                    disponibilitatePersonala = `
                                        <span style="display: inline-flex; align-items: center; gap: 3px; 
                                            padding: 2px 6px; border-radius: 10px; font-size: 11px; 
                                            background: white; border: 2px solid #667eea; 
                                            box-shadow: 0 1px 3px rgba(0,0,0,0.1);"
                                            title="Istoric cu mine: ${cerere.aprobate_pentru_mine}/${cerere.cereri_catre_el} aprobate">
                                            <span style="font-size: 12px;">${badge}</span>
                                            <span style="color: #667eea; font-weight: 600;">
                                                👤 ${procentPersonal}%
                                            </span>
                                        </span>
                                    `;
                                } else {
                                    disponibilitatePersonala = `
                                        <span style="display: inline-flex; align-items: center; gap: 3px; 
                                            padding: 2px 6px; border-radius: 10px; font-size: 11px; 
                                            background: #f5f5f5; color: #999;">
                                            👤 Prima cerere
                                        </span>
                                    `;
                                }
                                
                                // Determină badge-ul pentru disponibilitatea generală
                                let badgeGeneral;
                                if (disponibilitate >= 90) badgeGeneral = '💎';
                                else if (disponibilitate >= 75) badgeGeneral = '🏆';
                                else if (disponibilitate >= 60) badgeGeneral = '🥇';
                                else if (disponibilitate >= 40) badgeGeneral = '🥈';
                                else badgeGeneral = '🥉';
                                
                                // Determină culoarea bazată pe procent
                                let culoareBg;
                                if (disponibilitate >= 80) culoareBg = 'linear-gradient(135deg, #c8e6c9, #81c784)';
                                else if (disponibilitate >= 60) culoareBg = 'linear-gradient(135deg, #fff9c4, #ffd54f)';
                                else if (disponibilitate >= 40) culoareBg = 'linear-gradient(135deg, #ffe0b2, #ffb74d)';
                                else culoareBg = 'linear-gradient(135deg, #ffcdd2, #ef5350)';
                                
                                rankingBadge = `
                                    <span style="display: inline-flex; align-items: center; gap: 8px; margin-left: 10px;">
                                        <span style="display: inline-flex; align-items: center; gap: 4px; 
                                            padding: 3px 8px; border-radius: 15px; font-size: 12px; 
                                            background: ${culoareBg}; box-shadow: 0 2px 4px rgba(0,0,0,0.1);"
                                            title="Disponibilitate generală: ${disponibilitate}%">
                                            <span style="font-size: 14px;">${badgeGeneral}</span>
                                            <span>📤</span>
                                            <span style="color: #333; font-weight: 600;">${disponibilitate}%</span>
                                        </span>
                                        ${disponibilitatePersonala}
                                    </span>
                                `;
                            } else {
                                rankingBadge = `
                                    <span style="display: inline-flex; align-items: center; gap: 5px; 
                                        padding: 3px 8px; border-radius: 15px; font-size: 12px; 
                                        background: linear-gradient(135deg, #f5f5f5, #e0e0e0); margin-left: 10px;">
                                        <span style="font-size: 14px;">🆕</span>
                                        <span style="color: #666;">Utilizator nou</span>
                                    </span>
                                `;
                            }
                            
                            html += `
                                <div style="background: #f9f9f9; border: 2px solid #e0e0e0; 
                                           border-radius: 10px; padding: 20px; 
                                           ${!cerere.raspuns_citit && cerere.status !== 'in_asteptare' ? 'border-left: 5px solid #4CAF50; background: #f0fff0;' : ''}">
                                    <div style="display: flex; justify-content: space-between; align-items: start;">
                                        <div style="flex: 1;">
                                            <h4 style="margin: 0 0 10px 0; color: #333; display: flex; align-items: center; flex-wrap: wrap;">
                                                Cerere către ${cerere.proprietar_nume}
                                                ${rankingBadge}
                                                ${!cerere.raspuns_citit && cerere.status !== 'in_asteptare' ? '<span style="background: #4CAF50; color: white; padding: 2px 8px; border-radius: 10px; font-size: 11px; margin-left: 10px;">RĂSPUNS NOU</span>' : ''}
                                            </h4>
                                            <p style="margin: 5px 0; color: #666;">
                                                <strong>Obiect:</strong> ${cerere.denumire_obiect}<br>
                                                <strong>Din:</strong> ${cerere.nume_colectie}<br>
                                                <strong>Cutie:</strong> ${cerere.cutie} | <strong>Locație:</strong> ${cerere.locatie}<br>
                                                <strong>Perioada:</strong> ${cerere.data_inceput} - ${cerere.data_sfarsit}
                                            </p>
                                            ${cerere.mesaj ? `<p style="margin: 10px 0; padding: 10px; background: #fff; border-radius: 5px; font-style: italic; color: #555;">"${cerere.mesaj}"</p>` : ''}
                                        </div>
                                        <div style="text-align: right;">
                                            <span style="display: inline-block; padding: 5px 10px; 
                                                       background: ${statusColor}; color: white; 
                                                       border-radius: 15px; font-size: 12px;">
                                                ${cerere.status === 'in_asteptare' ? 'În așteptare' : 
                                                  cerere.status === 'aprobat' ? 'Aprobat' : 
                                                  cerere.status === 'refuzat' ? 'Refuzat' : 
                                                  cerere.status === 'imprumutat' ? 'Împrumutat' :
                                                  cerere.status === 'returnat' ? 'Returnat' : 
                                                  cerere.status === 'anulat' ? 'Anulat' : 'Expirat'}
                                            </span>
                                            ${cerere.status === 'aprobat' || cerere.status === 'imprumutat' ? `
                                                <div style="margin-top: 10px; position: relative; display: inline-block;">
                                                    <button onclick="deschideChat(${cerere.id_cerere}, '${cerere.proprietar_nume}')" 
                                                            style="padding: 8px 15px; background: #4CAF50; 
                                                                   color: white; border: none; border-radius: 5px; 
                                                                   cursor: pointer; font-weight: 600;">
                                                        💬 Discută
                                                        <span id="badgeChat${cerere.id_cerere}" style="display: none; 
                                                                position: absolute; top: -8px; right: -8px; 
                                                                background: #ff0000; color: white; border-radius: 50%; 
                                                                width: 20px; height: 20px; line-height: 20px; 
                                                                font-size: 11px; text-align: center;">0</span>
                                                    </button>
                                                </div>
                                            ` : ''}
                                        </div>
                                    </div>
                                    <div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #e0e0e0; 
                                               font-size: 12px; color: #999;">
                                        Cerere trimisă: ${cerere.data_cerere}
                                        ${cerere.data_raspuns ? `<br>Răspuns primit: ${cerere.data_raspuns}` : ''}
                                        ${cerere.data_predare ? `<br>Împrumutat la: ${cerere.data_predare}` : ''}
                                        ${cerere.data_returnare_efectiva ? `<br>Returnat la: ${cerere.data_returnare_efectiva}` : ''}
                                    </div>
                                </div>
                            `;
                        });
                        
                        html += '</div>';
                        container.innerHTML = html;
                        
                        // Actualizează badge-urile pentru mesaje necitite în chat
                        actualizareBadgeuriChat();
                    } else {
                        container.innerHTML = `
                            <div class="empty-state">
                                <div class="icon">📬</div>
                                <p>Nu ai trimis cereri de împrumut</p>
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Eroare:', error);
                    container.innerHTML = '<div class="empty-state"><div class="icon">❌</div><p>Eroare la încărcarea cererilor</p></div>';
                });
        }
        
        // Funcție pentru highlight și scroll la cerere specifică
        function highlightCerere(idCerere) {
            // Caută toate elementele care ar putea conține cererea
            const cereri = document.querySelectorAll('[data-cerere-id="' + idCerere + '"]');
            
            if (cereri.length === 0) {
                // Dacă nu găsim cu data attribute, căutăm în conținutul generat dinamic
                // Reîncarcă cererile pentru a fi siguri că avem datele
                const subtabActiv = document.querySelector('.btn-subtab.active');
                if (subtabActiv && subtabActiv.textContent.includes('Trimise')) {
                    incarcaCereriTrimise();
                } else {
                    incarcaCereriPrimite();
                }
                
                // Încearcă din nou după încărcare
                setTimeout(() => {
                    const toateCererile = document.querySelectorAll('#cereriPrimite > div > div, #cereriTrimise > div > div');
                    toateCererile.forEach(cerere => {
                        // Verifică dacă cererea conține ID-ul căutat
                        if (cerere.innerHTML.includes('raspundeCerere(' + idCerere) || 
                            cerere.innerHTML.includes('deschideChat(' + idCerere)) {
                            // Scroll la element
                            cerere.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            
                            // Adaugă highlight temporar
                            cerere.style.transition = 'all 0.3s ease';
                            cerere.style.boxShadow = '0 0 20px rgba(255, 102, 0, 0.6)';
                            cerere.style.transform = 'scale(1.02)';
                            
                            // Elimină highlight după 3 secunde
                            setTimeout(() => {
                                cerere.style.boxShadow = '';
                                cerere.style.transform = '';
                            }, 3000);
                        }
                    });
                }, 500);
            } else {
                // Dacă găsim cu data attribute
                cereri.forEach(cerere => {
                    cerere.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    cerere.style.transition = 'all 0.3s ease';
                    cerere.style.boxShadow = '0 0 20px rgba(255, 102, 0, 0.6)';
                    cerere.style.transform = 'scale(1.02)';
                    
                    setTimeout(() => {
                        cerere.style.boxShadow = '';
                        cerere.style.transform = '';
                    }, 3000);
                });
            }
        }
        
        // Răspunde la o cerere de împrumut
        function raspundeCerere(idCerere, raspuns) {
            confirmaActiune(
                'Confirmare răspuns',
                `Sigur vrei să ${raspuns === 'aprobat' ? 'aprobi' : 'refuzi'} această cerere?`,
                () => {
                    fetch('ajax_imprumut.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            actiune: 'raspunde_cerere',
                            id_cerere: idCerere,
                            raspuns: raspuns
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Actualizează ranking-ul dacă e necesar
                            if (data.trigger_ranking_update) {
                                fetch('ajax_ranking.php', {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                    body: `actiune=actualizeaza_dupa_cerere&id_proprietar=${data.id_proprietar}&id_solicitant=${data.id_solicitant}`
                                });
                            }
                            
                            afiseazaModal('Succes', 'Răspunsul a fost înregistrat!', 'success', () => {
                                incarcaCereriPrimite();
                            });
                        } else {
                            afiseazaModal('Eroare', data.error || 'Nu s-a putut înregistra răspunsul', 'error');
                        }
                    })
                    .catch(error => {
                        console.error('Eroare:', error);
                        afiseazaModal('Eroare', 'A apărut o eroare la trimiterea răspunsului', 'error');
                    });
                }
            );
        }
        
        // Verifică notificările la încărcarea paginii
        document.addEventListener('DOMContentLoaded', function() {
            verificaNotificariImprumuturi();
            // Verifică mesaje necitite pentru chat la fiecare 10 secunde
            setInterval(verificaMesajeNecititeToate, 10000);
            
            // Verifică parametrii URL pentru navigare automată
            const urlParams = new URLSearchParams(window.location.search);
            const tab = urlParams.get('tab');
            const subtab = urlParams.get('subtab');
            const highlight = urlParams.get('highlight');
            
            if (tab === 'imprumuturi') {
                // Activează tab-ul împrumuturi
                schimbaTab('imprumuturi');
                
                // Așteaptă puțin pentru încărcarea conținutului
                setTimeout(() => {
                    if (subtab === 'trimise') {
                        schimbaSubtab('trimise');
                    } else if (subtab === 'primite') {
                        schimbaSubtab('primite');
                    }
                    
                    // Highlight și scroll la cererea specifică
                    if (highlight) {
                        setTimeout(() => {
                            highlightCerere(highlight);
                        }, 500);
                    }
                }, 300);
            }
            
            // Actualizează automat cererile fără reîncărcarea paginii la fiecare 15 secunde
            setInterval(function() {
                // Actualizează automat cererile dacă tab-ul de împrumuturi este activ
                const tabActiv = document.querySelector('.tab-partajare.active');
                if (tabActiv && tabActiv.textContent.includes('Împrumuturi')) {
                    const subtabActiv = document.querySelector('.subtab-imprumuturi.active');
                    if (subtabActiv) {
                        if (subtabActiv.textContent.includes('Cereri Primite')) {
                            // Reîncarcă cereri primite fără refresh
                            incarcaCereriPrimite();
                        } else if (subtabActiv.textContent.includes('Cereri Trimise')) {
                            // Reîncarcă cereri trimise fără refresh
                            incarcaCereriTrimise();
                        }
                    }
                }
                // Actualizează și notificările
                verificaNotificariImprumuturi();
            }, 15000);
        });
        
        // Funcții pentru chat
        let chatInterval = null;
        let idCerereChat = null;
        
        function deschideChat(idCerere, numeInterlocutor) {
            idCerereChat = idCerere;
            
            // Obține detaliile cererii pentru a le afișa în header
            fetch(`ajax_chat.php?actiune=obtine_detalii_cerere&id_cerere=${idCerere}`)
                .then(response => response.json())
                .then(data => {
                    if (!data.success) return;
                    
                    const cerere = data.cerere;
                    
                    // Creează modalul de chat dacă nu există
                    if (!document.getElementById('modalChat')) {
                        const modal = document.createElement('div');
                        modal.id = 'modalChat';
                        modal.innerHTML = `
                            <div style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; 
                                        background: rgba(0,0,0,0.6); z-index: 10000; display: flex; 
                                        justify-content: center; align-items: center;">
                                <div style="background: white; width: 90%; max-width: 700px; height: 85vh; 
                                            border-radius: 10px; display: flex; flex-direction: column; 
                                            box-shadow: 0 20px 60px rgba(0,0,0,0.3);">
                                    <!-- Header -->
                                    <div style="padding: 20px; border-bottom: 2px solid #e0e0e0; 
                                                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
                                                border-radius: 10px 10px 0 0;">
                                        <div style="display: flex; justify-content: space-between; align-items: start;">
                                            <div style="flex: 1;">
                                                <h3 style="color: white; margin: 0 0 10px 0;">
                                                    💬 Discuție cu <span id="numeInterlocutor">${numeInterlocutor}</span>
                                                </h3>
                                                <div class="header-info" style="background: rgba(255,255,255,0.2); padding: 10px; 
                                                           border-radius: 5px; font-size: 13px; color: white;">
                                                    <div style="margin-bottom: 5px;">
                                                        <strong>📦 Obiect:</strong> ${cerere.denumire_obiect}
                                                    </div>
                                                    <div style="display: flex; gap: 15px; flex-wrap: wrap;">
                                                        <span><strong>📍</strong> ${cerere.cutie} - ${cerere.locatie}</span>
                                                        <span><strong>📅</strong> ${cerere.data_inceput} → ${cerere.data_sfarsit}</span>
                                                    </div>
                                                </div>
                                            </div>
                                            <button onclick="inchideChat()" 
                                                    style="background: none; border: none; color: white; 
                                                           font-size: 24px; cursor: pointer; padding: 0; 
                                                           margin-left: 15px;">×</button>
                                        </div>
                                    </div>
                            
                            <!-- Zona mesaje -->
                            <div id="zonaMesaje" style="flex: 1; overflow-y: auto; padding: 20px; 
                                                        background: #f5f5f5;">
                                <div style="text-align: center; color: #999;">
                                    <div class="empty-state">
                                        <div class="icon">⏳</div>
                                        <p>Se încarcă conversația...</p>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Zona input -->
                            <div id="zonaInput" style="padding: 20px; border-top: 2px solid #e0e0e0; 
                                                       background: white; border-radius: 0 0 10px 10px;">
                                <div style="display: flex; gap: 10px;">
                                    <input type="text" id="inputMesaj" 
                                           placeholder="Scrie un mesaj..." 
                                           style="flex: 1; padding: 10px; border: 2px solid #ddd; 
                                                  border-radius: 25px; font-size: 14px;"
                                           onkeypress="if(event.key === 'Enter') trimitereMesaj()">
                                    <button onclick="trimitereMesaj()" 
                                            style="padding: 10px 20px; background: #4CAF50; color: white; 
                                                   border: none; border-radius: 25px; cursor: pointer; 
                                                   font-weight: 600;">
                                        Trimite
                                    </button>
                                </div>
                                
                                <!-- Butoane contextuale pentru predare/returnare -->
                                <div id="butoaneContextuale" style="margin-top: 15px; display: none;">
                                    <!-- Vor fi adăugate dinamic în funcție de status și rol -->
                                </div>
                                </div>
                            </div>
                        </div>
                    `;
                    document.body.appendChild(modal);
                } else {
                    // Actualizează modalul existent cu noile detalii
                    document.getElementById('modalChat').style.display = 'flex';
                    document.getElementById('numeInterlocutor').textContent = numeInterlocutor;
                    
                    // Actualizează și detaliile obiectului în header
                    const headerInfo = document.querySelector('#modalChat .header-info');
                    if (headerInfo) {
                        headerInfo.innerHTML = `
                            <div style="margin-bottom: 5px;">
                                <strong>📦 Obiect:</strong> ${cerere.denumire_obiect}
                            </div>
                            <div style="display: flex; gap: 15px;">
                                <span><strong>📍</strong> ${cerere.cutie} - ${cerere.locatie}</span>
                                <span><strong>📅</strong> ${cerere.data_inceput} → ${cerere.data_sfarsit}</span>
                            </div>
                        `;
                    }
                }
                
                // Încarcă mesajele
                incarcaMesaje();
                
                // Pornește actualizarea automată
                chatInterval = setInterval(incarcaMesaje, 3000);
            })
            .catch(error => {
                console.error('Eroare la obținerea detaliilor cererii:', error);
                afiseazaModal('Eroare', 'Nu s-au putut obține detaliile cererii', 'error');
            });
        }
        
        function inchideChat() {
            document.getElementById('modalChat').style.display = 'none';
            if (chatInterval) {
                clearInterval(chatInterval);
                chatInterval = null;
            }
            idCerereChat = null;
        }
        
        function incarcaMesaje() {
            if (!idCerereChat) return;
            
            fetch(`ajax_chat.php?actiune=obtine_mesaje&id_cerere=${idCerereChat}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        afiseazaMesaje(data.mesaje, data.cerere);
                        actualizeazaButoaneContextuale(data.cerere);
                    }
                })
                .catch(error => console.error('Eroare încărcare mesaje:', error));
        }
        
        function afiseazaMesaje(mesaje, cerere) {
            const zonaMesaje = document.getElementById('zonaMesaje');
            const scrollLaFinal = zonaMesaje.scrollHeight - zonaMesaje.scrollTop <= zonaMesaje.clientHeight + 100;
            
            if (mesaje.length === 0) {
                zonaMesaje.innerHTML = `
                    <div style="text-align: center; color: #999; padding: 50px;">
                        <p>Începe conversația!</p>
                        ${cerere.mesaj ? `
                            <div style="margin-top: 20px; padding: 15px; background: white; 
                                       border-radius: 10px; text-align: left;">
                                <p style="color: #666; font-size: 12px; margin-bottom: 5px;">
                                    Mesajul tău inițial:
                                </p>
                                <p style="color: #333; font-style: italic;">
                                    "${cerere.mesaj}"
                                </p>
                            </div>
                        ` : ''}
                    </div>
                `;
            } else {
                let html = '';
                mesaje.forEach(mesaj => {
                    const esteMesajulMeu = mesaj.id_expeditor == <?php echo $user['id_utilizator']; ?>;
                    html += `
                        <div style="display: flex; ${esteMesajulMeu ? 'justify-content: flex-end' : 'justify-content: flex-start'}; 
                                    margin-bottom: 15px;">
                            <div style="max-width: 70%; padding: 10px 15px; border-radius: 18px; 
                                       background: ${esteMesajulMeu ? '#4CAF50' : 'white'}; 
                                       color: ${esteMesajulMeu ? 'white' : '#333'}; 
                                       box-shadow: 0 2px 5px rgba(0,0,0,0.1);">
                                <p style="margin: 0;">${mesaj.mesaj}</p>
                                <p style="margin: 5px 0 0 0; font-size: 11px; 
                                         color: ${esteMesajulMeu ? 'rgba(255,255,255,0.8)' : '#999'};">
                                    ${new Date(mesaj.data_trimitere).toLocaleString('ro-RO')}
                                </p>
                            </div>
                        </div>
                    `;
                });
                zonaMesaje.innerHTML = html;
            }
            
            if (scrollLaFinal) {
                zonaMesaje.scrollTop = zonaMesaje.scrollHeight;
            }
        }
        
        function trimitereMesaj() {
            const input = document.getElementById('inputMesaj');
            const mesaj = input.value.trim();
            
            if (!mesaj || !idCerereChat) return;
            
            fetch('ajax_chat.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    actiune: 'trimite_mesaj',
                    id_cerere: idCerereChat,
                    mesaj: mesaj
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    input.value = '';
                    incarcaMesaje();
                } else {
                    afiseazaModal('Eroare', data.error || 'Nu s-a putut trimite mesajul', 'error');
                }
            })
            .catch(error => {
                console.error('Eroare trimitere mesaj:', error);
                afiseazaModal('Eroare', 'Eroare la trimiterea mesajului', 'error');
            });
        }
        
        function actualizeazaButoaneContextuale(cerere) {
            const container = document.getElementById('butoaneContextuale');
            const idUtilizator = <?php echo $user['id_utilizator']; ?>;
            
            // Afișează butoane doar pentru statusuri relevante
            if (cerere.status === 'aprobat') {
                if (cerere.id_proprietar == idUtilizator) {
                    // Proprietar - poate genera QR pentru predare
                    container.innerHTML = `
                        <button onclick="genereazaQRPredare(${cerere.id_cerere})" 
                                style="width: 100%; padding: 12px; background: #ff6600; color: white; 
                                       border: none; border-radius: 25px; cursor: pointer; font-weight: 600;">
                            📦 Generează QR pentru predare
                        </button>
                    `;
                    container.style.display = 'block';
                } else {
                    // Solicitant - poate scana QR pentru preluare
                    container.innerHTML = `
                        <button onclick="deschideScanerQR('predare')" 
                                style="width: 100%; padding: 12px; background: #2196F3; color: white; 
                                       border: none; border-radius: 25px; cursor: pointer; font-weight: 600;">
                            📷 Scanează QR pentru preluare
                        </button>
                    `;
                    container.style.display = 'block';
                }
            } else if (cerere.status === 'imprumutat') {
                if (cerere.id_solicitant == idUtilizator) {
                    // Solicitant - poate genera QR pentru returnare
                    container.innerHTML = `
                        <button onclick="genereazaQRReturnare(${cerere.id_cerere})" 
                                style="width: 100%; padding: 12px; background: #9C27B0; color: white; 
                                       border: none; border-radius: 25px; cursor: pointer; font-weight: 600;">
                            🔄 Generează QR pentru returnare
                        </button>
                    `;
                    container.style.display = 'block';
                } else {
                    // Proprietar - poate scana QR pentru confirmare returnare
                    container.innerHTML = `
                        <button onclick="deschideScanerQR('returnare')" 
                                style="width: 100%; padding: 12px; background: #4CAF50; color: white; 
                                       border: none; border-radius: 25px; cursor: pointer; font-weight: 600;">
                            📷 Confirmă returnarea (scanează QR)
                        </button>
                    `;
                    container.style.display = 'block';
                }
            } else {
                container.style.display = 'none';
            }
        }
        
        // Funcții pentru QR Code
        let qrCodeInstance = null;
        let html5QrcodeScanner = null;
        
        function genereazaQRPredare(idCerere) {
            // Obține detaliile cererii pentru QR
            fetch(`ajax_imprumut.php?actiune=obtine_detalii_pentru_qr&id_cerere=${idCerere}&tip=predare`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Salvează QR în baza de date
                        const qrDataString = JSON.stringify(data.qr_data);
                        fetch('ajax_imprumut.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({
                                actiune: 'salveaza_qr',
                                id_cerere: idCerere,
                                tip: 'predare',
                                qr_data: qrDataString
                            })
                        })
                        .then(response => response.json())
                        .then(saveResult => {
                            if (saveResult.success) {
                                // Trimite datele compacte pentru QR și datele de afișare
                                afiseazaModalQR(data.qr_data, 'predare', data.display_data);
                            } else {
                                afiseazaModal('Eroare', saveResult.error || 'Nu s-a putut salva QR', 'error');
                            }
                        });
                    } else {
                        afiseazaModal('Eroare', data.error || 'Nu s-au putut obține detaliile pentru QR', 'error');
                    }
                })
                .catch(error => {
                    console.error('Eroare:', error);
                    afiseazaModal('Eroare', 'Eroare la generarea QR', 'error');
                });
        }
        
        function genereazaQRReturnare(idCerere) {
            // Similar cu predarea, dar pentru returnare
            fetch(`ajax_imprumut.php?actiune=obtine_detalii_pentru_qr&id_cerere=${idCerere}&tip=returnare`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Salvează QR în baza de date
                        const qrDataString = JSON.stringify(data.qr_data);
                        fetch('ajax_imprumut.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({
                                actiune: 'salveaza_qr',
                                id_cerere: idCerere,
                                tip: 'returnare',
                                qr_data: qrDataString
                            })
                        })
                        .then(response => response.json())
                        .then(saveResult => {
                            if (saveResult.success) {
                                // Trimite datele compacte pentru QR și datele de afișare
                                afiseazaModalQR(data.qr_data, 'returnare', data.display_data);
                            } else {
                                afiseazaModal('Eroare', saveResult.error || 'Nu s-a putut salva QR', 'error');
                            }
                        });
                    } else {
                        afiseazaModal('Eroare', data.error || 'Nu s-au putut obține detaliile pentru QR', 'error');
                    }
                })
                .catch(error => {
                    console.error('Eroare:', error);
                    afiseazaModal('Eroare', 'Eroare la generarea QR', 'error');
                });
        }
        
        function afiseazaModalQR(qrData, tip, displayData = null) {
            // Creează modalul pentru afișare QR
            const modal = document.createElement('div');
            modal.id = 'modalQR';
            modal.style.cssText = `
                position: fixed; top: 0; left: 0; width: 100%; height: 100%;
                background: rgba(0, 0, 0, 0.6); z-index: 10001;
                display: flex; justify-content: center; align-items: center;
                animation: fadeIn 0.3s ease;
            `;
            
            const titlu = tip === 'predare' ? '📦 Predare Obiect' : '🔄 Returnare Obiect';
            const culoare = tip === 'predare' ? '#ff6600' : '#9C27B0';
            
            modal.innerHTML = `
                <div style="background: white; border-radius: 10px; padding: 30px; 
                            max-width: 500px; box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
                            animation: slideUp 0.4s ease;">
                    <div style="text-align: center;">
                        <h2 style="color: #333; margin-bottom: 20px;">
                            ${titlu}
                        </h2>
                        
                        <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
                                    padding: 20px; border-radius: 10px; margin-bottom: 20px;">
                            <div id="qrCodeContainer" style="background: white; padding: 20px; 
                                                            border-radius: 5px; display: inline-block;">
                                <!-- QR Code va fi generat aici -->
                            </div>
                        </div>
                        
                        <div style="background: #f5f5f5; padding: 15px; border-radius: 5px; 
                                    margin-bottom: 20px; text-align: left;">
                            <p style="color: #666; font-size: 14px; margin-bottom: 10px;">
                                <strong>📦 Obiect:</strong> ${displayData ? displayData.obiect : 'Loading...'}
                            </p>
                            <p style="color: #666; font-size: 14px; margin-bottom: 10px;">
                                <strong>📍 Locație:</strong> ${displayData ? displayData.cutie + ' - ' + displayData.locatie : 'Loading...'}
                            </p>
                            <p style="color: #666; font-size: 14px;">
                                <strong>⏰ Moment:</strong> ${new Date().toLocaleString('ro-RO')}
                            </p>
                        </div>
                        
                        <div style="background: #fff3e0; border-left: 4px solid #ff9800; 
                                    padding: 15px; border-radius: 4px; margin-bottom: 20px;">
                            <p style="color: #e65100; font-weight: 600; margin-bottom: 5px;">
                                ℹ️ Instrucțiuni:
                            </p>
                            <p style="color: #666; font-size: 14px; line-height: 1.5;">
                                ${tip === 'predare' ? 
                                    'Arătați acest cod QR persoanei care împrumută obiectul. Aceasta trebuie să-l scaneze pentru a confirma preluarea.' :
                                    'Arătați acest cod QR proprietarului. Acesta trebuie să-l scaneze pentru a confirma returnarea.'}
                            </p>
                        </div>
                        
                        <button onclick="inchideModalQR()" 
                                style="padding: 10px 30px; background: ${culoare}; 
                                       color: white; border: none; border-radius: 25px; 
                                       cursor: pointer; font-weight: 600; font-size: 16px;">
                            Închide
                        </button>
                    </div>
                </div>
            `;
            
            document.body.appendChild(modal);
            
            // Generează QR Code
            setTimeout(() => {
                try {
                    // Verifică lungimea datelor înainte de a genera QR
                    const qrDataString = JSON.stringify(qrData);
                    const qrDataLength = qrDataString.length;
                    
                    // Limita pentru QRCode cu correctLevel H este aproximativ 2500 caractere
                    if (qrDataLength > 2500) {
                        // Afișează mesaj de eroare prietenos
                        document.getElementById('qrCodeContainer').innerHTML = `
                            <div style="padding: 20px; text-align: center; color: #d32f2f;">
                                <p style="font-size: 18px; font-weight: bold; margin-bottom: 10px;">
                                    ⚠️ Cod QR prea mare
                                </p>
                                <p style="font-size: 14px; color: #666; line-height: 1.5;">
                                    Datele pentru acest împrumut sunt prea complexe pentru a genera un cod QR 
                                    (${qrDataLength} caractere, limita: 2500).
                                </p>
                                <p style="font-size: 13px; color: #999; margin-top: 15px;">
                                    Sugestie: Folosiți denumiri mai scurte pentru obiecte sau locații.
                                </p>
                            </div>
                        `;
                        console.error(`QR Data overflow: ${qrDataLength} > 2500 caractere`);
                        return;
                    }
                    
                    qrCodeInstance = new QRCode(document.getElementById('qrCodeContainer'), {
                        text: qrDataString,
                        width: 256,
                        height: 256,
                        colorDark: '#000000',
                        colorLight: '#ffffff',
                        correctLevel: QRCode.CorrectLevel.H
                    });
                    
                    // QR-ul a fost deja salvat în baza de date
                } catch (error) {
                    // Eroare la generarea QR - afișează mesaj prietenos
                    console.error('Eroare generare QR:', error);
                    document.getElementById('qrCodeContainer').innerHTML = `
                        <div style="padding: 20px; text-align: center; color: #d32f2f;">
                            <p style="font-size: 18px; font-weight: bold;">
                                ❌ Eroare la generarea codului QR
                            </p>
                            <p style="font-size: 14px; color: #666; margin-top: 10px;">
                                ${error.message || 'Nu s-a putut genera codul QR.'}
                            </p>
                        </div>
                    `;
                }
            }, 100);
        }
        
        function inchideModalQR() {
            const modal = document.getElementById('modalQR');
            if (modal) {
                modal.remove();
                qrCodeInstance = null;
            }
        }
        
        function salveazaQRInBD(qrData) {
            fetch('ajax_imprumut.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    actiune: 'salveaza_qr',
                    id_cerere: qrData.id_cerere,
                    tip: qrData.tip,
                    qr_data: qrData
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    console.log('QR salvat în BD');
                }
            })
            .catch(error => console.error('Eroare salvare QR:', error));
        }
        
        function deschideScanerQR(tip) {
            // Creează modalul pentru scanner
            const modal = document.createElement('div');
            modal.id = 'modalScanner';
            modal.style.cssText = `
                position: fixed; top: 0; left: 0; width: 100%; height: 100%;
                background: rgba(0, 0, 0, 0.9); z-index: 10001;
                display: flex; justify-content: center; align-items: center;
            `;
            
            modal.innerHTML = `
                <div style="background: white; border-radius: 10px; padding: 20px; 
                            max-width: 500px; width: 90%; max-height: 90vh; overflow-y: auto;">
                    <div style="display: flex; justify-content: space-between; align-items: center; 
                                margin-bottom: 20px;">
                        <h2 style="color: #333; margin: 0;">
                            📷 Scanează codul QR
                        </h2>
                        <button onclick="inchideScanner()" 
                                style="background: none; border: none; font-size: 24px; 
                                       cursor: pointer; color: #666;">×</button>
                    </div>
                    
                    <div id="qr-reader" style="width: 100%;"></div>
                    
                    <div style="background: #e3f2fd; padding: 15px; border-radius: 5px; 
                                margin-top: 20px;">
                        <p style="color: #1976d2; font-size: 14px; text-align: center;">
                            Poziționează codul QR în centrul camerei
                        </p>
                    </div>
                </div>
            `;
            
            document.body.appendChild(modal);
            
            // Inițializează scanner-ul
            html5QrcodeScanner = new Html5Qrcode("qr-reader");
            
            const config = { 
                fps: 10, 
                qrbox: { width: 250, height: 250 },
                aspectRatio: 1.0
            };
            
            html5QrcodeScanner.start(
                { facingMode: "environment" },
                config,
                (decodedText, decodedResult) => {
                    // QR scanat cu succes
                    console.log('QR Scanat:', decodedText);
                    proceseazaQRScanat(decodedText, tip);
                    inchideScanner();
                },
                (errorMessage) => {
                    // Eroare scan (ignorăm, continuă să scaneze)
                }
            ).catch((err) => {
                console.error('Eroare la pornirea camerei:', err);
                afiseazaModal('Eroare', 'Nu s-a putut accesa camera. Verificați permisiunile.', 'error');
                inchideScanner();
            });
        }
        
        function inchideScanner() {
            if (html5QrcodeScanner) {
                html5QrcodeScanner.stop().then(() => {
                    html5QrcodeScanner = null;
                    const modal = document.getElementById('modalScanner');
                    if (modal) modal.remove();
                }).catch((err) => {
                    console.error('Eroare la oprirea scanner-ului:', err);
                });
            } else {
                const modal = document.getElementById('modalScanner');
                if (modal) modal.remove();
            }
        }
        
        function proceseazaQRScanat(qrText, tipAsteptat) {
            try {
                const qrData = JSON.parse(qrText);
                
                // Convertește formatul compact în formatul așteptat
                // Noul format: {t: 'p'/'r', id: 123, ts: timestamp, h: hash}
                // 't' poate fi 'p' pentru predare sau 'r' pentru returnare
                const tipQR = qrData.t === 'p' ? 'predare' : (qrData.t === 'r' ? 'returnare' : qrData.tip);
                
                // Validare tip QR
                if (tipQR !== tipAsteptat) {
                    afiseazaModal('Eroare', `Cod QR invalid! Așteptam cod pentru ${tipAsteptat}.`, 'error');
                    return;
                }
                
                // Trimite la server pentru validare și procesare
                fetch('ajax_imprumut.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        actiune: 'valideaza_qr',
                        qr_data: qrText  // Trimite string-ul complet pentru validare hash
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        afiseazaModalConfirmare(qrData, data);
                    } else {
                        afiseazaModal('Eroare', data.error || 'QR invalid', 'error');
                    }
                })
                .catch(error => {
                    console.error('Eroare validare QR:', error);
                    afiseazaModal('Eroare', 'Eroare la validarea codului QR', 'error');
                });
                
            } catch (e) {
                afiseazaModal('Eroare', 'Cod QR invalid sau deteriorat', 'error');
            }
        }
        
        function afiseazaModalConfirmare(qrData, validationData) {
            // Folosim datele complete din validationData.detalii care vin din BD
            const detalii = validationData.detalii || {};
            const tip = detalii.tip || validationData.tip;
            const titlu = tip === 'predare' ? '✅ Confirmare Preluare' : '✅ Confirmare Returnare';
            const mesaj = tip === 'predare' ? 
                `Confirmați preluarea obiectului "${detalii.obiect}" de la ${validationData.nume_proprietar}?` :
                `Confirmați returnarea obiectului "${detalii.obiect}" către ${validationData.nume_proprietar}?`;
                
            confirmaActiune(titlu, mesaj, () => {
                // Confirmă tranzacția folosind datele din BD
                fetch('ajax_imprumut.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        actiune: 'confirma_transfer',
                        id_cerere: detalii.id_cerere,
                        tip: tip,
                        qr_data: qrData  // Trimitem QR-ul original pentru verificare
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Actualizează ranking-ul dacă e necesar
                        if (data.trigger_ranking_update) {
                            // Actualizează ranking pentru proprietar
                            fetch('ajax_ranking.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                body: `actiune=actualizeaza_dupa_cerere&id_proprietar=${data.id_proprietar}&id_solicitant=${data.id_solicitant}`
                            });
                        }
                        
                        const mesajSucces = tip === 'predare' ? 
                            '🎉 Obiectul a fost preluat cu succes!' :
                            '🎉 Obiectul a fost returnat cu succes!';
                        
                        afiseazaModal('Succes', mesajSucces, 'success', () => {
                            // Reîncarcă cererile pentru a vedea noul status
                            incarcaCereriImprumuturi();
                            // Închide chat-ul dacă e deschis
                            inchideChat();
                            
                            // Pentru returnare, afișează modalul de feedback după 1 secundă
                            if (data.show_feedback && data.id_cerere) {
                                setTimeout(() => {
                                    if (typeof verificaAfisareFeedback === 'function') {
                                        verificaAfisareFeedback(data.id_cerere);
                                    }
                                }, 1000);
                            }
                        });
                    } else {
                        afiseazaModal('Eroare', data.error || 'Nu s-a putut confirma transferul', 'error');
                    }
                })
                .catch(error => {
                    console.error('Eroare confirmare transfer:', error);
                    afiseazaModal('Eroare', 'Eroare la confirmarea transferului', 'error');
                });
            });
        }
        
        // Funcție pentru verificarea mesajelor necitite pentru toate cererile
        function verificaMesajeNecititeToate() {
            let totalMesajeNecitite = 0;
            let promisiuni = [];
            
            // Verifică în cererile primite
            promisiuni.push(
                fetch('ajax_imprumut.php?actiune=obtine_cereri_primite')
                    .then(response => response.json())
                    .then(data => {
                        if (data.success && data.cereri) {
                            let subPromisiuni = [];
                            data.cereri.forEach(cerere => {
                                if (cerere.status === 'aprobat' || cerere.status === 'imprumutat') {
                                    subPromisiuni.push(
                                        fetch(`ajax_chat.php?actiune=numar_mesaje_necitite&id_cerere=${cerere.id_cerere}`)
                                            .then(response => response.json())
                                            .then(msgData => {
                                                if (msgData.success && msgData.numar > 0) {
                                                    totalMesajeNecitite += msgData.numar;
                                                    // Actualizează badge-ul specific dacă există
                                                    const badge = document.getElementById(`badgeChat${cerere.id_cerere}`);
                                                    if (badge) {
                                                        badge.textContent = msgData.numar;
                                                        badge.style.display = 'block';
                                                    }
                                                } else {
                                                    const badge = document.getElementById(`badgeChat${cerere.id_cerere}`);
                                                    if (badge) {
                                                        badge.style.display = 'none';
                                                    }
                                                }
                                            })
                                    );
                                }
                            });
                            return Promise.all(subPromisiuni);
                        }
                    })
                    .catch(error => console.error('Eroare verificare cereri primite:', error))
            );
            
            // Verifică în cererile trimise
            promisiuni.push(
                fetch('ajax_imprumut.php?actiune=obtine_cereri_trimise')
                    .then(response => response.json())
                    .then(data => {
                        if (data.success && data.cereri) {
                            let subPromisiuni = [];
                            data.cereri.forEach(cerere => {
                                if (cerere.status === 'aprobat' || cerere.status === 'imprumutat') {
                                    subPromisiuni.push(
                                        fetch(`ajax_chat.php?actiune=numar_mesaje_necitite&id_cerere=${cerere.id_cerere}`)
                                            .then(response => response.json())
                                            .then(msgData => {
                                                if (msgData.success && msgData.numar > 0) {
                                                    totalMesajeNecitite += msgData.numar;
                                                    // Actualizează badge-ul specific dacă există
                                                    const badge = document.getElementById(`badgeChat${cerere.id_cerere}`);
                                                    if (badge) {
                                                        badge.textContent = msgData.numar;
                                                        badge.style.display = 'block';
                                                    }
                                                } else {
                                                    const badge = document.getElementById(`badgeChat${cerere.id_cerere}`);
                                                    if (badge) {
                                                        badge.style.display = 'none';
                                                    }
                                                }
                                            })
                                    );
                                }
                            });
                            return Promise.all(subPromisiuni);
                        }
                    })
                    .catch(error => console.error('Eroare verificare cereri trimise:', error))
            );
            
            // După ce toate verificările sunt complete, actualizează notificarea globală
            Promise.all(promisiuni).then(() => {
                const notificareGlobala = document.getElementById('notificareChatGlobal');
                if (notificareGlobala) {
                    if (totalMesajeNecitite > 0) {
                        notificareGlobala.style.display = 'inline-block';
                    } else {
                        notificareGlobala.style.display = 'none';
                    }
                }
            });
        }
        
        // Verifică mesaje necitite pentru o cerere specifică
        function verificaMesajeNecititePentruCerere(idCerere) {
            fetch(`ajax_chat.php?actiune=numar_mesaje_necitite&id_cerere=${idCerere}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.numar > 0) {
                        const badge = document.getElementById(`badgeChat${idCerere}`);
                        if (badge) {
                            badge.textContent = data.numar;
                            badge.style.display = 'block';
                        }
                    } else {
                        const badge = document.getElementById(`badgeChat${idCerere}`);
                        if (badge) {
                            badge.style.display = 'none';
                        }
                    }
                })
                .catch(error => console.error('Eroare verificare mesaje:', error));
        }
        
        // Verifică mesajele necitite când se încarcă cererile
        function actualizareBadgeuriChat() {
            setTimeout(verificaMesajeNecititeToate, 500);
        }
    </script>
    
    <?php
    // Include modalul de feedback pentru ranking
    include 'modal_feedback_ranking.php';
    ?>
</body>
</html>