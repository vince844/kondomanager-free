import '../css/app.css';
import '../css/custom.css';

import { createInertiaApp, http, usePage } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import type { DefineComponent } from 'vue';
import { createApp, h, watch } from 'vue'; 
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import { initializeTheme } from './composables/useAppearance';
import { registraTracciamentoNavigazione } from './composables/useUrlPrecedente';
import money from 'v-money3';
import { i18nVue, loadLanguageAsync } from 'laravel-vue-i18n';
import FinestraConfermaPassword from './components/FinestraConfermaPassword.vue';
import { conConferma } from './lib/clientHttpConConferma';

createInertiaApp({
    // Una richiesta che riceve 423 «serve la password» apre la finestra della conferma e si rifà da sola, con gli stessi
    // dati, dentro la stessa visita (Coda 404).
    http: conConferma(http.getClient()),
    title: (title) => {
        const appName = usePage().props.name as string || 'KondoManager';
        return title ? `${title} - ${appName}` : appName;
    },
    resolve: (name) =>
        resolvePageComponent(
            `./pages/${name}.vue`,
            import.meta.glob<DefineComponent>('./pages/**/*.vue'),
        ),
        setup({ el, App, props, plugin }) {
            // Prima del mount: l'evento `before` deve essere in ascolto già alla
            // primissima navigazione, altrimenti la provenienza iniziale si perde.
            registraTracciamentoNavigazione();

            // La finestra della conferma della password sta accanto alla pagina, una sola per l'applicazione: la apre
            // il 423 di un'azione sensibile, su qualunque pagina e con qualunque layout (Coda 404).
            const app = createApp({ render: () => [h(App, props), h(FinestraConfermaPassword)] })
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