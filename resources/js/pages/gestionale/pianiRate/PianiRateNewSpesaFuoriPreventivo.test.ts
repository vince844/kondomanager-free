// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Coda 686 (decisioni 506 e 510): il nuovo piano rate di una spesa non prevista, dalla pagina vera.
 *
 * - (c) le due schede si chiamano «Dalle voci del preventivo» e «Spese fuori preventivo», con sotto la riga che dice dove vanno le spese registrate su una
 *   voce; l'integrativa è un'etichetta accanto al nome, non un altro nome;
 * - (a) il carrello vuoto dice che cosa mostra e che cosa non si vede (la fattura sulla voce, con il passaggio all'altra scheda e la voce già scelta; l'altro
 *   esercizio; il piano che la contiene già);
 * - (b) il pulsante, senza fatture scelte, dice «Scegli almeno una fattura per salvare» e non manda la richiesta; gli errori dello scudo legale stanno sotto
 *   i due campi (D194), e quelli di un campo che la pagina non mostra sopra il pulsante;
 * - (e) il piano che verrebbe a € 0,00 lo dice prima di salvare.
 *
 * Si monta la pagina vera (con la cornice, la guida e i moduli di contorno sostituiti); le risposte del server sono quelle di `axios`, con la forma delle
 * rotte vere (`SpesaNonPrevistaARateTest` le prova dal server).
 *
 * Cosa resta scoperto: il disegno a 375 px e il tema scuro, provati a video; la «i» con l'esempio (un HoverCard, che si apre al passaggio del mouse).
 */

import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const axios = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock('axios', () => ({ default: axios }));
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    router: { get: (_u: string, _d: unknown, o: any) => o.onSuccess({ props: { saldoInfo: { applicabile: false } } }), post: vi.fn(), reload: vi.fn(), flushAll: vi.fn() },
    usePage: () => ({ props: { flash: {}, auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasPermission: () => true, hasRole: () => true }),
}));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

const PianiRateNew = (await import('@/pages/gestionale/pianiRate/PianiRateNew.vue')).default;

const stubs = {
    GestionaleLayout: { template: '<div><slot /></div>' },
    PageHeaderGuide: { template: '<div><slot /></div>' },
    PianoRateGuide: true,
    ScopertoWarning: true,
    DestinatariWarning: true,
    MoneyInput: { name: 'MoneyInput', props: ['modelValue'], template: '<input :value="modelValue" />' },
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
};

const ORDINARIA = { id: 35, nome: 'Ordinaria 2026', tipo: 'ordinaria', data_inizio: '2026-01-01' };
const STRAORDINARIA = { id: 36, nome: 'Spesa fuori bilancio', tipo: 'straordinaria', data_inizio: '2026-01-01' };
const VOCE = { id: 7, nome: '[CAPITOLO] Riparazione colonna', tipo: 'spesa', importo_totale: 1220, residuo: 1220, disabled: false, is_sforo: false, is_radice: true, parent_id: null };
const FUORI = {
    gestione: 'Spesa fuori bilancio',
    sulle_voci: { totale: 1, fatture: [{ id: 1, fornitore: 'Idraulica Srl', numero_documento: 'FT-ROB', importo: 1220, voce: 'Riparazione colonna', altre_voci: 0, conto_id: 7 }] },
    altri_esercizi: [{ esercizio_id: 2, esercizio: 'Esercizio 2027', quante: 1 }],
    gia_nei_piani: { totale: 1, fatture: [{ id: 3, fornitore: 'Edilizia Rossi', numero_documento: 'FT-9', piani: ['Primo tentativo'] }] },
};
const FATTURA = { id: 1, fornitore: 'Idraulica Srl', numero_documento: 'FT-ROB', data_documento: '20/10/2026', totale_straordinario: 1220, gia_finanziato: 0,
    residuo_da_finanziare: 1220, importo_suggerito: 1220, totale_netto: 1220, note_collegate: [], selezionata: false, ha_competenza: false, is_pregresso: false,
    senza_periodo: false, periodo_nell_esercizio: false, competenza: null };

let pagina: VueWrapper | null = null;

/** Monta la pagina, sceglie la scheda e la gestione; il server risponde con le voci, il carrello e ciò che il carrello non mostra dati. */
async function monta(opzioni: { tipo?: string; voci?: unknown[]; carrello?: unknown[]; fuori?: unknown; hasPianoEsistente?: boolean; gestione?: number | null } = {}) {
    axios.get.mockReset();
    axios.get.mockImplementation(async (url?: string) => ({
        data: url?.includes('fetch-capitoli-gestione') ? (opzioni.voci ?? [VOCE])
            : url?.includes('fetch-fatture-straordinarie') ? (opzioni.carrello ?? []).map((f) => ({ ...(f as object) }))
                : url?.includes('fetch-fatture-fuori-dal-carrello') ? (opzioni.fuori ?? FUORI) : [],
    }));
    pagina = mount(PianiRateNew, {
        attachTo: document.body,
        props: {
            condominio: { id: 28, nome: 'Condominio Demo KM' } as never, esercizio: { id: 41, nome: '2026' } as never,
            gestioni: [ORDINARIA, STRAORDINARIA] as never, saldoInfo: { saldo: 0, applicabile: false, motivo: '' }, anagraficheDisponibili: [],
            hasPianoEsistente: opzioni.hasPianoEsistente ?? false,
        },
        global: { stubs, mocks: { route: (n: string) => `/${n}` } },
    });
    await flushPromises();
    form().tipo = opzioni.tipo ?? 'straordinario';
    if (opzioni.gestione !== null) {
        form().gestione_id = opzioni.gestione ?? STRAORDINARIA.id;
    }
    await flushPromises();
}

const form = () => (pagina!.vm as any).form;
const testo = () => document.body.textContent!.replace(/\s+/g, ' ');
const pulsanteSalva = () => document.body.querySelector('[data-pulsante="salva"]') as HTMLButtonElement;

afterEach(() => {
    pagina?.unmount();
    pagina = null;
    document.body.innerHTML = '';
});

describe('(c) i nomi delle schede', () => {
    test('«Dalle voci del preventivo» e «Spese fuori preventivo», con la riga sotto; i nomi di prima non ci sono più', async () => {
        await monta({ gestione: null });

        expect(document.body.querySelector('[data-scheda="dalle-voci"]')!.textContent!.trim()).toBe('Dalle voci del preventivo');
        expect(document.body.querySelector('[data-scheda="fuori-preventivo"]')!.textContent!.trim()).toBe('Spese fuori preventivo');
        expect(document.body.querySelector('[data-testo="riga-sotto-le-schede"]')!.textContent!.replace(/\s+/g, ' ').trim())
            .toBe('Le spese registrate su una voce del piano dei conti, anche di una gestione straordinaria, si mettono a rate con «Dalle voci del preventivo».');
        expect(testo()).not.toContain('Piano rate ordinario');
        expect(testo()).not.toContain('Piano rate straordinario');
        expect(testo()).not.toContain('Piano Rata Integrativa');
    });

    test('l\'integrativa è un\'etichetta accanto al nome: la scheda resta «Dalle voci del preventivo»', async () => {
        await monta({ tipo: 'ordinario', hasPianoEsistente: true, gestione: ORDINARIA.id });

        expect(document.body.querySelector('[data-scheda="dalle-voci"]')!.textContent!.trim()).toBe('Dalle voci del preventivo');
        expect(testo()).toContain('Rata integrativa');
        expect(testo()).not.toContain('Piano Rata Integrativa');
    });
});

describe('(a) il carrello vuoto dice che cosa non si vede, e dove andare', () => {
    test('che cosa mostra la scheda, e l\'elenco: la fattura sulla voce, l\'altro esercizio, il piano che la contiene già', async () => {
        await monta();

        const blocco = document.body.querySelector('[data-blocco="carrello-vuoto"]')!;
        expect(blocco.querySelector('[data-testo="titolo"]')!.textContent!.trim()).toBe('Nessuna fattura da mettere a rate qui');
        expect(blocco.querySelector('[data-testo="che-cosa-mostra"]')!.textContent!.replace(/\s+/g, ' ').trim())
            .toBe('Qui ci sono le fatture di «Spesa fuori bilancio» con una riga «Fuori preventivo» o addebitata a una sola unità.');
        const righe = [...blocco.querySelectorAll('[data-elenco="fuori-dal-carrello"] li')].map((li) => li.textContent!.replace(/\s+/g, ' ').trim());
        expect(righe).toEqual([
            'Idraulica Srl, € 1.220,00, sulla voce «Riparazione colonna»: mettila a rate con «Dalle voci del preventivo» →',
            'Una fattura «Fuori preventivo» di questa gestione è registrata nell\'esercizio «Esercizio 2027»: si mette a rate con un piano di quell\'esercizio.',
            'Edilizia Rossi, n. FT-9: è già per intero nel piano «Primo tentativo».',
        ]);
        expect(axios.get).toHaveBeenCalledWith('/admin.gestionale.fetch-fatture-fuori-dal-carrello', { params: { gestione_id: STRAORDINARIA.id, esercizio_id: 41 } });
    });

    test('«mettila a rate» porta all\'altra scheda con la voce già scelta', async () => {
        await monta();

        (document.body.querySelector('[data-riga="sulla-voce"] button') as HTMLButtonElement).click();
        await flushPromises();

        expect(form().tipo).toBe('ordinario');
        expect(form().capitoli_ids).toEqual([VOCE.id]);
    });

    test('con la voce spenta nel menu porta all\'altra scheda senza sceglierla', async () => {
        await monta({ voci: [{ ...VOCE, disabled: true, residuo: 0 }] });

        (document.body.querySelector('[data-riga="sulla-voce"] button') as HTMLButtonElement).click();
        await flushPromises();

        expect(form().tipo).toBe('ordinario');
        expect(form().capitoli_ids).toEqual([]);
    });

    test('controprova: senza niente fuori dal carrello resta la frase di prima, e il carrello pieno non chiede niente', async () => {
        await monta({ fuori: { gestione: 'Spesa fuori bilancio', sulle_voci: { totale: 0, fatture: [] }, altri_esercizi: [], gia_nei_piani: { totale: 0, fatture: [] } } });
        expect(document.body.querySelector('[data-testo="nessuna-altra"]')).not.toBeNull();
        pagina!.unmount();
        document.body.innerHTML = '';

        await monta({ carrello: [FATTURA] });
        expect(document.body.querySelector('[data-blocco="carrello-vuoto"]')).toBeNull();
        expect(axios.get.mock.calls.some(([url]) => String(url).includes('fuori-dal-carrello'))).toBe(false);
    });
});

describe('(b) il pulsante e i messaggi', () => {
    test('senza fatture scelte il pulsante dice «Scegli almeno una fattura per salvare» e non manda la richiesta', async () => {
        await monta({ carrello: [FATTURA] });
        const invio = vi.spyOn(form(), 'post');

        expect(pulsanteSalva().textContent!.trim()).toBe('Scegli almeno una fattura per salvare');
        expect(pulsanteSalva().getAttribute('aria-disabled')).toBe('true');
        // P9 della lente P (Fase 1-bis della beta.12): la frase si legge a dimensione normale, con la maiuscola solo all'inizio (standard 3); con il codice
        // di prima prendeva la classe del pulsante «SALVA PIANO RATE», 10 px e tutto maiuscolo.
        expect(pulsanteSalva().className).not.toMatch(/(^|\s)(uppercase|text-\[10px\]|text-\[11px\])(\s|$)/);
        expect(pulsanteSalva().className).toMatch(/(^|\s)text-sm(\s|$)/);
        await pagina!.find('form').trigger('submit');
        expect(invio).not.toHaveBeenCalled();
    });

    test('scelta una fattura il pulsante torna «Salva piano rate» e manda la richiesta con la fattura', async () => {
        await monta({ carrello: [FATTURA] });
        const invio = vi.spyOn(form(), 'post').mockImplementation(() => undefined as never);
        (pagina!.vm as any).fattureStraordinarie[0].selezionata = true;
        await flushPromises();

        expect(pulsanteSalva().textContent!.trim()).toBe('Salva piano rate');
        await pagina!.find('form').trigger('submit');
        expect(invio).toHaveBeenCalledTimes(1);
        expect(form().fatture_config).toEqual([{ id: FATTURA.id, importo: '1220.00' }]);
    });

    test('controprova: su «Dalle voci del preventivo» il pulsante resta «Salva piano rate»', async () => {
        await monta({ tipo: 'ordinario' });

        expect(pulsanteSalva().textContent!.trim()).toBe('Salva piano rate');
        expect(pulsanteSalva().getAttribute('aria-disabled')).toBeNull();
    });

    test('D194: gli errori del tipo di autorizzazione e della motivazione stanno sotto i due campi (lo Scudo Legale, su una gestione ordinaria)', async () => {
        await monta({ carrello: [FATTURA], gestione: ORDINARIA.id });
        form().errors = {
            tipo_autorizzazione: 'Scegli il tipo di autorizzazione: «Delibera Assembleare» o «Urgenza».',
            motivazione_autorizzazione: 'Scrivi la motivazione o gli estremi del verbale, per esempio «Verbale del 12/03/2026».',
        };
        await flushPromises();

        const campo = (id: string) => document.body.querySelector(`#${id}`)!.closest('.space-y-2')!.textContent!;
        expect(campo('motivazione_autorizzazione')).toContain('Scrivi la motivazione o gli estremi del verbale, per esempio «Verbale del 12/03/2026».');
        expect(document.body.querySelector('label[for="tipo_autorizzazione"]')!.closest('.space-y-2')!.textContent)
            .toContain('Scegli il tipo di autorizzazione: «Delibera Assembleare» o «Urgenza».');
    });

    test('Coda 688: su una gestione straordinaria gli stessi errori stanno sotto i campi di «La delibera, o l\'urgenza», che prende il posto dello Scudo Legale', async () => {
        await monta({ carrello: [FATTURA] });
        form().tipo_autorizzazione = 'urgenza';
        form().errors = { motivazione_autorizzazione: 'Scrivi il motivo dell\'urgenza.' };
        await flushPromises();

        expect(document.body.querySelector('#motivazione_autorizzazione')).toBeNull();
        expect(document.body.querySelector('[data-testid="la-delibera-o-l-urgenza"]')!.textContent).toContain('Scrivi il motivo dell\'urgenza.');
        expect(document.body.querySelector('[data-testid="periodo-sulle-fatture"]')).not.toBeNull();
    });

    test('l\'errore di un campo che la pagina non mostra accanto al campo sta sopra il pulsante', async () => {
        await monta({ tipo: 'ordinario' });
        form().errors = { giorno_scadenza: 'Il giorno del mese delle scadenze è un numero intero, per esempio 5.' };
        await flushPromises();

        const tutto = testo();
        expect(tutto).toContain('Il giorno del mese delle scadenze è un numero intero, per esempio 5.');
        expect(tutto.indexOf('Il giorno del mese delle scadenze')).toBeLessThan(tutto.lastIndexOf('Salva piano rate'));
    });
});

describe('(e) il piano che verrebbe a € 0,00', () => {
    test('con l\'unica voce spenta (lo sforo rimandato al conguaglio) lo dice prima di salvare', async () => {
        await monta({ tipo: 'ordinario', voci: [{ ...VOCE, disabled: true, residuo: 0 }] });

        expect(document.body.querySelector('[data-avviso="piano-a-zero"]')!.textContent!.replace(/\s+/g, ' ').trim())
            .toBe('Il piano verrebbe a € 0,00: nessuna voce di «Spesa fuori bilancio» ha ancora qualcosa da mettere a rate. Le voci esaurite, o già in un altro piano, sono spente nel menu «Voci di bilancio incluse».');
    });

    test('controprova: con la voce accesa non dice niente', async () => {
        await monta({ tipo: 'ordinario' });

        expect(document.body.querySelector('[data-avviso="piano-a-zero"]')).toBeNull();
    });
});
