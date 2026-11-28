// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La guida «Già versato» dice che cosa fanno «Versato da» (decisione 17) e «Vale per» (Coda 438).
 *
 * Due frasi erano false da quando la pagina del già versato ha «Versato da»: «Si registra per unità immobiliare, non per persona» e «lo sconto
 * vale per tutti e due» (sulla voce divisa fra proprietario e inquilino lo sconto va alla sola persona indicata). E la terza scelta della
 * pagina, «Vale per», non diceva che cosa vuol dire non averne ancora scelto una e che cosa sono i versamenti registrati prima di averla.
 *
 * Si legge il sorgente, come nei test delle altre guide: conta la frase, non il disegno. I fatti su cui le frasi poggiano si leggono dove vivono,
 * nella pagina di dettaglio (`ContributiEdit.vue`); che il motore sconti davvero la sola persona lo prova `ContributiVersatiHttpTest` (B2 S6).
 */
import { describe, expect, test } from 'vitest';
import fs from 'node:fs';

const guida = fs.readFileSync('resources/js/components/guides/GiaVersatoGuide.vue', 'utf8').replace(/\s+/g, ' ');
const pagina = fs.readFileSync('resources/js/pages/gestionale/contributi/ContributiEdit.vue', 'utf8').replace(/\s+/g, ' ');

describe('GiaVersatoGuide — «Versato da» (decisione 17)', () => {
    test('non dice più che il già versato si registra per unità e non per persona', () => {
        expect(guida).not.toContain('non per persona');
        expect(guida).toContain(
            'Si registra per <strong>unità immobiliare</strong>, e puoi dire anche <strong>chi ha versato</strong> («Versato da»): senza una persona il versamento è dell\'unità'
        );
    });

    test('dice che lo sconto vale per tutti e due solo con la riga dell\'unità, e che con «Versato da» va alla sola persona', () => {
        expect(guida).toContain('lo sconto dipende da «Versato da».</strong> Se la riga è dell\'<strong>unità</strong> (senza persona)');
        expect(guida).toContain('venga diviso fra i due ruoli: lo sconto vale per tutti e due.');
        expect(guida).toContain('scegli la persona in «Versato da»: lo sconto va solo alla sua quota, e l\'eventuale avanzo resta a suo nome.');
        // La strada esiste: la guida non dice più che «questa non è la strada» per tenere distinti i due versamenti.
        expect(guida).not.toContain('questa non è la strada');
    });

    test('il fatto su cui poggia: la pagina offre «Versato da», con la riga «L\'unità (senza persona)», e dice la stessa cosa dello sconto', () => {
        expect(pagina).toContain('<th class="text-left px-3 py-3 font-bold">Versato da</th>');
        expect(pagina).toContain('{ valore: SENZA_PERSONA, etichetta: \'L\\\'unità (senza persona)\' }');
        // La guida cita la voce come si legge nel menu.
        expect(guida).toContain('la pagina propone «L\'unità», non chi ha comprato');
        expect(pagina).toContain('Una riga registrata come «L\'unità» viene sottratta dal totale dell\'immobile <strong>prima</strong> di dividerlo fra proprietario e inquilino, quindi sconta entrambi.');
        expect(pagina).toContain('scegli chi in «Versato da»: lo sconto va solo alla sua quota, e l\'eventuale avanzo resta a suo nome.');
    });
});

describe('GiaVersatoGuide — chi si può scegliere in «Versato da»', () => {
    test('anche chi ha venduto, con il periodo; il versamento senza persona si divide come il piano divide la spesa', () => {
        expect(guida).toContain('Chi si può scegliere in «Versato da»');
        expect(guida).toContain('Chi lo è stato, per esempio chi ha venduto: accanto al nome c\'è il periodo. L\'inquilino non c\'è.');
        expect(guida).toContain('La pagina non ha la data del versamento: a chi va lo decidi tu.');
        expect(guida).toContain('Senza persona è dell\'unità: dopo una vendita si divide in proporzione a quello che il piano chiede a ciascuno.');
        expect(guida).toContain('Quello che una persona ha versato oltre la sua parte resta suo, anche se ha venduto.');
    });

    test('il fatto su cui poggia: la pagina scrive il periodo accanto a chi non è più titolare', () => {
        expect(pagina).toContain('+ (t.periodi?.length ? ` · ${periodiInParole(t.periodi)}` : \'\');');
    });
});

describe('GiaVersatoGuide — «Vale per» (Coda 438)', () => {
    test('dice che senza versamenti la pagina non propone nessun esercizio e non salva senza una scelta, e che i versamenti di prima valgono per «Tutti gli esercizi»', () => {
        expect(guida).toContain(
            'Per una voce senza versamenti la pagina non propone nessun esercizio e non salva finché non ne scegli uno; i versamenti registrati prima di questa scelta valgono per «Tutti gli esercizi», come prima.'
        );
        // La frase di prima della scelta, sull'esercizio scelto e su «Tutti gli esercizi», resta.
        expect(guida).toContain('Il piano rate di un esercizio toglie solo il già versato di quell\'esercizio');
        expect(guida).toContain('<strong>«Tutti gli esercizi»</strong> lo tolgono tutti i piani della voce, ognuno per la sua parte');
    });

    test('il fatto su cui poggia: la pagina parte da «Scegli l\'esercizio…» e rifiuta il salvataggio senza una scelta', () => {
        expect(pagina).toContain('placeholder="Scegli l\'esercizio…"');
        expect(pagina).toContain('if (valePer.value === null) { toast({ title: \'Scegli l\\\'esercizio\'');
        expect(pagina).toContain('{ valore: \'tutti\', etichetta: \'Tutti gli esercizi\' }');
    });
});

describe('GiaVersatoGuide — controprova', () => {
    test('le altre frasi della guida non cambiano', () => {
        expect(guida).toContain('<strong>La quota non scende mai sotto zero.</strong>');
        expect(guida).toContain('Spegnere l\'interruttore non cancella niente');
        expect(guida).toContain('I saldi iniziali sono la <strong>fotografia complessiva</strong>');
    });

    test('la scheda delle scelte non dice «due»: con «Vale per» le scelte della pagina sono tre', () => {
        expect(guida).not.toContain('>Le due scelte</TabsTrigger>');
        expect(guida).toContain('<TabsTrigger value="decisioni">Le scelte</TabsTrigger>');
        expect(guida).toContain('3. Per quale esercizio vale?');
    });
});

describe('GiaVersatoGuide — la somma sulla voce (Code 483, 486, 487)', () => {
    test('dice che conta anche il piano che chiede la voce con il suo capitolo o senza un importo suo, e che l\'anteprima di stampa lo dice', () => {
        expect(guida).toContain('Conta anche il piano che chiede la voce con il suo capitolo, o senza un importo suo: allora con l\'importo della voce.');
        expect(guida).toContain('Se un piano non ancora generato la supera, l\'anteprima della stampa del riparto lo dice al posto della tabella, con il messaggio della generazione.');
    });
});

describe('GiaVersatoGuide — il piano senza voci associate (rilievo 11 dello scettico della tappa 1)', () => {
    test('dice che conta anche il piano senza voci associate, con le voci che prende', () => {
        expect(guida).toContain('Conta anche il piano senza voci associate, nato con le versioni di prima: con le voci che prende, quelle che nessun altro piano del suo esercizio ha già.');
    });
});

describe('GiaVersatoGuide — i giorni senza titolare', () => {
    test('la guida dice che i giorni senza nessun titolare non si chiedono a nessuno, e la pagina ha la riga che lo mostra', () => {
        expect(guida).toContain('I giorni in cui nessuno è titolare, fra chi esce e chi entra, non si chiedono a nessuno: la riga «Giorni senza titolare» dice quanto.');
        expect(pagina).toContain('data-testid="senza-titolare-dell-unita"');
        expect(pagina).toContain('Giorni senza titolare: non si chiedono a nessuno, il piano li mette fra le quote non assegnate');
    });
});
