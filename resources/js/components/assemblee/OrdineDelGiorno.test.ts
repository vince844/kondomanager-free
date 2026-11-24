// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * L'ordine del giorno della pagina dell'assemblea (§3 P2 del progetto della 1.12.0-beta.13; scena 1 della bozza della pagina, scena 2b della bozza delle
 * scene della tappa 2).
 *
 * - I punti con il numero e i badge: la maggioranza, «Votano i conduttori», «Memo», «Solo la scala B» e «Tabella «Ascensore B»»; badge `rounded-md`.
 * - Vuoto: «Nessun punto: spunta quelli proposti o «Aggiungi un punto»».
 * - Le proposte che non ci sono ancora, spuntate di serie: togliendo la spunta, «Non spuntato: resta per la prossima assemblea.»; «Aggiungi N punti» manda
 *   le sole chiavi spuntate con la maggioranza, in una visita parziale.
 * - Dalla convocazione, niente pulsanti e la frase.
 * - La finestra del punto riceve il gruppo dell'assemblea (Coda 734) e, per i condòmini di un gruppo, il nome della scena 11 della bozza approvata.
 *
 * Che cosa non copre: la finestra del punto (i due menu della scala e della tabella si provano a video), il riordino.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const post = vi.fn();
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (n: string) => n, hasPermission: () => true }) }));
vi.mock('@inertiajs/vue3', async (importOriginal) => ({ ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    router: { post: (...a: unknown[]) => post(...a), patch: vi.fn(), delete: vi.fn(), reload: vi.fn() } }));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const Ordine = (await import('./OrdineDelGiorno.vue')).default;
const scelte = { tipi: [], perimetri: [], modalita: [], materie: [{ valore: 'lavori', nome: 'Lavori' }], maggioranze: [{ valore: 'ordinaria', nome: 'Ordinaria' },
    { valore: 'meta_del_valore', nome: 'Metà del valore' }], esiti: [], a_chi: [], unita: 26, palazzine: [], scale: [], tabelle: [], maggioranze_proposte: {} };
const assemblea = { id: 7, titolo: 'Assemblea ordinaria di Via Roma 12', data: '2026-12-15', in_preparazione: true, stato: 'in_preparazione', tabella: { nome: 'Proprietà generale' } };
const punto = (n: number, extra: Record<string, unknown> = {}) => ({
    id: n, numero: n, testo: `Il punto ${n}`, testo_pubblico: null, materia: 'altro', materia_parola: 'Altro', maggioranza: 'ordinaria', maggioranza_parola: 'Ordinaria',
    maggioranza_libera: null, avviso: null, amministrazione: 'ordinaria', conduttore: null, conduttore_parola: null, ambito: null, palazzina_id: null, scala_id: null,
    perimetro_parola: null, tabella_id: null, tabella_nome: null, tabella_controllo: null, esercizio_id: null, importo: null, fonte: 'a_mano', chiave_fonte: null,
    da_memo: false, delibere: [], ...extra,
});

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; post.mockReset(); document.body.innerHTML = ''; });
const monta = async (props: Record<string, unknown>) => {
    w = mount(Ordine, { attachTo: document.body, props: { condominioId: 3, condominioNome: 'Via Roma 12', assemblea, ordine: [], scelte, gestisce: true, ...props } });
    await flushPromises();
};

describe('l\'ordine del giorno', () => {
    test('i punti con i badge della bozza; vuoto la sua frase', async () => {
        await monta({ ordine: [
            punto(5, { testo: 'Riscaldamento: orari di accensione 2026/2027', conduttore: 'vota', conduttore_parola: 'Votano i conduttori' }),
            punto(7, { testo: 'Sostituzione delle funi dell\'ascensore della scala B: impresa e spesa di € 8.200,00', maggioranza: 'meta_del_valore',
                maggioranza_parola: 'Metà del valore', perimetro_parola: 'Solo la scala B', tabella_nome: 'Ascensore B', da_memo: true }),
            punto(8, { testo: 'Varie ed eventuali', maggioranza: 'senza_voto', maggioranza_parola: 'Senza voto' }),
        ] });
        const t = w!.text().replace(/\s+/g, ' ');
        expect(t).toContain('Votano i conduttori');
        expect(w!.get('[data-testid="badge-perimetro"]').text()).toBe('Solo la scala B');
        expect(w!.get('[data-testid="badge-tabella"]').text()).toBe('Tabella «Ascensore B»');
        expect(t).toContain('Memo');
        expect(w!.findAll('[data-testid="badge-maggioranza"]').map((b) => b.text())).toEqual(['Ordinaria', 'Metà del valore', 'Senza voto']);
        expect(w!.html()).not.toContain('rounded-full');
        w!.unmount();
        await monta({ ordine: [] });
        expect(w!.get('[data-testid="ordine-vuoto"]').text()).toBe('Nessun punto: spunta quelli proposti o «Aggiungi un punto».');
    });

    test('le proposte spuntate di serie; tolta la spunta resta per la prossima; «Aggiungi» manda le sole chiavi spuntate, in una visita parziale', async () => {
        const proposta = (chiave: string, testo: string) => ({ chiave_fonte: chiave, testo, testo_pubblico: testo, frase: null, materia: 'altro', maggioranza: 'ordinaria',
            avviso: null, amministrazione: 'ordinaria', origine: 'dal_programma', fonte: 'programma', spuntato: true, memo_id: null, oggetti: [], esercizio_id: null, importo: null });
        await monta({ ordine: [punto(1)], proposte: [{ chiave: 'dal_programma', titolo: 'Dal programma', punti: [proposta('fattura:45', 'Ratifica della spesa'),
            proposta('piano_rate:12', 'Lavori sulla facciata')] }] });
        expect(w!.get('[data-testid="aggiungi-proposte"]').text()).toContain('Aggiungi 2 punti');
        await w!.findAll('[data-testid="proposte-da-aggiungere"] [role="checkbox"]')[1].trigger('click');
        expect(w!.text()).toContain('Non spuntato: resta per la prossima assemblea.');
        await w!.get('[data-testid="aggiungi-proposte"]').trigger('click');
        expect(post).toHaveBeenCalledWith('/gestionale.assemblee.punti.dalle-proposte', { proposte: ['fattura:45'], maggioranze: { 'fattura:45': 'ordinaria' } },
            expect.objectContaining({ only: ['ordine', 'proposte', 'passi', 'flash'], preserveScroll: true }));
    });

    test('l\'ordine del giorno ha il suo id (la voce «Aggiungi» del passo 1 porta qui); con il passo «Convocazione» già letto i gesti lo rileggono', async () => {
        const proposta = { chiave_fonte: 'fattura:45', testo: 'Ratifica della spesa', testo_pubblico: null, frase: null, materia: 'altro', maggioranza: 'ordinaria',
            avviso: null, amministrazione: 'ordinaria', origine: 'dal_programma', fonte: 'programma', spuntato: true, memo_id: null, oggetti: [], esercizio_id: null, importo: null };
        await monta({ ordine: [punto(1)], rileggi: ['convocazione'], proposte: [{ chiave: 'dal_programma', titolo: 'Dal programma', punti: [proposta] }] });
        expect(w!.get('[data-testid="ordine-del-giorno"]').attributes('id')).toBe('ordine-del-giorno');
        await w!.get('[data-testid="aggiungi-proposte"]').trigger('click');
        expect(post).toHaveBeenCalledWith('/gestionale.assemblee.punti.dalle-proposte', expect.anything(),
            expect.objectContaining({ only: ['ordine', 'proposte', 'passi', 'flash', 'convocazione'] }));
        // La finestra «Aggiungi un punto» legge la stessa maggioranza scelta nel riquadro.
        expect(w!.findComponent({ name: 'FinestraDelPunto' }).props('maggioranzeScelte')).toEqual({ 'fattura:45': 'ordinaria' });
    });

    test('la finestra del punto riceve il gruppo dell\'assemblea e, per i condòmini di un gruppo, il nome della scena 11', async () => {
        const Finestra = (await import('./FinestraDelPunto.vue')).default;
        await monta({});
        expect(w!.findComponent(Finestra).props('gruppo')).toBeNull();
        expect(w!.findComponent(Finestra).props('assembleaNome')).toBe('Assemblea ordinaria di Via Roma 12 del 15/12/2026');
        w!.unmount();
        const gruppo = { tabella_id: 9, nome: 'Box', unita: 8 };
        await monta({ assemblea: { ...assemblea, titolo: 'Assemblea straordinaria di Via Roma 12', tipo_parola: 'Straordinaria', data: '2026-12-14', gruppo } });
        expect(w!.findComponent(Finestra).props('gruppo')).toEqual(gruppo);
        expect(w!.findComponent(Finestra).props('assembleaNome')).toBe('Assemblea straordinaria dei condòmini della tabella «Box» · 14/12/2026');
    });

    test('dalla prima spedizione niente pulsanti, e la frase', async () => {
        await monta({ ordine: [punto(1)], assemblea: { ...assemblea, in_preparazione: false, stato: 'convocata' } });
        expect(w!.find('[data-testid="aggiungi-punto"]').exists()).toBe(false);
        expect(w!.find('[data-testid="menu-punto"]').exists()).toBe(false);
        expect(w!.text()).toContain('Dalla prima spedizione date e ordine del giorno non si cambiano più: si annulla e si riconvoca.');
    });
});
