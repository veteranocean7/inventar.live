<?php
/**
 * Vizualizare Log-uri Vision API
 * Pentru debugging pe server cu cPanel
 */

session_start();
require_once 'config.php';
require_once 'includes/auth_functions.php';

// Verifică autentificarea
$user = checkSession();
if (!$user) {
    die(json_encode(['success' => false, 'message' => 'Neautentificat']));
}

$log_file = __DIR__ . '/vision_debug.log';

// Dacă e cerere AJAX pentru log-uri
if (isset($_GET['action']) && $_GET['action'] === 'get_logs') {
    header('Content-Type: application/json');
    
    if (!file_exists($log_file)) {
        echo json_encode(['success' => true, 'logs' => 'Nu există log-uri încă.']);
        exit;
    }
    
    // Citim ultimele N linii
    $lines_to_show = isset($_GET['lines']) ? intval($_GET['lines']) : 100;
    $lines = file($log_file);
    $lines = array_slice($lines, -$lines_to_show);
    
    echo json_encode([
        'success' => true, 
        'logs' => implode('', $lines),
        'total_lines' => count(file($log_file)),
        'file_size' => filesize($log_file)
    ]);
    exit;
}

// Dacă e cerere pentru ștergere log-uri
if (isset($_POST['action']) && $_POST['action'] === 'clear_logs') {
    header('Content-Type: application/json');
    
    if (file_exists($log_file)) {
        file_put_contents($log_file, '');
        echo json_encode(['success' => true, 'message' => 'Log-urile au fost șterse.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Fișierul de log nu există.']);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vizualizare Log-uri Vision API</title>
    <style>
        body {
            margin: 0;
            padding: 20px;
            font-family: 'Courier New', monospace;
            background: #1e1e1e;
            color: #d4d4d4;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            background: #2d2d30;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        h1 {
            color: #4ec9b0;
            margin: 0;
            font-size: 24px;
        }
        
        .controls {
            display: flex;
            gap: 10px;
        }
        
        button {
            padding: 8px 16px;
            background: #007acc;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }
        
        button:hover {
            background: #005a9e;
        }
        
        button.danger {
            background: #f44336;
        }
        
        button.danger:hover {
            background: #d32f2f;
        }
        
        .info {
            padding: 10px;
            background: #2d2d30;
            border-radius: 4px;
            margin-bottom: 20px;
            font-size: 12px;
            color: #808080;
        }
        
        .log-container {
            background: #1e1e1e;
            border: 1px solid #3e3e42;
            border-radius: 4px;
            padding: 15px;
            height: 600px;
            overflow-y: auto;
            font-size: 13px;
            line-height: 1.5;
        }
        
        .log-container pre {
            margin: 0;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        
        /* Colorare sintaxă pentru diferite tipuri de mesaje */
        .log-line {
            margin: 2px 0;
        }
        
        .log-timestamp {
            color: #808080;
        }
        
        .log-error {
            color: #f48771;
        }
        
        .log-warning {
            color: #dcdcaa;
        }
        
        .log-success {
            color: #4ec9b0;
        }
        
        .log-info {
            color: #9cdcfe;
        }
        
        .log-debug {
            color: #c586c0;
        }
        
        .loading {
            text-align: center;
            padding: 50px;
            color: #808080;
        }
        
        .auto-refresh {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .auto-refresh input {
            margin: 0;
        }
        
        .lines-selector {
            padding: 6px;
            background: #3e3e42;
            border: 1px solid #555;
            color: #d4d4d4;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📋 Log-uri Vision API</h1>
            <div class="controls">
                <div class="auto-refresh">
                    <input type="checkbox" id="autoRefresh" checked>
                    <label for="autoRefresh">Auto-refresh (5s)</label>
                </div>
                <select class="lines-selector" id="linesCount">
                    <option value="50">Ultimele 50 linii</option>
                    <option value="100" selected>Ultimele 100 linii</option>
                    <option value="200">Ultimele 200 linii</option>
                    <option value="500">Ultimele 500 linii</option>
                </select>
                <button onclick="refreshLogs()">🔄 Reîmprospătare</button>
                <button onclick="copyLogs()">📋 Copiază Log-uri</button>
                <button onclick="clearLogs()" class="danger">🗑️ Șterge Log-uri</button>
                <button onclick="window.location.href='etichete_imagine.php'">⬅️ Înapoi</button>
            </div>
        </div>
        
        <div class="info" id="info">
            Așteptăm informații despre fișierul de log...
        </div>
        
        <div class="log-container" id="logContainer">
            <div class="loading">Se încarcă log-urile...</div>
        </div>
    </div>
    
    <script>
        let autoRefreshInterval = null;
        
        function formatLogLine(line) {
            // Colorăm diferit în funcție de tipul de mesaj
            if (line.includes('ERROR') || line.includes('EROARE')) {
                return `<span class="log-error">${escapeHtml(line)}</span>`;
            } else if (line.includes('WARNING') || line.includes('ATENȚIE')) {
                return `<span class="log-warning">${escapeHtml(line)}</span>`;
            } else if (line.includes('✓') || line.includes('SUCCESS')) {
                return `<span class="log-success">${escapeHtml(line)}</span>`;
            } else if (line.includes('===') || line.includes('---')) {
                return `<span class="log-info">${escapeHtml(line)}</span>`;
            } else if (line.includes('DEBUG') || line.includes('COLȚURI') || line.includes('ARANJAMENT')) {
                return `<span class="log-debug">${escapeHtml(line)}</span>`;
            } else if (line.match(/^\[\d{4}-\d{2}-\d{2}/)) {
                // Linie cu timestamp
                return `<span class="log-timestamp">${escapeHtml(line)}</span>`;
            }
            return escapeHtml(line);
        }
        
        function escapeHtml(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, m => map[m]);
        }
        
        // Stocăm ultimele log-uri pentru copiare
        let lastLogs = '';
        
        function refreshLogs() {
            const lines = document.getElementById('linesCount').value;
            
            fetch(`vizualizare_log_vision.php?action=get_logs&lines=${lines}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const container = document.getElementById('logContainer');
                        const info = document.getElementById('info');
                        
                        if (data.logs) {
                            // Salvăm log-urile pentru copiare
                            lastLogs = data.logs;
                            
                            const lines = data.logs.split('\n');
                            const formattedLines = lines.map(line => formatLogLine(line));
                            container.innerHTML = '<pre>' + formattedLines.join('\n') + '</pre>';
                            
                            // Actualizăm info
                            const sizeKB = (data.file_size / 1024).toFixed(2);
                            info.innerHTML = `📊 Total linii: ${data.total_lines} | 💾 Dimensiune fișier: ${sizeKB} KB | 🕐 Ultima actualizare: ${new Date().toLocaleTimeString('ro-RO')}`;
                            
                            // Scroll la final
                            container.scrollTop = container.scrollHeight;
                        } else {
                            container.innerHTML = '<div class="loading">Nu există log-uri încă.</div>';
                            lastLogs = '';
                        }
                    }
                })
                .catch(error => {
                    console.error('Eroare la încărcarea log-urilor:', error);
                    document.getElementById('logContainer').innerHTML = 
                        '<div class="log-error">Eroare la încărcarea log-urilor: ' + error + '</div>';
                });
        }
        
        function copyLogs() {
            if (!lastLogs) {
                alert('Nu există log-uri de copiat!');
                return;
            }
            
            // Creăm un element temporar pentru copierea textului
            const textarea = document.createElement('textarea');
            textarea.value = lastLogs;
            textarea.style.position = 'fixed';
            textarea.style.left = '-999999px';
            document.body.appendChild(textarea);
            
            try {
                textarea.select();
                document.execCommand('copy');
                
                // Feedback vizual
                const button = event.target;
                const originalText = button.innerHTML;
                button.innerHTML = '✅ Copiat!';
                button.style.background = '#4caf50';
                
                setTimeout(() => {
                    button.innerHTML = originalText;
                    button.style.background = '';
                }, 2000);
                
            } catch (err) {
                alert('Eroare la copiere: ' + err);
            } finally {
                document.body.removeChild(textarea);
            }
        }
        
        function clearLogs() {
            if (!confirm('Sigur doriți să ștergeți toate log-urile?')) {
                return;
            }
            
            fetch('vizualizare_log_vision.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=clear_logs'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    refreshLogs();
                } else {
                    alert('Eroare: ' + data.message);
                }
            })
            .catch(error => {
                alert('Eroare la ștergerea log-urilor: ' + error);
            });
        }
        
        function toggleAutoRefresh() {
            const checkbox = document.getElementById('autoRefresh');
            
            if (checkbox.checked) {
                autoRefreshInterval = setInterval(refreshLogs, 5000);
            } else {
                if (autoRefreshInterval) {
                    clearInterval(autoRefreshInterval);
                    autoRefreshInterval = null;
                }
            }
        }
        
        // Event listeners
        document.getElementById('autoRefresh').addEventListener('change', toggleAutoRefresh);
        document.getElementById('linesCount').addEventListener('change', refreshLogs);
        
        // Încărcăm log-urile la început
        refreshLogs();
        
        // Activăm auto-refresh dacă e bifat
        if (document.getElementById('autoRefresh').checked) {
            toggleAutoRefresh();
        }
    </script>
</body>
</html>