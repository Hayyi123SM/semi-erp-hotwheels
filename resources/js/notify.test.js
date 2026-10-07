import { effect, reactive } from "@vue/reactivity";
import { describe, it, expect, vi, afterEach, beforeEach } from "vitest";

/**
 * notify talks to SweetAlert2 through an injected `fire`, so these tests pin
 * what this project decides -- which options go out, and what happens to the
 * page while a dialog is up -- without asserting the library's own behaviour.
 */
async function freshNotify(overrides = {}) {
    vi.resetModules();
    const { createNotify } = await import("./notify");

    return createNotify(overrides);
}

async function freshRegisterToast() {
    vi.resetModules();

    return (await import("./notify")).registerToast;
}

/**
 * Alpine's `store()`, and only that.
 *
 * Read from the library's own source: `stores` is a `reactive()` container, a
 * set call stores the value untouched, and a get call hands back a proxy. That
 * asymmetry is the whole bug, so a stand-in that got it wrong would make these
 * tests agree with a broken build.
 */
function fakeAlpine() {
    const stores = reactive({});

    return {
        store(name, value) {
            if (value === undefined) {
                return stores[name];
            }

            stores[name] = value;
        },
    };
}

/** Runs a full open/close cycle, the way a real dialog does. */
function fakeFire(result = { isConfirmed: true }) {
    const calls = [];
    const popup = document.createElement("div");

    const fire = async (params) => {
        calls.push(params);
        params.didOpen?.(popup);
        params.didClose?.(popup);

        return result;
    };

    fire.calls = calls;
    fire.popup = popup;

    return fire;
}

describe("notify: messages", () => {
    let notify;

    beforeEach(async () => {
        vi.useFakeTimers();
        notify = await freshNotify();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it("gives every message a distinct id, so two in one millisecond still get two rows", () => {
        notify.push("Satu", "success");
        notify.push("Dua", "info");

        expect(notify.items.map((i) => i.id)).toEqual([1, 2]);
    });

    it("carries the type and its glyph through to the row", () => {
        notify.push("Tersimpan", "success");

        expect(notify.items[0]).toMatchObject({
            message: "Tersimpan",
            type: "success",
            icon: "✓",
        });
    });

    it("falls back to info for a type it does not know, rather than claiming success", () => {
        notify.push("Aneh", "kebal");

        expect(notify.items[0].type).toBe("info");
    });

    it("raises nothing for a message with no words in it", () => {
        expect(notify.push("   ", "success")).toBeNull();
        expect(notify.push(null, "error")).toBeNull();
        expect(notify.push(undefined, "error")).toBeNull();
        expect(notify.items).toHaveLength(0);
    });

    it("drops the oldest once the stack would cover the page", () => {
        for (let n = 1; n <= 7; n += 1) {
            notify.push(`Pesan ${n}`, "info");
        }

        expect(notify.items).toHaveLength(5);
        expect(notify.items[0].message).toBe("Pesan 3");
        expect(notify.items[4].message).toBe("Pesan 7");
    });

    it("takes a message back when its time is up", () => {
        notify.push("Sementara", "info");
        expect(notify.items).toHaveLength(1);

        vi.advanceTimersByTime(3000);
        expect(notify.items).toHaveLength(0);
    });

    /**
     * A save that worked is finished news. A failure is the only record anyone
     * has of what went wrong, and two seconds is not long enough to read a
     * sentence and act on it -- so the two are not given the same clock.
     */
    it("holds a failure longer than a success, because it is the one worth reading", () => {
        notify.push("Tersimpan", "success");
        notify.push("Gagal menyimpan", "error");
        notify.push("Hati-hati", "warning");
        notify.push("Catatan", "info");

        vi.advanceTimersByTime(3000);
        expect(notify.items.map((i) => i.message)).toEqual([
            "Gagal menyimpan",
            "Hati-hati",
        ]);

        vi.advanceTimersByTime(3000);
        expect(notify.items).toHaveLength(0);
    });

    it("leaves a message alone one tick before its time is up", () => {
        notify.push("Hampir selesai", "info");

        vi.advanceTimersByTime(2999);
        expect(notify.items).toHaveLength(1);
    });

    it("keeps the toast methods reading as the same call", () => {
        notify.success("Satu");
        notify.error("Dua");
        notify.warning("Tiga");
        notify.info("Empat");

        expect(notify.items.map((i) => i.type)).toEqual([
            "success",
            "error",
            "warning",
            "info",
        ]);
    });
});

describe("notify: what a redirect left behind", () => {
    let notify;

    beforeEach(async () => {
        notify = await freshNotify();
    });

    it("raises a flashed message", () => {
        expect(
            notify.ingest({ type: "success", message: "Produk disimpan" }),
        ).toBe(1);
        expect(notify.items[0]).toMatchObject({
            type: "success",
            message: "Produk disimpan",
        });
    });

    it("reads a bare string, which is what a careless redirect leaves", () => {
        notify.ingest("Selesai");

        expect(notify.items[0]).toMatchObject({
            type: "info",
            message: "Selesai",
        });
    });

    it("defaults to info when the type is missing or unknown, never to success", () => {
        notify.ingest({ message: "Tanpa tipe" });
        notify.ingest({ type: "sukses", message: "Typo" });

        expect(notify.items.map((i) => i.type)).toEqual(["info", "info"]);
    });

    it("reads a list, because two messages in one redirect is normal", () => {
        notify.ingest([
            { type: "success", message: "Satu" },
            { type: "error", message: "Dua" },
        ]);

        expect(notify.items.map((i) => i.message)).toEqual(["Satu", "Dua"]);
    });

    it("ignores an entry with nothing to say instead of rendering an empty row", () => {
        expect(notify.ingest({ type: "error" })).toBe(0);
        expect(notify.ingest([null, 42, { message: "" }])).toBe(0);
        expect(notify.items).toHaveLength(0);
    });
});

describe("notify: taking one away by hand", () => {
    let notify;

    beforeEach(async () => {
        vi.useFakeTimers();
        notify = await freshNotify();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it("removes only the row it was asked for", () => {
        const first = notify.push("Satu", "info");
        notify.push("Dua", "info");
        notify.push("Tiga", "info");

        notify.dismiss(first);

        expect(notify.items.map((i) => i.message)).toEqual(["Dua", "Tiga"]);
    });

    it("ignores an id that is already gone, rather than emptying the stack", () => {
        notify.push("Satu", "info");
        notify.dismiss(999);

        expect(notify.items).toHaveLength(1);
    });

    /**
     * The clock has to be cleared on the way out, or a toast dismissed by hand
     * leaves a timer running down to an id that is no longer on screen. It is
     * harmless today -- the second removal finds nothing to filter -- and it is
     * exactly the kind of harmless thing that is still wrong: one more live
     * timer per dismissed toast, and a `pause` that would then act on a clock
     * belonging to a row that has gone.
     */
    it("stops the clock when a message is taken away by hand", () => {
        const id = notify.push("Sementara", "info");
        expect(vi.getTimerCount()).toBe(1);

        notify.dismiss(id);
        expect(vi.getTimerCount()).toBe(0);
    });

    it("stops the clock of a message the stack limit pushed out", () => {
        for (let n = 1; n <= 7; n += 1) {
            notify.push(`Pesan ${n}`, "info");
        }

        // Five rows on screen, five clocks: the two the limit dropped must not
        // still be counting down to ids nothing is rendering.
        expect(vi.getTimerCount()).toBe(5);
    });

    it("stops every clock when the whole stack is taken down", () => {
        notify.push("Satu", "info");
        notify.push("Dua", "error");
        expect(vi.getTimerCount()).toBe(2);

        notify.dismissAll();

        expect(notify.items).toHaveLength(0);
        expect(vi.getTimerCount()).toBe(0);
    });
});

describe("notify: holding a message still while it is read", () => {
    let notify;

    beforeEach(async () => {
        vi.useFakeTimers();
        notify = await freshNotify();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it("does not take a hovered message away", () => {
        const id = notify.push("Sedang dibaca", "info");

        notify.pause(id);
        vi.advanceTimersByTime(60_000);

        expect(notify.items).toHaveLength(1);
    });

    /**
     * Resuming with a fresh duration would mean a pointer travelling across a
     * stack holds every toast it touches open for its full life, which is the
     * opposite of the point. The clock picks up where it left off.
     */
    it("resumes with the time it had left, not a fresh full duration", () => {
        const id = notify.push("Sedang dibaca", "info");

        // A second of the three goes by before the pointer arrives, so there is
        // something to have been spent.
        vi.advanceTimersByTime(1000);
        notify.pause(id);
        notify.resume(id);

        // Two seconds were left, not three.
        vi.advanceTimersByTime(1999);
        expect(notify.items).toHaveLength(1);

        vi.advanceTimersByTime(1);
        expect(notify.items).toHaveLength(0);
    });

    it("survives being paused and resumed more than once", () => {
        const id = notify.push("Berubah-ubah pikiran", "info");

        vi.advanceTimersByTime(1500);
        notify.pause(id);
        vi.advanceTimersByTime(1500);
        notify.resume(id);

        vi.advanceTimersByTime(1000);
        notify.pause(id);
        expect(notify.items).toHaveLength(1);

        // A long pause, which is the point: the reader wandered off and came back.
        vi.advanceTimersByTime(60_000);
        notify.resume(id);
        expect(notify.items).toHaveLength(1);

        // 2000ms were left when it was paused the second time.
        vi.advanceTimersByTime(2000);
        expect(notify.items).toHaveLength(0);
    });

    it("is a no-op for a message that is already gone", () => {
        notify.push("Hiang", "info");

        // A pointer arriving in the same tick the timer fires must not strand a
        // row on screen with nothing left to take it away.
        expect(() => {
            notify.pause(999);
            notify.resume(999);
        }).not.toThrow();

        vi.advanceTimersByTime(3000);
        expect(notify.items).toHaveLength(0);
    });

    it("does not restart a message that was never paused", () => {
        const id = notify.push("Utuh", "info");

        notify.resume(id);
        vi.advanceTimersByTime(3000);

        expect(notify.items).toHaveLength(0);
    });
});

describe("notify: reading the flash out of the page", () => {
    it("raises the payload and takes the node away", async () => {
        const notify = await freshNotify();
        document.body.innerHTML =
            '<script type="application/json" id="flash-toast">{"type":"error","message":"Gagal simpan"}</script>';

        expect(notify.drainFlash()).toBe(1);
        expect(notify.items[0].message).toBe("Gagal simpan");
        expect(document.getElementById("flash-toast")).toBeNull();
    });

    it("survives a payload it cannot parse, and still cleans up", async () => {
        const notify = await freshNotify();
        document.body.innerHTML =
            '<script type="application/json" id="flash-toast">{not json</script>';

        expect(notify.drainFlash()).toBe(0);
        expect(notify.items).toHaveLength(0);
        expect(document.getElementById("flash-toast")).toBeNull();
    });

    it("does nothing at all on a page that flashed nothing", async () => {
        const notify = await freshNotify();
        document.body.innerHTML = "<main>halaman biasa</main>";

        expect(notify.drainFlash()).toBe(0);
    });
});

/**
 * The bug this tail exists for.
 *
 * Every test above reads `notify.items` directly, and that is the trap: an array
 * can be perfectly correct while the page never learns it changed. The toast
 * that stayed on screen forever had an *empty* `items` the whole time -- it was
 * the DOM that was never told. A test that never looks at the DOM walks straight
 * past the failure.
 *
 * So these put the object where Alpine puts it and watch what a subscriber sees.
 * Alpine's `store()` is a `reactive()` container that keeps the object it was
 * given in raw form and only wraps it in a proxy when it is *read*, so a write
 * to the raw object is invisible to the template and a write through the proxy
 * is not. `registerStore` is those two lines, which makes the difference under
 * test the same one the browser makes.
 */
describe("notify: the store the layout actually watches", () => {
    let notify;
    let raw;
    let store;
    let seen;

    /**
     * The two lines Alpine's `store()` runs, and the whole of the trap: `raw` is
     * what `createNotify()` handed over and `store` is what the template reads.
     * They are different objects, and only one of them is wired up.
     */
    function registerStore(notifyObject) {
        const stores = reactive({});

        stores.toast = notifyObject;
        raw = notifyObject;
        store = stores.toast;

        seen = [];
        effect(() => {
            seen.push(store.items.length);
        });
    }

    beforeEach(async () => {
        vi.useFakeTimers();
        notify = await freshNotify();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it("is not the raw object, which is the entire reason a flash used to stick", () => {
        registerStore(notify);

        expect(store).not.toBe(raw);
    });

    /**
     * The regression, stated as behaviour: a message that arrived with a
     * redirect, and a clock that removes it three seconds later, has to produce
     * an update the layout can render for each of those two moments.
     *
     * Before the fix the raise happened before the first paint, so the row
     * appeared and nobody suspected anything; the removal wrote to the raw
     * object, produced no update, and the row sat there until a reload.
     */
    it("tells a subscriber when a flashed message is raised, and again when its time is up", () => {
        registerStore(notify);
        document.body.innerHTML =
            '<script type="application/json" id="flash-toast">{"type":"success","message":"Tersimpan"}</script>';

        store.drainFlash();
        expect(seen.at(-1)).toBe(1);

        vi.advanceTimersByTime(3000);

        expect(store.items).toHaveLength(0);
        expect(seen.at(-1)).toBe(0);
    });

    it("tells a subscriber when a message is taken away by hand", () => {
        registerStore(notify);

        const id = store.push("Tersimpan", "success");
        expect(seen.at(-1)).toBe(1);

        store.dismiss(id);

        expect(seen.at(-1)).toBe(0);
    });

    it("tells a subscriber when a paused message finally goes", () => {
        registerStore(notify);

        const id = store.push("Sedang dibaca", "info");

        store.pause(id);
        vi.advanceTimersByTime(60_000);
        expect(seen.at(-1)).toBe(1);

        store.resume(id);
        vi.advanceTimersByTime(3000);

        expect(seen.at(-1)).toBe(0);
    });

    it("tells a subscriber when the stack is emptied", () => {
        registerStore(notify);

        store.push("Satu", "info");
        store.push("Dua", "error");
        expect(seen.at(-1)).toBe(2);

        store.dismissAll();

        expect(seen.at(-1)).toBe(0);
    });
});

describe("notify: asking a question", () => {
    it("answers true only when the question was confirmed", async () => {
        const yes = await freshNotify({
            fire: fakeFire({ isConfirmed: true }),
        });
        const no = await freshNotify({
            fire: fakeFire({ isConfirmed: false }),
        });
        const dismissed = await freshNotify({
            fire: fakeFire({ isDismissed: true }),
        });

        expect(await yes.ask({ title: "Hapus?" })).toBe(true);
        expect(await no.ask({ title: "Hapus?" })).toBe(false);
        expect(await dismissed.ask({ title: "Hapus?" })).toBe(false);
    });

    it("never leaves the scrollbar padding to SweetAlert2, which fights the shared lock", async () => {
        const fire = fakeFire();
        const notify = await freshNotify({ fire });

        await notify.ask({ title: "Hapus?" });

        expect(fire.calls[0].scrollbarPadding).toBe(false);
    });

    it("passes a description as text, which SweetAlert2 writes without parsing it as markup", async () => {
        const fire = fakeFire();
        const notify = await freshNotify({ fire });

        await notify.ask({
            title: "Hapus?",
            description: "<img src=x onerror=alert(1)>",
        });

        expect(fire.calls[0].text).toBe("<img src=x onerror=alert(1)>");
        expect(fire.calls[0].html).toBeUndefined();
    });

    it("focuses the way out of a destructive question, not the way through it", async () => {
        const fire = fakeFire();
        const notify = await freshNotify({ fire });

        await notify.ask({ title: "Hapus?", danger: true });

        expect(fire.calls[0].focusCancel).toBe(true);
        expect(fire.calls[0].customClass).toEqual({
            confirmButton: "swal-confirm-danger",
        });
    });

    it("leaves focus on the confirmation for an ordinary question", async () => {
        const fire = fakeFire();
        const notify = await freshNotify({ fire });

        await notify.ask({ title: "Lanjut?" });

        expect(fire.calls[0].focusCancel).toBe(false);
    });

    it("ignores a click on the backdrop when the question destroys something", async () => {
        const fire = fakeFire();
        const notify = await freshNotify({ fire });

        await notify.dangerConfirm({ title: "Arsipkan penitip?" });

        // Clicking away is the reflex people have for a modal, and on a warning
        // about losing input it is the reflex that would lose it. The row would
        // stay looking unarchived, with no sign anything had been asked.
        expect(fire.calls[0].allowOutsideClick).toBe(false);
    });

    it("still lets an ordinary question be dismissed by clicking away", async () => {
        const fire = fakeFire();
        const notify = await freshNotify({ fire });

        await notify.ask({ title: "Lanjut?" });

        // Here dismissing and answering no are the same thing, so the shortcut
        // costs nothing and the one way out is the one that is already there.
        expect(fire.calls[0].allowOutsideClick).toBe(true);
    });

    it("will not let a stray Escape wave through a destructive question", async () => {
        const fire = fakeFire();
        const notify = await freshNotify({ fire });

        await notify.dangerConfirm({ title: "Arsipkan penitip?" });

        // Esc is the same reflex as a backdrop click, and it lands in the same
        // place: dismissed, so the answer reads as no, and the row is still
        // there looking unarchived with no sign anything had been asked.
        expect(fire.calls[0].allowEscapeKey).toBe(false);
    });

    it("still lets an ordinary question be dismissed with Escape", async () => {
        const fire = fakeFire();
        const notify = await freshNotify({ fire });

        await notify.ask({ title: "Lanjut?" });

        expect(fire.calls[0].allowEscapeKey).toBe(true);
    });

    it("keeps a destructive question down to its two answers", async () => {
        const fire = fakeFire();
        const notify = await freshNotify({ fire });

        await notify.dangerConfirm({ title: "Hapus?" });

        // No X on the dialog whose answer destroys something. It would be one
        // more place for the reflex to land, and the Cancel button it sits
        // beside is already that answer.
        expect(fire.calls[0].showCloseButton).toBe(false);
        expect(fire.calls[0].showConfirmButton).toBe(true);
        expect(fire.calls[0].showCancelButton).not.toBe(false);
    });

    it("offers the X on an ordinary question, where it only ever means no", async () => {
        const fire = fakeFire();
        const notify = await freshNotify({ fire });

        await notify.ask({ title: "Lanjut?" });

        expect(fire.calls[0].showCloseButton).toBe(true);
    });

    it("leaves a destructive question two ways out, so closing it is a choice", async () => {
        const fire = fakeFire();
        const notify = await freshNotify({ fire });

        await notify.dangerConfirm({ title: "Hapus?" });

        // Blocking the backdrop, Escape and the X all at once would be a trap
        // with no way out if the buttons were ever unreachable. Two buttons
        // remain, and focus lands on the one that destroys nothing.
        expect(fire.calls[0].focusCancel).toBe(true);
        expect(fire.calls[0].showCancelButton).toBe(true);
        expect(fire.calls[0].allowOutsideClick).toBe(false);
        expect(fire.calls[0].allowEscapeKey).toBe(false);
        expect(fire.calls[0].showCloseButton).toBe(false);
    });

    it("answers no however the question was dismissed, backdrop included", async () => {
        const dismissed = await freshNotify({
            fire: fakeFire({ isDismissed: true }),
        });

        // Belt and braces on top of `allowOutsideClick`: the promise's contract
        // is that only a confirmed answer is a yes, whatever closed the popup.
        expect(await dismissed.dangerConfirm({ title: "Hapus?" })).toBe(false);
    });

    it("spins on the button while the work behind a yes is still running", async () => {
        const fire = fakeFire();
        const notify = await freshNotify({ fire });

        await notify.dangerConfirm({
            title: "Hapus?",
            onConfirm: async () => {},
        });

        // The loader is what covers the confirm button between the press and
        // the request going out, which is the window a second press gets in.
        expect(fire.calls[0].showLoaderOnConfirm).toBe(true);
        expect(fire.calls[0].preConfirm).toBeTypeOf("function");
    });

    it("does not put a spinner on a question that is only asking", async () => {
        const fire = fakeFire();
        const notify = await freshNotify({ fire });

        await notify.ask({ title: "Lanjut?" });

        // A loader with no promise to wait on would show a busy button for a
        // single frame on every question in the app.
        expect(fire.calls[0].showLoaderOnConfirm).toBeUndefined();
        expect(fire.calls[0].preConfirm).toBeUndefined();
    });

    it("leaves the work to the dialog rather than running it on its own", async () => {
        const fire = fakeFire({ isConfirmed: false });
        const notify = await freshNotify({ fire });
        const onConfirm = vi.fn();

        await notify.dangerConfirm({ title: "Hapus?", onConfirm });

        // The helper wires the work up and steps back. A no, a dismissal and a
        // backdrop click are all SweetAlert2's to decide, and a helper that ran
        // the work itself would destroy the row before anyone had answered.
        expect(onConfirm).not.toHaveBeenCalled();
    });

    it("holds the dialog open when the work reports it did not happen", async () => {
        const fire = fakeFire();
        const notify = await freshNotify({ fire });

        await notify.dangerConfirm({ title: "Hapus?", onConfirm: () => false });

        // SweetAlert2 reads anything but `false` as done, so this is the one
        // value that leaves the reader looking at the question rather than at a
        // success that never arrived.
        expect(await fire.calls[0].preConfirm()).toBe(false);
    });

    it("closes on any other outcome, including a work that returns nothing", async () => {
        const fire = fakeFire();
        const notify = await freshNotify({ fire });

        await notify.dangerConfirm({
            title: "Hapus?",
            onConfirm: () => undefined,
        });

        expect(await fire.calls[0].preConfirm()).toBe(true);
    });

    it("waits for the work before letting the dialog go, rather than firing and forgetting", async () => {
        const fire = fakeFire();
        const notify = await freshNotify({ fire });
        let finished = false;

        await notify.dangerConfirm({
            title: "Hapus?",
            onConfirm: async () => {
                await new Promise((resolve) => setTimeout(resolve, 10));
                finished = true;
            },
        });

        // The hook hands SweetAlert2 a promise, not a call it walks away from.
        // An un-awaited preConfirm would close the dialog the instant it ran,
        // which is the gap the loader is supposed to cover.
        const settled = fire.calls[0].preConfirm();
        expect(settled).toBeInstanceOf(Promise);
        expect(finished).toBe(false);

        await settled;
        expect(finished).toBe(true);
    });

    it("locks the body for exactly as long as it is up", async () => {
        const seen = [];
        const fire = async (params) => {
            params.didOpen?.();
            seen.push({
                moment: "while open",
                overflow: document.body.style.overflow,
            });
            params.didClose?.();
            seen.push({
                moment: "after close",
                overflow: document.body.style.overflow,
            });

            return { isConfirmed: true };
        };
        const notify = await freshNotify({ fire });

        await notify.ask({ title: "Hapus?" });

        expect(seen).toEqual([
            { moment: "while open", overflow: "hidden" },
            { moment: "after close", overflow: "" },
        ]);
    });

    it("leaves another overlay holding the page when a dialog opens and closes over it", async () => {
        // The order that used to leave the page permanently frozen: the drawer
        // takes the lock, the dialog snapshots a body that is already hidden and
        // puts that value back on the way out, and the drawer's own release
        // then clears only `overflow`, leaving the dialog's `overflowY` behind.
        const { acquireBodyLock, releaseBodyLock } =
            await import("./alpine/scroll-lock");
        const fire = fakeFire();
        const notify = await freshNotify({ fire });

        acquireBodyLock();
        expect(document.body.style.overflow).toBe("hidden");

        await notify.ask({ title: "Hapus?" });
        expect(document.body.style.overflow).toBe("hidden");

        releaseBodyLock();
        expect(document.body.style.overflow).toBe("");
        expect(document.body.style.overflowY).toBe("");
    });

    it("cannot release a lock it never took when it is dismissed before it opens", async () => {
        const { acquireBodyLock, releaseBodyLock } =
            await import("./alpine/scroll-lock");
        // didOpen never runs, because the dialog was dismissed mid-animation.
        const fire = async (params) => {
            params.didClose?.();

            return { isConfirmed: false };
        };
        const notify = await freshNotify({ fire });

        acquireBodyLock();

        expect(await notify.ask({ title: "Hapus?" })).toBe(false);
        expect(document.body.style.overflow).toBe("hidden");

        releaseBodyLock();
        expect(document.body.style.overflow).toBe("");
    });
});

describe("notify: a dialog with a body of our own markup", () => {
    /** Alpine is not booted in these tests, so the tree walk is stubbed. */
    async function modalNotify(overrides = {}) {
        return freshNotify({
            initTree: vi.fn(),
            destroyTree: vi.fn(),
            ...overrides,
        });
    }

    it("keeps the page underneath from hearing the keyboard", async () => {
        const fire = fakeFire();
        const notify = await modalNotify({ fire });

        await notify.modal({ title: "Cari Produk", html: "", size: "xl" });

        // The register binds Escape on window to empty the cart, and F2/F8 to open
        // the picker and take payment. A keystroke inside an open dialog bubbles
        // straight past SweetAlert2 -- it only listens on its own popup -- so
        // without this, backing out of a search would also discard the sale.
        expect(fire.calls[0].stopKeydownPropagation).toBe(true);
    });

    it("walks the subtree on open and tears it down on close", async () => {
        const initTree = vi.fn();
        const destroyTree = vi.fn();
        const fire = fakeFire();
        const notify = await modalNotify({ fire, initTree, destroyTree });

        await notify.modal({
            title: "Kelola Seri",
            html: '<form x-data="seriManager()"></form>',
        });

        expect(initTree).toHaveBeenCalledWith(fire.popup);
        expect(destroyTree).toHaveBeenCalledWith(fire.popup);
    });

    it("keeps the footprint the modal component used to have", async () => {
        const fire = fakeFire();
        const notify = await modalNotify({ fire });

        await notify.modal({ title: "Kelola Seri", html: "", size: "lg" });
        expect(fire.calls[0].width).toBe("42rem");

        await notify.modal({ title: "Kecil", html: "", size: "sm" });
        expect(fire.calls[1].width).toBe("28rem");
    });

    it("falls back rather than showing a dialog of no width for a size nobody asked for", async () => {
        const fire = fakeFire();
        const notify = await modalNotify({ fire });

        await notify.modal({ title: "?", html: "", size: "enorme" });

        expect(fire.calls[0].width).toBe("32rem");
    });

    it("shows no buttons of its own unless the caller supplies one to do the work", async () => {
        const fire = fakeFire();
        const notify = await modalNotify({ fire });

        await notify.modal({ title: "Panel", html: "<p>isi</p>" });
        expect(fire.calls[0].showConfirmButton).toBe(false);
        expect(fire.calls[0].showCancelButton).toBe(false);
    });

    it("carries a description through", async () => {
        const fire = fakeFire();
        const notify = await modalNotify({ fire });

        await notify.modal({
            title: "Kelola Seri",
            description: "Tambah, ubah, atau hapus seri.",
        });

        expect(fire.calls[0].description).toBe(
            "Tambah, ubah, atau hapus seri.",
        );
    });
});

describe("notify: a dialog built from a template on the page", () => {
    function pageWithTemplate(id, innerHTML) {
        document.body.innerHTML = `<template id="${id}">${innerHTML}</template>`;

        return document;
    }

    it("lifts the body out of the template, so the trigger stays one expression", async () => {
        const fire = fakeFire();
        const doc = pageWithTemplate(
            "seri-panel",
            '<div x-data="seriManager()">isi</div>',
        );
        const notify = await freshNotify({
            fire,
            document: doc,
            initTree: vi.fn(),
            destroyTree: vi.fn(),
        });

        await notify.templateModal("seri-panel", {
            title: "Kelola Seri",
            size: "lg",
        });

        expect(fire.calls[0].html).toBe(
            '<div x-data="seriManager()">isi</div>',
        );
        expect(fire.calls[0].width).toBe("42rem");
    });

    it("passes the rest of the options through untouched", async () => {
        const fire = fakeFire();
        const doc = pageWithTemplate("seri-panel", "<p>isi</p>");
        const notify = await freshNotify({
            fire,
            document: doc,
            initTree: vi.fn(),
            destroyTree: vi.fn(),
        });

        await notify.templateModal("seri-panel", {
            title: "Kelola Seri",
            size: "lg",
        });

        expect(fire.calls[0].title).toBe("Kelola Seri");
    });

    it("opens nothing, and says which template is missing", async () => {
        const fire = fakeFire();
        const warn = vi.spyOn(console, "warn").mockImplementation(() => {});
        const doc = pageWithTemplate("seri-panel", "<p>isi</p>");
        const notify = await freshNotify({
            fire,
            document: doc,
            initTree: vi.fn(),
            destroyTree: vi.fn(),
        });

        const result = await notify.templateModal("a-tile-that-was-renamed", {
            title: "Kelola Seri",
        });

        // An empty dialog reads as a broken page, so the name goes in the
        // console instead.
        expect(fire.calls).toHaveLength(0);
        expect(result).toBeNull();
        expect(warn).toHaveBeenCalledWith(
            expect.stringContaining("a-tile-that-was-renamed"),
        );
    });
});

describe("registerToast: the one handle anything else is given", () => {
    let notify;
    let registerToast;
    let Alpine;
    let toast;

    beforeEach(async () => {
        vi.useFakeTimers();
        registerToast = await freshRegisterToast();
        notify = await freshNotify();
        Alpine = fakeAlpine();
    });

    afterEach(() => {
        vi.useRealTimers();
        delete window.notify;
    });

    it("hands back the reactive store rather than the object it registered", () => {
        toast = registerToast(Alpine, notify);

        expect(toast).not.toBe(notify);
        expect(toast).toBe(Alpine.store("toast"));
    });

    /**
     * The regression, at the line it actually happened on.
     *
     * Every create, update and delete in this application flashes a message and
     * then redirects, and that flash is the one path that used to raise its
     * toast on the raw object. The raise painted -- the layout's first render
     * read the array it found -- so nothing looked wrong. The removal three
     * seconds later did not, and the row stayed until the page was reloaded.
     */
    it("takes a flashed message off the screen when its time is up", () => {
        toast = registerToast(Alpine, notify);

        const seen = [];
        const stop = effect(() => {
            seen.push(Alpine.store("toast").items.length);
        });

        document.body.innerHTML =
            '<script type="application/json" id="flash-toast">{"type":"success","message":"Konsignor tersimpan"}</script>';

        toast.drainFlash();
        expect(Alpine.store("toast").items).toHaveLength(1);
        expect(seen.at(-1)).toBe(1);

        vi.advanceTimersByTime(3000);

        expect(Alpine.store("toast").items).toHaveLength(0);
        expect(seen.at(-1)).toBe(0);
        expect(seen.length).toBeGreaterThanOrEqual(3);

        stop();
    });

    it("leaves nothing else holding a blind handle onto the raw object", () => {
        toast = registerToast(Alpine, notify);

        // `row-confirm.js` and `unsaved-changes.js` both fall back to
        // `window.notify`, so a raw object left there would be the same bug
        // arriving through the front door later.
        expect(window.notify).toBe(toast);
    });
});
