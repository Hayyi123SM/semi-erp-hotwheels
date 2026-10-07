/**
 * Reference-counted body scroll lock, shared by every overlay.
 *
 * The drawer, the modal and the row-confirm dialog each had their own
 * `document.body.style.overflow` write, so the first one to close unlocked the
 * page while the others were still up. The counter here makes the last holder
 * out turn the lights off.
 *
 * Registered as the `$lock` magic, so the holder state lives per element:
 *
 *   x-effect="$lock.set(open)"   re-runs freely, acquiring only once
 *   destroy()   { this.$lock.set(false); }   releases if the element is removed
 *
 * SweetAlert2 is the odd one out: it locks the body itself and offers no way
 * to opt out, writing `overflowY` and `paddingRight` inline. So the two
 * properties it touches are owned here as well, and this module is the only
 * thing that writes them. `overflowY` in particular is set explicitly rather
 * than left to the `overflow` shorthand, because a dialog that opened while
 * the drawer was already locked restores `overflowY` from the value it
 * computed on open and would otherwise clear the drawer's lock early. Writing
 * the longhand here means this module wins that race, and clearing both on
 * release leaves nothing of Swal's behind.
 */
let holders = 0;
let previous = null;

function lockBody() {
    holders += 1;

    if (holders > 1) {
        return;
    }

    const body = document.body;
    previous = { overflow: body.style.overflow, overflowY: body.style.overflowY, paddingRight: body.style.paddingRight };

    // Hiding the scrollbar narrows the viewport, which nudges every centred or
    // right-aligned thing on the page sideways. Padding it back keeps the
    // layout still while the overlay is up.
    const gap = window.innerWidth - document.documentElement.clientWidth;

    if (gap > 0) {
        const current = parseFloat(window.getComputedStyle(body).paddingRight) || 0;
        body.style.paddingRight = `${current + gap}px`;
    }

    body.style.overflow = 'hidden';
    body.style.overflowY = 'hidden';
}

function unlockBody() {
    if (holders === 0) {
        return;
    }

    holders -= 1;

    if (holders > 0) {
        return;
    }

    document.body.style.overflow = previous.overflow;
    document.body.style.overflowY = previous.overflowY;
    document.body.style.paddingRight = previous.paddingRight;
    previous = null;
}

/**
 * Acquire a lock from outside Alpine, for overlays that are not x-data.
 *
 * Shares the counter with the `$lock` magic above, so a SweetAlert2 dialog
 * opening over the mobile drawer cannot unlock the page when it closes.
 */
export function acquireBodyLock() {
    lockBody();
}

/** Counterpart to {@link acquireBodyLock}. */
export function releaseBodyLock() {
    unlockBody();
}

export function registerScrollLock(Alpine) {
    Alpine.magic('lock', () => {
        let held = false;

        return {
            /**
             * Driven from the desired state rather than from edges, because
             * x-effect re-runs on every re-render of its dependencies.
             */
            set(shouldLock) {
                if (shouldLock === held) {
                    return;
                }

                held = shouldLock;

                if (held) {
                    lockBody();
                } else {
                    unlockBody();
                }
            },
        };
    });
}
