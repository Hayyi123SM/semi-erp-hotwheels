/**
 * Responsive sidebar state for the three layout modes.
 *
 *   < 768px    off-canvas drawer, hidden until the topbar button opens it
 *   768-1279   icon rail, 72px, widened to 256px while pinned
 *   >= 1280px  full sidebar, 256px
 *
 * The width is not set from here. It is driven by the `--sidebar-current`
 * custom property in app.css, so the first paint is already the correct size
 * and the content gutter can never drift from the sidebar. This component owns
 * the boolean state, mirrors the pinned preference onto <html> as
 * `data-sidebar`, and keeps the drawer behave like a drawer: it locks the page
 * behind it, closes on Escape, and hands focus back where it came from.
 */
const STORAGE_KEY = 'sidebar-expanded';
const TABLET_QUERY = '(min-width: 768px)';
const DESKTOP_QUERY = '(min-width: 1280px)';

function readPinned() {
    try {
        return window.localStorage.getItem(STORAGE_KEY) === '1';
    } catch {
        // Private browsing and blocked storage: fall back to the rail default.
        return false;
    }
}

function writePinned(value) {
    try {
        window.localStorage.setItem(STORAGE_KEY, value ? '1' : '0');
    } catch {
        // Preference simply does not persist; the rail still works.
    }
}

export function sidebarLayout() {
    const tablet = window.matchMedia(TABLET_QUERY);
    const desktop = window.matchMedia(DESKTOP_QUERY);

    return {
        open: false,
        isTablet: tablet.matches,
        isDesktop: desktop.matches,
        pinned: readPinned(),

        init() {
            // A previous session on a wide screen must not leave the drawer open
            // on a phone, and pinning is meaningless on mobile.
            if (!tablet.matches) {
                this.pinned = false;
            }

            this.applyPinned();

            // Only the mobile drawer overlays content, so only it locks scroll.
            // The rail and the full sidebar are pushed layout, not overlays.
            this.$watch('open', (isOpen) => this.$lock.set(isOpen));

            // Re-evaluate on every breakpoint crossing. Without these listeners
            // the sidebar used to freeze at whatever width the page loaded at,
            // so rotating a tablet left it stranded over the content.
            const sync = () => {
                this.isTablet = tablet.matches;
                this.isDesktop = desktop.matches;

                if (!tablet.matches) {
                    // Assigned directly rather than through closeDrawer: a
                    // rotation must not yank focus to the topbar trigger.
                    this.open = false;
                    this.pinned = false;
                }

                this.applyPinned();
            };

            tablet.addEventListener('change', sync);
            desktop.addEventListener('change', sync);
        },

        destroy() {
            // Leaving a locked body behind is how a navigation ends up with a
            // page that cannot scroll.
            this.$lock.set(false);
        },

        /** Labels, group headings and footer detail are only shown when there is room. */
        get isLabelled() {
            // Below md the sidebar is a 256px drawer, so it is always labelled.
            return !this.isTablet || this.isDesktop || this.pinned;
        },

        /** The rail collapse control is meaningless outside the tablet band. */
        get isPinnable() {
            return this.isTablet && !this.isDesktop;
        },

        /** The drawer is only translated in while it is the active layout. */
        get isVisible() {
            // The desktop query is a subset of the tablet one, so isTablet
            // already covers the full sidebar.
            return this.isTablet || this.open;
        },

        toggleDrawer() {
            this.open = !this.open;
            this.syncFocus();
        },

        closeDrawer({ restoreFocus = true } = {}) {
            if (!this.open) {
                return;
            }

            this.open = false;

            if (restoreFocus) {
                this.focusTrigger();
            }
        },

        /**
         * Move focus into the drawer, or hand it back to the trigger that opened
         * it. Keyboard users land on the close button rather than being dropped
         * back at the top of the document.
         */
        syncFocus() {
            if (!this.open) {
                this.focusTrigger();
                return;
            }

            // After the next frame, so the drawer is already transformed in and
            // the button is focusable.
            requestAnimationFrame(() => this.$refs.drawerClose?.focus());
        },

        focusTrigger() {
            this.$refs.menuToggle?.focus();
        },

        togglePinned() {
            this.pinned = !this.pinned;
            this.applyPinned();
            // Without this the pin resets on every reload: the choice was
            // applied to the DOM but never handed to storage.
            writePinned(this.pinned);
        },

        applyPinned() {
            const expanded = this.pinned && !this.isDesktop;

            if (expanded) {
                document.documentElement.dataset.sidebar = 'expanded';
            } else {
                delete document.documentElement.dataset.sidebar;
            }
        },
    };
}
