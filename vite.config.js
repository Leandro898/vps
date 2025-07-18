import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/css/filament/admin/filament.css', // Esta ruta para el tema de Filament para personalizarlo
                'resources/js/app.js',
              ],
            refresh: true,
        }),
    ],
});
