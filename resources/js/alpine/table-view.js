/**
 * The table <-> grid switch for a data table.
 *
 * The visible branch is not this component's job. It is CSS on
 * `data-table-view` over <html>, because the choice has to survive a reload
 * without the table flashing past first — an inline script in the layout seeds
 * that attribute before first paint, and this only keeps the toggle itself in
 * step with it.
 *
 * One preference is shared by every table on the page, which is why the write
 * targets the document and not the element.
 */
const TABLE = 'table';
const GRID = 'grid';

function readStored(key) {
    try {
        return window.localStorage.getItem(key);
    } catch {
        // Blocked storage: the CSS default of Table still applies.
        return null;
    }
}

function writeStored(key, value) {
    try {
        window.localStorage.setItem(key, value);
    } catch {
        // The layout still switches, it just will not be remembered.
    }
}

export function tableView(storageKey) {
    return {
        view: TABLE,

        init() {
            // The attribute is the source of truth when it is set: the inline
            // script may have read a value this component cannot.
            const current = document.documentElement.dataset.tableView ?? readStored(storageKey);

            this.view = current === GRID ? GRID : TABLE;
        },

        /** Takes the value, not the edge, so a repeated call is a no-op. */
        setView(view) {
            this.view = view === GRID ? GRID : TABLE;
            document.documentElement.dataset.tableView = this.view;
            writeStored(storageKey, this.view);
        },
    };
}
