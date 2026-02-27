/**
 * Offline Operations Manager pentru Inventar.live
 * Faza 3: Offline Write - Interceptare și queue operații
 * Versiune: 1.0.0
 */

const OfflineOperations = (function() {
    'use strict';

    // Tipuri de operații suportate
    const OPERATION_TYPES = {
        CREATE_OBIECT: 'create_obiect',
        UPDATE_OBIECT: 'update_obiect',
        DELETE_OBIECT: 'delete_obiect',
        DELETE_CUTIE: 'delete_cutie',
        DELETE_IMAGINE: 'delete_imagine',
        UPDATE_COLECTIE: 'update_colectie'
    };

    // Mapping endpoint → tip operație
    const ENDPOINT_MAPPING = {
        'adauga_obiect.php': OPERATION_TYPES.CREATE_OBIECT,
        'actualizeaza_obiect.php': OPERATION_TYPES.UPDATE_OBIECT,
        'sterge_cutie.php': OPERATION_TYPES.DELETE_CUTIE,
        'sterge_imagine.php': OPERATION_TYPES.DELETE_IMAGINE,
        'ajax_colectii.php': OPERATION_TYPES.UPDATE_COLECTIE
    };

    // Operații care pot fi executate offline
    const OFFLINE_CAPABLE = [
        OPERATION_TYPES.UPDATE_OBIECT,
        OPERATION_TYPES.DELETE_OBIECT,
        OPERATION_TYPES.DELETE_CUTIE
    ];

    let isInitialized = false;
    let originalFetch = null;

    /**
     * Inițializare - interceptează fetch pentru operații offline
     */
    function init() {
        if (isInitialized) return;

        // Salvează fetch original
        originalFetch = window.fetch;

        // Înlocuiește cu versiunea noastră
        window.fetch = interceptedFetch;

        isInitialized = true;
        console.log('[OfflineOps] Interceptor inițializat');

        // Înregistrează pentru Background Sync
        registerBackgroundSync();

        return true;
    }

    /**
     * Fetch interceptat - decide dacă execută online sau queue offline
     */
    async function interceptedFetch(input, init = {}) {
        const url = typeof input === 'string' ? input : input.url;
        const method = init.method || 'GET';

        // Doar interceptăm POST/PUT/DELETE către endpoint-uri cunoscute
        if (method === 'GET' || !shouldIntercept(url)) {
            return originalFetch(input, init);
        }

        // Verifică dacă suntem online
        if (navigator.onLine) {
            try {
                const response = await originalFetch(input, init);

                // Dacă operația a reușit, actualizează și IndexedDB
                if (response.ok) {
                    await syncLocalAfterOperation(url, init.body);
                }

                return response;
            } catch (error) {
                // Eroare de rețea - tratează ca offline
                console.log('[OfflineOps] Eroare rețea, salvare offline:', error);
            }
        }

        // Suntem offline sau eroare rețea - queue operația
        return queueOfflineOperation(url, method, init);
    }

    /**
     * Verifică dacă URL-ul trebuie interceptat
     */
    function shouldIntercept(url) {
        const endpoint = extractEndpoint(url);
        return endpoint && ENDPOINT_MAPPING[endpoint];
    }

    /**
     * Extrage endpoint-ul din URL
     */
    function extractEndpoint(url) {
        const match = url.match(/([^\/]+\.php)/);
        return match ? match[1] : null;
    }

    /**
     * Adaugă operația în queue pentru execuție ulterioară
     */
    async function queueOfflineOperation(url, method, init) {
        const endpoint = extractEndpoint(url);
        const operationType = ENDPOINT_MAPPING[endpoint];

        // Parsează datele din body
        let data = {};
        if (init.body) {
            if (init.body instanceof FormData) {
                for (const [key, value] of init.body.entries()) {
                    // Nu salvăm fișiere în queue (prea mari)
                    if (!(value instanceof File)) {
                        data[key] = value;
                    }
                }
            } else if (typeof init.body === 'string') {
                try {
                    data = JSON.parse(init.body);
                } catch (e) {
                    // URL encoded
                    data = Object.fromEntries(new URLSearchParams(init.body));
                }
            }
        }

        // Creează operația pentru queue
        const operation = {
            type: operationType,
            endpoint: url,
            method: method,
            data: data,
            timestamp: Date.now(),
            status: 'pending',
            retryCount: 0
        };

        // Salvează în IndexedDB
        const id = await IDBManager.SyncQueue.add(operation);
        console.log('[OfflineOps] Operație salvată offline:', operationType, id);

        // Aplică operația local (optimistic update)
        await applyLocalOperation(operation);

        // Notifică UI
        dispatchEvent('operationQueued', { operation, id });

        // Încearcă să înregistreze Background Sync
        await requestBackgroundSync();

        // Returnează un răspuns simulat
        return new Response(JSON.stringify({
            success: true,
            offline: true,
            queueId: id,
            message: 'Operație salvată pentru sincronizare ulterioară'
        }), {
            status: 200,
            headers: { 'Content-Type': 'application/json' }
        });
    }

    /**
     * Aplică operația local în IndexedDB (optimistic update)
     */
    async function applyLocalOperation(operation) {
        const { type, data } = operation;

        try {
            switch (type) {
                case OPERATION_TYPES.UPDATE_OBIECT:
                    if (data.id_obiect) {
                        const existing = await IDBManager.Obiecte.get(parseInt(data.id_obiect));
                        if (existing) {
                            // Merge cu datele noi
                            const updated = { ...existing, ...data, _pendingSync: true };
                            await IDBManager.Obiecte.save(updated);
                            console.log('[OfflineOps] Obiect actualizat local:', data.id_obiect);
                        }
                    }
                    break;

                case OPERATION_TYPES.DELETE_CUTIE:
                    if (data.id_obiect) {
                        // Marchează ca șters (nu șterge efectiv până la sync)
                        const existing = await IDBManager.Obiecte.get(parseInt(data.id_obiect));
                        if (existing) {
                            existing._deleted = true;
                            existing._pendingSync = true;
                            await IDBManager.Obiecte.save(existing);
                            console.log('[OfflineOps] Cutie marcată pentru ștergere:', data.id_obiect);
                        }
                    }
                    break;

                case OPERATION_TYPES.DELETE_OBIECT:
                    // Similar cu delete cutie
                    break;

                default:
                    console.log('[OfflineOps] Tip operație netratat local:', type);
            }
        } catch (error) {
            console.error('[OfflineOps] Eroare la aplicare locală:', error);
        }
    }

    /**
     * Sincronizează IndexedDB după operație online reușită
     */
    async function syncLocalAfterOperation(url, body) {
        // Re-fetch datele actualizate de pe server
        // Aceasta se face automat prin OfflineSync la următoarea încărcare
    }

    /**
     * Înregistrare pentru Background Sync API
     */
    async function registerBackgroundSync() {
        if ('serviceWorker' in navigator && 'sync' in window.registration) {
            try {
                const registration = await navigator.serviceWorker.ready;
                // Background sync va fi gestionat de SW
                console.log('[OfflineOps] Background Sync disponibil');
            } catch (error) {
                console.warn('[OfflineOps] Background Sync nu e disponibil:', error);
            }
        }
    }

    /**
     * Solicită Background Sync
     */
    async function requestBackgroundSync() {
        if ('serviceWorker' in navigator) {
            try {
                const registration = await navigator.serviceWorker.ready;
                if ('sync' in registration) {
                    await registration.sync.register('inventar-sync');
                    console.log('[OfflineOps] Background Sync înregistrat');
                }
            } catch (error) {
                console.warn('[OfflineOps] Nu s-a putut înregistra sync:', error);
            }
        }
    }

    /**
     * Procesează toate operațiile din queue (când revine conexiunea)
     */
    async function processQueue() {
        if (!navigator.onLine) {
            console.log('[OfflineOps] Încă offline, nu procesăm queue');
            return { processed: 0, failed: 0 };
        }

        const pending = await IDBManager.SyncQueue.getPending();

        if (pending.length === 0) {
            console.log('[OfflineOps] Queue gol');
            return { processed: 0, failed: 0 };
        }

        console.log(`[OfflineOps] Procesare ${pending.length} operații...`);
        dispatchEvent('syncStart', { count: pending.length });

        let processed = 0;
        let failed = 0;

        for (const operation of pending) {
            try {
                await executeOperation(operation);
                await IDBManager.SyncQueue.markComplete(operation.id);
                processed++;
                dispatchEvent('operationSynced', { operation });
            } catch (error) {
                console.error('[OfflineOps] Eroare la operația:', operation.id, error);

                operation.retryCount = (operation.retryCount || 0) + 1;

                if (operation.retryCount >= 3) {
                    await IDBManager.SyncQueue.markFailed(operation.id, error.message);
                    failed++;
                    dispatchEvent('operationFailed', { operation, error });
                } else {
                    // Păstrează pentru retry
                    await IDBManager._put(IDBManager.STORES.SYNC_QUEUE, operation);
                }
            }
        }

        // Curăță operațiile completate
        await IDBManager.SyncQueue.clearCompleted();

        // Re-sincronizează pentru a avea datele fresh
        if (processed > 0 && typeof OfflineSync !== 'undefined') {
            await OfflineSync.syncFromServer();
        }

        dispatchEvent('syncComplete', { processed, failed });
        console.log(`[OfflineOps] Sincronizare completă: ${processed} reușite, ${failed} eșuate`);

        return { processed, failed };
    }

    /**
     * Execută o singură operație pe server
     */
    async function executeOperation(operation) {
        const { endpoint, method, data } = operation;

        // Construiește FormData pentru compatibilitate cu endpoint-urile existente
        const formData = new FormData();
        for (const [key, value] of Object.entries(data)) {
            formData.append(key, value);
        }

        const response = await originalFetch(endpoint, {
            method: method || 'POST',
            body: formData,
            credentials: 'same-origin'
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        const result = await response.json();

        if (!result.success) {
            throw new Error(result.error || 'Operație eșuată');
        }

        return result;
    }

    /**
     * Obține operațiile în așteptare
     */
    async function getPendingOperations() {
        return IDBManager.SyncQueue.getPending();
    }

    /**
     * Obține numărul de operații în așteptare
     */
    async function getPendingCount() {
        const pending = await IDBManager.SyncQueue.getPending();
        return pending.length;
    }

    /**
     * Șterge o operație din queue (cancel)
     */
    async function cancelOperation(id) {
        await IDBManager.SyncQueue.remove(id);
        dispatchEvent('operationCancelled', { id });
    }

    /**
     * Sistem de evenimente
     */
    const eventListeners = {};

    function on(event, callback) {
        if (!eventListeners[event]) {
            eventListeners[event] = [];
        }
        eventListeners[event].push(callback);
    }

    function off(event, callback) {
        if (eventListeners[event]) {
            eventListeners[event] = eventListeners[event].filter(cb => cb !== callback);
        }
    }

    function dispatchEvent(event, data) {
        if (eventListeners[event]) {
            eventListeners[event].forEach(cb => {
                try {
                    cb(data);
                } catch (e) {
                    console.error('[OfflineOps] Eroare în listener:', e);
                }
            });
        }

        // Dispatch și ca DOM event pentru componente externe
        window.dispatchEvent(new CustomEvent('offlineOps:' + event, { detail: data }));
    }

    // Public API
    return {
        init,
        OPERATION_TYPES,
        processQueue,
        getPendingOperations,
        getPendingCount,
        cancelOperation,
        on,
        off,
        // Pentru debugging
        _originalFetch: () => originalFetch
    };
})();

// Auto-init
if (typeof window !== 'undefined') {
    window.OfflineOperations = OfflineOperations;
}
