import { defineConfig } from 'vitest/config';

export default defineConfig({
    test: {
        // The in-place refresh reads and writes a live document: it parses a
        // fragment, swaps regions and focuses controls. A DOM implementation is
        // the unit under test as much as the component is.
        environment: 'happy-dom',
        include: ['resources/js/**/*.test.js'],
    },
});
