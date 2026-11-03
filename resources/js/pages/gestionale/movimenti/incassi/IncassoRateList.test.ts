// @vitest-environment jsdom

/**
 * 1.11.0-beta.50 — la conferma del riallineamento dei crediti dice la data vera (correzione rimasta dalla .49, verbale
 * `docs/piano_esecutivo_beta49.md`, «Chiusura», «Da fare nella .50, dalla Fase 5»).
 *
 * La finestra «Riallinea i crediti» della card «Crediti da riallineare» diceva che le scritture si scrivono «nella data e
 * nell'esercizio della scrittura che correggono». Non è vero per la data: `RiallineaCreditiService` scrive la **data di
 * competenza** della scrittura che corregge e la **registrazione** del giorno in cui si scrive, che è quella che il Libro Giornale
 * mostra (il controllo sulle due date è in `tests/Feature/Gestionale/CorrezioniDellaBeta49Test.php`). Chi cerca la rettifica nel
 * giornale alla data della compensazione non la trova.
 *
 * Perché un test di pagina e non di un modulo in `resources/js/lib/`: il testo nasce in un `computed` dentro la pagina
 * (`IncassoRateList.vue`, `testoConferma`), e il difetto sta in ciò che la finestra mostra. Si monta la pagina con
 * `shallowMount` (i componenti figli diventano segnaposto che rendono il loro contenuto) e si legge il testo della finestra; se la
 * correzione sposta il testo in un modulo, il test resta valido perché guarda la pagina.
 *
 * Che cosa copre: la frase con una scrittura e con due, al singolare e al plurale; che dica la competenza e non «la data della
 * scrittura che correggono»; che dica anche il giorno della registrazione, oggi, che è la data che il Libro Giornale mostra
 * (aggiunto dalla revisione: «solo competenza» passava); che resti la frase sulle scritture esistenti (controllo). Il testo si
 * legge nella sola `DialogDescription` della conferma, non in tutta la pagina.
 *
 * Che cosa NON copre: la stessa frase nel changelog della .49 (va nell'errata della .50, che nessun test legge); la card in sola
 * lettura; il messaggio del server dopo la conferma; il modo scuro e le larghezze (verifica a video).
 */

import { describe, expect, test, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    usePage: () => ({ props: { flash: {}, auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));

vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasPermission: () => true, hasRole: () => true }),
}));

// `route()` di Ziggy va su globalThis: la pagina lo chiama anche fuori dal template.
(globalThis as unknown as { route: unknown }).route = (name: string) => `/${name}`;

const IncassoRateList = (await import('./IncassoRateList.vue')).default;

function monta(compensazioni: number, rate: number) {
    return shallowMount(IncassoRateList, {
        props: {
            condominio: { id: 28, nome: 'Condominio Demo KM' } as never,
            condomini: [] as never,
            esercizio: { id: 5, nome: 'Esercizio 2026' } as never,
            esercizi: [] as never,
            movimenti: { data: [], meta: {} },
            stats: { totale_incassi: 0, incassi_mese: 0, stornati: 0 },
            filters: {},
            riallineamento_crediti: {
                compensazioni: Array.from({ length: compensazioni }, (_, i) => ({
                    scrittura_id: 100 + i, protocollo: `2026/${100 + i}`, data: '15/03/2026', quota_id: 10 + i, numero_rata: 0, persona: 'Ugo Uno', importo: 5000,
                })),
                rate: Array.from({ length: rate }, (_, i) => ({
                    rata_id: 200 + i, numero_rata: 1 + i, piano: 'Preventivo 2026', azione: 'scrivi' as const, persone: [{ nome: 'Ugo Uno', spesa: 5000, credito: 20000 }],
                })),
                da_emettere: [],
                restano: [],
            },
        },
        global: {
            // I segnaposto rendono il loro contenuto: la finestra di conferma (Dialog → DialogContent → DialogDescription) è nel DOM
            // anche chiusa, e il suo testo si legge come lo legge l'amministratore quando la apre.
            renderStubDefaultSlot: true,
            mocks: { route: (name: string) => `/${name}` },
        },
    });
}

/**
 * Il testo della sola finestra di conferma (la `DialogDescription` che parla del libro giornale), non di tutta la pagina: le
 * parole cercate sotto non devono poter arrivare da un'altra parte della pagina (revisione dei test della Fase 1).
 */
function finestra(compensazioni: number, rate: number): string {
    return monta(compensazioni, rate).findAllComponents({ name: 'DialogDescription' }).map((c) => c.text()).find((t) => t.includes('libro giornale')) ?? '';
}

describe('la conferma del riallineamento dice la data di competenza', () => {
    test.each([
        ['una scrittura', 0, 1],
        ['due scritture', 1, 1],
    ])('con %s: dice la competenza e la registrazione di oggi, non «nella data e nell\'esercizio della scrittura che correggono»', (_, compensazioni, rate) => {
        const testo = finestra(compensazioni, rate);

        // Controllo: è la finestra giusta, e il numero delle scritture è quello dato.
        expect(testo).toContain(compensazioni + rate === 1 ? 'Verrà scritta nel libro giornale 1 scrittura' : `Verranno scritte nel libro giornale ${compensazioni + rate} scritture`);
        expect(testo).toContain('Le scritture esistenti non si modificano.');

        // Il difetto corretto: la data non è quella della scrittura corretta, è la sua competenza; la registrazione è di oggi.
        expect(testo).not.toMatch(/nella data e nell'esercizio della scrittura che corregg/);
        expect(testo).toContain('competenza');
        // Revisione dei test della Fase 1: una correzione che dice solo «nella data di competenza» passava (mutazione provata), ma chi
        // cerca la rettifica nel libro giornale la cerca alla data che il giornale mostra, la registrazione, che è di oggi (verbale
        // della .49, «Chiusura»: «la registrazione è del giorno in cui si scrive, ed è quella che il Libro Giornale mostra»). Il testo
        // resta libero: basta che dica il giorno, «oggi» o «il giorno in cui si scrive/scrivono».
        expect(testo).toMatch(/\boggi\b|giorno in cui (si )?scriv/i);
    });
});

// Aggiunto con il giro di mutazioni sulle correzioni della .50: il verbo segue il numero delle scritture (M153).
describe('la conferma del riallineamento, singolare e plurale del verbo', () => {
    test('una scrittura «prende» la data di competenza, due «prendono»', () => {
        expect(finestra(0, 1)).toContain('Prende la data di competenza e l\'esercizio della scrittura che corregge');
        expect(finestra(1, 1)).toContain('Prendono la data di competenza e l\'esercizio della scrittura che correggono');
    });
});

// ─── Coda 313 (1.11.0-beta.59): le emissioni con la spesa ad personam addebitata due volte ──────────────────────────────────────
//
// La decisione 153.1: dei dati già scritti, solo il rilevamento. La card elenca, per rata e unità, la cifra addebitata due volte, il
// piano e la data, con frasi vere per costruzione (che cosa è doppio e dove; le rate dei condòmini sono giuste), e il pulsante non le
// tocca: la conferma conta solo le scritture che fa. Le cifre e le frasi del server sono in `Beta59313AdPersonamRilevamentoTest`.

type Riallineamento = Record<string, unknown>;

function montaCon(riallineamento: Riallineamento) {
    return shallowMount(IncassoRateList, {
        props: {
            condominio: { id: 28, nome: 'Condominio Demo KM' } as never,
            condomini: [] as never,
            esercizio: { id: 5, nome: 'Esercizio 2026' } as never,
            esercizi: [] as never,
            movimenti: { data: [], meta: {} },
            stats: { totale_incassi: 0, incassi_mese: 0, stornati: 0 },
            filters: {},
            riallineamento_crediti: { compensazioni: [], rate: [], da_emettere: [], restano: [], ad_personam: [], ad_personam_non_calcolabili: [], ...riallineamento } as never,
        },
        global: { renderStubDefaultSlot: true, mocks: { route: (name: string) => `/${name}` } },
    });
}

const voce = (extra: Record<string, unknown> = {}) => ({
    rata_id: 7, numero_rata: 1, piano: 'Balcone interno 1', immobile_id: 3, unita: 'Int. 1', data: '01/04/2026', esercizio: 'Esercizio 2026',
    esercizio_aperto: true, importo: 61000, ...extra,
});

/** Il testo della card con gli spazi normali al posto di quelli non divisibili dell'importo. */
const testo = (w: ReturnType<typeof montaCon>) => w.text().replace(/ /g, ' ');

describe('Coda 313 — la card elenca la spesa ad personam addebitata due volte, in sola lettura', () => {
    test('con le sole voci della spesa ad personam: il perché in chiaro, la voce con la cifra, nessun pulsante', () => {
        const w = montaCon({ ad_personam: [voce(), voce({ rata_id: 8, numero_rata: 2, data: '01/05/2026' })] });
        const t = testo(w);

        expect(w.find('[data-testid="riallineamento-ad-personam"]').exists()).toBe(true);
        expect(t).toContain('Le rate dei condòmini sono giuste: nessuno deve pagare di più.');
        expect(t).toContain('la fattura l\'ha già scritta su «Crediti verso condomini» con l\'unità');
        expect(t).toContain('Il riallineamento non la corregge: qui è solo elencata.');
        expect(t).toContain('Rata 1 (Balcone interno 1), Int. 1, emessa il 01/04/2026: € 610,00 di spesa ad personam addebitati due volte.');
        expect(t).toContain('Rata 2 (Balcone interno 1), Int. 1, emessa il 01/05/2026: € 610,00 di spesa ad personam addebitati due volte.');
        // Il pulsante si conta sulle scritture da fare: qui nessuna. E il paragrafo delle voci che restano non c'è, perché non ce ne sono.
        expect(t).not.toContain('Scrivi le correzioni');
        expect(t).not.toContain('Niente da scrivere: il riallineamento lascia queste voci come sono.');
    });

    test('un\'emissione in un esercizio chiuso lo dice nella voce', () => {
        const t = testo(montaCon({ ad_personam: [voce({ esercizio_aperto: false })] }));

        expect(t).toContain('€ 610,00 di spesa ad personam addebitati due volte (esercizio «Esercizio 2026», chiuso).');
    });

    test('il piano per cui la cifra non si calcola ha la sua voce, con il perché del server', () => {
        const t = testo(montaCon({ ad_personam_non_calcolabili: [{ piano_id: 4, piano: 'Facciata 2025', motivo: 'il piano «Facciata 2025» non ha il registro del riparto' }] }));

        expect(t).toContain('Da controllare a mano: il piano «Facciata 2025» non ha il registro del riparto.');
        expect(t).not.toContain('Scrivi le correzioni');
    });

    test('con una compensazione da rettificare il pulsante c\'è, e la conferma conta solo la scrittura che fa', () => {
        const w = montaCon({
            compensazioni: [{ scrittura_id: 100, protocollo: '2026/100', data: '15/03/2026', quota_id: 10, numero_rata: 0, persona: 'Ugo Uno', importo: 5000 }],
            ad_personam: [voce(), voce({ rata_id: 8, numero_rata: 2 })],
        });
        const conferma = w.findAllComponents({ name: 'DialogDescription' }).map((c) => c.text()).find((x) => x.includes('libro giornale')) ?? '';

        expect(testo(w)).toContain('Scrivi le correzioni');
        expect(conferma).toContain('Verrà scritta nel libro giornale 1 scrittura: la rettifica di una compensazione.');
    });
});

describe('Coda 313 — con i soli piani da controllare a mano la card non dice che la spesa è addebitata due volte', () => {
    test('niente frase del doppio, che il programma non ha misurato: la frase dei piani da controllare', () => {
        const w = montaCon({ ad_personam_non_calcolabili: [{ piano_id: 4, piano: 'Facciata 2025', motivo: 'il piano «Facciata 2025» non ha il registro del riparto' }] });
        const t = testo(w);

        expect(w.find('[data-testid="riallineamento-ad-personam"]').exists()).toBe(false);
        expect(t).not.toContain('Spesa ad personam addebitata due volte');
        expect(w.find('[data-testid="riallineamento-ad-personam-da-controllare"]').exists()).toBe(true);
        expect(t).toContain('per i piani elencati nel dettaglio il programma non sa calcolare se le emissioni delle rate l\'hanno addebitata due volte.');
    });

    test('con una voce e un piano da controllare resta la frase del doppio, e non quella dei soli piani', () => {
        const w = montaCon({ ad_personam: [voce()], ad_personam_non_calcolabili: [{ piano_id: 4, piano: 'Facciata 2025', motivo: 'il piano «Facciata 2025» non ha il registro del riparto' }] });

        expect(w.find('[data-testid="riallineamento-ad-personam"]').exists()).toBe(true);
        expect(w.find('[data-testid="riallineamento-ad-personam-da-controllare"]').exists()).toBe(false);
    });
});
