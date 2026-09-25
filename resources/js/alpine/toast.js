export function uiToast() {
    return {
        items: [],

        push(message, type = 'success') {
            const id = Date.now() + '-' + Math.random().toString(36).slice(2, 7);
            const icons = {
                success: '✓',
                error: '✕',
                warning: '!',
                info: 'i',
            };
            this.items.push({ id, message, type, icon: icons[type] || 'i' });

            setTimeout(() => this.dismiss(id), 2400);
        },

        dismiss(id) {
            this.items = this.items.filter((item) => item.id !== id);
        },
    };
}