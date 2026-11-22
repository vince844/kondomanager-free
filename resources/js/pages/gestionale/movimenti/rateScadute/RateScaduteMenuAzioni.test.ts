// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Le azioni della riga nella pagina «Rate scadute» stanno nel menu «⋯» in fondo alla riga, non più nella colonna «Ultima lettera»: la colonna
 * tiene solo lo stato; il menu ha «Prepara una lettera», «Registra un accordo di rientro» (o «Apri l'accordo di rientro» se c'è già) e
 * «Vedi la corrispondenza» solo se c'è una lettera; nella vista «In scadenza» non c'è l'accordo.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

vi.mock('axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }));
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { get: vi.fn(), reload: vi.fn(), post: vi.fn(), delete: vi.fn() },
    usePage: () => ({ props: { errors: {} } }),
}));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => nome, generatePath: (p: string) => `/${p}` }) }));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

const Pagina = (await import('./RateScadute.vue')).default;

const accordo = { id: 7, data_accordo: '2026-06-26', fino_al: '2026-12-15', riconosciuti: 150000, oggi: 99410, invariato: false, fino_al_passato: false, versati_oltre: 0,
    autorizzato_assemblea: false, data_autorizzazione: null, verbale_autorizzazione: null, nota: null, chiuso_il: null, nota_chiusura: null };
const riga = (extra: Record<string, unknown> = {}) => ({
    anagrafica_id: 7, nome: 'Ugo Bianchi', unita: ['Interno 1'], importo: 15000, credito: 0, fasce: { '1_30': 5000, '31_90': 10000, oltre_90: 0 }, ritardo_testo: 'in ritardo di 43 giorni',
    sei_mesi: { data: null, dispensa: null, mancano: 0 }, con_pregresso: false, origine_non_dichiarata: false, rate: [], accordi: [],
    ultima_lettera: null, lettera_in_sospeso: null, ...extra,
});
const proprieta = (r: Record<string, unknown>, vista = 'scadute') => ({
    condominio: { id: 3, nome: 'Condominio Via dei Pini' }, condomini: [], esercizio: { id: 9 },
    righe: { data: [r], meta: { current_page: 1, last_page: 1, per_page: 20, total: 1, from: 1, to: 1 } },
    totale: 15000, persone_totali: 1,
    filtri: { alla: '2026-03-20', vista, giorni: 30, cerca: null, anagrafica_id: null, fascia: null, minimo: null, gestione_id: null, sei_mesi_entro: null, con_pregresso: false,
        ordine: 'scaduto', senza_lettere_da: null, mai_scritto: false, da_spedire: false, con_accordo: false },
    oggi: '2026-03-20', non_emesse: null, gestioni: [], persona_della_porta: null, testo_norma: '«…»', frase_festivo: '', lettere_da_riprovare: 0, lettere_in_coda_da_tempo: 0,
});

let w: VueWrapper | null = null;
const stubs = { GestionaleLayout: { template: '<div><slot /></div>' }, MovimentiLayout: { template: '<div><slot /></div>' }, PageHeaderGuide: true, LettereDialog: true, AccordoRientroDialog: true };
const monta = (r: Record<string, unknown>, vista = 'scadute') => {
    w = mount(Pagina, { attachTo: document.body, props: proprieta(r, vista) as any, global: { stubs } });

    return w;
};
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });

const apriMenu = async () => {
    // Come da tastiera: in jsdom il clic del mouse non apre il menu di reka-ui, Invio sì.
    await w!.get('[data-testid="menu-7"]').trigger('keydown', { key: 'Enter' });
    await flushPromises();
};
const voci = () => Array.from(document.body.querySelectorAll('[role="menuitem"]')).map((e) => (e.textContent ?? '').replace(/\s+/g, ' ').trim());
const lettera = { tipo: 'sollecito', data: '2026-03-10', canale: 'email', stato: 'inviata', destinatari: ['ugo@x.it'] };

describe('il menu «⋯» delle azioni della riga', () => {
    test('la colonna «Ultima lettera» tiene solo lo stato: niente pulsanti né collegamenti', () => {
        monta(riga({ ultima_lettera: lettera }));
        const cella = w!.get('[data-testid="ultima-lettera"]');
        expect(cella.find('button').exists()).toBe(false);
        expect(cella.find('a').exists()).toBe(false);
        expect(cella.text()).toContain('Sollecito inviato il 10/03/2026');
        expect(cella.text()).not.toContain('Prepara una lettera');
    });

    test('il pulsante è nella colonna stretta in fondo, con un titolo per i lettori di schermo', () => {
        monta(riga());
        const celle = w!.findAll('tbody tr:first-child > td');
        expect(celle.at(-1)!.attributes('data-testid')).toBe('azioni-riga');
        expect(celle.at(-1)!.find('[data-testid="menu-7"]').exists()).toBe(true);
        expect(w!.findAll('thead th').at(-1)!.text()).toBe('Azioni');
        expect(w!.findAll('thead th')).toHaveLength(celle.length);
    });

    test('nessuna lettera: «Prepara una lettera» e «Registra un accordo di rientro»; la corrispondenza non c\'è', async () => {
        monta(riga());
        await apriMenu();
        expect(voci()).toEqual(['Prepara una lettera', 'Registra un accordo di rientro']);
        expect(document.body.textContent).toContain('Azioni');
    });

    test('lettera e accordo già registrato: «Apri l\'accordo di rientro» e «Vedi la corrispondenza» con l\'indirizzo di prima', async () => {
        monta(riga({ ultima_lettera: lettera, accordi: [accordo] }));
        await apriMenu();
        expect(voci()).toEqual(['Prepara una lettera', 'Apri l\'accordo di rientro', 'Vedi la corrispondenza']);
        const link = document.body.querySelector('[data-testid="corrispondenza-7"]')!;
        expect(link.getAttribute('href')).toMatch(/gestionale\.anagrafiche\.estratto-conto.*\?scheda=corrispondenza$|\?scheda=corrispondenza$/);
    });

    test('la sola lettera in sospeso basta per «Vedi la corrispondenza»', async () => {
        monta(riga({ lettera_in_sospeso: { tipo: 'diffida', data: '2026-03-18', canale: 'stampa', stato: 'in_coda' } }));
        await apriMenu();
        expect(voci()).toContain('Vedi la corrispondenza');
    });

    test('la pagina «in scadenza»: niente accordo di rientro', async () => {
        monta(riga({ ultima_lettera: lettera }), 'in_scadenza');
        await apriMenu();
        expect(voci()).toEqual(['Prepara una lettera', 'Vedi la corrispondenza']);
    });

    test('la riga aperta sotto la persona copre tutte le colonne, anche quella del menu', async () => {
        monta(riga({ rate: [] }));
        const colonne = w!.findAll('thead th').length;
        await w!.get('button[aria-label="Apri le rate"]').trigger('click');
        const aperta = w!.findAll('tbody tr').at(1)!.findAll('td');
        expect(1 + Number(aperta[1].attributes('colspan'))).toBeGreaterThanOrEqual(colonne);
    });
});
