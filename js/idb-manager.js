/**
 * IndexedDB Manager pentru Inventar.live
 * Faza 2: Offline Read - Stocare locală a inventarului
 * Versiune: 1.0.0
 */

const IDBManager = (function() {
    'use strict';

    const DB_NAME = 'inventar_offline';
    const DB_VERSION = 1;

    // Store names
    const STORES = {
        OBIECTE: 'obiecte',
        COLECTII: 'colectii',
        IMAGINI: 'imagini_cache',
        SYNC_QUEUE: 'sync_queue',
        META: 'metadata'
    };

    let db = null;

    /**
     * Inițializare bază de date
     */
    function init() {
        return new Promise((resolve, reject) => {
            if (db) {
                resolve(db);
                return;
            }

            if (!('indexedDB' in window)) {
                reject(new Error('IndexedDB nu este suportat'));
                return;
            }

            const request = indexedDB.open(DB_NAME, DB_VERSION);

            request.onerror = (event) => {
                console.error('[IDB] Eroare la deschiderea bazei de date:', event.target.error);
                reject(event.target.error);
            };

            request.onsuccess = (event) => {
                db = event.target.result;
                console.log('[IDB] Baza de date deschisă cu succes');
                resolve(db);
            };

            request.onupgradeneeded = (event) => {
                console.log('[IDB] Upgrade bază de date...');
                const database = event.target.result;

                // Store pentru obiecte/cutii
                if (!database.objectStoreNames.contains(STORES.OBIECTE)) {
                    const obiectStore = database.createObjectStore(STORES.OBIECTE, {
                        keyPath: 'id_obiect'
                    });
                    obiectStore.createIndex('locatie', 'locatie', { unique: false });
                    obiectStore.createIndex('cutie', 'cutie', { unique: false });
                    obiectStore.createIndex('locatie_cutie', ['locatie', 'cutie'], { unique: false });
                    obiectStore.createIndex('colectie_id', 'colectie_id', { unique: false });
                }

                // Store pentru colecții
                if (!database.objectStoreNames.contains(STORES.COLECTII)) {
                    const colectiiStore = database.createObjectStore(STORES.COLECTII, {
                        keyPath: 'id_colectie'
                    });
                    colectiiStore.createIndex('tip', 'tip', { unique: false });
                }

                // Store pentru imagini cache (blob references)
                if (!database.objectStoreNames.contains(STORES.IMAGINI)) {
                    const imaginiStore = database.createObjectStore(STORES.IMAGINI, {
                        keyPath: 'url'
                    });
                    imaginiStore.createIndex('timestamp', 'timestamp', { unique: false });
                    imaginiStore.createIndex('size', 'size', { unique: false });
                }

                // Store pentru operații în așteptare (sync queue)
                if (!database.objectStoreNames.contains(STORES.SYNC_QUEUE)) {
                    const syncStore = database.createObjectStore(STORES.SYNC_QUEUE, {
                        keyPath: 'id',
                        autoIncrement: true
                    });
                    syncStore.createIndex('timestamp', 'timestamp', { unique: false });
                    syncStore.createIndex('type', 'type', { unique: false });
                    syncStore.createIndex('status', 'status', { unique: false });
                }

                // Store pentru metadata (ultima sincronizare, etc.)
                if (!database.objectStoreNames.contains(STORES.META)) {
                    database.createObjectStore(STORES.META, { keyPath: 'key' });
                }

                console.log('[IDB] Structura bazei de date creată');
            };
        });
    }

    /**
     * Operații generice CRUD
     */
    function getStore(storeName, mode = 'readonly') {
        if (!db) {
            throw new Error('Baza de date nu este inițializată');
        }
        const transaction = db.transaction(storeName, mode);
        return transaction.objectStore(storeName);
    }

    function add(storeName, data) {
        return new Promise((resolve, reject) => {
            const store = getStore(storeName, 'readwrite');
            const request = store.add(data);
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });
    }

    function put(storeName, data) {
        return new Promise((resolve, reject) => {
            const store = getStore(storeName, 'readwrite');
            const request = store.put(data);
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });
    }

    function get(storeName, key) {
        return new Promise((resolve, reject) => {
            const store = getStore(storeName, 'readonly');
            const request = store.get(key);
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });
    }

    function getAll(storeName) {
        return new Promise((resolve, reject) => {
            const store = getStore(storeName, 'readonly');
            const request = store.getAll();
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });
    }

    function remove(storeName, key) {
        return new Promise((resolve, reject) => {
            const store = getStore(storeName, 'readwrite');
            const request = store.delete(key);
            request.onsuccess = () => resolve();
            request.onerror = () => reject(request.error);
        });
    }

    function clear(storeName) {
        return new Promise((resolve, reject) => {
            const store = getStore(storeName, 'readwrite');
            const request = store.clear();
            request.onsuccess = () => resolve();
            request.onerror = () => reject(request.error);
        });
    }

    function getByIndex(storeName, indexName, value) {
        return new Promise((resolve, reject) => {
            const store = getStore(storeName, 'readonly');
            const index = store.index(indexName);
            const request = index.getAll(value);
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });
    }

    /**
     * Operații specifice pentru Obiecte
     */
    const Obiecte = {
        save: (obiect) => put(STORES.OBIECTE, obiect),

        saveAll: async (obiecte, colectieId) => {
            // Șterge obiectele vechi din această colecție
            const existing = await getByIndex(STORES.OBIECTE, 'colectie_id', colectieId);
            for (const obj of existing) {
                await remove(STORES.OBIECTE, obj.id_obiect);
            }

            // Salvează obiectele noi
            for (const obiect of obiecte) {
                obiect.colectie_id = colectieId;
                await put(STORES.OBIECTE, obiect);
            }

            console.log(`[IDB] Salvate ${obiecte.length} obiecte pentru colecția ${colectieId}`);
        },

        get: (id) => get(STORES.OBIECTE, id),

        getAll: () => getAll(STORES.OBIECTE),

        getByColectie: (colectieId) => getByIndex(STORES.OBIECTE, 'colectie_id', colectieId),

        getByLocatie: (locatie) => getByIndex(STORES.OBIECTE, 'locatie', locatie),

        getByCutie: (locatie, cutie) => getByIndex(STORES.OBIECTE, 'locatie_cutie', [locatie, cutie]),

        delete: (id) => remove(STORES.OBIECTE, id),

        clear: () => clear(STORES.OBIECTE),

        count: async () => {
            const all = await getAll(STORES.OBIECTE);
            return all.length;
        }
    };

    /**
     * Operații specifice pentru Colecții
     */
    const Colectii = {
        save: (colectie) => put(STORES.COLECTII, colectie),

        saveAll: async (colectii) => {
            for (const colectie of colectii) {
                await put(STORES.COLECTII, colectie);
            }
            console.log(`[IDB] Salvate ${colectii.length} colecții`);
        },

        get: (id) => get(STORES.COLECTII, id),

        getAll: () => getAll(STORES.COLECTII),

        getProprii: () => getByIndex(STORES.COLECTII, 'tip', 'proprie'),

        getPartajate: () => getByIndex(STORES.COLECTII, 'tip', 'partajata'),

        delete: (id) => remove(STORES.COLECTII, id),

        clear: () => clear(STORES.COLECTII)
    };

    /**
     * Operații pentru Sync Queue (operații offline)
     */
    const SyncQueue = {
        add: (operation) => {
            const queueItem = {
                ...operation,
                timestamp: Date.now(),
                status: 'pending'
            };
            return add(STORES.SYNC_QUEUE, queueItem);
        },

        getAll: () => getAll(STORES.SYNC_QUEUE),

        getPending: () => getByIndex(STORES.SYNC_QUEUE, 'status', 'pending'),

        markComplete: async (id) => {
            const item = await get(STORES.SYNC_QUEUE, id);
            if (item) {
                item.status = 'completed';
                item.completedAt = Date.now();
                await put(STORES.SYNC_QUEUE, item);
            }
        },

        markFailed: async (id, error) => {
            const item = await get(STORES.SYNC_QUEUE, id);
            if (item) {
                item.status = 'failed';
                item.error = error;
                item.failedAt = Date.now();
                await put(STORES.SYNC_QUEUE, item);
            }
        },

        remove: (id) => remove(STORES.SYNC_QUEUE, id),

        clearCompleted: async () => {
            const all = await getAll(STORES.SYNC_QUEUE);
            for (const item of all) {
                if (item.status === 'completed') {
                    await remove(STORES.SYNC_QUEUE, item.id);
                }
            }
        }
    };

    /**
     * Operații pentru Metadata
     */
    const Meta = {
        set: (key, value) => put(STORES.META, { key, value, updatedAt: Date.now() }),

        get: async (key) => {
            const result = await get(STORES.META, key);
            return result ? result.value : null;
        },

        getLastSync: () => Meta.get('lastSync'),

        setLastSync: (timestamp = Date.now()) => Meta.set('lastSync', timestamp),

        getCurrentUser: () => Meta.get('currentUser'),

        setCurrentUser: (userId) => Meta.set('currentUser', userId),

        getCurrentColectie: () => Meta.get('currentColectie'),

        setCurrentColectie: (colectieId) => Meta.set('currentColectie', colectieId)
    };

    /**
     * Utilități
     */
    function getStorageUsage() {
        return new Promise((resolve) => {
            if ('storage' in navigator && 'estimate' in navigator.storage) {
                navigator.storage.estimate().then(estimate => {
                    resolve({
                        usage: estimate.usage,
                        quota: estimate.quota,
                        usagePercent: ((estimate.usage / estimate.quota) * 100).toFixed(2)
                    });
                });
            } else {
                resolve({ usage: 0, quota: 0, usagePercent: 0 });
            }
        });
    }

    async function clearAll() {
        await clear(STORES.OBIECTE);
        await clear(STORES.COLECTII);
        await clear(STORES.IMAGINI);
        await clear(STORES.SYNC_QUEUE);
        await clear(STORES.META);
        console.log('[IDB] Toate datele au fost șterse');
    }

    // Public API
    return {
        init,
        STORES,
        Obiecte,
        Colectii,
        SyncQueue,
        Meta,
        getStorageUsage,
        clearAll,
        // Low-level access
        _add: add,
        _put: put,
        _get: get,
        _getAll: getAll,
        _remove: remove,
        _clear: clear,
        _getByIndex: getByIndex
    };
})();

// Auto-init când scriptul se încarcă
if (typeof window !== 'undefined') {
    window.IDBManager = IDBManager;
}
