import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import statamic from '@statamic/cms/vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/cp.js', 'resources/css/cp.css'],
            // Must byte-match $vite on the service provider.
            publicDirectory: 'resources/dist',
            refresh: true,
        }),
        // Rewrites `vue` to `window.Vue` so the bundle shares the CP's instance.
        statamic(),
        tailwindcss(),
    ],
});
