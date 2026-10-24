// @vitest-environment jsdom

/**
 * 1.11.0-beta.55, Coda 245 — il pannello «Cosa cambierà» alla fine di una locazione con un coinquilino che resta, montato.
 *
 * Con un coinquilino che resta la coppia è della regola del piano: la parte dell'inquilino si ridivide per quota e per giorni fra chi è
 * stato conduttore, e le righe in saldi portano ognuno alla sua parte. La scelta c'è, con le due voci e nessuna già scelta (decisioni 72 e
 * 77), con i testi della regola del piano (`conguaglio.regola`: `piano`, o `misto` quando l'unità e il box hanno regole diverse). Il piede
 * della tabella non parla di «chi paga dopo l'uscita»: credito a chi esce, debito a chi riceve; e le righe per persona ci sono anche con un
 * ricevente solo, perché la tabella per gestione non dice chi.
 *
 * I JSON sono quelli dell'anteprima vera (`Beta55CoinquilinoCoppiaDelPianoTest`), alleggeriti delle chiavi che il pannello non legge. Le
 * cifre: C1, Ines − € 60,66 e Dora + € 60,66; C6.5c-bis (Dora chiusa il 31/7), Dora − € 19,47 (un credito) e Ugo + € 150,90, in tutto
 * € 131,43 a chi riceve; C7, l'Interno 1 con la regola del piano (Dora € 54,59) e il box con i giorni (Rita € 18,15).
 *
 * Cosa NON copre: il server (le cifre e le frasi: i test PHP); il modulo `PassaggioNew.vue`; il modo scuro e le larghezze (verifica a video).
 */

import { describe, expect, test } from 'vitest';
import { mount } from '@vue/test-utils';

import AnteprimaPassaggio from './AnteprimaPassaggio.vue';

type Scelta = 'scrivi' | 'non_scrivere' | null;

const SCRIVI = 'La parte dell\'inquilino si ridivide per quota e per giorni fra chi è stato conduttore, come in un piano generato dopo l\'uscita: le righe in saldi portano ognuno alla sua parte.';
const NON_SCRIVERLO = 'Nessuna riga in saldi: le rate restano come sono, e le parti regolano la differenza fra loro con il prospetto degli oneri accessori.';
const MISTO = 'Le righe in saldi portano ognuno alla sua parte: dove la locazione continua con un coinquilino la voce si ridivide per quota e per giorni fra i conduttori, altrove i giorni dopo l\'uscita passano a chi paga al posto dell\'inquilino.';
const PIEDE = 'Credito a chi esce, debito a chi riceve';

const emesse = [1, 2, 3, 4, 5, 6].map((rata) => ({
    rata, scadenza: `2026-0${rata}-05`, importo: 1500, importo_formattato: '€ 15,00', intestatario: 'Ines', piano: 'Preventivo 2026', natura: 'ordinaria',
}));

const gruppo = (immobile: number, nome: string, importo: number, formattato: string, regola: 'piano' | 'giorni') => ({
    gestione_id: 1, gestione: 'Ordinaria 2026', immobile_id: immobile, immobile_nome: nome, natura: 'ordinaria', gradino: ['gestione'],
    periodo: [{ dal: '2026-01-01', al: '2026-12-31' }], voce_per_voce: false, quote: 12, quota_pura: 18000, pregressi: 0,
    giorni_uscente: 181, giorni_entrante: 184, giorni_periodo: 365, importo, importo_formattato: formattato,
    importo_lordo: importo, importo_lordo_formattato: formattato, bozze_passate: 0, bozze_passate_importo: 0, bozze_passate_formattato: '€ 0,00',
    non_risolte: 0, non_separabili: 0, mai_passate: 0, escluse: 0, esercizio_id: 1, regola,
});

const coppia = (immobile: number, id: number, nome: string, importo: number, formattato: string, regola: 'piano' | 'giorni') => ({
    gestione_id: 1, gestione: 'Ordinaria 2026', immobile_id: immobile, esercizio_id: 1, importo, importo_formattato: formattato, per_nudo: null,
    anagrafica_entrante_id: id, entrante_nome: nome, ...(regola === 'piano' ? { regola } : {}),
});

const FRASE_DELLA_FINE = 'Le rate già emesse non si toccano. La locazione continua con Dora: con la regola del piano la parte dell\'inquilino si divide per quota e per giorni fra chi è stato conduttore nell\'anno, come in un piano generato dopo l\'uscita. Sulla gestione Ordinaria 2026 Ines € 119,34 per i suoi 181 giorni e Dora € 240,66 per i suoi 365 giorni; nelle loro rate Ines ne ha € 180,00 e Dora € 180,00. Se scrivi il conguaglio, le righe nei saldi sono € 60,66 a credito di Ines e € 60,66 a debito di Dora. Se non lo scrivi, nei saldi non si scrive niente: le rate restano come sono, e il prospetto degli oneri accessori dice che Dora rimborsa a Ines € 60,66.';

/** La fine di Ines il 1/7 con Dora che resta, come la manda il server; `coppie` e `per_gestione` si cambiano per i casi. */
function conCoinquilino(coppie = [coppia(1, 4, 'Dora', 6066, '€ 60,66', 'piano')], perGestione = [gruppo(1, 'Interno 1', 6066, '€ 60,66', 'piano')], regola: 'piano' | 'misto' = 'piano'): any {
    const totale = coppie.reduce((s, c) => s + c.importo, 0);
    return {
        nudi: null,
        riferimento: { uscente_fino_al: '2026-06-30', entrante_dal: '2026-07-01', frase: 'Ines risulterà inquilino fino al 30 giugno 2026 compreso.' },
        anagrafica: { frasi: ['Ines risulterà inquilino fino al 30 giugno 2026.'], pertinenze: [] },
        rate: {
            stato: 'calcolato', emesse, piani_distinti: 1, totale_emesso: 9000, totale_emesso_formattato: '€ 90,00',
            altre: { quote: 12, intestatari: ['Ugo', 'Dora'] }, morosita: null,
            conguaglio: {
                stato: 'calcolato', anagrafica_uscente_id: 3, anagrafica_entrante_id: 1, quote: [], per_gestione: perGestione, coppie, regola,
                totale_entrante: totale, totale_entrante_formattato: `€ ${(totale / 100).toFixed(2).replace('.', ',')}`, totale_entrante_assoluto_formattato: `€ ${(Math.abs(totale) / 100).toFixed(2).replace('.', ',')}`,
                riassegnazione: [], pregressi: 0, non_risolte: [], frasi: [],
            },
            frasi: [FRASE_DELLA_FINE],
            frasi_del_conguaglio: ['Sulla gestione Ordinaria 2026, con la regola del piano: € 60,66 a credito di Ines e € 60,66 a debito di Dora.'],
            scelta_del_conguaglio: true,
        },
        obbligati: { frasi: ['Dal 1 luglio 2026 la locazione continua con Dora: nei piani generati o ricalcolati dopo il passaggio le voci a carico dell\'inquilino si dividono fra gli inquilini per quota e per giorni. Le rate già emesse a Ines restano sue.'], copia_autentica_mancante: false },
        invarianti: { frasi: ['Millesimi: invariati.'] },
        ordinaria: { applicabile: false, scelta: null, usufruttuario: null, nudo: null, voci: [], frasi: [], frasi_bloccate: [], frasi_altri_usufrutti: [], impronta: null, ereditata: null },
        cancello: { richiesto: true, motivi: ['6 quote di rate già emesse a Ines su questa unità'], informazioni: [], avvisi: [] },
    };
}

function monta(dati: any, scelta: Scelta = null) {
    return mount(AnteprimaPassaggio, { props: { dati, inCorso: false, errore: false, mancante: [], tipo: 'fine_locazione', senzaSuccessore: true, sceltaConguaglio: scelta } });
}

const radio = (w: ReturnType<typeof monta>) => w.findAll('input[type="radio"]').map((r) => r.element as HTMLInputElement);
const celle = (w: ReturnType<typeof monta>) => w.findAll('td').map((td) => td.text());

describe('AnteprimaPassaggio — la fine con un coinquilino che resta, con la regola del piano (Coda 245)', () => {
    test('le due voci ci sono e nessuna è già scelta, con i testi della regola del piano', () => {
        const w = monta(conCoinquilino());
        expect(radio(w).map((r) => r.value)).toEqual(['scrivi', 'non_scrivere']);
        expect(radio(w).map((r) => r.checked)).toEqual([false, false]);
        expect(w.text()).toContain(SCRIVI);
        expect(w.text()).toContain(NON_SCRIVERLO);
        expect(w.text()).not.toContain('I giorni dopo l\'uscita passano a chi paga al posto dell\'inquilino');
        expect(w.text()).not.toContain('i giorni dopo l\'uscita restano nelle rate di chi esce');
        expect(w.text()).toContain(FRASE_DELLA_FINE);
    });

    test('il piede della tabella: credito a chi esce, debito a chi riceve, e la riga di Dora anche se riceve da sola', () => {
        const w = monta(conCoinquilino(), 'scrivi');
        expect(w.text()).toContain(PIEDE);
        expect(w.text()).not.toContain('chi paga dopo l\'uscita');
        expect(celle(w)).toContain('a Dora');
        expect(celle(w).some((c) => c.includes('€ 60,66'))).toBe(true);
    });

    test('una riga a credito di chi riceve si dice «a credito», non con il meno: Dora € 19,47 a credito e Ugo € 150,90', () => {
        const dati = conCoinquilino([coppia(1, 4, 'Dora', -1947, '€ 19,47 a credito', 'piano'), coppia(1, 1, 'Ugo', 15090, '€ 150,90', 'piano')], [gruppo(1, 'Interno 1', 13143, '€ 131,43', 'piano')]);
        const w = monta(dati, 'scrivi');
        expect(celle(w)).toContain('a Dora');
        expect(celle(w)).toContain('a Ugo');
        expect(w.text()).toContain('€ 19,47');
        expect(w.text()).toContain('€ 150,90');
        expect(w.text()).not.toContain('€ -');
        expect(w.text()).toContain('€ 131,43');
    });

    test('con un\'entrata che pesa più della spesa chi esce ha un debito: il piede si rovescia, debito a chi esce e credito a chi riceve (E1)', () => {
        // E1: Ines + € 89,34 (debito), Dora − € 89,34 (credito): la coppia di Dora è negativa, e il totale a chi riceve anche.
        const dati = conCoinquilino([coppia(1, 4, 'Dora', -8934, '€ 89,34 a credito', 'piano')], [gruppo(1, 'Interno 1', -8934, '€ 89,34 a credito', 'piano')]);
        const testo = monta(dati, 'scrivi').text();
        expect(testo).toContain('Debito a chi esce, credito a chi riceve');
        expect(testo).not.toContain(PIEDE);
        expect(testo).not.toContain('€ -');
    });

    test('con «Non scriverlo»: niente righe, il piede «Non scritte», e la nota resta facoltativa', () => {
        const w = monta(conCoinquilino(), 'non_scrivere');
        expect(w.text()).toContain('Non scritte: nessuna riga in saldi');
        expect(w.text()).not.toContain('almeno 10 caratteri');
        expect(w.text()).not.toContain('Sulla gestione Ordinaria 2026, con la regola del piano');
    });

    test('l\'unità con il coinquilino e il box senza: una scelta sola, e la voce «Scrivi» dice le due regole', () => {
        const dati = conCoinquilino([coppia(1, 4, 'Dora', 5459, '€ 54,59', 'piano'), coppia(2, 5, 'Rita', 1815, '€ 18,15', 'giorni')],
            [gruppo(1, 'Interno 1', 5459, '€ 54,59', 'piano'), gruppo(2, 'Box 12', 1815, '€ 18,15', 'giorni')], 'misto');
        const w = monta(dati);
        expect(radio(w)).toHaveLength(2);
        expect(w.text()).toContain(MISTO);
        expect(w.text()).toContain(NON_SCRIVERLO);
        expect(celle(w)).toContain('a Dora');
        expect(celle(w)).toContain('a Rita');
        expect(monta(dati, 'scrivi').text()).toContain(PIEDE);
    });
});

/**
 * Decisione 95.6 (b): dopo una fine con la regola del piano, la fine di chi restava scrive solo la sua coppia (C4: Dora − € 78,75 a Ugo), e la
 * parte di chi era già uscito che la regola del piano cambierebbe (Ines € 11,99 sopra il piano) resta fuori: le righe in saldi non portano ognuno
 * alla sua parte, e la voce «Scrivi» non lo dice (`conguaglio.regola` `piano_coppia`). Il JSON è quello dell'anteprima vera
 * (`Beta55CoinquilinoDopoLaCoppiaTest`, C4), alleggerito.
 */
describe('AnteprimaPassaggio — la fine di chi restava, dopo una fine con la regola del piano (Coda 245)', () => {
    const PIANO_COPPIA = 'Le righe in saldi sono quelle della regola del piano fra chi esce e chi riceve i suoi giorni: ciò che resta fuori il pannello lo dice, con la persona e la cifra.';

    test('la voce «Scrivi» non dice che le righe portano ognuno alla sua parte; «Non scriverlo» e il piede restano quelli della regola del piano', () => {
        const dati = conCoinquilino([coppia(1, 1, 'Ugo', 7875, '€ 78,75', 'piano')], [gruppo(1, 'Interno 1', 7875, '€ 78,75', 'piano')], 'piano_coppia' as never);
        const w = monta(dati);
        expect(radio(w).map((r) => r.checked)).toEqual([false, false]);
        expect(w.text()).toContain(PIANO_COPPIA);
        expect(w.text()).not.toContain(SCRIVI);
        expect(w.text()).toContain(NON_SCRIVERLO);
        expect(monta(dati, 'scrivi').text()).toContain(PIEDE);
    });
});

/**
 * Le voci della scelta dove la coppia non è «ognuno alla sua parte»: il cambio d'inquilino sull'anello della regola del piano (la coppia è la differenza del piano,
 * non i giorni: Dora → Luca il 1/10, Luca + € 60,66), la regola del piano che lascia fuori una parte (`piano_coppia`, e `misto_coppia` dove l'unità e il box hanno
 * regole diverse: Interno 1 con Dora + € 53,38 e un resto di Pia, Box 12 con Rita), e `misto` quando chi esce è sostituito da chi entra (altrove i giorni passano a lui).
 */
describe('AnteprimaPassaggio — le voci della scelta con la regola del piano (Coda 245)', () => {
    const SCRIVI_CAMBIO = 'I giorni dopo l\'uscita passano a chi entra, e la parte dell\'inquilino si ridivide con la regola del piano: le righe in saldi portano ognuno alla sua parte.';
    const PIANO_COPPIA = 'Le righe in saldi sono quelle della regola del piano fra chi esce e chi riceve i suoi giorni: ciò che resta fuori il pannello lo dice, con la persona e la cifra.';
    const MISTO_COPPIA = 'Dove la locazione continua con un coinquilino le righe in saldi sono quelle della regola del piano, e ciò che resta fuori il pannello lo dice, con la persona e la cifra; altrove i giorni dopo l\'uscita passano a chi paga al posto dell\'inquilino.';
    const montaCambio = (dati: any, scelta: Scelta = null) => mount(AnteprimaPassaggio, { props: { dati, inCorso: false, errore: false, mancante: [], tipo: 'fine_locazione', senzaSuccessore: false, sceltaConguaglio: scelta } });

    test('il cambio d\'inquilino sull\'anello: «Scrivi» non rimette ognuno ai suoi giorni, «Non scriverlo» rimanda al prospetto per la differenza del piano', () => {
        const dati = conCoinquilino([coppia(1, 7, 'Luca', 6066, '€ 60,66', 'piano')], [gruppo(1, 'Interno 1', 6066, '€ 60,66', 'piano')]);
        const testo = montaCambio(dati).text();
        expect(testo).toContain(SCRIVI_CAMBIO);
        expect(testo).toContain(NON_SCRIVERLO);
        expect(testo).not.toContain('rimettono ognuno ai suoi giorni');
        expect(testo).not.toContain('glieli rimborsa');
        expect(testo).not.toContain('restano nelle rate di chi esce');
        // Nel cambio chi riceve è chi entra, e la tabella non ripete la sua riga per persona: il piede resta quello di chi entra.
        expect(celle(montaCambio(dati))).not.toContain('a Luca');
        expect(montaCambio(dati, 'scrivi').text()).toContain('Debito a chi entra, credito a chi esce');
    });

    test('la regola del piano che lascia fuori i giorni di nessuno, o un resto: la voce «Scrivi» lo dice anche nel cambio d\'inquilino', () => {
        const dati = conCoinquilino([coppia(1, 7, 'Luca', 6066, '€ 60,66', 'piano')], [gruppo(1, 'Interno 1', 6066, '€ 60,66', 'piano')], 'piano_coppia' as never);
        const testo = montaCambio(dati).text();
        expect(testo).toContain(PIANO_COPPIA);
        expect(testo).not.toContain(SCRIVI_CAMBIO);
        expect(testo).not.toContain('portano ognuno alla sua parte');
    });

    test('l\'unità con il coinquilino e un resto, e il box senza: la voce «Scrivi» non dice che le righe portano ognuno alla sua parte', () => {
        const dati = conCoinquilino([coppia(1, 4, 'Dora', 5338, '€ 53,38', 'piano'), coppia(2, 5, 'Rita', 1815, '€ 18,15', 'giorni')],
            [gruppo(1, 'Interno 1', 5338, '€ 53,38', 'piano'), gruppo(2, 'Box 12', 1815, '€ 18,15', 'giorni')], 'misto_coppia' as never);
        const testo = monta(dati).text();
        expect(testo).toContain(MISTO_COPPIA);
        expect(testo).not.toContain(MISTO);
        expect(testo).toContain(NON_SCRIVERLO);
        expect(monta(dati, 'scrivi').text()).toContain(PIEDE);
    });

    test('l\'unità e il box nel cambio d\'inquilino: altrove i giorni dopo l\'uscita passano a chi entra, non a chi paga al posto dell\'inquilino', () => {
        const dati = conCoinquilino([coppia(1, 7, 'Luca', 5459, '€ 54,59', 'piano'), coppia(2, 7, 'Luca', 1815, '€ 18,15', 'giorni')],
            [gruppo(1, 'Interno 1', 5459, '€ 54,59', 'piano'), gruppo(2, 'Box 12', 1815, '€ 18,15', 'giorni')], 'misto');
        const testo = montaCambio(dati).text();
        expect(testo).toContain('Le righe in saldi portano ognuno alla sua parte: dove la locazione continua con un coinquilino la voce si ridivide per quota e per giorni fra i conduttori, altrove i giorni dopo l\'uscita passano a chi entra.');
        expect(testo).not.toContain('passano a chi paga al posto dell\'inquilino');
    });

    // Il cambio Dora → Luca con il box su un piano generato dalla 1.10 (i dati come li manda il server, `Beta55CoinquilinoProspettoTest`): il prospetto legge il piano per l'Interno 1 (Luca
    // rimborsa a Dora € 54,59) e lo lascia fuori per il box (€ 9,07 fra le parti). La voce «Non scriverlo» lo dice: prima diceva solo «con il prospetto», falso per il box.
    test('il cambio sull\'anello con il box su un piano della 1.10: «Non scriverlo» dice che per il box il prospetto lascia fuori il piano', () => {
        const dati = conCoinquilino([coppia(1, 7, 'Luca', 5459, '€ 54,59', 'piano'), coppia(2, 7, 'Luca', 907, '€ 9,07', 'giorni')],
            [gruppo(1, 'Interno 1', 5459, '€ 54,59', 'piano'), gruppo(2, 'Box 12', 907, '€ 9,07', 'giorni')], 'misto');
        dati.rate.prospetto_esclude = ['Preventivo 2026'];
        dati.rate.prospetto_esclude_dove = [{ piano: 'Preventivo 2026', unita: 'Box 12' }];
        const testo = montaCambio(dati).text();
        expect(testo).toContain(NON_SCRIVERLO + ' Per «Box 12» il prospetto degli oneri accessori lascia fuori il piano «Preventivo 2026», generato da una versione precedente: lì chi entra glieli rimborsa fra le parti.');
        // Senza piani lasciati fuori la voce resta quella del prospetto.
        dati.rate.prospetto_esclude = [];
        dati.rate.prospetto_esclude_dove = [];
        expect(montaCambio(dati).text()).not.toContain('lascia fuori');
    });

    // Un piano della 1.10 lasciato fuori dal prospetto e un piano dove il cambio di prima è stato regolato fra le parti, nello stesso cambio (la regola dei giorni): la voce «Non scriverlo» dice le due
    // cose una dopo l'altra. Era: «… fra le parti.Sul piano …», senza lo spazio (un `<template>` che comincia con uno spazio lo perde).
    test('«Non scriverlo» con un piano della 1.10 lasciato fuori e un cambio di prima regolato fra le parti: le due frasi sono separate da uno spazio', () => {
        const dati = conCoinquilino([coppia(1, 7, 'Luca', 12033, '€ 120,33', 'giorni')], [gruppo(1, 'Interno 1', 12033, '€ 120,33', 'giorni')], 'giorni' as never);
        dati.rate.prospetto_esclude = ['Preventivo 2026'];
        dati.rate.prospetto_regolato = ['Integrativa 2026'];
        const testo = montaCambio(dati).text();
        expect(testo).toContain('generato da una versione precedente: lì chi entra glieli rimborsa fra le parti. Sul piano «Integrativa 2026» il cambio d\'inquilino di prima è stato regolato fra le parti, '
            + 'e il prospetto non va oltre quell\'accordo: anche lì il rimborso di chi entra si regola fra le parti.');
        expect(testo).not.toContain('fra le parti.Sul piano');
        // Con due piani regolati la frase dice «Sui piani».
        dati.rate.prospetto_regolato = ['Integrativa 2026', 'Pulizie 2026'];
        expect(montaCambio(dati).text()).toContain('fra le parti. Sui piani «Integrativa 2026», «Pulizie 2026» il cambio d\'inquilino di prima è stato regolato');
    });
});

/**
 * La riga della gestione nella tabella del conguaglio: la colonna di mezzo dice come è nata la cifra. Per i giorni («giorni 181 / 184», e sotto il periodo della gestione) vale la
 * regola dei giorni; con la regola del piano la cifra non è una questione di giorni di chi esce e di chi entra, ma della parte dell'inquilino divisa per quota e per giorni fra chi è
 * stato conduttore: la riga lo dice, e non i giorni di due persone. L'unità con la regola del piano e il box con i giorni, accanto, ognuno la sua (C7).
 */
describe('AnteprimaPassaggio — la riga della gestione dice come è nata la cifra (Coda 245)', () => {
    // La colonna di mezzo ha due righe a capo (`block`): il testo della cella le tiene attaccate, e si leggono come due pezzi.
    const colonna = (w: ReturnType<typeof monta>) => celle(w).filter((c) => /^(giorni|regola del piano)/.test(c));

    test('con la regola del piano la riga della gestione non dice i giorni di chi esce e di chi entra', () => {
        const w = monta(conCoinquilino());

        expect(colonna(w)).toEqual(['regola del pianoper quota e per giorni']);
        expect(w.text()).not.toContain('giorni 181 / 184');
        expect(w.text()).not.toContain('periodo della gestione');
    });

    test('l\'unità con il coinquilino e il box senza: l\'Interno 1 dice la regola del piano, il Box 12 i suoi giorni', () => {
        const dati = conCoinquilino([coppia(1, 4, 'Dora', 5459, '€ 54,59', 'piano'), coppia(2, 5, 'Rita', 1815, '€ 18,15', 'giorni')],
            [gruppo(1, 'Interno 1', 5459, '€ 54,59', 'piano'), gruppo(2, 'Box 12', 1815, '€ 18,15', 'giorni')], 'misto');

        expect(colonna(monta(dati))).toEqual(['regola del pianoper quota e per giorni', 'giorni 181 / 184periodo della gestione']);
    });

    test('un piano straordinario con la competenza dichiarata sulla fattura: la riga dice la competenza, e con la regola del piano non i giorni di chi esce e di chi entra', () => {
        const straordinario = (regola: 'piano' | 'giorni') => ({ ...gruppo(1, 'Interno 1', 6066, '€ 60,66', regola), natura: 'straordinaria', gradino: ['dichiarata'] });
        const delPiano = monta(conCoinquilino([coppia(1, 4, 'Dora', 6066, '€ 60,66', 'piano')], [straordinario('piano')])).findAll('td').map((td) => td.text());
        const deiGiorni = monta(conCoinquilino([coppia(1, 5, 'Rita', 6066, '€ 60,66', 'giorni')], [straordinario('giorni')], 'giorni' as never)).findAll('td').map((td) => td.text());

        expect(delPiano.filter((c) => c.includes('competenza dichiarata'))).toHaveLength(1);
        expect(delPiano.join(' ')).not.toContain('giorni 181 / 184');
        expect(deiGiorni.filter((c) => c.includes('competenza dichiarata'))).toHaveLength(1);
        expect(deiGiorni.join(' ')).toContain('giorni 181 / 184');
    });

    test('controllo — la fine di una locazione con la regola dei giorni dice ancora «giorni 181 / 184» e il periodo della gestione', () => {
        const dati = conCoinquilino([coppia(1, 5, 'Rita', 1815, '€ 18,15', 'giorni')], [gruppo(1, 'Interno 1', 1815, '€ 18,15', 'giorni')], 'giorni' as never);

        expect(colonna(monta(dati))).toEqual(['giorni 181 / 184periodo della gestione']);
    });
});
