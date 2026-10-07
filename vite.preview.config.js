/**
 * Preview-only Vite config.
 *
 * The static design preview under /preview renders the redesigned shell with
 * the SAME stylesheet and the SAME behaviour layer the Laravel app ships
 * (resources/css/app.css + resources/js/app.js). It exists so the UI can be
 * reviewed in a browser without booting PHP/MySQL; it is not part of the
 * application build (see vite.config.js for the real entry points).
 */
import { defineConfig } from 'vite';

export default defineConfig({
    base: './',
    publicDir: false, // never copy Laravel's public/ into the preview
    build: {
        outDir: 'preview/assets',
        emptyOutDir: true,
        manifest: false,
        rollupOptions: {
            input: {
                preview: 'preview/src/preview.js',
            },
            output: {
                entryFileNames: '[name].js',
                assetFileNames: '[name][extname]',
            },
        },
    },
});
