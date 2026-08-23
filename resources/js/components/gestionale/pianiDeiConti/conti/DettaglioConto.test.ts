// @vitest-environment jsdom

/**
 * Il pannello di dettaglio di una voce di spesa: il consuntivo che non c'era, e la voce
 * classificata per quello che è.
 *
 * ## Da dove nasce
 *
 * Segnalazione dal forum su 1.9.1: «ho caricato una fattura di pulizia scale, mi aspettavo di
 * vedere il budget eroso e vedo soltanto il budget assegnato e la copertura». La beta.30 aveva
 * messo il consuntivo nell'elenco a sinistra, nel totale di testata e nella finestra dei
 * movimenti — ma **non** in questo pannello, che è esattamente dove l'amministratore guarda
 * quando apre una voce.
 *
 * ## Cosa questi test difendono
 *
 * 1. **Il preventivo mostrato è quello deliberato, non lo speso.** `PianoContiController` porta
 *    `conto.importo` al maggiore fra preventivo e spesa — è il *fabbisogno*, e va bene per la
 *    barra di copertura — ma leggere quel numero e chiamarlo preventivo significa mostrare a
 *    video una voce che «non ha mai sforato», qualunque cosa sia successo. Il preventivo vero è
 *    `budget_originale_raw`.
 * 2. **Una voce senza preventivo mostra un trattino, non € 0,00.** Le sopravvenienze nascono
 *    fuori preventivo: uno zero calcolato e un budget assente non sono la stessa cosa, ed è la
 *    convenzione già scelta dalla beta.30 per l'elenco.
 * 3. **Un capitolo è tale perché è dichiarato tale.** L'euristica precedente — nessun padre e
 *    importo a zero — sbagliava in due direzioni, e quella non ovvia è questa: su un capitolo
 *    con una spesa diretta l'importo *non* è zero, perché il gonfiaggio del controller scorre
 *    anche i conti radice. Il capitolo veniva quindi trattato come una voce normale.
 *
 * ## Cosa NON copre
 *
 * - `percentuale_copertura` e `stato_copertura`, che restano deliberatamente immobili in questa
 *   beta: sono calcolati sul fabbisogno, coerentemente con cruscotto e piani rate, e oggi non
 *   hanno alcun test. Vanno coperti *prima* di toccarli, non dopo.
 * - La rimozione del gonfiaggio in `PianoContiController`, rimandata alla 1.10.1.
 */

import { describe, expect, test } from 'vitest';
import { mount } from '@vue/test-utils';

const DettaglioConto = (await import('./DettaglioConto.vue')).default;

type ContoDiProva = Record<string, unknown>;

const voceBase: ContoDiProva = {
    id: 7,
    nome: 'Pulizia scale',
    tipo: 'spesa',
    parent_id: 3,
    is_capitolo: false,
    importo: '€ 5.000,00',
    importo_raw: 500000,
    budget_originale_raw: 500000,
    speso_raw: 0,
    impegnato: 0,
    percentuale_copertura: 0,
    tabelle_millesimali: [],
    sottoconti: [],
};

const montaCon = (conto: ContoDiProva) =>
    mount(DettaglioConto, {
        props: { conto, condominioId: 1, esercizioId: 1, gestioneId: 1 },
        global: {
            stubs: { Link: { template: '<a><slot /></a>' } },
        },
    });

/**
 * I valori del riquadro «Informazioni», letti per etichetta.
 *
 * Non si asserisce sul testo dell'intero componente: «Eccedenza» compare anche nella legenda
 * dei colori della barra di copertura, quindi un `not.toContain` globale sarebbe sempre falso.
 * E `euro()` separa simbolo e cifre con uno spazio **unificatore**: cercare «€ 0,00» scritto
 * con lo spazio normale non corrisponde mai, cioè passa qualunque cosa succeda a video. Le
 * cifre si confrontano quindi normalizzando lo spazio.
 */
const valoriPerEtichetta = (wrapper: ReturnType<typeof montaCon>): Record<string, string> =>
    Object.fromEntries(
        wrapper.findAll('label').map((l) => [
            l.text().trim(),
            (l.element.parentElement?.querySelector('p')?.textContent ?? '')
                .replace(/ /g, ' ')
                .trim(),
        ]),
    );

describe('preventivo e consuntivo', () => {
    test('su una voce in sforo mostra il preventivo deliberato, non lo speso', () => {
        // Il caso che il controller gonfia: preventivo € 5.000,00, speso € 6.000,00.
        // `importo`/`importo_raw` arrivano già portati a 600000; il preventivo vero resta in
        // `budget_originale_raw`.
        const valori = valoriPerEtichetta(montaCon({
            ...voceBase,
            importo: '€ 6.000,00',
            importo_raw: 600000,
            budget_originale_raw: 500000,
            speso_raw: 600000,
        }));

        expect(valori['Preventivo']).toBe('€ 5.000,00');
        expect(valori['Consuntivo']).toBe('€ 6.000,00');
    });

    test("su una voce in sforo dichiara l'eccedenza, non un residuo", () => {
        const valori = valoriPerEtichetta(montaCon({
            ...voceBase,
            importo: '€ 6.000,00',
            importo_raw: 600000,
            budget_originale_raw: 500000,
            speso_raw: 600000,
        }));

        expect(valori['Eccedenza']).toBe('€ 1.000,00');
        expect(valori).not.toHaveProperty('Residuo');
    });

    test('su una voce dentro il budget mostra il residuo', () => {
        const valori = valoriPerEtichetta(montaCon({ ...voceBase, speso_raw: 200000 }));

        expect(valori['Residuo']).toBe('€ 3.000,00');
        expect(valori).not.toHaveProperty('Eccedenza');
    });

    test('una voce senza spesa mostra un trattino al consuntivo, non € 0,00', () => {
        const valori = valoriPerEtichetta(montaCon({ ...voceBase, speso_raw: 0 }));

        // «Non ancora speso» non è «zero euro».
        expect(valori['Consuntivo']).toBe('—');
        expect(valori['Preventivo']).toBe('€ 5.000,00');
    });

    test('una sopravvenienza senza preventivo non viene marcata come sforo', () => {
        // Nasce fuori preventivo per definizione: differenza negativa, ma nessun budget superato.
        const valori = valoriPerEtichetta(montaCon({
            ...voceBase,
            importo: '€ 800,00',
            importo_raw: 80000,
            budget_originale_raw: 0,
            speso_raw: 80000,
        }));

        expect(valori['Preventivo']).toBe('—');
        expect(valori['Consuntivo']).toBe('€ 800,00');
        // Nessuna colonna di differenza: senza budget non c'è né residuo né eccedenza.
        expect(valori).not.toHaveProperty('Eccedenza');
        expect(valori).not.toHaveProperty('Residuo');
    });
});

describe('classificazione della voce', () => {
    test('un capitolo con spesa diretta resta un capitolo, anche con importo diverso da zero', () => {
        // Il gonfiaggio del controller scorre anche i conti radice: qui `importo_raw` vale 250000
        // e la vecchia euristica `parent_id === null && importo_raw === 0` lo dava per voce
        // normale, con il campo Importo e la card di copertura che a un capitolo non spettano.
        const valori = valoriPerEtichetta(montaCon({
            ...voceBase,
            nome: 'Manutenzioni ordinarie',
            parent_id: null,
            is_capitolo: true,
            importo: '€ 2.500,00',
            importo_raw: 250000,
            budget_originale_raw: 0,
            speso_raw: 250000,
        }));

        expect(valori).not.toHaveProperty('Preventivo');
        expect(valori).not.toHaveProperty('Consuntivo');
    });

    test('un sottoconto non ancora budgetato non viene scambiato per un capitolo', () => {
        const valori = valoriPerEtichetta(montaCon({
            ...voceBase,
            importo: '€ 0,00',
            importo_raw: 0,
            budget_originale_raw: 0,
            speso_raw: 0,
        }));

        expect(valori).toHaveProperty('Preventivo');
    });
});

describe('etichette della barra di copertura', () => {
    test('il denominatore della barra si chiama fabbisogno, non preventivato', () => {
        const testo = montaCon({ ...voceBase, impegnato: 250000, percentuale_copertura: 50 }).text();

        expect(testo).toContain('Fabbisogno');
        expect(testo).not.toContain('Preventivato');
    });

    test('la didascalia non promette che il piano rate sia stato emesso', () => {
        // `BudgetCoverageService` conta anche i piani in bozza, e `StatoPianoRate` non ha
        // nemmeno uno stato «emesso»: la frase precedente descriveva un filtro inesistente.
        const testo = montaCon({ ...voceBase, impegnato: 250000, percentuale_copertura: 50 }).text();

        expect(testo).toContain('in bozza o approvato');
        expect(testo).not.toContain('emesso ai condòmini');
    });
});
