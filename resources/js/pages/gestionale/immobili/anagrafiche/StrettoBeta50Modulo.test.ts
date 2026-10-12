// @vitest-environment jsdom

/**
 * 1.11.0-beta.50, giro stretto sulle correzioni della Fase 1-bis — due pagine dei titolari dell'unità, con i rilievi della verifica delle
 * correzioni (verbale `piano_esecutivo_beta50.md`, «Le correzioni della Fase 1-bis», «Restano senza test o con un difetto»).
 *
 * - R11, il riquadro verde dopo «Registra passaggio» (`AnagraficheList.vue`): alla fine di una locazione senza un nuovo inquilino, con
 *   «Scrivi», nessuno entra; la coppia è di chi paga al posto dell'inquilino, e il riquadro lo nomina con la sua cifra e il suo estratto
 *   conto (`al_posto` del server). Era giusto già prima e nessun test lo teneva: il test fissa il riquadro, e cade togliendo il riquadro
 *   (mutazione in una copia). Con due persone al posto dell'inquilino il verbo restava al singolare («…, € 90,74 a debito di Rita, che
 *   paga al posto dell'inquilino»): era lo stesso difetto della R20, e il test lo fissa. Il verbo segue le persone di `al_posto`, non le
 *   coppie: con il box spuntato Ugo da solo ha due coppie, e la guardia tiene il singolare (un plurale deciso sulle coppie la fa cadere).
 * - R13, la scheda delle pertinenze nella locazione (`PassaggioPertinenzeCard.vue`, montata dal modulo `PassaggioNew.vue`): la nota dice
 *   che «la data del passaggio e la scelta sul conguaglio valgono insieme per l'unità e per le pertinenze spuntate», anche nell'inizio
 *   della locazione, dove la scelta sul conguaglio non c'è (decisione 77: l'inizio legge la scelta della fine). Nell'inizio la nota deve
 *   dire solo la data, e il test lo fissa; nella fine la nota resta quella con la scelta (controllo).
 *
 * Le cifre: «Inquilino» € 360,00 sull'Interno 1; Ines esce il 1/7, 184 giorni dopo l'uscita: 36000 × 184/365 = 18147,95 → la coppia
 * 18148 (€ 181,48). Con Ugo e Rita proprietari al 50 % ciascuno: 18148 / 2 = 9074 (€ 90,74) a testa, esatto.
 *
 * Che cosa NON copre: il server che compone `al_posto` (il test PHP di R11); la congiunzione fra le due cifre del riquadro (una
 * virgola, non decisa); il riquadro della successione, della vendita e del cambio d'inquilino (rami di prima, non toccati); la scheda
 * delle pertinenze nella vendita, nell'usufrutto e nella successione; il modo scuro e le larghezze.
 */

import { describe, expect, test, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';

const stato = vi.hoisted(() => ({ flash: {} as Record<string, unknown> }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    usePage: () => ({ props: { flash: stato.flash, auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));

vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasPermission: () => true, hasRole: () => true }),
}));

vi.mock('axios', () => ({ default: { post: vi.fn(() => new Promise(() => {})) } }));

(globalThis as unknown as { route: unknown }).route = (name: string) => `/${name}`;

const AnagraficheList = (await import('./AnagraficheList.vue')).default;
const PassaggioNew = (await import('./PassaggioNew.vue')).default;
const PassaggioPertinenzeCard = (await import('@/components/gestionale/immobili/PassaggioPertinenzeCard.vue')).default;

// --- R11: il riquadro verde dopo la fine senza un nuovo inquilino -----------------------------------------------------------------

type AlPosto = { nome: string; frase: string; estratto_conto: string };

/** Il riquadro come lo manda il server (`PassaggioController::store`) per la fine di Ines il 1/7 con «Scrivi», senza un nuovo inquilino. */
function fineScritta(alPosto: AlPosto[]): Record<string, unknown> {
    return {
        subentro_id: 9, frase: 'Ines risulterà inquilino fino al 30 giugno 2026 compreso.', coppie: alPosto.length, conguaglio: '€ 181,48',
        conguaglio_rovesciato: false, riassegnate: 0, riassegnate_frase: null, rinuncia: false, non_scritto: false,
        documento: null, promemoria: null, avvisi: [], entrante: null, eredi: [], per_erede: [], per_erede_testa: null,
        al_posto: alPosto, azioni: { estratto_conto: null, anagrafe: null },
    };
}

const UGO_TUTTO: AlPosto = { nome: 'Ugo', frase: '€ 181,48 a debito di Ugo', estratto_conto: '/estratto/1' };
const UGO_META: AlPosto = { nome: 'Ugo', frase: '€ 90,74 a debito di Ugo', estratto_conto: '/estratto/1' };
const RITA_META: AlPosto = { nome: 'Rita', frase: '€ 90,74 a debito di Rita', estratto_conto: '/estratto/5' };

/** La pagina dei titolari dell'Interno 1, appena tornata da «Registra passaggio» con il riquadro dato. */
function montaLaLista(registrato: Record<string, unknown>) {
    stato.flash = { passaggio_registrato: registrato };

    return shallowMount(AnagraficheList, {
        props: {
            condominio: { id: 1, nome: 'Condominio Demo' } as never,
            immobile: { id: 7, nome: 'Interno 1', interno: '1', anagrafiche: [] } as never,
            storico: { passaggi: 1, periodi_chiusi: 0, righe: [], gruppi: [], subentri: [] } as never,
            oggi: '2026-10-12',
            prospettoOneri: null,
        },
        // I layout sono segnaposto che rendono il loro contenuto: il riquadro sta dentro il layout dell'unità.
        global: { renderStubDefaultSlot: true, mocks: { route: (name: string) => `/${name}` } },
    });
}

/** Il testo del riquadro verde, gli spazi uniti. */
function riquadro(w: ReturnType<typeof montaLaLista>): string {
    const testa = w.findAll('p').find((p) => p.text() === 'Passaggio registrato.');
    if (!testa) throw new Error('Il riquadro «Passaggio registrato.» non c\'è.');

    return (testa.element.closest('.space-y-3')?.textContent ?? '').replace(/\s+/g, ' ');
}

/** Gli estratti conto del riquadro: [testo del pulsante, indirizzo]. */
function estratti(w: ReturnType<typeof montaLaLista>): [string, string][] {
    return w.findAll('a').filter((a) => a.text().includes('Estratto conto')).map((a) => [a.text().replace(/\s+/g, ' ').trim(), a.attributes('href') ?? '']);
}

describe('AnagraficheList — R11, il riquadro dopo la fine di Ines con «Scrivi» e senza un nuovo inquilino', () => {
    test('il riquadro dice la coppia di Ugo, che paga al posto dell\'inquilino, con il suo estratto conto; non «di chi entra»', () => {
        const w = montaLaLista(fineScritta([UGO_TUTTO]));
        const testo = riquadro(w);

        expect(testo).toContain('Ines risulterà inquilino fino al 30 giugno 2026 compreso.');
        expect(testo).toContain('Conguaglio scritto in saldi: 1 coppia a somma zero, € 181,48 a debito di Ugo, che paga al posto dell\'inquilino nei giorni dopo l\'uscita. Il prossimo piano rate le assorbe.');
        expect(testo).not.toContain('di chi entra');
        expect(estratti(w)).toEqual([['Estratto conto di Ugo', '/estratto/1']]);
        expect(testo).not.toContain('Estratto conto di chi entra');
    });

    test('la guardia — nel cambio d\'inquilino (Luca entra, nessun `al_posto`) il riquadro dice ancora «a debito di chi entra», con l\'estratto di Luca', () => {
        const w = montaLaLista({ ...fineScritta([]), coppie: 1, entrante: 'Luca', azioni: { estratto_conto: '/estratto/3', anagrafe: '/anagrafe/3' } });

        expect(riquadro(w)).toContain('Conguaglio scritto in saldi: 1 coppia a somma zero, € 181,48 a debito di chi entra.');
        expect(estratti(w)).toEqual([['Estratto conto di Luca', '/estratto/3']]);
    });

    test('la guardia — Ugo solo, con il Box 12 spuntato: 2 coppie ma una persona, e il verbo resta al singolare', () => {
        // Come lo manda il server: con il box le coppie sono due, una per unità (Interno 1 a 900 millesimi: 120000 × 900/1000 × 30 % =
        // 32400, 32400 × 184/365 = 16333,15 → 16333; Box 12 a 100: 3600 × 184/365 = 1814,79 → 1815), e `al_posto` ha una riga per
        // persona, con la somma 16333 + 1815 = 18148. Il verbo segue le persone, non le coppie.
        const w = montaLaLista({ ...fineScritta([UGO_TUTTO]), coppie: 2 });
        const testo = riquadro(w);

        expect(testo).toContain('Conguaglio scritto in saldi: 2 coppie a somma zero, € 181,48 a debito di Ugo, che paga al posto dell\'inquilino nei giorni dopo l\'uscita.');
        expect(testo).not.toContain('che pagano');
        expect(estratti(w)).toEqual([['Estratto conto di Ugo', '/estratto/1']]);
    });

    test('con Ugo e Rita proprietari al 50 %, il riquadro dice le due cifre e il verbo al plurale: «che pagano al posto dell\'inquilino»', () => {
        const w = montaLaLista(fineScritta([UGO_META, RITA_META]));
        const testo = riquadro(w);

        // Controlli: le due coppie, le due cifre e i due estratti conto.
        expect(testo).toContain('Conguaglio scritto in saldi: 2 coppie a somma zero');
        expect(testo).toContain('€ 90,74 a debito di Ugo');
        expect(testo).toContain('€ 90,74 a debito di Rita');
        expect(estratti(w)).toEqual([['Estratto conto di Ugo', '/estratto/1'], ['Estratto conto di Rita', '/estratto/5']]);

        expect(testo, 'con due persone il verbo è al singolare').not.toContain('che paga al posto dell\'inquilino');
        expect(testo).toContain('che pagano al posto dell\'inquilino nei giorni dopo l\'uscita');
    });
});

// --- R13: la nota della scheda delle pertinenze nell'inizio della locazione -------------------------------------------------------

const UGO = { id: 10, anagrafica: { id: 1, nome: 'Ugo', codice_fiscale: null }, tipologia: 'proprietario', quota: 100, data_inizio: '2019-01-01', data_fine: null };
const INES = { id: 11, anagrafica: { id: 2, nome: 'Ines', codice_fiscale: null }, tipologia: 'inquilino', quota: 100, data_inizio: '2019-01-01', data_fine: null };
const LUCA = { id: 5, nome: 'Luca', codice_fiscale: null, indirizzo: 'Via Roma 1' };

/** Il modulo della locazione dell'Interno 1 con il Box 12 collegato, e la scheda delle pertinenze montata davvero (non un segnaposto). */
function montaIlModulo(tipo: 'inizio_locazione' | 'fine_locazione') {
    stato.flash = {};
    const inizio = tipo === 'inizio_locazione';

    return shallowMount(PassaggioNew, {
        props: {
            condominio: { id: 1, nome: 'Condominio Demo' } as never,
            immobile: { id: 7, nome: 'Interno 1' } as never,
            tipo,
            rigaPreselezionata: inizio ? null : 11,
            titolari: inizio ? [UGO] : [UGO, INES],
            anagrafiche: [LUCA],
            pertinenze: [{ id: 20, nome: 'Box 12', etichetta: 'Box 12', titolari: inizio ? [{ nome: 'Ugo', tipologia: 'proprietario' }] : [{ nome: 'Ugo', tipologia: 'proprietario' }, { nome: 'Ines', tipologia: 'inquilino' }] }],
            rateEmesseCount: inizio ? 0 : 6,
        },
        global: { renderStubDefaultSlot: true, stubs: { PassaggioPertinenzeCard: false }, mocks: { route: (name: string) => `/${name}` } },
    });
}

/** Il testo della scheda delle pertinenze, gli spazi uniti. */
function scheda(w: ReturnType<typeof montaIlModulo>): string {
    const card = w.findComponent(PassaggioPertinenzeCard);
    if (!card.exists()) throw new Error('La scheda delle pertinenze non c\'è.');

    return card.text().replace(/\s+/g, ' ');
}

/** La frase falsa nell'inizio: la scelta sul conguaglio che vale insieme per l'unità e per le pertinenze. */
const SCELTA_CHE_VALE = /scelta sul conguaglio\s+(vale|valgono)/;

describe('PassaggioNew — R13, la nota della scheda delle pertinenze nella locazione', () => {
    test('nell\'inizio della locazione di Luca la scheda non dice che «la scelta sul conguaglio» vale per le pertinenze: lì la scelta non c\'è', () => {
        const testo = scheda(montaIlModulo('inizio_locazione'));

        // Controlli: la scheda c'è, con il Box 12, e la nota è quella della locazione (non l'art. 818 c.c. sul titolo).
        expect(testo).toContain('Box 12');
        expect(testo).toContain('contratto');
        expect(testo).not.toContain('art. 818');

        expect(testo).not.toMatch(SCELTA_CHE_VALE);
    });

    test('la guardia — nella fine della locazione di Ines la nota dice ancora che la data e la scelta sul conguaglio valgono anche per le pertinenze spuntate', () => {
        const testo = scheda(montaIlModulo('fine_locazione'));

        expect(testo).toContain('Box 12');
        expect(testo).toMatch(SCELTA_CHE_VALE);
        expect(testo).toContain('per l\'unità e per le pertinenze spuntate');
    });
});
