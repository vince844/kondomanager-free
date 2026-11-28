// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Le Code 742 e 743 nella guida del nuovo piano: il debito ereditato da ripianare (a schema, corto) e la voce a € 0 fra le spese fuori preventivo. Le parole
 * della guida sono quelle della pagina (`PianiRateNew.vue`, `ripianamento.ts`).
 */
import { describe, expect, test } from 'vitest';
import fs from 'node:fs';

const leggi = (f: string) => fs.readFileSync(f, 'utf8').replace(/\s+/g, ' ');
const guida = leggi('resources/js/components/guides/PianoRateGuide.vue');

describe('PianoRateGuide — il debito ereditato e la voce a € 0', () => {
    test('743: il debito ereditato da ripianare, a schema', () => {
        expect(guida).toContain('<h3 class="mb-2 font-bold text-slate-900 dark:text-slate-100">Il debito ereditato da ripianare</h3>');
        expect(guida).toContain('<li>Si apre da «Prepara il piano di ripianamento», nel compito «Deficit da recuperare» o nel seguito della delibera.</li>');
        // Il giro di correzioni della tappa 4: le due frasi dicono il vero (lo scoperto si legge oggi; il piano si prepara nell'esercizio della pregressa).
        expect(guida).toContain('<li>Il riquadro dice il fornitore, lo scoperto di oggi e il massimo. Lo scoperto è il debito meno i pregressi chiesti ai condòmini, '
            + 'anche quelli scritti dopo; con più debiti ereditati è uno per tutti, e quanto su ogni debito lo decide l\'assemblea.</li>');
        expect(guida).toContain('<li>Il programma non vede il denaro ereditato in banca: se c\'è, lo scoperto vero è minore.</li>');
        expect(guida).toContain('<li>Si prepara nell\'esercizio della pregressa: nel suo rendiconto è «da pagare» finché non paghi il fornitore. In un altro '
            + 'esercizio la pagina si ferma e dice dove.</li>');
        expect(guida).not.toContain('mai oltre lo scoperto, che i pregressi dei condòmini non coprono');
        expect(guida).toContain('<li>Si scrive solo quando salvi il piano. Il compito del deficit si chiude quando non resta niente; con una parte, resta aperto con il resto.</li>');
        expect(guida).toContain('<li>Se elimini il piano, si toglie con uno storno, e il compito torna da fare (se l\'hai chiuso tu, resta chiuso).</li>');
    });
    test('743: le parole della guida ci sono nella pagina', () => {
        const pagina = leggi('resources/js/pages/gestionale/pianiRate/PianiRateNew.vue') + leggi('resources/js/lib/gestionale/pianiRate/ripianamento.ts');
        for (const nome of ['Il debito ereditato da ripianare', 'Quanto mettere a rate', 'La tabella del riparto', 'Si scrive solo quando salvi il piano',
            'lo scoperto di oggi', 'Il programma non vede il denaro ereditato in banca']) {
            expect(pagina).toContain(nome);
        }
    });
    test('742: la voce a € 0 fra le spese fuori preventivo', () => {
        expect(guida).toContain('addebitata a una sola unità (il lavoro ad personam) o su una voce che nel preventivo vale € 0 (se il fondo di riserva non la copre già).');
        // Finché la fattura è nel piano la voce resta a € 0 e le righe della fattura non cambiano composizione: le parole dei due rifiuti.
        expect(guida).toContain('Con una voce a € 0, finché la fattura è nel piano la voce non cambia importo e la fattura non prende né perde righe di un\'unità: '
            + 'per correggere l\'una o l\'altra, togli prima la fattura dal piano (si elimina il piano).');
        expect(leggi('app/Services/Gestionale/BloccoDellaVoce.php') + leggi('app/Services/Gestionale/FatturaPassivaService.php')).toContain('togli prima la fattura dal piano');
    });
    test('742: «Sposta spesa» non dà budget alla voce a € 0 messa a rate, né al capitolo sopra di lei', () => {
        expect(guida).toContain('«Sposta spesa» non le dà budget, né al capitolo sopra di lei: la chiederebbe due volte.');
        expect(leggi('resources/js/components/guides/PianoRateDettaglioGuide.vue')).toContain('Una voce che nel preventivo vale € 0 e la cui spesa è già a rate con '
            + '«Spese fuori preventivo» non riceve budget, e nemmeno il capitolo sopra di lei: il menu della destinazione non li offre');
        expect(leggi('app/Services/Gestionale/BudgetMovementService.php')).toContain('spostato qui, si chiederebbe due volte. Sposta il budget su un\\\'altra voce');
    });
    test('743: i pregressi scritti dopo il piano, e con più debiti il compito che ricorda la parte', () => {
        expect(guida).toContain('<li>Un pregresso scritto dopo il piano può renderlo più grande dello scoperto: il Wallet lo dice con i numeri, e il compito torna '
            + 'aperto. Il piano non cambia da solo: decidi tu.</li>');
        expect(guida).toContain('<li>Con più debiti, se lo scoperto che resta lo ricorda il compito di un altro debito, il programma dice quale: prepara il piano da lì.</li>');
        expect(leggi('resources/js/components/guides/SaldiGuide.vue')).toContain('un pregresso scritto dopo può farlo chiedere due volte: il messaggio lo dice con '
            + 'i numeri, e il compito «Deficit da recuperare» torna aperto');
        expect(leggi('app/Services/Gestionale/RipianamentoDelDeficit.php')).toContain('prepara il piano da lì').toContain('Il programma non cambia il piano');
    });
});
