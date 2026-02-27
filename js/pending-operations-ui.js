/**
 * UI pentru operații în așteptare - Inventar.live
 * Faza 3: Afișare vizuală operații pending
 * Versiune: 1.0.0
 */

const PendingOperationsUI = (function() {
    'use strict';

    let container = null;
    let badge = null;
    let isExpanded = false;

    /**
     * Inițializare UI
     */
    function init() {
        createUI();
        bindEvents();
        updateUI();

        console.log('[PendingUI] Inițializat');
        return true;
    }

    /**
     * Creează elementele UI
     */
    function createUI() {
        // Container principal
        container = document.createElement('div');
        container.id = 'pending-ops-container';
        container.className = 'pending-ops-container';
        container.innerHTML = `
            <div class="pending-ops-header" id="pending-ops-toggle">
                <span class="pending-ops-icon">⏳</span>
                <span class="pending-ops-title">Operații în așteptare</span>
                <span class="pending-ops-badge" id="pending-ops-badge">0</span>
                <span class="pending-ops-arrow">▼</span>
            </div>
            <div class="pending-ops-list" id="pending-ops-list"></div>
            <div class="pending-ops-actions" id="pending-ops-actions">
                <button class="btn-sync-now" id="btn-sync-now">
                    <span>🔄</span> Sincronizează acum
                </button>
            </div>
        `;

        // Adaugă stiluri
        addStyles();

        // Adaugă în DOM
        document.body.appendChild(container);

        // Referințe
        badge = document.getElementById('pending-ops-badge');
    }

    /**
     * Adaugă stiluri CSS
     */
    function addStyles() {
        const style = document.createElement('style');
        style.textContent = `
            .pending-ops-container {
                position: fixed;
                bottom: 20px;
                left: 20px;
                background: white;
                border-radius: 12px;
                box-shadow: 0 4px 20px rgba(0,0,0,0.15);
                z-index: 9998;
                min-width: 280px;
                max-width: 350px;
                overflow: hidden;
                transition: all 0.3s ease;
                display: none;
            }

            .pending-ops-container.visible {
                display: block;
            }

            .pending-ops-container.expanded {
                max-height: 400px;
            }

            .pending-ops-header {
                display: flex;
                align-items: center;
                gap: 10px;
                padding: 12px 15px;
                background: linear-gradient(135deg, #ff9800, #f57c00);
                color: white;
                cursor: pointer;
                user-select: none;
            }

            .pending-ops-icon {
                font-size: 18px;
            }

            .pending-ops-title {
                flex: 1;
                font-weight: 600;
                font-size: 14px;
            }

            .pending-ops-badge {
                background: white;
                color: #ff9800;
                padding: 2px 8px;
                border-radius: 10px;
                font-size: 12px;
                font-weight: bold;
                min-width: 20px;
                text-align: center;
            }

            .pending-ops-arrow {
                transition: transform 0.3s;
            }

            .pending-ops-container.expanded .pending-ops-arrow {
                transform: rotate(180deg);
            }

            .pending-ops-list {
                max-height: 0;
                overflow: hidden;
                transition: max-height 0.3s ease;
            }

            .pending-ops-container.expanded .pending-ops-list {
                max-height: 250px;
                overflow-y: auto;
            }

            .pending-op-item {
                display: flex;
                align-items: center;
                gap: 10px;
                padding: 10px 15px;
                border-bottom: 1px solid #eee;
                font-size: 13px;
            }

            .pending-op-item:last-child {
                border-bottom: none;
            }

            .pending-op-icon {
                font-size: 16px;
            }

            .pending-op-info {
                flex: 1;
            }

            .pending-op-type {
                font-weight: 500;
                color: #333;
            }

            .pending-op-time {
                font-size: 11px;
                color: #888;
            }

            .pending-op-cancel {
                background: none;
                border: none;
                color: #999;
                cursor: pointer;
                padding: 5px;
                font-size: 14px;
                width: auto;
                margin: 0;
            }

            .pending-op-cancel:hover {
                color: #f44336;
            }

            .pending-ops-actions {
                display: none;
                padding: 10px 15px;
                border-top: 1px solid #eee;
            }

            .pending-ops-container.expanded .pending-ops-actions {
                display: block;
            }

            .btn-sync-now {
                width: 100%;
                padding: 10px;
                background: #4CAF50;
                color: white;
                border: none;
                border-radius: 6px;
                cursor: pointer;
                font-size: 14px;
                font-weight: 500;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                transition: background 0.3s;
            }

            .btn-sync-now:hover {
                background: #43a047;
            }

            .btn-sync-now:disabled {
                background: #ccc;
                cursor: not-allowed;
            }

            .btn-sync-now.syncing {
                background: #2196F3;
            }

            .pending-ops-empty {
                padding: 20px;
                text-align: center;
                color: #888;
                font-size: 13px;
            }

            /* Animație pentru operații noi */
            @keyframes slideIn {
                from {
                    opacity: 0;
                    transform: translateX(-20px);
                }
                to {
                    opacity: 1;
                    transform: translateX(0);
                }
            }

            .pending-op-item.new {
                animation: slideIn 0.3s ease;
            }

            /* Status sincronizare */
            .pending-ops-container.syncing .pending-ops-header {
                background: linear-gradient(135deg, #2196F3, #1976D2);
            }

            .pending-ops-container.success .pending-ops-header {
                background: linear-gradient(135deg, #4CAF50, #388E3C);
            }
        `;
        document.head.appendChild(style);
    }

    /**
     * Bind evenimente
     */
    function bindEvents() {
        // Toggle expand/collapse
        document.getElementById('pending-ops-toggle').addEventListener('click', toggleExpand);

        // Buton sincronizare
        document.getElementById('btn-sync-now').addEventListener('click', syncNow);

        // Evenimente de la OfflineOperations
        if (typeof OfflineOperations !== 'undefined') {
            OfflineOperations.on('operationQueued', handleOperationQueued);
            OfflineOperations.on('operationSynced', handleOperationSynced);
            OfflineOperations.on('operationFailed', handleOperationFailed);
            OfflineOperations.on('syncStart', handleSyncStart);
            OfflineOperations.on('syncComplete', handleSyncComplete);
        }

        // Ascultă și evenimente de conectivitate
        window.addEventListener('online', updateUI);
        window.addEventListener('offline', updateUI);
    }

    /**
     * Toggle expand/collapse
     */
    function toggleExpand() {
        isExpanded = !isExpanded;
        container.classList.toggle('expanded', isExpanded);
    }

    /**
     * Actualizează UI cu operațiile curente
     */
    async function updateUI() {
        if (typeof OfflineOperations === 'undefined') return;

        const pending = await OfflineOperations.getPendingOperations();
        const count = pending.length;

        // Actualizează badge
        badge.textContent = count;

        // Afișează/ascunde container
        container.classList.toggle('visible', count > 0);

        // Populează lista
        const list = document.getElementById('pending-ops-list');

        if (count === 0) {
            list.innerHTML = '<div class="pending-ops-empty">Nicio operație în așteptare</div>';
            return;
        }

        list.innerHTML = pending.map(op => `
            <div class="pending-op-item" data-id="${op.id}">
                <span class="pending-op-icon">${getOperationIcon(op.type)}</span>
                <div class="pending-op-info">
                    <div class="pending-op-type">${getOperationLabel(op.type)}</div>
                    <div class="pending-op-time">${formatTime(op.timestamp)}</div>
                </div>
                <button class="pending-op-cancel" data-id="${op.id}" title="Anulează">✕</button>
            </div>
        `).join('');

        // Bind cancel buttons
        list.querySelectorAll('.pending-op-cancel').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                cancelOperation(btn.dataset.id);
            });
        });

        // Actualizează butonul de sync
        const syncBtn = document.getElementById('btn-sync-now');
        syncBtn.disabled = !navigator.onLine;
        syncBtn.title = navigator.onLine ? 'Sincronizează acum' : 'Ești offline';
    }

    /**
     * Obține icon pentru tip operație
     */
    function getOperationIcon(type) {
        const icons = {
            'create_obiect': '➕',
            'update_obiect': '✏️',
            'delete_obiect': '🗑️',
            'delete_cutie': '📦',
            'delete_imagine': '🖼️',
            'update_colectie': '📁'
        };
        return icons[type] || '📝';
    }

    /**
     * Obține label pentru tip operație
     */
    function getOperationLabel(type) {
        const labels = {
            'create_obiect': 'Adăugare obiect',
            'update_obiect': 'Modificare obiect',
            'delete_obiect': 'Ștergere obiect',
            'delete_cutie': 'Ștergere cutie',
            'delete_imagine': 'Ștergere imagine',
            'update_colectie': 'Modificare colecție'
        };
        return labels[type] || 'Operație';
    }

    /**
     * Formatează timestamp
     */
    function formatTime(timestamp) {
        const date = new Date(timestamp);
        const now = new Date();
        const diff = now - date;

        if (diff < 60000) return 'Acum';
        if (diff < 3600000) return `Acum ${Math.floor(diff / 60000)} min`;
        if (diff < 86400000) return `Acum ${Math.floor(diff / 3600000)} ore`;

        return date.toLocaleString('ro-RO', {
            hour: '2-digit',
            minute: '2-digit',
            day: '2-digit',
            month: 'short'
        });
    }

    /**
     * Anulează o operație
     */
    async function cancelOperation(id) {
        if (confirm('Sigur vrei să anulezi această operație?')) {
            await OfflineOperations.cancelOperation(parseInt(id));
            updateUI();
        }
    }

    /**
     * Sincronizează acum
     */
    async function syncNow() {
        if (!navigator.onLine) {
            alert('Ești offline. Conectează-te la internet pentru a sincroniza.');
            return;
        }

        const btn = document.getElementById('btn-sync-now');
        btn.disabled = true;
        btn.classList.add('syncing');
        btn.innerHTML = '<span>⏳</span> Sincronizare...';

        try {
            const result = await OfflineOperations.processQueue();

            btn.classList.remove('syncing');
            btn.innerHTML = '<span>✓</span> Sincronizat!';

            setTimeout(() => {
                btn.innerHTML = '<span>🔄</span> Sincronizează acum';
                btn.disabled = false;
                updateUI();
            }, 2000);

        } catch (error) {
            console.error('[PendingUI] Eroare sincronizare:', error);
            btn.classList.remove('syncing');
            btn.innerHTML = '<span>✕</span> Eroare';
            btn.disabled = false;

            setTimeout(() => {
                btn.innerHTML = '<span>🔄</span> Sincronizează acum';
            }, 2000);
        }
    }

    /**
     * Event handlers
     */
    function handleOperationQueued(data) {
        updateUI();
        // Expand pentru a arăta noua operație
        if (!isExpanded) {
            isExpanded = true;
            container.classList.add('expanded');
        }
    }

    function handleOperationSynced(data) {
        updateUI();
    }

    function handleOperationFailed(data) {
        updateUI();
        // Notificare eroare
        console.error('[PendingUI] Operație eșuată:', data);
    }

    function handleSyncStart(data) {
        container.classList.add('syncing');
    }

    function handleSyncComplete(data) {
        container.classList.remove('syncing');
        container.classList.add('success');
        setTimeout(() => {
            container.classList.remove('success');
        }, 2000);
        updateUI();
    }

    // Public API
    return {
        init,
        updateUI,
        show: () => container.classList.add('visible'),
        hide: () => container.classList.remove('visible')
    };
})();

// Auto-init când DOM e gata
if (typeof window !== 'undefined') {
    window.PendingOperationsUI = PendingOperationsUI;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            // Init după ce OfflineOperations e gata
            setTimeout(PendingOperationsUI.init, 500);
        });
    } else {
        setTimeout(PendingOperationsUI.init, 500);
    }
}
