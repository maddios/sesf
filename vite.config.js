import { defineConfig } from 'vitest/config';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
        }),
        vue(),
        tailwindcss(),
    ],
    server: {
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        hmr: {
            host: 'localhost',
            clientPort: 5173,
        },
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
    test: {
        environment: 'jsdom',
        setupFiles: ['resources/js/test/setup.ts'],
        include: ['resources/js/**/*.spec.ts'],
        coverage: {
            provider: 'v8',
            include: ['resources/js/**'],
            exclude: ['resources/js/test/**', 'resources/js/**/*.d.ts', 'resources/js/**/__tests__/**'],
            reporter: ['text', 'html', 'lcov'],
            thresholds: {
                lines: 80,
                statements: 80,
                functions: 80,
                branches: 80,
            },
        },
    },
});
