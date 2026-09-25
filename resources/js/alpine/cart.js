export function registerCart(Alpine) {
    Alpine.data('posCart', (items = []) => ({
        items,
        paymentMethod: 'TUNAI',
        tender: 0,

        get subtotal() {
            return this.items.reduce((sum, item) => sum + item.price * item.qty, 0);
        },

        get change() {
            return Math.max(0, this.tender - this.subtotal);
        },

        addItem(sku, name, price, ownership) {
            const existing = this.items.find((i) => i.sku === sku);
            if (existing) {
                existing.qty += 1;
            } else {
                this.items.push({ sku, name, price, ownership, qty: 1 });
            }
            window.Alpine.store('toast').push(`+ ${name}`, 'success');
        },

        increment(sku) {
            const item = this.items.find((i) => i.sku === sku);
            if (item) item.qty += 1;
        },

        decrement(sku) {
            const item = this.items.find((i) => i.sku === sku);
            if (!item) return;
            item.qty -= 1;
            if (item.qty <= 0) this.removeItem(sku);
        },

        removeItem(sku) {
            this.items = this.items.filter((i) => i.sku !== sku);
        },

        quickTender(amount) {
            this.tender = amount;
        },

        pay() {
            if (this.items.length === 0) {
                window.Alpine.store('toast').push('Keranjang kosong', 'warning');
                return;
            }
            const change = this.tender > this.subtotal ? this.tender - this.subtotal : 0;
            window.Alpine.store('toast').push(
                `Transaksi sukses · kembalian Rp${change.toLocaleString('id-ID')}`,
                'success',
            );
            this.items = [];
            this.tender = 0;
        },
    }));
}