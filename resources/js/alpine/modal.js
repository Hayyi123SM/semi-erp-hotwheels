export function uiModal() {
    return {
        open: false,
        title: '',
        slot: '',

        show(title, slot = null) {
            this.title = title;
            this.slot = slot;
            this.open = true;
        },

        hide() {
            this.open = false;
        },
    };
}