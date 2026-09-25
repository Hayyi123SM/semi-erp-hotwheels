export function registerBulkGrid(Alpine) {
    Alpine.data('bulkGrid', (initialRows = []) => ({
        rows: initialRows,
        lastActive: -1,

        addRow() {
            this.rows.push(this.blankRow());
        },

        duplicateRow(index) {
            const source = this.rows[index];
            const copy = {
                ...this.blankRow(),
                sku: source.sku,
                product: source.product,
                series: source.series,
                condition: source.condition,
                qty: source.qty,
                price: source.price,
                scheme: source.scheme,
                schemeValue: source.schemeValue,
                rack: source.rack,
            };
            this.rows.splice(index + 1, 0, copy);
        },

        removeRow(index) {
            this.rows.splice(index, 1);
        },

        clearRows() {
            this.rows = [];
        },

        blankRow() {
            return {
                sku: '',
                product: '',
                series: 'Hot Wheels',
                condition: 'Mint',
                qty: 1,
                price: 0,
                scheme: 'percent',
                schemeValue: 20,
                rack: '',
            };
        },

        totalQty() {
            return this.rows.reduce((sum, row) => sum + Number(row.qty || 0), 0);
        },

        totalValue() {
            return this.rows.reduce((sum, row) => sum + Number(row.qty || 0) * Number(row.price || 0), 0);
        },

        async onPaste(event) {
            const text = await navigator.clipboard?.readText?.().catch(() => '');
            if (!text) return;
            const lines = text.split(/\r?\n/).filter((line) => line.trim());
            for (const line of lines) {
                const parts = line.split('\t');
                const row = this.blankRow();
                row.sku = (parts[0] || '').trim();
                row.product = (parts[1] || '').trim();
                row.qty = Number(parts[2]) || 1;
                row.price = Number(parts[3]) || 0;
                this.rows.push(row);
            }
        },

        commit() {
            const done = this.rows.filter((row) => row.sku && row.product && row.qty > 0);
            window.Alpine.store('toast').push(`${done.length} baris tersimpan & siap cetak label.`, 'success');
            this.rows = [];
        },
    }));
}