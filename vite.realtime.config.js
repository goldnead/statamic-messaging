import { defineConfig } from 'vite';

// The browser client of the realtime feature: one self-contained script
// (Echo and the Pusher protocol client included), committed so that an app
// needs no JavaScript build. Served by the addon at /messaging/realtime.js.
export default defineConfig({
    publicDir: false,
    build: {
        outDir: 'resources/dist/realtime',
        emptyOutDir: true,
        lib: {
            entry: 'resources/js/realtime.js',
            formats: ['iife'],
            name: 'MessagingRealtime',
            fileName: () => 'messaging-realtime.js',
        },
    },
});
