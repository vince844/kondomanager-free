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
