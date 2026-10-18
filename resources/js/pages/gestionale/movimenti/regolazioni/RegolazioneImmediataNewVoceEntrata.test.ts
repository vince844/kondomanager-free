// @vitest-environment jsdom

/**
 * Il modulo della regolazione immediata e il rifiuto della voce di entrata.
 *
 * La regolazione registra un'uscita di cassa (DARE sul costo, AVERE sulla cassa): su una voce di entrata scriveva un'uscita di banca. Il server non manda
 * più le entrate nel menu del capitolo e rifiuta quella scelta con un richiesta costruita, sul campo `conto_id` (`StoreRegolazioneImmediataRequest`,
 * `Beta52VoceEntrataFattureTest`). Qui si monta la pagina con il `vue-select` vero: il testo del rifiuto sta sotto il menu, e la via d'uscita — scegliere
 * una voce di spesa dallo stesso menu — resta raggiungibile e riempie il campo.
 *
 * È una controprova: il modulo mostra già gli errori di `conto_id`; il test fissa che il rifiuto nuovo ci passa e che il menu non lo trattiene. Il rifiuto della
 * stessa regola che arriva dall'azione (`regolazione_non_ammessa`) ha il suo riquadro con «Registra una fattura passiva»: per una voce di entrata la strada è
 * il menu a fianco, per questo il rifiuto della richiesta sta sul campo.
 *
 * I test che cominciano con «controprova» proteggono ciò che la correzione non deve rompere: sono verdi prima e dopo.
 *
 * Cosa resta scoperto: il server; il riquadro «Operazione non ammessa» (riservato ai rifiuti dell'azione: ritenuta e scadenziario).
 */

import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    usePage: () => ({ props: { flash: {}, auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasPermission: () => true, hasRole: () => true }),
}));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

const RegolazioneImmediataNew = (await import('@/pages/gestionale/movimenti/regolazioni/RegolazioneImmediataNew.vue')).default;

const stubs = {
    GestionaleLayout: { template: '<div><slot /></div>' },
    PageHeaderGuide: { template: '<div><slot /></div>' },
    RegolazioneImmediataGuide: true,
    MoneyInput: { name: 'MoneyInput', props: ['modelValue'], template: '<input :value="modelValue" />' },
};

let pagina: VueWrapper | null = null;

async function monta() {
    pagina = mount(RegolazioneImmediataNew, {
        attachTo: document.body,
        props: {
            condominio: { id: 28, nome: 'Condominio Demo KM' }, condomini: [], esercizi: [{ id: 41, nome: '2026' }],
            gestioni: [{ id: 35, nome: 'Gestione ordinaria 2026', tipo: 'ordinaria', esercizio_ids: [41] }],
            capitoli: [{ id: 55, nome: 'Spese bancarie', parent_nome: null, label: 'Spese bancarie' }, { id: 57, nome: 'Bolli', parent_nome: null, label: 'Bolli' }],
            casse: [{ id: 3, nome: 'Banca principale', tipo: 'banca' }], fornitori: [], prossimo_protocollo: 'RIM-2026-00001',
        },
        global: { stubs, mocks: { route: (n: string) => `/${n}` } },
    });
    await flushPromises();
}

const modulo = () => (pagina!.vm as any).form;
const menuCapitolo = () => document.body.querySelector('input[placeholder^="Su quale voce"]')?.closest('.v-select') as HTMLElement | null;

afterEach(() => {
    pagina?.unmount();
    pagina = null;
    document.body.innerHTML = '';
});

describe('il rifiuto della voce di entrata', () => {
    test('controprova — il testo del rifiuto sta sotto il menu del capitolo', async () => {
        await monta();

        modulo().errors = { conto_id: '«Affitto antenna» è una voce di entrata: la regolazione immediata registra un\'uscita di cassa e non si usa per un\'entrata. Scegli una voce di spesa.' };
        await flushPromises();

        expect(document.body.textContent).toContain('«Affitto antenna» è una voce di entrata: la regolazione immediata registra un\'uscita di cassa');
    });

    test('controprova — la via d\'uscita: il menu del capitolo offre le voci di spesa e se ne sceglie una', async () => {
        await monta();
        modulo().errors = { conto_id: '«Affitto antenna» è una voce di entrata: la regolazione immediata registra un\'uscita di cassa e non si usa per un\'entrata. Scegli una voce di spesa.' };
        await flushPromises();

        (menuCapitolo()!.querySelector('.vs__dropdown-toggle') as HTMLElement).dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
        await flushPromises();
        const voci = [...document.body.querySelectorAll('li.vs__dropdown-option')];
        expect(voci.map((o) => o.textContent?.trim())).toEqual(['Spese bancarie', 'Bolli']);

        voci[1].dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
        voci[1].dispatchEvent(new MouseEvent('click', { bubbles: true }));
        await flushPromises();

        expect(modulo().conto_id).toBe(57);
    });
});
