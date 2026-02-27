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

$errors = [];
$success = false;

// TODO: Procesare formular când vom avea tabelele create
?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Partajare Inventar - Inventar.live</title>
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
            max-width: 1200px;
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
        
        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 30px;
            background-color: rgba(255, 255, 255, 0.5);
            padding: 10px;
            border-radius: 5px;
        }
        
        .tab {
            padding: 12px 24px;
            background-color: #666;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .tab:hover {
            background-color: #555;
        }
        
        .tab.active {
            background-color: #ff6600;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            color: #333;
            font-weight: 600;
            margin-bottom: 8px;
        }
        
        .form-group input[type="email"],
        .form-group input[type="text"],
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #ccc;
            border-radius: 5px;
            font-size: 16px;
            transition: border-color 0.3s;
        }
        
        .form-group input:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #ff6600;
        }
        
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 2px solid #ccc;
            border-radius: 5px;
            font-size: 16px;
            background-color: white;
            cursor: pointer;
        }
        
        .radio-group {
            display: flex;
            gap: 20px;
            margin-top: 10px;
        }
        
        .radio-group label {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            font-weight: normal;
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
        }
        
        .btn-secondary {
            background-color: #666;
            color: white;
        }
        
        .btn-danger {
            background-color: #dc3545;
            color: white;
        }
        
        .partajari-list {
            margin-top: 20px;
        }
        
        .partajare-item {
            background-color: rgba(255, 255, 255, 0.7);
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .partajare-info {
            flex-grow: 1;
        }
        
        .partajare-email {
            font-weight: 600;
            color: #333;
        }
        
        .partajare-tip {
            font-size: 14px;
            color: #666;
            margin-top: 5px;
        }
        
        .partajare-actions {
            display: flex;
            gap: 10px;
        }
        
        .info-box {
            background-color: rgba(255, 102, 0, 0.1);
            border: 2px solid #ff6600;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .info-box h4 {
            color: #ff6600;
            margin-bottom: 10px;
        }
        
        .info-box ul {
            margin-left: 20px;
            color: #555;
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
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-box {
            background-color: rgba(255, 255, 255, 0.7);
            padding: 20px;
            border-radius: 5px;
            text-align: center;
        }
        
        .stat-number {
            font-size: 32px;
            font-weight: bold;
            color: #ff6600;
        }
        
        .stat-label {
            color: #666;
            margin-top: 5px;
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="index.php" class="back-link">
            <span>←</span> Înapoi la inventar
        </a>
        
        <div class="header">
            <h1>Partajare Inventar</h1>
            <div class="subtitle">Gestionează ce împarți cu ceilalți din colecția ta</div>
        </div>
        
        <!-- Statistici -->
        <div class="section">
            <div class="stats-grid">
                <div class="stat-box">
                    <div class="stat-number">0</div>
                    <div class="stat-label">Membri familie</div>
                </div>
                <div class="stat-box">
                    <div class="stat-number">0</div>
                    <div class="stat-label">Vizualizări publice</div>
                </div>
                <div class="stat-box">
                    <div class="stat-number">0</div>
                    <div class="stat-label">Invitații în așteptare</div>
                </div>
            </div>
        </div>
        
        <!-- Tab-uri -->
        <div class="section">
            <div class="tabs">
                <button class="tab active" onclick="switchTab('familie')">👨‍👩‍👧‍👦 Cu cine împart</button>
                <button class="tab" onclick="switchTab('obiecte')">📦 Ce obiecte împart</button>
                <button class="tab" onclick="switchTab('primite')">📥 Cutii primite de la alții</button>
            </div>
            
            <!-- Tab Familie -->
            <div id="tab-familie" class="tab-content active">
                <h2 class="section-title">Invită membri ai familiei</h2>
                
                <div class="info-box">
                    <h4>ℹ️ Despre partajarea cu familia:</h4>
                    <ul>
                        <li>Membrii familiei pot vedea și modifica inventarul tău</li>
                        <li>Pot adăuga obiecte noi și șterge cele existente</li>
                        <li>Ideal pentru gestionarea comună a lucrurilor din casă</li>
                        <li>Poți revoca accesul oricând</li>
                    </ul>
                </div>
                
                <form method="POST" action="">
                    <div class="form-group">
                        <label for="email_membru">Email-ul membrului familiei:</label>
                        <input type="email" id="email_membru" name="email_membru" 
                               placeholder="exemplu@email.com" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Tip acces:</label>
                        <div class="radio-group">
                            <label>
                                <input type="radio" name="tip_acces" value="scriere" checked>
                                <span>✏️ Poate modifica (recomandat pentru familie)</span>
                            </label>
                            <label>
                                <input type="radio" name="tip_acces" value="citire">
                                <span>👁️ Doar vizualizare</span>
                            </label>
                        </div>
                    </div>
                    
                    <button type="submit" name="invita_membru" class="btn btn-primary">
                        Trimite invitație
                    </button>
                </form>
                
                <h3 style="margin-top: 40px; margin-bottom: 20px;">Membri actuali:</h3>
                <div class="partajari-list">
                    <!-- Exemplu de membru (va fi generat dinamic) -->
                    <div class="partajare-item">
                        <div class="partajare-info">
                            <div class="partajare-email">maria@example.com</div>
                            <div class="partajare-tip">✏️ Poate modifica • Membru din 15 iulie 2025</div>
                        </div>
                        <div class="partajare-actions">
                            <button class="btn btn-secondary">Schimbă permisiuni</button>
                            <button class="btn btn-danger">Revocă acces</button>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Tab Obiecte -->
            <div id="tab-obiecte" class="tab-content">
                <h2 class="section-title">Selectează ce obiecte pui în cutia "Împarte cu ceilalți"</h2>
                
                <div class="info-box">
                    <h4>ℹ️ Despre cutia virtuală "Împarte cu ceilalți":</h4>
                    <ul>
                        <li>Obiectele selectate vor apărea în cutia ta virtuală partajată</li>
                        <li>Ceilalți vor vedea: "Cutia: Împarte cu ceilalți a lui <?php echo htmlspecialchars($user['prenume']); ?>"</li>
                        <li>Obiectele rămân în cutiile lor reale, doar sunt marcate ca partajate</li>
                        <li>Poți adăuga sau scoate obiecte oricând</li>
                    </ul>
                </div>
                
                <!-- Nume pentru colecția ta -->
                <div class="form-group" style="background-color: rgba(255,255,255,0.7); padding: 20px; border-radius: 5px; margin-bottom: 30px;">
                    <label for="nume_colectie">Cum vrei să se numească colecția ta?</label>
                    <input type="text" id="nume_colectie" name="nume_colectie" 
                           value="<?php echo htmlspecialchars($user['nume_colectie'] ?? 'Inventarul meu'); ?>"
                           placeholder="ex: Inventarul familiei Popescu">
                    <button type="button" class="btn btn-secondary" style="margin-top: 10px;" 
                            onclick="salveazaNumeColectie()">Salvează numele</button>
                </div>
                
                <!-- Lista obiectelor cu checkbox -->
                <div style="margin-top: 30px;">
                    <h3>Obiectele tale:</h3>
                    <div style="margin: 20px 0;">
                        <button type="button" class="btn btn-secondary" onclick="selecteazaToate()">
                            Selectează toate
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="deselecteazaToate()">
                            Deselectează toate
                        </button>
                    </div>
                    
                    <!-- Aici vor fi listate obiectele cu checkbox-uri -->
                    <div class="partajari-list">
                        <!-- Exemplu (va fi generat dinamic din baza de date) -->
                        <div class="partajare-item">
                            <label style="display: flex; align-items: center; gap: 15px; cursor: pointer; width: 100%;">
                                <input type="checkbox" name="obiecte_partajate[]" value="1" style="width: 20px; height: 20px;">
                                <div class="partajare-info">
                                    <div class="partajare-email">Ciocan mare metal</div>
                                    <div class="partajare-tip">📦 Cutie: Dulap metalic - dreapta • 🏷️ Unelte</div>
                                </div>
                            </label>
                        </div>
                        
                        <div class="partajare-item">
                            <label style="display: flex; align-items: center; gap: 15px; cursor: pointer; width: 100%;">
                                <input type="checkbox" name="obiecte_partajate[]" value="2" style="width: 20px; height: 20px;">
                                <div class="partajare-info">
                                    <div class="partajare-email">Set burghie Bosch</div>
                                    <div class="partajare-tip">📦 Cutie: Sertar 1 • 🏷️ Unelte, Consumabile</div>
                                </div>
                            </label>
                        </div>
                    </div>
                    
                    <button type="submit" name="salveaza_obiecte_partajate" class="btn btn-primary" 
                            style="margin-top: 30px;">
                        Actualizează cutia "Împarte cu ceilalți"
                    </button>
                </div>
            </div>
            
            <!-- Tab Primite -->
            <div id="tab-primite" class="tab-content">
                <h2 class="section-title">Cutii virtuale la care am acces</h2>
                
                <div class="info-box">
                    <h4>ℹ️ Aici vezi cutiile virtuale partajate cu tine:</h4>
                    <ul>
                        <li>Cutiile "Împarte cu ceilalți" ale familiei sau prietenilor</li>
                        <li>Poți vedea și eventual modifica obiectele din aceste cutii</li>
                        <li>Obiectele apar și în locația lor reală din inventarul proprietarului</li>
                    </ul>
                </div>
                
                <div class="partajari-list">
                    <!-- Exemplu de cutie partajată (va fi generat dinamic) -->
                    <div class="partajare-item">
                        <div class="partajare-info">
                            <div class="partajare-email">📦 Cutia: Împarte cu ceilalți a lui Maria</div>
                            <div class="partajare-tip">✏️ Pot modifica • Din colecția "Inventarul familiei Popescu" • 25 obiecte</div>
                        </div>
                        <div class="partajare-actions">
                            <button class="btn btn-primary" onclick="window.location.href='index.php?cutie_virtuala=maria'">
                                Vezi cutia
                            </button>
                            <button class="btn btn-secondary" onclick="window.location.href='index.php?colectie=2'">
                                Vezi toată colecția
                            </button>
                        </div>
                    </div>
                    
                    <div class="partajare-item">
                        <div class="partajare-info">
                            <div class="partajare-email">📦 Cutia: Împarte cu ceilalți a lui Ion</div>
                            <div class="partajare-tip">👁️ Doar vizualizare • Din "Unelte de grădină" • 15 obiecte</div>
                        </div>
                        <div class="partajare-actions">
                            <button class="btn btn-primary" onclick="window.location.href='index.php?cutie_virtuala=ion&readonly=1'">
                                Vezi cutia
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="js/notifications.js"></script>
    <script>
        function switchTab(tabName) {
            // Ascunde toate tab-urile
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Afișează tab-ul selectat
            document.getElementById('tab-' + tabName).classList.add('active');
            event.target.classList.add('active');
        }
        
        function selecteazaToate() {
            document.querySelectorAll('input[name="obiecte_partajate[]"]').forEach(cb => {
                cb.checked = true;
            });
        }
        
        function deselecteazaToate() {
            document.querySelectorAll('input[name="obiecte_partajate[]"]').forEach(cb => {
                cb.checked = false;
            });
        }
        
        function salveazaNumeColectie() {
            const nume = document.getElementById('nume_colectie').value;
            // TODO: Implementare AJAX pentru salvare
            showSuccess('Numele colecției a fost salvat!');
        }
    </script>
</body>
</html>