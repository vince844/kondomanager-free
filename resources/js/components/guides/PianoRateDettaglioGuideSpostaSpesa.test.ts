// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La guida della pagina del piano dice dove «Sposta spesa», lo storno e il cestino non lavorano, con le stesse regole del server
 * (`BudgetMovementController::store`, `BudgetMovementService::controllaSpesaNegativa`, `controllaCapitoloSenzaCifra`,
 * `controllaStornoSuUnaVoceNegativa`, `PianoRateController::detachCapitolo`), e che la card dello scaduto netta per persona.
 */

import { readFileSync } from 'node:fs';
import { describe, expect, test } from 'vitest';
import { mount } from '@vue/test-utils';

import PianoRateDettaglioGuide from './PianoRateDettaglioGuide.vue';

function testoDellaGuida(): string {
    const w = mount(PianoRateDettaglioGuide, {
        props: { open: true },
        global: {
            stubs: {
                Sheet: { template: '<div><slot /></div>' }, SheetContent: { template: '<div><slot /></div>' }, SheetHeader: { template: '<div><slot /></div>' },
                SheetTitle: { template: '<div><slot /></div>' }, SheetDescription: { template: '<div><slot /></div>' },
            },
        },
    });

    return w.text().replace(/\s+/g, ' ');
}

describe('PianoRateDettaglioGuide — «Sposta spesa», lo storno, il cestino e lo scaduto', () => {
    test('nel piano straordinario «Sposta spesa» e il cestino non ci sono', () => {
        const testo = testoDellaGuida();

        expect(testo).toContain('Nei piani straordinari «Sposta spesa» non c\'è: le voci del piano sono le fatture.');
        expect(testo).not.toContain('il programma lo rifiuta sempre');
        expect(testo).toContain('Nei piani straordinari il cestino non c\'è: una fattura non si toglie da qui, si elimina il piano e lo si ricrea con le fatture giuste.');
    });

    test('l\'entrata scritta con il meno, il capitolo senza cifra e lo storno', () => {
        const testo = testoDellaGuida();

        expect(testo).toContain('Entrata scritta con il meno — vale la stessa regola: non cede e non riceve budget');
        expect(testo).toContain('Capitolo senza cifra — vale il suo importo più i sottoconti, come nelle rate.');
        // La strada che esiste (`BudgetMovementService::fraseDelCapitoloSenzaCifra`): il piano non ha un campo per la cifra.
        expect(testo).toContain('Non cede e non riceve budget finché non ha una cifra nel piano, salvo che ogni sottoconto abbia la sua riga. Il piano non ha un campo per scriverla: togli il capitolo con «Rimuovi voce e ricalcola» e rimettilo con «Sincronizza», che gli scrive la somma dei sottoconti.');
        expect(testo).not.toContain('finché non scrivi la sua cifra nel piano');
        expect(testo).toContain('Lo storno — non rimette budget su una voce che nel frattempo è scritta con il meno');
    });

    // Le card sono in un'altra scheda della guida, che il montaggio non apre: si legge il sorgente.
    test('la card dello scaduto: il credito di un\'altra persona non toglie mai', () => {
        const sorgente = readFileSync('resources/js/components/guides/PianoRateDettaglioGuide.vue', 'utf8').replace(/\s+/g, ' ');

        expect(sorgente).toContain('mai da quello di un\'altra persona della stessa unità. La cifra è la stessa nella scheda per persona e in quella per unità.');
    });
});
