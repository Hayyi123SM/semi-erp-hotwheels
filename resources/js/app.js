import Alpine from 'alpinejs';

import { uiToast } from './alpine/toast';
import { uiModal } from './alpine/modal';
import { registerScanDirective } from './alpine/scan';
import { registerBulkGrid } from './alpine/bulk-grid';
import { registerQuarantineCalculator } from './alpine/quarantine';
import { registerCart } from './alpine/cart';

window.Alpine = Alpine;

document.addEventListener('alpine:init', () => {
    Alpine.store('toast', uiToast());
    Alpine.store('modal', uiModal());

    registerScanDirective(Alpine);
    registerBulkGrid(Alpine);
    registerQuarantineCalculator(Alpine);
    registerCart(Alpine);
});

Alpine.start();