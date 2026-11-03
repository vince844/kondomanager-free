// @vitest-environment jsdom

/**
 * Il modulo di REGISTRAZIONE di una fattura mostra nel riepilogo i rifiuti del perimetro, con il loro messaggio.
 *
 * Il server rifiuta un'unità, un conto di addebito, un fondo di riserva o la fonte di una copertura che non sono del condominio dell'indirizzo
 * (`Beta52VoceEntrataPerimetroFattureTest`). Nessuno di questi campi ha un messaggio accanto al proprio menu nella pagina: la rete sotto è il riepilogo
 * (`FormErrorSummary`), che elenca ogni chiave con il suo messaggio. Il test monta la pagina vera, scrive l'errore sul modulo come fa Inertia dopo un rifiuto e
 * legge il riepilogo come lo legge chi registra.
 *
 * Il riepilogo è lo stesso componente della modifica: una riga porta il suo numero («Riga 1»), il conto di addebito il suo nome.
 *
 * Il rifiuto di una riga su una voce che non è di spesa sta anche sotto il menu del capitolo, e il menu (quello vero) offre le voci di spesa: se ne sceglie una e il
 * rifiuto di quella riga sparisce.
 *
 * I test che cominciano con «controprova» proteggono ciò che la correzione non deve rompere: sono verdi prima e dopo.
 *
 * Cosa resta scoperto: il server; il riepilogo della modifica (`FatturaRegisterEditRiepilogo.test.ts`); la lettura dell'XML.
 */

import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const axios = vi.hoisted(() => ({ get: vi.fn(async () => ({ data: [] })), post: vi.fn() }));
vi.mock('axios', () => ({ default: axios }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    usePage: () => ({ props: { auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));

(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

const FatturaRegisterNew = (await import('@/pages/gestionale/movimenti/fatture/FatturaRegisterNew.vue')).default;

const stubs = {
    GestionaleLayout: { template: '<div><slot /></div>' },
    PageHeaderGuide: { template: '<div><slot /></div>' },
    ModalImportaXml: true,
    ConfirmDialog: true,
    FatturaRegistrazioneGuide: true,
    WidgetDoubleLock: true,
    ModalSpesaImprevista: true,
    ModalOverrideBudget: true,
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    'v-select': true,
    MoneyInput: { name: 'MoneyInput', props: ['modelValue'], template: '<input :value="modelValue" />' },
};

let pagina: VueWrapper | null = null;

async function monta(vSelectVero = false) {
    const { 'v-select': _stub, ...stubsSenzaMenu } = stubs;
    pagina = mount(FatturaRegisterNew, {
        attachTo: document.body,
        props: {
            condominio: { id: 28, nome: 'Condominio Demo KM' }, condomini: [{ id: 28, nome: 'Condominio Demo KM' }], esercizio: { id: 41, nome: '2026', stato: 'aperto' },
            esercizi: [{ id: 41, nome: '2026', stato: 'aperto' }], gestioni: [{ id: 35, nome: 'Gestione ordinaria 2026', tipo: 'ordinaria', esercizio_ids: [41] }],
            fornitori: [{ id: 10, ragione_sociale: 'Alfa Servizi Srl', soggetto_ritenuta: false, partita_iva: '01234567897' }],
            conti: [{ id: 55, nome: 'Pulizie', tipo: 'spesa', residuo_budget: 100000, is_capiente: true, parent_nome: null }], banche: [], immobili: [], debiti_patrimoniali: [], fatture_pregresse_registrate: [], fondi_riserva: [],
            capienza_rata_zero: 0, incassato_rata_zero: 0,
        },
        global: { stubs: vSelectVero ? stubsSenzaMenu : stubs, mocks: { route: (n: string) => `/${n}` } },
    });
    await flushPromises();
}

const form = () => (pagina!.vm as any).form;
const riepilogo = () => [...document.body.querySelectorAll('[role="alert"]')].find((r) => r.textContent?.includes('Il salvataggio è stato rifiutato')) as HTMLElement | undefined;
const testo = () => (riepilogo()?.textContent ?? '').replace(/\s+/g, ' ').trim();

afterEach(() => {
    pagina?.unmount();
    pagina = null;
    document.body.innerHTML = '';
});

describe('il riepilogo dei rifiuti del perimetro', () => {
    test('controprova — l\'unità di un altro condominio compare con il numero della riga', async () => {
        await monta();

        form().errors = { 'righe.0.immobile_id': 'L\'unità scelta non appartiene a questo condominio.' };
        await flushPromises();

        expect(testo()).toContain('Riga 1');
        expect(testo()).toContain('L\'unità scelta non appartiene a questo condominio.');
    });

    test('controprova — il fondo di riserva di un altro condominio compare con il suo messaggio', async () => {
        await monta();

        form().errors = { 'dati_extra.override_budget.fondo_patrimoniale_id': 'Il fondo di riserva scelto non appartiene a questo condominio.' };
        await flushPromises();

        expect(testo()).toContain('Il fondo di riserva scelto non appartiene a questo condominio.');
    });

    test('controprova — il conto di addebito di un altro condominio compare con il nome «Conto addebito»', async () => {
        await monta();

        form().errors = { conto_corrente_id: 'Il conto di addebito scelto non appartiene a questo condominio.' };
        await flushPromises();

        expect(testo()).toContain('Conto addebito');
        expect(testo()).toContain('Il conto di addebito scelto non appartiene a questo condominio.');
    });

    test('controprova — la fonte di una copertura e il debito di un altro condominio compaiono con il loro messaggio', async () => {
        await monta();

        form().errors = {
            'coperture.0.fonte_id': 'Il debito scelto non appartiene a questo condominio.',
            saldo_patrimoniale_id: 'Il debito scelto non appartiene a questo condominio.',
        };
        await flushPromises();

        expect(testo()).toContain('2 campi da correggere');
        expect(testo()).toContain('Il debito scelto non appartiene a questo condominio.');
    });
});

describe('il rifiuto di una riga e la via d\'uscita nel menu', () => {
    test('controprova — l\'errore sta sotto il menu del capitolo, si sceglie una voce di spesa e l\'errore di quella riga sparisce', async () => {
        await monta(true);
        form().errors = { 'righe.0.conto_id': '«Affitto antenna» è una voce di entrata: il capitolo di una fattura è una voce di spesa. Scegli un\'altra voce.' };
        await flushPromises();
        expect(document.body.textContent).toContain('«Affitto antenna» è una voce di entrata');

        const menuCapitolo = document.body.querySelector('input[placeholder^="Cerca capitolo"]')?.closest('.v-select') as HTMLElement;
        const toggle = menuCapitolo.querySelector('.vs__dropdown-toggle') as HTMLElement;
        toggle.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
        await flushPromises();
        const voci = [...document.body.querySelectorAll('li.vs__dropdown-option')];
        expect(voci.map((o) => (o.textContent ?? '').replace(/\s+/g, ' ').trim().split('€')[0].trim())).toEqual(['Pulizie']);

        voci[0].dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
        voci[0].dispatchEvent(new MouseEvent('click', { bubbles: true }));
        await flushPromises();

        expect(form().righe[0].conto_id).toBe(55);
        expect(document.body.textContent).not.toContain('«Affitto antenna» è una voce di entrata');
    });
});
