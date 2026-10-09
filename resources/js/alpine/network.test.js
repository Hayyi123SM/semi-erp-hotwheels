import { reactive } from "@vue/reactivity";
import { describe, it, expect, vi, beforeEach } from "vitest";

async function freshRegisterNetwork() {
    vi.resetModules();

    return (await import("./network")).registerNetwork;
}

/**
 * Alpine's `store()`, and only that.
 *
 * Same stand-in as notify.test.js: `stores` is a `reactive()` container, a set
 * call stores the value untouched, and a get call hands back a proxy -- the
 * asymmetry the tests depend on for reactivity.
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

/** Make `navigator.onLine` return the given value from here on. */
function setOnline(value) {
    Object.defineProperty(window.navigator, "onLine", {
        configurable: true,
        get: () => value,
    });
}

describe("network store", () => {
    beforeEach(() => {
        window.localStorage.clear();
        setOnline(true);
    });

    it("starts from the browser's own connectivity state", async () => {
        const registerNetwork = await freshRegisterNetwork();
        const network = registerNetwork(fakeAlpine());

        expect(network.online).toBe(true);
    });

    it("turns the badge over on the browser offline/online events", async () => {
        const registerNetwork = await freshRegisterNetwork();
        const network = registerNetwork(fakeAlpine());

        window.dispatchEvent(new Event("offline"));
        expect(network.online).toBe(false);

        window.dispatchEvent(new Event("online"));
        expect(network.online).toBe(true);
    });

    it("counts the queue written by the future offline mechanism", async () => {
        const registerNetwork = await freshRegisterNetwork();

        expect(registerNetwork(fakeAlpine()).pendingSync).toBe(0);

        window.localStorage.setItem("offline-queue", JSON.stringify([1, 2]));

        expect(registerNetwork(fakeAlpine()).pendingSync).toBe(2);
    });

    it("treats unreadable queue storage as empty, never a backlog", async () => {
        const registerNetwork = await freshRegisterNetwork();

        window.localStorage.setItem("offline-queue", "not json");

        expect(registerNetwork(fakeAlpine()).pendingSync).toBe(0);
    });

    it("re-reads the queue when connectivity returns, as sync drains it", async () => {
        const registerNetwork = await freshRegisterNetwork();
        const network = registerNetwork(fakeAlpine());
        setOnline(false);

        window.localStorage.setItem("offline-queue", JSON.stringify([1]));

        window.dispatchEvent(new Event("online"));

        expect(network.online).toBe(true);
        expect(network.pendingSync).toBe(1);

        window.localStorage.clear();
        window.dispatchEvent(new Event("offline"));
        window.dispatchEvent(new Event("online"));

        expect(network.pendingSync).toBe(0);
    });
});