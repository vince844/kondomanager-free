// @vitest-environment jsdom

/**
 * beta.51 — Il terzo livello del piano dei conti, da invisibile a segnalato.
 *
 * Il piano dei conti prevede **due** livelli: il capitolo raggruppa, il sottoconto porta
 * l'importo e la tabella millesimale. Dalla beta.16 la validazione impedisce di creare un
 * terzo livello, ma fino alla 1.9.1 il menu «capitolo padre» elencava ogni voce con importo 0
 * — e un sottoconto non ancora budgetato ha importo 0. Compariva quindi indistinguibile da un
 * capitolo vero, e sceglierlo creava un terzo livello con due clic. Nessuna migrazione
 * appiattisce quei dati.
 *
 * Il difetto non era nel componente, che è sempre stato ricorsivo: era nel controller, che
 * caricava un livello solo. La voce esisteva nel database, impediva di cancellare il padre
 * — «contiene sottoconti» — e non compariva da nessuna parte. Invisibile e non eliminabile.
 *
 * ## Cosa questi test difendono
 *
 * Due proprietà opposte, e la seconda conta quanto la prima:
 *
 * 1. una voce di terzo livello **si vede** e porta il contrassegno «fuori struttura», che è la
 *    via d'uscita per chi ce l'ha;
 * 2. su un piano dei conti **sano** quel contrassegno non compare da nessuna parte. Un avviso
 *    che si accende anche quando va tutto bene viene ignorato entro la seconda occorrenza, e a
 *    quel punto tanto vale non averlo scritto.
 *
 * ## Cosa NON copre
 *
 * - I totali di pagina e la stampa della distinta, che con tre livelli sottostimano già oggi:
 *   lasciati fuori dalla beta.51 per scelta esplicita, il rimedio è appiattire la struttura.
 * - Il quarto livello e oltre: il caricamento lato server si ferma a due livelli di discendenza.
 */

import { describe, expect, test } from 'vitest';
import { mount } from '@vue/test-utils';

const AlberoDeiConti = (await import('./AlberoDeiConti.vue')).default;

type ContoDiProva = {
    id: number;
    nome: string;
    is_capitolo: boolean;
    importo?: string;
    importo_raw?: number;
    sottoconti?: ContoDiProva[];
};

function conto(id: number, nome: string, extra: Partial<ContoDiProva> = {}): ContoDiProva {
    return { id, nome, is_capitolo: false, importo_raw: 0, ...extra };
}

function monta(conti: ContoDiProva[]) {
    return mount(AlberoDeiConti, {
        props: { conti: conti as never },
        global: {
            stubs: {
                // Il tooltip degli importi non c'entra con la struttura e monta un portale.
                TooltipProvider: { template: '<div><slot /></div>' },
                Tooltip: { template: '<div><slot /></div>' },
                TooltipTrigger: { template: '<div><slot /></div>' },
                TooltipContent: { template: '<div />' },
            },
        },
    });
}

/**
 * Il contrassegno è un'icona dentro un tooltip: il testo visibile compare solo all'apertura,
 * che in jsdom non si può simulare in modo affidabile. Si cerca quindi l'`aria-label`, che
 * porta lo stesso identico messaggio ed è il canale per chi il tooltip non lo vede comunque.
 */
function segnalatiFuoriStruttura(w: ReturnType<typeof monta>): string[] {
    return w
        .findAll('[aria-label]')
        .map((n) => n.attributes('aria-label') ?? '')
        .filter((t) => t.includes('fuori struttura'));
}

describe('la voce di terzo livello si vede', () => {
    test('il nipote compare nell\'albero, non solo il figlio', () => {
        const w = monta([
            conto(1, 'Spese ordinarie', {
                is_capitolo: true,
                sottoconti: [
                    conto(2, 'Spese amministrative', {
                        is_capitolo: true,
                        sottoconti: [conto(3, 'Compenso amministratore', { importo_raw: 240000 })],
                    }),
                ],
            }),
        ]);

        expect(w.text()).toContain('Spese ordinarie');
        expect(w.text()).toContain('Spese amministrative');
        // È questa la riga che prima non esisteva a video.
        expect(w.text()).toContain('Compenso amministratore');
    });

    test('porta il contrassegno «fuori struttura», e lo porta solo lui', () => {
        const w = monta([
            conto(1, 'Spese ordinarie', {
                is_capitolo: true,
                sottoconti: [
                    conto(2, 'Spese amministrative', {
                        is_capitolo: true,
                        sottoconti: [conto(3, 'Compenso amministratore', { importo_raw: 240000 })],
                    }),
                ],
            }),
        ]);

        const segnalati = segnalatiFuoriStruttura(w);

        // Uno solo: il capitolo e il contenitore intermedio sono ai loro posti legittimi.
        expect(segnalati).toHaveLength(1);
        // L'avviso deve dire cosa fare, non solo che c'è un problema.
        expect(segnalati[0]).toContain('due livelli');
        expect(segnalati[0]).toContain('primo livello');
        // E deve nominare la conseguenza che costa denaro: un avviso puramente strutturale
        // («questa voce è nel posto sbagliato») si rimanda a domani. Questa riga è anche il
        // presidio contro il rischio opposto — che il testo resti così dopo che qualcuno avrà
        // corretto la generazione, diventando falso nella beta che lo corregge.
        expect(segnalati[0]).toContain('NON la addebita');
    });

    test('un contenitore non marcato capitolo mostra comunque i suoi figli', () => {
        // La discesa non può appoggiarsi al solo `is_capitolo`: una voce fuori struttura può
        // avere figli senza essere marcata capitolo, e in quel caso i figli tornerebbero
        // invisibili — cioè il difetto da cui veniamo, in un'altra forma.
        const w = monta([
            conto(1, 'Spese ordinarie', {
                is_capitolo: true,
                sottoconti: [
                    conto(2, 'Spese amministrative', {
                        is_capitolo: false,
                        sottoconti: [conto(3, 'Compenso amministratore', { importo_raw: 240000 })],
                    }),
                ],
            }),
        ]);

        expect(w.text()).toContain('Compenso amministratore');
    });
});

describe('su un piano dei conti sano non si accende niente', () => {
    test('due livelli: nessun contrassegno «fuori struttura»', () => {
        const w = monta([
            conto(1, 'Spese ordinarie', {
                is_capitolo: true,
                sottoconti: [conto(2, 'Compenso amministratore', { importo_raw: 240000 })],
            }),
        ]);

        expect(w.text()).toContain('Compenso amministratore');
        expect(segnalatiFuoriStruttura(w)).toHaveLength(0);
    });

    test('un capitolo senza sottoconti continua a dirlo, invece di tacere', () => {
        const w = monta([conto(1, 'Manutenzione giardino', { is_capitolo: true })]);

        // La condizione di discesa è cambiata: va verificato che il ramo «altrimenti» regga.
        expect(w.text()).toContain('Nessun sottoconto');
        expect(segnalatiFuoriStruttura(w)).toHaveLength(0);
    });
});
