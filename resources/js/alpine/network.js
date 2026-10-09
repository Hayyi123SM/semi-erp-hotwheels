/**
 * Real connectivity for the topbar badge, and the seat where the offline queue
 * count will sit once the PWA lands.
 *
 * The ONLINE/OFFLINE badge used to be server-side static, which was honest only
 * because the page had loaded. The browser already knows the truth, so a tiny
 * store mirrors `navigator.onLine` and turns the badge over the moment the
 * network drops -- no reload, no polling. The count of queued offline writes
 * (`offline-queue` in localStorage) sits next to it for the same reason: the
 * PWA will write there when it queues a mutation offline and drain it on
 * reconnect, so the badge appearing above zero is that queue's doing, not ours.
 */
const SYNC_STORAGE_KEY = 'offline-queue';

/**
 * Length of the pending queue, read as an array of queued writes.
 *
 * Anything unreadable is treated as an empty queue: blocked or corrupt storage
 * must not look like a backlog waiting to sync.
 */
function readPendingSync() {
    try {
        const raw = window.localStorage.getItem(SYNC_STORAGE_KEY);

        if (raw === null) {
            return 0;
        }

        const parsed = JSON.parse(raw);

        return Array.isArray(parsed) ? parsed.length : 0;
    } catch {
        return 0;
    }
}

/**
 * Register the connectivity store and wire it to the browser's own events.
 *
 * @param {object} Alpine the Alpine instance
 * @returns {object} the reactive store, not the object that was registered
 */
export function registerNetwork(Alpine) {
    Alpine.store('network', {
        online: navigator.onLine,
        pendingSync: readPendingSync(),
    });

    // Read back out on purpose. This is the proxy, and it is the only handle
    // that is wired to the layout.
    const network = Alpine.store('network');

    window.addEventListener('online', () => {
        network.online = true;

        // Reconnecting is when the queue drains, so re-read its length.
        network.pendingSync = readPendingSync();
    });

    window.addEventListener('offline', () => {
        network.online = false;
    });

    return network;
}