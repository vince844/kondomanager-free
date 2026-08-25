import path from 'path';
import vue from '@vitejs/plugin-vue';
import { defineConfig } from 'vitest/config';

/**
 * Configurazione separata da `vite.config.ts`: quella serve alla build servita da Laravel
 * (plugin laravel-vite-plugin, entrypoint, manifest) e qui darebbe solo fastidio.
 *
 * Due famiglie di test, con esigenze diverse:
 *
 * - I moduli di calcolo in `resources/js/lib/` — il denaro che il form mostra prima di
 *   salvare. Sono funzioni pure: non serve un DOM, e girano in ambiente `node`.
 * - Le poche schermate in cui il difetto vive nel *template* e non nella logica, dove
 *   l'unico modo di coglierlo è montare davvero il componente. Quei file dichiarano
 *   `// @vitest-environment jsdom` in testa: l'ambiente pesante si paga solo dove serve.
 */
export default defineConfig({
    plugins: [vue()],
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
