// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * «Rate scadute» con il debito ereditato (decisioni 494 (3) e 516). Nella prova a video della beta.12 (la successione di Massimo Bagnara del 15/11/2026) le righe
 * degli eredi non mostravano l'etichetta «ereditato» né la riga «da …, successione del …»: il server le mandava (lette nel database condiviso, in sola lettura),
 * ma la pagina le aveva solo nelle rate della persona, che si vedono aprendo la riga. Ora il debito ereditato si vede anche con la riga chiusa, accanto al nome,
 * con la cifra; aperta, la rata ha il badge e la frase del server («nella Rata 1 — … che scade il …» dopo l'emissione della rata nuova). Il badge è
 * `rounded-md`, non una pillola (decisione 399).
 *
 * Che cosa non copre: le cifre (il server, `InteressiEreditatiCorrezioniTest.php`); il tema scuro e il telefono (a video).
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';

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

const ereditato = (frase: string) => ({ da: 'Massimo Bagnara', da_anagrafica_id: 12, successione_del: '2026-11-15', subentro_id: 51, nelle_rate: [], fuori_rata: 3279, frase });
const rata = (id: number, unita: string, residuo: number, e: ReturnType<typeof ereditato> | null) => ({
    rata_id: id, immobile_id: 19, numero_rata: 1, piano: 'Gestione ordinaria 2026', unita, scadenza: '2026-03-13', residuo, giorni_ritardo: 250, pregresso: null,
    pregresso_nella_rata: false, sei_mesi: null, accordo: null, ereditato: e,
});
const tobia = {
    anagrafica_id: 13, nome: 'Tobia Cecchino', unita: ['Int. 1C', 'Int. 1B'], importo: 12774, credito: 0, fasce: { '1_30': 0, '31_90': 0, oltre_90: 12774 },
    ritardo_testo: 'in ritardo di 250 giorni', sei_mesi: { data: null, dispensa: null, mancano: 0 }, con_pregresso: false, origine_non_dichiarata: false, ultima_lettera: null,
    lettera_in_sospeso: null, accordi: [],
    rate: [rata(390, 'Int. 1C', 5000, null), rata(391, 'Int. 1B', 3279, ereditato('da Massimo Bagnara, successione del 15/11/2026, non ancora in una rata')), rata(391, 'Int. 1C', 4495, null)],
};
const proprieta = () => ({
    condominio: { id: 16, nome: 'Via roma' }, condomini: [], esercizio: { id: 29 },
    righe: { data: [tobia], meta: { current_page: 1, last_page: 1, per_page: 20, total: 1, from: 1, to: 1 } },
    totale: 12774, persone_totali: 1, oggi: '2026-11-18', non_emesse: null, gestioni: [], persona_della_porta: null, testo_norma: '«…»', frase_festivo: '',
    lettere_da_riprovare: 0, lettere_in_coda_da_tempo: 0, regole_del_recupero: '1,60 % annuo, tasso legale', suggerimenti: [],
    filtri: { alla: '2026-11-18', vista: 'scadute', giorni: 30, cerca: null, anagrafica_id: null, fascia: null, minimo: null, gestione_id: null, sei_mesi_entro: null,
        con_pregresso: false, ordine: 'scaduto', senza_lettere_da: null, mai_scritto: false, da_spedire: false, con_accordo: false },
});
const stubs = { GestionaleLayout: { template: '<div><slot /></div>' }, MovimentiLayout: { template: '<div><slot /></div>' }, PageHeaderGuide: true, LettereDialog: true,
    AccordoRientroDialog: true, ProponiInteressiDialog: true, AddebitaSpesaDialog: true };

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });
const pulito = (t: string) => t.replace(/\s+/g, ' ').trim();

describe('il debito ereditato in «Rate scadute»', () => {
    test('con la riga chiusa, accanto al nome: il badge «ereditato» e la cifra da chi, con la successione', () => {
        w = mount(Pagina, { attachTo: document.body, props: proprieta() as any, global: { stubs } });
        const testa = w.get('[data-testid="ereditato-in-testa"]');
        // Con il codice di prima l'etichetta c'era solo aprendo la riga.
        expect(pulito(testa.text())).toBe('ereditato € 32,79 da Massimo Bagnara, successione del 15/11/2026');
        const badge = testa.get('div');
        expect(badge.classes()).toContain('rounded-md');
        expect(badge.classes()).not.toContain('rounded-full');
        expect(w.find('[data-testid="badge-ereditato"]').exists()).toBe(false);
    });

    test('aperta, la rata ereditata ha il badge e la frase del server; le due rate 391 di unità diverse restano due righe', async () => {
        w = mount(Pagina, { attachTo: document.body, props: proprieta() as any, global: { stubs } });
        await w.get('button[aria-label="Apri le rate"]').trigger('click');
        expect(w.findAll('[data-testid="badge-ereditato"]')).toHaveLength(1);
        expect(pulito(w.get('[data-testid="ereditato-da"]').text())).toContain('da Massimo Bagnara, successione del 15/11/2026, non ancora in una rata');
        expect(w.findAll('tr.align-top')).toHaveLength(3);
    });

    test('dopo l\'emissione della rata nuova la riga dice la rata e la sua scadenza (decisione 516)', async () => {
        const p = proprieta();
        p.righe.data[0].rate[1].ereditato = ereditato('da Massimo Bagnara, successione del 15/11/2026, nella Rata 1 — Saldi della successione che scade il 15/12/2026');
        w = mount(Pagina, { attachTo: document.body, props: p as any, global: { stubs } });
        await w.get('button[aria-label="Apri le rate"]').trigger('click');
        expect(pulito(w.get('[data-testid="ereditato-da"]').text())).toContain('nella Rata 1 — Saldi della successione che scade il 15/12/2026');
    });
});
