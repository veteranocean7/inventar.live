/**
 * Offline Sync Manager pentru Inventar.live
 * Faza 2: Sincronizare date între server și IndexedDB
 * Versiune: 1.0.0
 */

const OfflineSync = (function() {
    'use strict';

    let isOnline = navigator.onLine;
    let syncInProgress = false;
    let eventListeners = {};

    /**
     * Inițializare
     */
    async function init() {
        // Așteaptă inițializarea IndexedDB
        await IDBManager.init();

        // Monitorizare status conexiune
        window.addEventListener('online', handleOnline);
        window.addEventListener('offline', handleOffline);

        // Verifică și sincronizează la load dacă suntem online
        if (isOnline) {
            // Delay mic pentru a permite paginii să se încarce
            setTimeout(() => syncFromServer(), 2000);
        }

        console.log('[Sync] Manager inițializat, online:', isOnline);
        return true;
    }

    /**
     * Event handlers pentru conectivitate
     */
    function handleOnline() {
        console.log('[Sync] Conexiune restabilită');
        isOnline = true;
        emit('online');

        // Încearcă sincronizarea
        syncPendingOperations();
    }

    function handleOffline() {
        console.log('[Sync] Conexiune pierdută');
        isOnline = false;
        emit('offline');
    }

    /**
     * Sincronizare date de pe server în IndexedDB
     */
    async function syncFromServer(colectieId = null) {
        if (!isOnline) {
            console.log('[Sync] Offline - nu se poate sincroniza');
            return false;
        }

        if (syncInProgress) {
            console.log('[Sync] Sincronizare deja în progres');
            return false;
        }

        syncInProgress = true;
        emit('syncStart');

        try {
            // Obține ID-ul colecției curente dacă nu e specificat
            if (!colectieId) {
                colectieId = await IDBManager.Meta.getCurrentColectie();
            }

            // Apel API pentru a obține datele
            const url = colectieId
                ? `api_inventar.php?action=sync&colectie=${colectieId}`
                : 'api_inventar.php?action=sync';

            const response = await fetch(url, {
                credentials: 'same-origin'
            });

            if (!response.ok) {
                throw new Error(`HTTP error: ${response.status}`);
            }

            const data = await response.json();

            if (!data.success) {
                throw new Error(data.error || 'Eroare la sincronizare');
            }

            // Salvează datele în IndexedDB
            if (data.obiecte && data.obiecte.length > 0) {
                await IDBManager.Obiecte.saveAll(data.obiecte, data.colectie_id || colectieId);
            }

            if (data.colectii) {
                await IDBManager.Colectii.saveAll(data.colectii);
            }

            // Actualizează metadata
            await IDBManager.Meta.setLastSync(Date.now());
            if (data.colectie_id) {
                await IDBManager.Meta.setCurrentColectie(data.colectie_id);
            }
            if (data.user_id) {
                await IDBManager.Meta.setCurrentUser(data.user_id);
            }

            console.log(`[Sync] Sincronizare completă: ${data.obiecte?.length || 0} obiecte`);
            emit('syncComplete', {
                obiectCount: data.obiecte?.length || 0,
                colectiiCount: data.colectii?.length || 0
            });

            return true;

        } catch (error) {
            console.error('[Sync] Eroare la sincronizare:', error);
            emit('syncError', error);
            return false;

        } finally {
            syncInProgress = false;
        }
    }

    /**
     * Sincronizează operațiile în așteptare (Faza 3 - pregătire)
     */
    async function syncPendingOperations() {
        if (!isOnline) return;

        const pendingOps = await IDBManager.SyncQueue.getPending();

        if (pendingOps.length === 0) {
            console.log('[Sync] Nicio operație în așteptare');
            return;
        }

        console.log(`[Sync] Procesare ${pendingOps.length} operații în așteptare`);
        emit('pendingSyncStart', { count: pendingOps.length });

        for (const op of pendingOps) {
            try {
                await processOperation(op);
                await IDBManager.SyncQueue.markComplete(op.id);
                emit('operationSynced', op);
            } catch (error) {
                console.error('[Sync] Eroare la operația:', op, error);
                await IDBManager.SyncQueue.markFailed(op.id, error.message);
                emit('operationFailed', { operation: op, error });
            }
        }

        // Curăță operațiile completate
        await IDBManager.SyncQueue.clearCompleted();
        emit('pendingSyncComplete');
    }

    /**
     * Procesează o operație din queue (Faza 3)
     */
    async function processOperation(operation) {
        const { type, endpoint, method, data } = operation;

        const response = await fetch(endpoint, {
            method: method || 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'same-origin',
            body: JSON.stringify(data)
        });

        if (!response.ok) {
            throw new Error(`HTTP error: ${response.status}`);
        }

        return response.json();
    }

    /**
     * Adaugă operație în queue pentru sincronizare ulterioară
     */
    async function queueOperation(type, endpoint, method, data) {
        const operation = { type, endpoint, method, data };
        const id = await IDBManager.SyncQueue.add(operation);
        console.log(`[Sync] Operație adăugată în queue: ${type}`, id);

        // Dacă suntem online, încearcă sincronizare imediată
        if (isOnline) {
            setTimeout(syncPendingOperations, 100);
        }

        return id;
    }

    /**
     * Obține date din IndexedDB pentru afișare offline
     */
    async function getOfflineData(colectieId = null) {
        try {
            let obiecte;

            if (colectieId) {
                obiecte = await IDBManager.Obiecte.getByColectie(colectieId);
            } else {
                obiecte = await IDBManager.Obiecte.getAll();
            }

            const colectii = await IDBManager.Colectii.getAll();
            const lastSync = await IDBManager.Meta.getLastSync();

            return {
                success: true,
                obiecte,
                colectii,
                lastSync,
                isOffline: !isOnline
            };

        } catch (error) {
            console.error('[Sync] Eroare la obținere date offline:', error);
            return {
                success: false,
                error: error.message,
                obiecte: [],
                colectii: [],
                isOffline: !isOnline
            };
        }
    }

    /**
     * Grupează obiectele pe locații și cutii (ca în PHP)
     */
    function grupareObiecte(obiecte) {
        const grupuri = {};

        for (const obiect of obiecte) {
            const cheie = `${obiect.locatie}||${obiect.cutie}`;

            if (!grupuri[cheie]) {
                grupuri[cheie] = {
                    info: {
                        id_obiect: obiect.id_obiect,
                        locatie: obiect.locatie,
                        cutie: obiect.cutie,
                        imagine: obiect.imagine,
                        categorie: obiect.categorie,
                        eticheta: obiect.eticheta,
                        descriere: obiect.descriere_categorie || '',
                        cantitate: obiect.cantitate_obiect || '',
                        eticheta_obiect: obiect.eticheta_obiect || '',
                        imagine_obiect: obiect.imagine_obiect || '',
                        obiecte_partajate: obiect.obiecte_partajate || ''
                    },
                    obiecte: []
                };
            }

            // Parsează denumirile și cantitățile
            if (obiect.denumire_obiect) {
                const denumiri = obiect.denumire_obiect.split(',').map(d => d.trim());
                const cantitati = (obiect.cantitate_obiect || '').split(',').map(c => c.trim());

                denumiri.forEach((denumire, idx) => {
                    grupuri[cheie].obiecte.push({
                        denumire,
                        cantitate: cantitati[idx] || '1'
                    });
                });
            }
        }

        return grupuri;
    }

    /**
     * Sistem de evenimente
     */
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

    function emit(event, data) {
        if (eventListeners[event]) {
            eventListeners[event].forEach(callback => {
                try {
                    callback(data);
                } catch (e) {
                    console.error('[Sync] Eroare în event listener:', e);
                }
            });
        }
    }

    /**
     * Verifică starea sincronizării
     */
    async function getStatus() {
        const lastSync = await IDBManager.Meta.getLastSync();
        const pendingOps = await IDBManager.SyncQueue.getPending();
        const obiectCount = await IDBManager.Obiecte.count();
        const storage = await IDBManager.getStorageUsage();

        return {
            isOnline,
            syncInProgress,
            lastSync,
            lastSyncFormatted: lastSync ? new Date(lastSync).toLocaleString('ro-RO') : 'Niciodată',
            pendingOperations: pendingOps.length,
            cachedObjects: obiectCount,
            storage
        };
    }

    /**
     * Forțează sincronizare completă
     */
    async function forceSync() {
        // Șterge toate datele și re-sincronizează
        await IDBManager.Obiecte.clear();
        await IDBManager.Colectii.clear();
        return syncFromServer();
    }

    // Public API
    return {
        init,
        syncFromServer,
        syncPendingOperations,
        queueOperation,
        getOfflineData,
        grupareObiecte,
        getStatus,
        forceSync,
        isOnline: () => isOnline,
        isSyncing: () => syncInProgress,
        on,
        off
    };
})();

// Auto-init
if (typeof window !== 'undefined') {
    window.OfflineSync = OfflineSync;
}
