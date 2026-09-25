export function registerScanDirective(Alpine) {
    Alpine.directive('scan', (el, { expression }, { evaluateLater }) => {
        const evaluate = evaluateLater(expression);

        el.tabIndex = 0;
        el.classList.add('scan-input');

        const refocus = () => {
            setTimeout(() => {
                const active = document.activeElement;
                if (active && active !== el && active.closest('[data-allow-focus]')) {
                    return;
                }
                el.focus();
            }, 100);
        };

        let buffer = '';
        let lastKeyTime = 0;

        const flush = () => {
            if (!buffer) return;
            const value = buffer.trim();
            buffer = '';
            if (value) evaluate((next) => next(value));
        };

        el.addEventListener('keydown', (event) => {
            const now = Date.now();
            const wedge = now - lastKeyTime <= 35 && buffer.length > 0;
            lastKeyTime = now;

            if (event.key === 'Enter') {
                event.preventDefault();
                flush();
                return;
            }

            if (event.key.length === 1 && !event.ctrlKey && !event.metaKey && !event.altKey) {
                buffer += wedge ? event.key : event.key;
            } else if (event.key === 'Backspace') {
                buffer = buffer.slice(0, -1);
            }
        });

        el.addEventListener('blur', refocus);
        el.focus();
    });
}