// @vitest-environment jsdom

/**
 * Cosa resta scoperto: le altre schede della guida; la traduzione (la guida è solo in italiano).
 *
 * Coda 414 e D143: le guide del piano rate e le quote non assegnate.
 *
 * La guida del dettaglio del piano (`PianoRateDettaglioGuide.vue`) diceva che «Disallineato: ricalcola!» vuol dire che qualcuno ha modificato un
 * preventivo o una tabella millesimale, e che «Ricalcola» sistema le quote: con una quota non assegnata accettata è falso (lo stato è «quota aperta»,
 * `PianoRateQuoteService::statoAllineamento`), e il confronto è fra i totali, quindi una tabella cambiata a totale uguale non lo accende; il preventivo
 * della voce cambiato dopo di solito nemmeno, perché il piano chiede l'importo della sua riga (`Beta59PianoQuoteNonAssegnateTest`), salvo su una voce
 * con del già versato (`Beta59PianoQuoteNonAssegnateCasiTest`). Ora dice i tre stati.
 * La guida «Ruoli e usufrutto» (`RipartoUsufruttoGuide.vue`) diceva «recupera la quota con un addebito manuale», che il programma non ha (D143).
 *
 * I test che cominciano con «controprova» sono verdi prima e dopo la correzione.
 *
 * Si monta la guida con i segnaposto del foglio e si legge il testo con gli spazi normalizzati.
 */
import { describe, expect, test } from 'vitest';
import { mount } from '@vue/test-utils';

import PianoRateDettaglioGuide from './PianoRateDettaglioGuide.vue';
import RipartoUsufruttoGuide from './RipartoUsufruttoGuide.vue';

const STUBS = {
    Sheet: { template: '<div><slot /></div>' }, SheetContent: { template: '<div><slot /></div>' }, SheetHeader: { template: '<div><slot /></div>' },
    SheetTitle: { template: '<div><slot /></div>' }, SheetDescription: { template: '<div><slot /></div>' },
};

const testo = (guida: unknown) => mount(guida as never, { props: { open: true }, global: { stubs: STUBS } }).text().replace(/\s+/g, ' ');

describe('PianoRateDettaglioGuide — i tre stati di «Copertura spese» (Coda 414)', () => {
    test('dice i tre casi: nessun badge, «Quota aperta» in ambra con cifra e unità, «Disallineato: ricalcola!» in rosso', () => {
        const t = testo(PianoRateDettaglioGuide);

        expect(t).toContain('nessun badge: le due cifre coincidono;');
        expect(t).toContain('«Quota aperta», in ambra, con la cifra e l\'unità: la differenza sono le quote non assegnate che hai accettato con «Procedi comunque»');
        expect(t).toContain('Ricalcola da solo non le assegna a nessuno: prima va sistemata la causa');
        expect(t).toContain('«Disallineato: ricalcola!», in rosso: la differenza è un\'altra, per esempio un già versato registrato dopo la generazione o una voce che ha cambiato tipo.');
        expect(t).toContain('Se il piano non si ricalcola più (rate emesse, incassi, il conguaglio di un passaggio) il badge dice solo «Disallineato»');
    });

    test('non dice più che il rosso viene da un preventivo o da una tabella modificati, e dice che il confronto è fra i totali', () => {
        const t = testo(PianoRateDettaglioGuide);

        expect(t).not.toContain('qualcuno ha modificato un preventivo o una tabella millesimale');
        expect(t).toContain('un cambio di millesimi o di titolari a totale uguale non accende il badge, e di solito nemmeno il preventivo della voce cambiato dopo');
    });

    test('il preventivo cambiato dopo: l\'eccezione della voce con del già versato (`Beta59PianoQuoteNonAssegnateCasiTest`)', () => {
        expect(testo(PianoRateDettaglioGuide)).toContain('Fa eccezione una voce con del già versato: la parte del già versato che il piano toglie dipende dal preventivo della voce, e un preventivo cambiato dopo può accendere «Disallineato»; Ricalcola rifà le rate.');
    });

    test('dice che i piani accettati prima di questo aggiornamento restano «Disallineato», e che cosa fa «Ricalcola»', () => {
        expect(testo(PianoRateDettaglioGuide)).toContain('Un piano con quote non assegnate accettate prima di questo aggiornamento resta «Disallineato»: la cifra non era registrata.');
    });

    test('«Parziale» e «Non assegnati»: le righe sotto la voce', () => {
        const t = testo(PianoRateDettaglioGuide);

        expect(t).toContain('Parziale — l\'importo incluso è diverso dal preventivo della voce: una riga sotto la voce dice il preventivo, la parte che non è in questo piano');
        expect(t).toContain('Non assegnati — una riga sotto la voce dice la cifra delle quote non assegnate accettate su quella voce, e dove stanno: restano fuori dalle rate.');
    });

    test('controprova — le voci di entrata si contano ancora con il segno meno', () => {
        expect(testo(PianoRateDettaglioGuide)).toContain('Le voci di entrata si contano con il segno meno, come fa il calcolo delle quote.');
    });
});

describe('RipartoUsufruttoGuide — gli scoperti dopo il censimento (D143)', () => {
    test('non promette un addebito manuale: dice di riaprire il piano con i passi del pulsante, e che il programma non ha un addebito individuale', () => {
        const t = testo(RipartoUsufruttoGuide);

        expect(t).not.toContain('addebito manuale');
        expect(t).toContain('va prima riaperto con i passi che il pulsante elenca — annullare le emissioni, e prima ancora stornare gli incassi — e poi ricalcolato. Il programma non ha un addebito individuale.');
    });

    test('controprova — se il piano si ricalcola ancora, basta ricalcolarlo, senza fatture finte', () => {
        expect(testo(RipartoUsufruttoGuide)).toContain('non servono fatture finte: se il piano si ricalcola ancora, ricalcolalo;');
    });
});
