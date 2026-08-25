// @vitest-environment jsdom

/**
 * «Attiva tutte» / «Disattiva tutte» nella schermata delle preferenze di notifica.
 *
 * ## Perché esistono i due pulsanti
 *
 * Le notifiche sono diventate **quattordici** per un amministratore e dodici per un condòmino, e la
 * beta.64 ne ha aggiunte tre tutte in una volta. Spegnerle tutte voleva dire quattordici clic, e
 * l'elenco è destinato a crescere.
 *
 * ## L'invariante che conta, e che è facile perdere
 *
 * ⚠️ **I due pulsanti cambiano il modulo, non il database.** La schermata ha un «Salva preferenze»
 * esplicito: un'azione che scrivesse di nascosto sarebbe l'unica della pagina a comportarsi così, e
 * chi la clicca per sbaglio non avrebbe modo di tornare indietro. Verificato anche a video il
 * 22/08/2026 — dopo un «Disattiva tutte» il database aveva ancora 14 preferenze accese su 14, e
 * ricaricando la pagina tornavano tutte.
 *
 * Il test lo presidia perché è precisamente il genere di cosa che una modifica futura «semplifica»
 * collegando il pulsante direttamente al salvataggio.
 *
 * ## Cosa NON copre
 *
 * - **Non copre chi vede quali notifiche**: il filtro per permesso è del server
 *   (`NotificationPreferenceService`), e qui arriva già applicato nella prop. Che i tre tipi nuovi
 *   siano visibili anche a un condòmino è presidiato da `OgniNotificaHaLaSuaEtichettaTest`.
 * - **Non copre il salvataggio vero**: quello è del controller.
 */

import { describe, expect, test, vi } from 'vitest';
import { reactive } from 'vue';
import { mount } from '@vue/test-utils';

const put = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
    usePage: () => ({ props: { flash: {} } }),
    // `useForm` è mockato con un oggetto reattivo vero: il conteggio e lo stato dei pulsanti
    // dipendono dalla reattività, e un mock inerte li renderebbe sempre uguali.
    useForm: (dati: Record<string, unknown>) => reactive({ ...dati, processing: false, put }),
}));

vi.mock('laravel-vue-i18n', () => ({
    trans: (chiave: string, sost?: Record<string, unknown>) =>
        sost ? `${chiave}:${JSON.stringify(sost)}` : chiave,
}));

vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generateRoute: (n: string) => n }),
}));

const Notifications = (await import('./Notifications.vue')).default;

/**
 * ⚠️ **`route` si mette su `globalThis`, non in `global.mocks`, e la differenza è tutta la guardia.**
 *
 * Ziggy espone `route()` come globale. `global.mocks` di Vue Test Utils lo inietta nel contesto di
 * **rendering del template** — ed è quello che serve a `Confirm.test.ts`, dove il template lo usa.
 * Qui invece la chiamata è dentro `submit()`, cioè in `<script setup>`, dove il contesto del
 * template non arriva.
 *
 * Provato sbagliando **due volte**: collegando di proposito il pulsante al salvataggio, `submit()`
 * esplodeva su `route is not defined` prima di raggiungere `put`, e il test «nessuno dei due salva»
 * restava **verde**. Peggio: vitest segnalava «5 errors» accanto a «7 passed» — un'eccezione non
 * gestita dentro un gestore di click **non fa fallire il test**, si limita a comparire nel rumore
 * sopra il riepilogo, dove non la guarda nessuno.
 *
 * Una guardia che non morde è peggio di nessuna guardia, perché chi la legge smette di controllare.
 */
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const mocks = { route: (nome: string) => `/${nome}` };

const stubs = {
    AppLayout: { template: '<div><slot /></div>' },
    SettingsLayout: { template: '<div><slot /></div>' },
    HeadingSmall: { template: '<div />' },
    Alert: { template: '<div />' },
    Card: { template: '<div><slot /></div>' },
    CardContent: { template: '<div><slot /></div>' },
    CardFooter: { template: '<div><slot /></div>' },
    Label: { template: '<label><slot /></label>' },
    LoaderCircle: { template: '<span />' },
    Button: {
        props: ['disabled'],
        template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
    },
    Switch: {
        props: ['modelValue'],
        emits: ['update:modelValue'],
        template: '<input type="checkbox" :checked="modelValue" @change="$emit(\'update:modelValue\', $event.target.checked)" />',
    },
};

function render(accese: boolean[] = [true, true, true]) {
    return mount(Notifications, {
        props: {
            preferences: accese.map((enabled, i) => ({
                type: `tipo_${i}`,
                label: `Notifica ${i}`,
                description: `Descrizione ${i}`,
                enabled,
            })),
        },
            global: { stubs, mocks },
    });
}

/** I due pulsanti stanno in cima; il terzo è «Salva preferenze». */
const attivaTutte = (w: ReturnType<typeof render>) => w.findAll('button')[0];
const disattivaTutte = (w: ReturnType<typeof render>) => w.findAll('button')[1];
const interruttori = (w: ReturnType<typeof render>) => w.findAll('input[type="checkbox"]');

describe('i due pulsanti', () => {
    test('«Attiva tutte» è spento quando sono già tutte accese', () => {
        const w = render([true, true, true]);

        expect(attivaTutte(w).attributes('disabled')).toBeDefined();
        expect(disattivaTutte(w).attributes('disabled')).toBeUndefined();
    });

    test('«Disattiva tutte» è spento quando sono già tutte spente', () => {
        const w = render([false, false, false]);

        expect(disattivaTutte(w).attributes('disabled')).toBeDefined();
        expect(attivaTutte(w).attributes('disabled')).toBeUndefined();
    });

    test('un clic su «Disattiva tutte» le spegne tutte', async () => {
        const w = render([true, true, true]);

        await disattivaTutte(w).trigger('click');

        expect(interruttori(w).every(i => !(i.element as HTMLInputElement).checked)).toBe(true);
    });

    test('un clic su «Attiva tutte» le accende tutte, anche quelle già accese', async () => {
        const w = render([true, false, false]);

        await attivaTutte(w).trigger('click');

        expect(interruttori(w).every(i => (i.element as HTMLInputElement).checked)).toBe(true);
    });

    test('⚠️ nessuno dei due salva: il database si tocca solo con «Salva preferenze»', async () => {
        // È l'invariante del file. Se un giorno qualcuno collega il pulsante direttamente al
        // salvataggio «per comodità», questo test diventa rosso e spiega perché non si fa.
        put.mockClear();

        const w = render([true, true, true]);

        await disattivaTutte(w).trigger('click');
        await attivaTutte(w).trigger('click');

        expect(put).not.toHaveBeenCalled();
    });
});

describe('il conteggio', () => {
    test('dice quante ne sono accese sul totale', () => {
        const w = render([true, false, true]);

        expect(w.text()).toContain('"attive":2');
        expect(w.text()).toContain('"totali":3');
    });

    test('si aggiorna subito dopo il clic, prima di salvare', async () => {
        // ⚠️ Senza questo il pulsante sembrerebbe non aver fatto niente, e si cliccherebbe due
        // volte: il conteggio si legge da `form.preferences`, non dalla prop del server.
        const w = render([true, true, true]);

        await disattivaTutte(w).trigger('click');

        expect(w.text()).toContain('"attive":0');
    });
});
