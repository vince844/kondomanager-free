import path from 'path';
import { defineConfig } from 'vitest/config';

/**
 * Configurazione separata da `vite.config.ts`: quella serve alla build servita da Laravel
 * (plugin laravel-vite-plugin, entrypoint, manifest) e qui darebbe solo fastidio.
 *
 * I test coprono i moduli di calcolo in `resources/js/lib/`, cioè il denaro che il form
 * mostra prima di salvare. Non sono test di componenti: non serve un DOM.
 */
export default defineConfig({
    resolve: {
        alias: {
            '@': path.resolve(__dirname, './resources/js'),
        },
    },
    test: {
        include: ['resources/js/**/*.test.ts'],
        environment: 'node',
    },
});
