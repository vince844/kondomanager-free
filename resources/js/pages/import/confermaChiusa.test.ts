// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Coda 759 (1) e (2) e Coda 646 (1.12.0-beta.14, tappa 5), con le risposte di Vincenzo a D1 e D3 (decisione 707 (2) e (4)), come la bozza
 * approvata docs/design/beta14_importazione_bozza.html con la variante di D1:
 * - il riconoscimento: «Puoi proseguire lo stesso» solo per i file che non fermano niente; quello che ferma sta sopra, con «senza, persone e saldi non entrano»;
 * - l'anteprima con lo scarto: il motivo nel riquadro dei saldi e accanto al pulsante spento, «Ricarica i file», «Importa senza i saldi» con
 *   l'elenco di ciò che non entra, che manda la conferma con `senza_saldi`;
 * - l'anteprima con i saldi che tornano: «Se non tornasse, i saldi non entrerebbero», e nessun «Importa senza i saldi»;
 * - l'avviso giallo dei due esercizi aperti, sopra il pulsante.
 * Le props sono come le mandano `ImportController::riconoscimento()` e `anteprima()`; nomi e cifre inventati.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';

const post = vi.fn();
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => ({ props: { flash: {} } }),
    router: { post: (...a: unknown[]) => post(...a), put: vi.fn(), delete: vi.fn() },
}));
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const Anteprima = (await import('./ImportAnteprima.vue')).default;
const Riconoscimento = (await import('./ImportRiconoscimento.vue')).default;

const STUBS = { AppLayout: { template: '<div><slot /></div>' }, PageHeaderGuide: { template: '<div />' }, ImportGuide: { template: '<div />' } };
const MOCKS = { route: (nome: string) => `/${nome}` };

function anteprima(saldi: Record<string, unknown>, altro: Record<string, unknown> = {}) {
    return {
        lotto: { uuid: 'u-1', stato: 'in_corso' },
        anteprima: {
            condominio: { nome: 'Condominio Esempio', codice_fiscale: null, indirizzo: 'Via Roma 12', esercizio: { etichetta: '2026', dal: '01/01/2026', al: '31/12/2026', solare: true } },
            collisioni: { totale: 0, voci: [] },
            titolarita: { totale: 2, unita_coinvolte: 2, unita_senza_titolare: [], elenco: [] },
            persone: { totale: 2, senza_codice_fiscale: 0, da_dividere: [] },
            unita: { totale: 2, elenco: [] },
            tabelle: [],
            saldi: {
                righe: 6, totale_riferimento: '€ 3.024,30', somma_righe: '€ 3.014,30', arrotondamenti: '€ 0,00', scarto: '€ 10,00', quadra: false,
                fonte: 'riparto consuntivo', verificabile: true, da_titolari_cessati: 0, importo_cessati: '€ 0,00', ...saldi,
            },
            totale_record: 14,
        },
        decisioni: {},
        confermabile: false,
        senza_saldi: { possibile: false, motivi: [], non_entra: [] },
        esercizi_aperti: null,
        ...altro,
    };
}

const CON_LO_SCARTO = {
    confermabile: false,
    senza_saldi: {
        possibile: true,
        motivi: [{
            codice: 'saldi.non_quadrano',
            motivo: 'Lo scarto è di € 10,00: la somma dei saldi letti non torna con il totale scritto nel riparto.',
            strada: 'Controlla nel file le righe dei saldi (una cella cambiata, una riga in più o in meno) e ricaricalo.',
        }],
        non_entra: [{ cosa: 'Saldi di apertura', dettaglio: '6 posizioni dal riparto consuntivo' }],
    },
};

let pagina: VueWrapper | null = null;

afterEach(() => {
    pagina?.unmount();
    pagina = null;
    post.mockReset();
});

const pulsante = (w: VueWrapper, testo: string) => w.findAll('button').find((b) => b.text().trim().startsWith(testo));

describe('l\'anteprima con i saldi che non entrano', () => {
    test('il pulsante principale è spento, il motivo sta accanto e nel riquadro dei saldi, e c\'è «Ricarica i file»', () => {
        pagina = mount(Anteprima, { props: anteprima({}, CON_LO_SCARTO) as never, global: { stubs: STUBS, mocks: MOCKS } });

        expect(pulsante(pagina, 'Importa 14 record')?.attributes('disabled')).toBeDefined();
        expect(pagina.find('[data-motivo-pulsante]').text()).toBe('Lo scarto è di € 10,00: la somma dei saldi letti non torna con il totale scritto nel riparto.');
        expect(pagina.find('[data-saldi-motivo]').text()).toContain('I saldi non entrano.');
        expect(pagina.find('[data-saldi-motivo]').text()).toContain('una cella cambiata, una riga in più o in meno');
        expect(pagina.text()).not.toContain('non ti farei proseguire');
        expect(pagina.findAll('a').map((a) => a.text())).toContain('Ricarica i file');
    });

    test('«Importa senza i saldi» dice prima che cosa non entra e chi scrive i saldi, e manda la conferma senza i saldi', async () => {
        pagina = mount(Anteprima, { props: anteprima({}, CON_LO_SCARTO) as never, global: { stubs: STUBS, mocks: MOCKS } });

        const avviso = pagina.find('[data-senza-saldi]').text();
        expect(avviso).toContain('Con «Importa senza i saldi» entra tutto il resto.');
        expect(avviso).toContain('Saldi di apertura: 6 posizioni dal riparto consuntivo');
        expect(avviso).toContain('I saldi li scrivi tu, dopo, dalla pagina «Saldi iniziali» del condominio');

        const senza = pulsante(pagina, 'Importa senza i saldi');
        expect(senza?.attributes('disabled')).toBeUndefined();
        await senza!.trigger('click');
        expect(post).toHaveBeenCalledWith('/import.conferma', { senza_saldi: true }, expect.anything());
    });

    test('con una decisione ancora da prendere «Importa senza i saldi» aspetta, come il pulsante principale', () => {
        const props = anteprima({}, CON_LO_SCARTO);
        (props.anteprima.persone as Record<string, unknown>).da_dividere = [{ chiave: 'k', nome: 'UGO / ELSA', pezzi: ['UGO', 'ELSA'] }];
        pagina = mount(Anteprima, { props: props as never, global: { stubs: STUBS, mocks: MOCKS } });

        expect(pulsante(pagina, 'Importa senza i saldi')?.attributes('disabled')).toBeDefined();
    });
});

describe('l\'anteprima con i saldi che tornano', () => {
    test('nessun «Importa senza i saldi», e la frase dice il vero: se non tornasse, i saldi non entrerebbero', () => {
        pagina = mount(Anteprima, { props: anteprima({ scarto: '€ 0,00', quadra: true }, { confermabile: true }) as never, global: { stubs: STUBS, mocks: MOCKS } });

        expect(pulsante(pagina, 'Importa senza i saldi')).toBeUndefined();
        expect(pulsante(pagina, 'Importa 14 record')?.attributes('disabled')).toBeUndefined();
        expect(pagina.text()).toContain('Se non tornasse, i saldi non entrerebbero.');
        expect(pagina.find('[data-saldi-motivo]').exists()).toBe(false);
    });

    test('l\'avviso giallo dei due esercizi aperti sta sopra il pulsante, e non lo spegne', () => {
        pagina = mount(Anteprima, {
            props: anteprima({ scarto: '€ 0,00', quadra: true }, {
                confermabile: true,
                esercizi_aperti: {
                    nomi: ['2025', '2026'], piu_recente: '2026', titolo: 'Resteranno aperti due esercizi: «2025» e «2026».',
                    testo: 'Incassi, pagamenti e saldi che registri da qui in poi vanno nel «2026»; le rate di ogni piano vanno nell\'esercizio del suo piano. Quando hai finito con il «2025», chiudilo.',
                },
            }) as never,
            global: { stubs: STUBS, mocks: MOCKS },
        });

        const avviso = pagina.find('[data-esercizi-aperti]');
        expect(avviso.text()).toContain('Resteranno aperti due esercizi: «2025» e «2026».');
        expect(avviso.text()).toContain('vanno nel «2026»');
        expect(pulsante(pagina, 'Importa 14 record')?.attributes('disabled')).toBeUndefined();
    });
});

describe('il riconoscimento', () => {
    const base = { lotto: { uuid: 'u-1', stato: 'in_corso' }, file: [], tipi: [] };

    test('quello che ferma sta sopra con «senza, persone e saldi non entrano»; gli altri sotto, «quando vuoi, anche dopo»', () => {
        pagina = mount(Riconoscimento, {
            props: { ...base, mancanti: [
                { cosa: 'l\'Elenco unità', serve_per: 'persone, unità e chi possiede cosa', bloccante: true },
                { cosa: 'l\'Anagrafica con i millesimi', serve_per: 'le tabelle millesimali', bloccante: false },
            ] } as never,
            global: { stubs: { ...STUBS, 'v-select': { template: '<div />' } }, mocks: MOCKS },
        });

        expect(pagina.text()).not.toContain('Puoi proseguire lo stesso');
        expect(pagina.find('[data-mancanti="bloccanti"]').text()).toContain('senza, persone e saldi non entrano');
        expect(pagina.find('[data-mancanti="facoltativi"]').text()).toContain('Questi puoi aggiungerli quando vuoi, anche dopo:');
        expect(pagina.find('[data-mancanti="facoltativi"]').text()).not.toContain('senza, persone e saldi non entrano');
    });

    test('senza niente che ferma resta «Puoi proseguire lo stesso»', () => {
        pagina = mount(Riconoscimento, {
            props: { ...base, mancanti: [{ cosa: 'l\'Anagrafica con i millesimi', serve_per: 'le tabelle millesimali', bloccante: false }] } as never,
            global: { stubs: { ...STUBS, 'v-select': { template: '<div />' } }, mocks: MOCKS },
        });

        expect(pagina.text()).toContain('Puoi proseguire lo stesso e aggiungere questi file quando vuoi.');
        expect(pagina.text()).not.toContain('senza, persone e saldi non entrano');
    });
});
