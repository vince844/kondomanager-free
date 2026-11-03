// @vitest-environment jsdom

/**
 * Cosa resta scoperto: la tabella delle rate e le schede della pagina (non cambiano con i totali); il colore, la larghezza a 375 px e il tema
 * scuro (si guardano a video); il badge «Disallineato: ricalcola!», che dipende dalla proprietà `disallineato` che manda il server (la provano i
 * test Pest dell'atteso).
 *
 * Il riquadro «Copertura spese» della pagina del piano rate, con le voci di entrata.
 *
 * La riga sotto il titolo diceva «totale preventivo» e leggeva `totale_capitoli`, la somma delle voci senza il segno: con un'entrata il numero
 * era più alto di ciò che il piano chiede. Ora legge `totale_spese`, `totale_entrate` e `totale_da_ripartire` (centesimi) del server: senza
 * entrate dice, come prima, «totale preventivo: € 1.200,00»; con un'entrata dice «da ripartire: € 960,00 (spese € 1.200,00, entrate € 240,00)».
 * La parola «da ripartire» compare dove il netto differisce dalle spese; una colonna fissa dell'elenco porta sempre il nome del netto.
 *
 * Le frasi che dicono come si ottiene il netto («da ripartire … (spese …, entrate …)») si firmano con la clausola del motore, letta alla lettera:
 * `in_array($conto->tipo, ['spesa', 'uscita']) ? abs($importo) : -abs($importo)` (`CalcoloQuoteService::processaConti`, il ramo con l'importo del piano e quello con l'importo della voce): le voci di
 * un tipo diverso da spesa e uscita si sottraggono.
 *
 * Aperto l'elenco, ogni voce di entrata porta l'etichetta «Entrata» e l'importo con il meno, come le stampe del riparto.
 *
 * La pagina è quella vera: si stubbano la cornice, la guida, il selettore di date, il pannello laterale, il modale di «Sposta spesa», lo storico
 * dei movimenti e gli avvisi dei destinatari. Il testo si legge dal documento.
 */

import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    router: { delete: vi.fn(), post: vi.fn(), get: vi.fn(), reload: vi.fn((o?: { onFinish?: () => void }) => o?.onFinish?.()) },
    usePage: () => ({ props: { flash: {}, auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasPermission: () => true, hasRole: () => true }),
}));
vi.mock('laravel-vue-i18n', () => ({ trans: (c: string) => c }));
(globalThis as unknown as { route: unknown }).route = (nome: string, parametri?: Record<string, unknown>) => `/${nome}?${JSON.stringify(parametri ?? {})}`;

const PianiRateShow = (await import('@/pages/gestionale/pianiRate/PianiRateShow.vue')).default;

let pagina: VueWrapper | null = null;

const SPESA = { id: 1, nome: 'Spese generali', importo: 120000, importo_originale: 120000, is_parent: false, is_frazionato: false, tipo: 'spesa' };
const ENTRATA = { id: 2, nome: 'Proventi antenna', importo: 24000, importo_originale: 24000, is_parent: false, is_frazionato: false, tipo: 'entrata' };

async function monta(totali: Record<string, number>, capitoli: unknown[]) {
    pagina = mount(PianiRateShow, {
        attachTo: document.body,
        props: {
            condominio: { id: 1, nome: 'Condominio Demo' } as never, esercizio: { id: 1, nome: '2026' } as never,
            pianoRate: { id: 5, nome: 'Preventivo 2026', stato: 'bozza', tipo: 'ordinario', numero_rate: 12, ...totali, budget_movements: [], capitoli },
            quotePerAnagrafica: [], quotePerImmobile: [], ratePure: [], needsMigration: false, sources: [], destinations: [], copertura: null, fermo_del_piano: null,
        },
        global: { stubs: { GestionaleLayout: { template: '<div><slot /></div>' }, PageHeaderGuide: true, VueDatePicker: true, PianoRateSpaccatoSheet: true, ModalSpostaSpesa: true, BudgetHistoryPopover: true, DestinatariWarning: true, DestinatariWarningDefunto: true, ScopertoWarning: true, Head: { template: '<span />' }, Link: { template: '<a><slot /></a>' } }, mocks: { route: (n: string) => `/${n}` } },
    });
    await flushPromises();
}

const testo = () => (document.body.textContent ?? '').replace(/ /g, ' ').replace(/\s+/g, ' ').trim();

const apriLeVoci = async () => {
    const intestazione = [...document.body.querySelectorAll('button')].find((b) => b.textContent?.includes('Copertura spese')) as HTMLButtonElement;
    intestazione.click();
    await flushPromises();
    await new Promise((r) => setTimeout(r, 150));
    await flushPromises();
};

afterEach(() => {
    pagina?.unmount();
    pagina = null;
    document.body.innerHTML = '';
});

describe('la riga del totale sotto «Copertura spese»', () => {
    test('con spese € 1.200,00 ed entrata € 240,00 dice «da ripartire: € 960,00» e da dove viene', async () => {
        await monta({ totale_spese: 120000, totale_entrate: 24000, totale_da_ripartire: 96000 }, [SPESA, ENTRATA]);

        expect(testo()).toContain('da ripartire: € 960,00 (spese € 1.200,00, entrate € 240,00)');
        expect(testo()).not.toContain('totale preventivo');
        expect(testo()).not.toContain('NaN');
    });

    test('controprova: senza entrate resta com\'è, «totale preventivo: € 1.200,00» (la risposta porta anche la chiave di prima, come un server non ancora aggiornato)', async () => {
        await monta({ totale_capitoli: 120000, totale_spese: 120000, totale_entrate: 0, totale_da_ripartire: 120000 }, [SPESA]);

        expect(testo()).toContain('totale preventivo: € 1.200,00');
        expect(testo()).not.toContain('da ripartire');
    });

    test('con la sola entrata il netto è negativo e si legge col meno', async () => {
        await monta({ totale_spese: 0, totale_entrate: 24000, totale_da_ripartire: -24000 }, [ENTRATA]);

        expect(testo()).toContain('da ripartire: € -240,00 (spese € 0,00, entrate € 240,00)');
    });
});

describe('le voci del piano, a elenco aperto', () => {
    test('la voce di entrata porta «Entrata» e l\'importo con il meno; la voce di spesa resta com\'è', async () => {
        await monta({ totale_spese: 120000, totale_entrate: 24000, totale_da_ripartire: 96000 }, [SPESA, ENTRATA]);
        await apriLeVoci();

        const righe = [...document.body.querySelectorAll('div.flex.items-start.justify-between')].map((r) => (r.textContent ?? '').replace(/ /g, ' ').replace(/\s+/g, ' ').trim());
        const riga = (nome: string) => righe.find((r) => r.includes(nome)) ?? '';

        expect(riga('Proventi antenna')).toContain('Entrata');
        expect(riga('Proventi antenna')).toContain('€ -240,00');
        expect(riga('Spese generali')).not.toContain('Entrata');
        expect(riga('Spese generali')).toContain('€ 1.200,00');
    });
});
