import '../css/app.css';
import '../css/custom.css';

import { createInertiaApp, usePage } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import type { DefineComponent } from 'vue';
import { createApp, h, watch } from 'vue'; 
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import { initializeTheme } from './composables/useAppearance';
import money from 'v-money3';
import { i18nVue, loadLanguageAsync } from 'laravel-vue-i18n';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(
            `./pages/${name}.vue`,
            import.meta.glob<DefineComponent>('./pages/**/*.vue'),
        ),
        setup({ el, App, props, plugin }) {
            const app = createApp({ render: () => h(App, props) })
                .use(plugin)
                .use(ZiggyVue)
                .use(money)
                .use(i18nVue, {
                    lang: props.initialPage.props.locale as string,
                    resolve: async (lang: string) => {
                        const langs = import.meta.glob('../../lang/*.json');
                        return await langs[`../../lang/${lang}.json`]();
                    },
                });

            app.mount(el);

            // Usiamo un watcher dopo il mount per intercettare i cambiamenti di Inertia
            watch(
                () => usePage().props.locale,
                (newLocale) => {
                    if (newLocale) {
                        loadLanguageAsync(newLocale as string);
                    }
                }
            );
        },
    progress: {
        color: '#4B5563',
    },
});

// Imposta il tema al caricamento
initializeTheme();