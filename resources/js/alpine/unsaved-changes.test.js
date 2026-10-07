import { describe, it, expect, vi, afterEach } from 'vitest';
import { unsavedChanges } from './unsaved-changes';

const HERE = 'http://localhost/master/penitip/1/edit';
const LIST = 'http://localhost/master/penitip';

/**
 * The shape a guarded page actually has: a form that opted in, a form that did
 * not, and links sitting outside both of them, because the ones the guard has
 * to catch are the sidebar, the breadcrumb and the back button.
 */
function mount({ guarded = true } = {}) {
    document.body.innerHTML = `
        <nav>
            <a href="${LIST}" id="sidebar">Data Penitip</a>
            <a href="#top" id="anchor">Ke atas</a>
            <a href="${LIST}" id="newtab" target="_blank">Data Penitip</a>
            <a href="${LIST}" id="download" download>Unduh</a>
            <a href="https://example.com/x" id="offsite">Luar</a>
            <a href="${LIST}" id="plain">Tidak dijaga</a>
        </nav>
        <form ${guarded ? 'data-guard' : ''} method="POST" action="${HERE}">
            <input type="hidden" name="_token" value="abc">
            <input type="text" name="name" value="Budi Santoso">
            <input type="checkbox" name="wa_opt_in" value="1">
            <select name="scheme_type"><option value="PERCENTAGE" selected>Persen</option></select>
            <input type="text" name="harga" value="10" data-guard-ignore>
            <input type="text" value="tanpa nama">
            <button type="submit" id="save">Simpan</button>
        </form>
        <form method="GET" action="${LIST}">
            <input type="search" name="q" value="">
        </form>
    `;

    return {
        form: document.querySelector('form[data-guard]'),
        name: document.querySelector('input[name="name"]'),
    };
}

function build({ leaving = true } = {}) {
    const location = { href: HERE, origin: 'http://localhost' };
    const notify = { dangerConfirm: vi.fn().mockResolvedValue(leaving) };
    const component = unsavedChanges({ location, notify });

    return { component, location, notify };
}

function clickEvent(target, extra = {}) {
    return { target, button: 0, defaultPrevented: false, preventDefault: vi.fn(), ...extra };
}

function unloadEvent() {
    return { preventDefault: vi.fn(), returnValue: undefined };
}

/** Puts a value in the way a reader would, and arms the guard off the back of it. */
function type(component, field, value) {
    component.arm(field.form);
    field.value = value;
}

/** Walks the full event path, the way the document listener would. */
function openVia(component, link, extra = {}) {
    const event = clickEvent(link, extra);

    component.onClick(event);

    return event;
}

/** Lets the question a click raised settle into its answer. */
async function settled() {
    await Promise.resolve();
    await Promise.resolve();
}

afterEach(() => {
    document.body.innerHTML = '';
    vi.restoreAllMocks();
});

describe('unsavedChanges', () => {
    describe('deciding whether anything is at stake', () => {
        it('leaves a form nobody has touched alone', () => {
            mount();
            const { component } = build();

            component.arm(document.querySelector('form[data-guard]'));

            expect(component.hasUnsavedChanges()).toBe(false);
        });

        it('notices a field that was edited', () => {
            const { form, name } = mount();
            const { component } = build();

            type(component, name, 'Budi Sudarsono');

            expect(component.hasUnsavedChanges()).toBe(true);
            expect(component.isDirty(form)).toBe(true);
        });

        it('forgets a field that was edited and then put back', () => {
            const { name } = mount();
            const { component } = build();

            type(component, name, 'Salah ketik');
            name.value = 'Budi Santoso';

            // Reverting is not a change worth warning about, which is why this
            // compares against the server's value instead of tracking keystrokes.
            expect(component.hasUnsavedChanges()).toBe(false);
        });

        it('counts a checkbox by whether it is ticked, not by its value', () => {
            mount();
            const { component } = build();
            const box = document.querySelector('input[name="wa_opt_in"]');

            component.arm(box.form);
            expect(component.hasUnsavedChanges()).toBe(false);

            box.checked = true;

            expect(component.hasUnsavedChanges()).toBe(true);
        });

        it('counts a select by the option that is chosen', () => {
            mount();
            const { component } = build();
            const select = document.querySelector('select[name="scheme_type"]');

            component.arm(select.form);
            select.value = 'NETT';

            expect(component.hasUnsavedChanges()).toBe(true);
        });

        it('counts a pending upload, which the field itself refuses to report', () => {
            mount();
            const { component } = build();
            document.querySelector('form[data-guard]')
                .insertAdjacentHTML('beforeend', '<input type="file" name="doc">');
            const file = document.querySelector('input[name="doc"]');

            component.arm(file.form);
            expect(component.hasUnsavedChanges()).toBe(false);

            // A file input empties its own value as soon as the picker closes.
            Object.defineProperty(file, 'files', {
                value: [new File(['x'], 'akta.pdf', { type: 'application/pdf' })],
                configurable: true,
            });

            expect(component.hasUnsavedChanges()).toBe(true);
        });

        it('ignores a field the view marked as not worth warning about', () => {
            mount();
            const { component } = build();

            type(component, document.querySelector('[data-guard-ignore]'), 'diubah');

            expect(component.hasUnsavedChanges()).toBe(false);
        });

        it('ignores a control with no name, because nothing would be submitted for it', () => {
            mount();
            const { component } = build();

            type(component, document.querySelector('input[value="tanpa nama"]'), 'diubah');

            expect(component.hasUnsavedChanges()).toBe(false);
        });

        it('leaves a form that never opted in alone', () => {
            mount();
            const { component } = build();
            const search = document.querySelector('form:not([data-guard]) input[name="q"]');

            // Typing a search and then clicking a sidebar link has to stay
            // silent, or the guard is an obstacle rather than a safety net.
            search.value = 'peni';

            expect(component.hasUnsavedChanges()).toBe(false);
        });

        it('notices a field the reader added to a form already on the page', () => {
            const { form } = mount();
            const { component } = build();

            component.arm(form);
            form.insertAdjacentHTML('beforeend', '<input type="text" name="baru" value="isi">');

            expect(component.hasUnsavedChanges()).toBe(true);
        });
    });

    describe('a click inside the app', () => {
        it('catches a link and holds the navigation', () => {
            const { name } = mount();
            const { component } = build();
            type(component, name, 'Budi Sudarsono');

            const event = openVia(component, document.getElementById('sidebar'));

            expect(event.preventDefault).toHaveBeenCalled();
            expect(component.pendingHref).toBe(LIST);
        });

        it('does not touch a link when there is nothing to lose', () => {
            mount();
            const { component } = build();

            const event = openVia(component, document.getElementById('sidebar'));

            expect(event.preventDefault).not.toHaveBeenCalled();
            expect(component.pendingHref).toBeNull();
        });

        it('leaves a link the reader asked to open in a new tab alone', () => {
            const { name } = mount();
            const { component } = build();
            type(component, name, 'Budi Sudarsono');

            const event = openVia(component, document.getElementById('newtab'));

            expect(event.preventDefault).not.toHaveBeenCalled();
        });

        it('leaves a download to the browser', () => {
            const { name } = mount();
            const { component } = build();
            type(component, name, 'Budi Sudarsono');

            expect(openVia(component, document.getElementById('download')).preventDefault).not.toHaveBeenCalled();
        });

        it('leaves a link off this site alone', () => {
            const { name } = mount();
            const { component } = build();
            type(component, name, 'Budi Sudarsono');

            expect(openVia(component, document.getElementById('offsite')).preventDefault).not.toHaveBeenCalled();
        });

        it('leaves a link the reader modified alone', () => {
            const { name } = mount();
            const { component } = build();
            type(component, name, 'Budi Sudarsono');

            const event = openVia(component, document.getElementById('sidebar'), { metaKey: true });

            expect(event.preventDefault).not.toHaveBeenCalled();
        });

        it('leaves a jump to an anchor on this page alone', () => {
            const { name } = mount();
            const { component } = build();
            type(component, name, 'Budi Sudarsono');

            expect(openVia(component, document.getElementById('anchor')).preventDefault).not.toHaveBeenCalled();
        });
    });

    describe('saving', () => {
        it('does not warn the reader about the exit they just asked for', () => {
            const { form, name } = mount();
            const { component } = build();
            type(component, name, 'Budi Sudarsono');

            component.onSubmit({ target: form });
            const event = unloadEvent();
            component.onBeforeUnload(event);

            // The worst possible moment for a prompt is the moment the reader
            // clicks Save: the form is definitionally dirty and the POST is
            // already on its way.
            expect(event.preventDefault).not.toHaveBeenCalled();
        });

        it('leaves a form that was turned away by validation still under watch', () => {
            const { form, name } = mount();
            const { component } = build();
            type(component, name, 'Budi Sudarsono');

            // A form stopped by HTML5 validation never reaches `submit`, so
            // nothing is flagged and the reader is still warned next time.
            expect(component.hasUnsavedChanges()).toBe(true);
        });

        it('leaves a form that is not the guarded one alone', () => {
            mount();
            const { component } = build();
            const search = document.querySelector('form:not([data-guard])');

            component.onSubmit({ target: search });

            expect(component.submitting.has(search)).toBe(false);
        });
    });

    describe('a real unload', () => {
        it('asks before the tab goes away with input in it', () => {
            const { name } = mount();
            const { component } = build();
            type(component, name, 'Budi Sudarsono');

            const event = unloadEvent();
            component.onBeforeUnload(event);

            expect(event.preventDefault).toHaveBeenCalled();
        });

        it('says nothing when there is nothing to lose', () => {
            mount();
            const { component } = build();

            const event = unloadEvent();
            component.onBeforeUnload(event);

            expect(event.preventDefault).not.toHaveBeenCalled();
        });
    });

    describe('answering the warning', () => {
        it('goes where the reader was headed, without asking a second time', async () => {
            const { name } = mount();
            const { component, location, notify } = build();
            type(component, name, 'Budi Sudarsono');

            openVia(component, document.getElementById('sidebar'));
            await settled();

            expect(location.href).toBe(LIST);
            expect(notify.dangerConfirm).toHaveBeenCalledTimes(1);
            // The unload this navigation causes is the one prompt the reader has
            // already agreed to.
            const event = unloadEvent();
            component.onBeforeUnload(event);
            expect(event.preventDefault).not.toHaveBeenCalled();
        });

        it('stays put and drops the destination when the reader backs out', async () => {
            const { name } = mount();
            const { component, location } = build({ leaving: false });
            type(component, name, 'Budi Sudarsono');

            openVia(component, document.getElementById('sidebar'));
            await settled();

            expect(component.pendingHref).toBeNull();
            expect(location.href).toBe(HERE);
        });

        it('keeps the form under watch after backing out, so the next click asks again', async () => {
            const { name } = mount();
            const { component, notify } = build({ leaving: false });
            type(component, name, 'Budi Sudarsono');

            openVia(component, document.getElementById('sidebar'));
            await settled();
            expect(component.hasUnsavedChanges()).toBe(true);

            openVia(component, document.getElementById('plain'));
            await settled();

            expect(notify.dangerConfirm).toHaveBeenCalledTimes(2);
        });

        it('asks with the wording the reader is shown', async () => {
            mount();
            const { component, notify } = build();
            type(component, document.querySelector('input[name="name"]'), 'Budi Sudarsono');

            openVia(component, document.getElementById('sidebar'));
            await settled();

            expect(notify.dangerConfirm).toHaveBeenCalledWith({
                title: 'Perubahan belum disimpan',
                description: expect.stringContaining('Isi form ini akan hilang'),
                confirmText: 'Ya, tinggalkan',
                cancelText: 'Tetap di sini',
            });
        });

        it('cancels the link before the question is answered', () => {
            const { name } = mount();
            const { component } = build();
            type(component, name, 'Budi Sudarsono');

            const event = openVia(component, document.getElementById('sidebar'));

            // Were this left to the awaited answer, the browser would follow the
            // link while the dialog was still opening.
            expect(event.preventDefault).toHaveBeenCalled();
        });

        it('asks again after a page restored from the back/forward cache', async () => {
            const { name } = mount();
            const { component, notify } = build();
            type(component, name, 'Budi Sudarsono');

            openVia(component, document.getElementById('sidebar'));
            await settled();

            // Coming back through the back/forward cache spends the allowance.
            component.onPageShow();
            openVia(component, document.getElementById('plain'));

            // Checked before the answer lands: the point is that this click is
            // held and put to the reader rather than slipping through on the
            // allowance the first decision bought.
            expect(component.pendingHref).toBe(LIST);
            expect(component.bypassed).toBe(false);

            await settled();
            expect(notify.dangerConfirm).toHaveBeenCalledTimes(2);
        });
    });

    describe('wiring', () => {
        it('listens on the document, because the links it catches are not in a form', () => {
            mount();
            const { component } = build();
            const add = vi.spyOn(document, 'addEventListener');

            component.init();

            const types = add.mock.calls.map(([type]) => type);
            expect(types).toEqual(expect.arrayContaining(['click', 'submit', 'focusin', 'input', 'change']));
            expect(add.mock.calls.find(([type]) => type === 'click')[2]).toBe(true);
        });

        it('stops listening when it goes away', () => {
            mount();
            const { component } = build();
            const remove = vi.spyOn(document, 'removeEventListener');
            component.init();

            component.destroy();

            expect(remove).toHaveBeenCalledWith('click', expect.any(Function), true);
        });

        it('arms off an event rather than on load, so a framework binding is not mistaken for an edit', () => {
            const { name } = mount();
            const { component } = build();
            // A select driven by x-model writes its own value while the page
            // settles, before the reader has touched anything.
            name.value = 'Budi Santoso';

            expect(component.hasUnsavedChanges()).toBe(false);

            component.onFocusIn({ target: name });
            expect(component.hasUnsavedChanges()).toBe(false);
        });

        it('counts an edit that arrived without a click, such as an autofill', () => {
            const { name } = mount();
            const { component } = build();

            name.value = 'Budi Santoso';
            component.onEdited({ target: name });

            expect(component.hasUnsavedChanges()).toBe(false);

            name.value = 'Budi Santoso, tabs';
            expect(component.hasUnsavedChanges()).toBe(true);
        });
    });
});
