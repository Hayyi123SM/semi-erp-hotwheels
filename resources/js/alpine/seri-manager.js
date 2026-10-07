export function seriManager() {
    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

    return {
        series: [],
        name: '',
        code: '',
        loading: false,
        error: '',

        async init() {
            await this.load();
        },

        async load() {
            const res = await fetch('/master/seri', { headers: { Accept: 'application/json' } });
            this.series = res.ok ? await res.json() : [];
        },

        async add() {
            this.error = '';
            this.loading = true;

            const body = new URLSearchParams();
            body.append('name', this.name);
            body.append('code', this.code);

            const res = await fetch('/master/seri', {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
                body,
            });

            const data = await res.json().catch(() => ({}));
            this.loading = false;

            if (!res.ok) {
                const reasons = data.errors ? Object.values(data.errors).flat().join(', ') : data.message;
                this.error = reasons || 'Gagal menyimpan seri.';
                return;
            }

            this.name = '';
            this.code = '';
            this.$store.toast.push(data.message || 'Seri dibuat.', 'success');
            await this.load();
        },

        async remove(id) {
            if (!confirm('Hapus seri ini? (akan gagal bila masih dipakai produk)')) {
                return;
            }

            const res = await fetch(`/master/seri/${id}`, {
                method: 'DELETE',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
            });

            const data = await res.json().catch(() => ({}));

            if (!res.ok) {
                this.$store.toast.push(data.message || 'Gagal menghapus seri.', 'error');
                return;
            }

            this.$store.toast.push(data.message || 'Seri dihapus.', 'info');
            await this.load();
        },
    };
}