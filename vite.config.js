import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

// Public bundle: the CSS (every page) and the first-party share module
// (event page only, EVT-07; never loaded on /). The admin panel's Filament
// theme is also an entry, so Tailwind utilities in admin views get compiled.
// No build-time font provider: the font is the self-hosted
// @fontsource-variable package, so the build is offline (CAL-01).
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/share.js', 'resources/css/filament/admin/theme.css'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
