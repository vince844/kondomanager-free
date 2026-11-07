// @vitest-environment jsdom

/**
 * La pagina dell'incasso (1.12.0-beta.3, decisioni 217 e 218): chi l'ha registrato, a che cosa va il versamento e come si corregge una
 * dichiarazione sbagliata; se è stornato, chi, quando, da quando vale e perché. Le date arrivano dal server come `aaaa-mm-gg`: la pagina
 * le scrive come gg/mm/aaaa e basta. Gli incassi e gli storni di prima lo dicono.
 */

import { describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    usePage: () => ({ props: { auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));

import IncassoRateShow from './IncassoRateShow.vue';

(globalThis as any).route = (name: string) => `/${name}`;

const stubs = {
    GestionaleLayout: { template: '<div><slot /></div>' },
    PageHeaderGuide: { props: ['pageSubtitle'], template: '<div data-sottotitolo>{{ pageSubtitle }}</div>' },
    Head: { template: '<span />' },
    RicevutaDialog: true,
    ConfirmDialog: true,
};

function apri(props: Record<string, unknown>) {
    return mount(IncassoRateShow, {
        props: {
            condominio: { data: { id: 28, nome: 'Condominio Demo' } },
            esercizio: { id: 1 },
            condomini: [],
            incasso: { id: 7, numero_protocollo: 'INC-2026-00007', stato: 'registrata', causale: 'Bonifico', data_competenza: '2026-10-01', updated_at: '2026-10-01T10:00:00Z' },
            incassoFormatted: {
                dettagli_rate: [],
                pagante: { principale: 'Ugo Venditore', altri_count: 0, lista_completa: 'Ugo Venditore', ruolo: 'Proprietario', versato_da: null },
                cassa_nome: 'Banca', cassa_tipo_label: 'Conto corrente', importo_totale_formatted: '€ 100,00', gestione_nome: 'Ordinaria',
            },
            utenteCreatore: 'Anna Registra',
            utenteStornatore: null,
            imputazione: { codice: 'ordine_scadenze', testo: 'Secondo l\'ordine delle scadenze', nota: null },
            storno: null,
            ...props,
        },
        global: { stubs, mocks: { route: (name: string) => `/${name}` } },
    });
}

const testo = (w: any) => w.text().replace(/\s+/g, ' ');
/** Le righe del riquadro dello storno, come [etichetta, valore]. */
const righeStorno = (w: any) => Object.fromEntries(w.findAll('[data-testid="riquadro-storno"] .grid > div').map((d: any) => d.findAll('span').map((s: any) => s.text().trim())));

describe('l\'imputazione e chi ha registrato', () => {
    test('l\'imputazione con la nota, e come si corregge', () => {
        const w = apri({ imputazione: { codice: 'scelta_amministratore', testo: 'Scelta dall\'amministratore', nota: 'Accordo in assemblea' } });

        expect(w.find('[data-testid="imputazione"]').text().replace(/\s+/g, ' ')).toContain('Scelta dall\'amministratore: Accordo in assemblea');
        expect(testo(w)).toContain('Se la dichiarazione non è giusta: nell\'elenco degli incassi, dal menu della riga, «Storna» con «Registrazione sbagliata», poi registralo di nuovo con la data del versamento.');
        expect(w.find('[data-sottotitolo]').text()).toBe('Protocollo INC-2026-00007 — Registrato da Anna Registra');
    });

    test('un incasso di prima: l\'imputazione non dichiarata, e chi l\'ha registrato non si sa', () => {
        const w = apri({ utenteCreatore: null, imputazione: { codice: null, testo: 'Non dichiarata: registrato prima che il programma la chiedesse', nota: null } });

        expect(w.find('[data-testid="imputazione"]').text()).toContain('Non dichiarata: registrato prima che il programma la chiedesse');
        expect(w.find('[data-sottotitolo]').text()).toBe('Protocollo INC-2026-00007');
        expect(testo(w)).not.toContain('Sistema');
    });
});

describe('la via per correggere la dichiarazione', () => {
    test('c\'è per «indicata», «scelta» e «fuori ordine»; non per l\'ordine, la compensazione, gli incassi di prima e gli annullati', () => {
        const conLaVia = (codice: string | null, stato = 'registrata') =>
            testo(apri({ incasso: { id: 7, numero_protocollo: 'INC-2026-00007', stato, causale: 'Bonifico', data_competenza: '2026-10-01', updated_at: '2026-10-01T10:00:00Z' },
                imputazione: { codice, testo: 'x', nota: null } })).includes('Se la dichiarazione non è giusta');

        expect(['indicata_dal_versante', 'scelta_amministratore', 'fuori_ordine'].map((c) => conLaVia(c))).toEqual([true, true, true]);
        expect(['ordine_scadenze', 'compensazione', null].map((c) => conLaVia(c))).toEqual([false, false, false]);
        expect(conLaVia('fuori_ordine', 'annullata')).toBe(false);
    });
});

describe('lo storno', () => {
    test('chi, quando, da quando vale e perché; le date del server come gg/mm/aaaa', () => {
        const w = apri({
            incasso: { id: 7, numero_protocollo: 'INC-2026-00007', stato: 'annullata', causale: 'Bonifico', data_competenza: '2026-03-10', updated_at: '2026-03-26T00:30:00Z' },
            utenteStornatore: 'Bruno Storna',
            storno: { stornato_il: '2026-03-25', vale_dal: '2026-03-10', protocollo: 'RET-2026-00001', natura: 'errore_registrazione', natura_testo: 'Registrazione sbagliata: il versamento non c\'è mai stato, o va registrato di nuovo in un altro modo' },
        });
        expect(righeStorno(w)).toEqual({
            'Stornato il': '25/03/2026',
            'Vale dal': '10/03/2026',
            'Perché': 'Registrazione sbagliata: il versamento non c\'è mai stato, o va registrato di nuovo in un altro modo',
            'Stornato da': 'Bruno Storna',
        });
    });

    test('uno storno di prima dice che la natura e chi l\'ha fatto non sono scritti', () => {
        const w = apri({
            incasso: { id: 7, numero_protocollo: 'INC-2026-00007', stato: 'annullata', causale: 'Bonifico', data_competenza: '2026-03-10', updated_at: '2026-03-26T00:30:00Z' },
            utenteStornatore: null,
            storno: { stornato_il: '2026-03-20', vale_dal: '2026-03-20', protocollo: 'RET-2026-00001', natura: null, natura_testo: 'Natura non dichiarata: stornato prima che il programma la chiedesse' },
        });
        expect(righeStorno(w)).toMatchObject({ 'Perché': 'Natura non dichiarata: stornato prima che il programma la chiedesse', 'Stornato da': 'non indicato' });
        expect(testo(w)).not.toContain('Sconosciuto');
    });
});
