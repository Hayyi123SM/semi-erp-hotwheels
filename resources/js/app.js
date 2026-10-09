import Alpine from 'alpinejs';

import { createNotify, registerToast } from './notify';
import './thermal';
import * as format from './format';
import { sidebarLayout } from './alpine/sidebar';
import { registerNetwork } from './alpine/network';
import { rowConfirm } from './alpine/row-confirm';
import { dataTable } from './alpine/data-table';
import { dataTableForm } from './alpine/data-table-form';
import { unsavedChanges } from './alpine/unsaved-changes';
import { registerScrollLock } from './alpine/scroll-lock';
import { seriManager } from './alpine/seri-manager';
import { registerScanDirective } from './alpine/scan';
import { registerMoneyDirective, registerMoneyModelDirective } from './alpine/money';
import { registerBulkGrid } from './alpine/bulk-grid';
import { searchableSelect } from './alpine/searchable-select';
import { registerQuarantineCalculator } from './alpine/quarantine';
import { registerCart } from './alpine/cart';
import { productPicker } from './alpine/product-picker';
import { stockInPribadiForm } from './alpine/stock-in-pribadi';
import { inboundGrid } from './alpine/inbound-grid';
import { pinDialog, pinDialogForm } from './alpine/pin-dialog';
import { labelQueue } from './alpine/label-queue';
import { reprintForm } from './alpine/reprint-form';
import { shiftCloseForm } from './alpine/shift-close-form';
import { importDropzone } from './alpine/import-dropzone';
import { stokOpname } from './alpine/stok-opname';

window.Alpine = Alpine;

// Reachable from an Alpine expression, so `x-text="format.rupiah(item.price)"`
// reads the same as the server-rendered `Format::rupiah($price)` beside it.
// The twelve call sites this replaced each concatenated 'Rp' onto a
// hand-rolled `toLocaleString`, which is how a cell and its own total drift
// apart.
window.format = format;

document.addEventListener('alpine:init', () => {
    // One object is both the toast store the layout renders and the dialog
    // helper the pages call, so `items`/`push`/`dismiss` stay exactly as the
    // existing call sites use them while `confirm` sits on the same thing.
    const notify = createNotify();

    // The proxy, not the object that was registered. `notify` is now only the
    // argument; nothing else in this file is allowed to touch it.
    const toast = registerToast(Alpine, notify);

    registerScrollLock(Alpine);
    registerNetwork(Alpine);

    // Read before Alpine renders, so a message that survived a redirect is in
    // the first paint rather than arriving a frame later.
    toast.drainFlash();

    Alpine.data('sidebarLayout', sidebarLayout);
    Alpine.data('rowConfirm', rowConfirm);
    Alpine.data('dataTable', dataTable);
    Alpine.data('dataTableForm', dataTableForm);
    Alpine.data('unsavedChanges', unsavedChanges);
    Alpine.data('seriManager', seriManager);
    Alpine.data('searchableSelect', searchableSelect);
    Alpine.data('inboundGrid', inboundGrid);
    Alpine.data('labelQueue', labelQueue);
    Alpine.data('reprintForm', reprintForm);
    Alpine.data('shiftCloseForm', shiftCloseForm);
    Alpine.data('importDropzone', importDropzone);
    Alpine.data('stokOpname', stokOpname);
    // The URL is passed in rather than read from the markup: the panel is lifted
    // into a dialog, so the route has to reach it as an argument.
    Alpine.data('productPicker', productPicker);

    registerScanDirective(Alpine);
    registerMoneyDirective(Alpine);
    registerMoneyModelDirective(Alpine);
    registerBulkGrid(Alpine);
    registerQuarantineCalculator(Alpine);
    registerCart(Alpine);

    // A form that needs an Owner's permission asks for a token before it
    // submits, rather than carrying a PIN field of its own. On the window, and
    // not as a scope, because nothing on the page should have to own it: a
    // label print asks from an ordinary button and a POS asks from a keypress.
    Alpine.data('pinDialogForm', pinDialogForm);
    Alpine.data('stockInPribadiForm', stockInPribadiForm);
    window.pin = pinDialog({ Alpine });
});

Alpine.start();


