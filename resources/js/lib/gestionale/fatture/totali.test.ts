import { describe, expect, it } from 'vitest';
import { calcolaTotali, risolviRegimeRitenuta } from './totali';

/**
 * Il contratto di questo modulo è uno solo: **l'anteprima deve dire lo stesso numero che
 * il backend salverà**. Ogni caso qui sotto fissa un punto in cui le due aritmetiche
 * — centesimi interi lato PHP, float lato form — divergevano.
 *
 * I valori attesi non sono ricavati da questo codice: sono calcolati a mano seguendo
 * `FatturaPassivaService::registraFattura()` e `RitenutaService::calcola()`.
 */

const APPALTO_4 = { percBase: 100, percTratt: 4 };
const PROVVIGIONI_50 = { percBase: 50, percTratt: 23 };

describe('calcolaTotali — il caso segnalato dal forum', () => {
    // Imponibile 316,20 · IVA 22% · ritenuta d'appalto 4%.
    // PHP:  31620 + round(31620×22/100)=6956 − round(31620×4/100)=1265  =  37311
    // Il vecchio form sommava i grezzi: 316,20 + 69,564 − 12,648 = 373,116 → 373,12.
    const totali = calcolaTotali({
        is_pregresso: false,
        righe: [{ importo_imponibile: 316.2, aliquota_iva: 22 }],
        ritenuta: APPALTO_4,
    });

    it('mostra i componenti arrotondati al centesimo', () => {
        expect(totali.imponibile_cents).toBe(31620);
        expect(totali.iva_cents).toBe(6956);
        expect(totali.ritenuta_cents).toBe(1265);
    });

    it('dà 373,11 di netto, non 373,12', () => {
        expect(totali.netto_cents).toBe(37311);
    });

    it('quadra con i componenti che l\'utente legge sopra il totale', () => {
        expect(totali.netto_cents).toBe(
            totali.imponibile_cents + totali.iva_cents - totali.ritenuta_cents,
        );
    });
});

describe('calcolaTotali — arrotondamenti che il backend fa e il form non faceva', () => {
    it('arrotonda l\'IVA riga per riga, non sul totale', () => {
        // 1,15 × 22% = 25,3 centesimi per riga → 25 + 25 = 50.
        // Sommando i grezzi verrebbe 0,506 € → 51 centesimi.
        const totali = calcolaTotali({
            is_pregresso: false,
            righe: [
                { importo_imponibile: 1.15, aliquota_iva: 22 },
                { importo_imponibile: 1.15, aliquota_iva: 22 },
            ],
            ritenuta: null,
        });

        expect(totali.iva_cents).toBe(50);
        expect(totali.totale_documento_cents).toBe(280);
    });

    it('arrotonda la base ridotta della ritenuta prima di applicare l\'aliquota', () => {
        // Provvigioni con riduzione: base 50%, aliquota 23%.
        // PHP:  round(100013 × 50/100) = 50007  →  round(50007 × 23/100) = 11502
        // In un passo solo: round(100013 × 0,115) = 11501.
        const totali = calcolaTotali({
            is_pregresso: false,
            righe: [{ importo_imponibile: 1000.13, aliquota_iva: 22 }],
            ritenuta: PROVVIGIONI_50,
        });

        expect(totali.base_ritenuta_cents).toBe(50007);
        expect(totali.ritenuta_cents).toBe(11502);
        expect(totali.netto_cents).toBe(100013 + 22003 - 11502);
    });

    it('arrotonda lontano da zero sugli importi negativi, come round() di PHP', () => {
        // −0,25 × 22% = −5,5 centesimi. PHP dà −6; Math.round darebbe −5.
        const totali = calcolaTotali({
            is_pregresso: false,
            righe: [{ importo_imponibile: -0.25, aliquota_iva: 22 }],
            ritenuta: null,
        });

        expect(totali.iva_cents).toBe(-6);
    });
});

describe('calcolaTotali — base della ritenuta', () => {
    it('esclude le righe che non concorrono, senza toccare l\'imponibile', () => {
        // Rimborso art. 15 / contributo cassa: entra nel documento, non nella base ritenuta.
        const totali = calcolaTotali({
            is_pregresso: false,
            righe: [
                { importo_imponibile: 1000, aliquota_iva: 22 },
                { importo_imponibile: 200, aliquota_iva: 22, concorre_base_ritenuta: false },
            ],
            ritenuta: APPALTO_4,
        });

        expect(totali.imponibile_cents).toBe(120000);
        expect(totali.base_ritenuta_cents).toBe(100000);
        expect(totali.ritenuta_cents).toBe(4000);
    });

    it('sulla fattura pregressa usa l\'imponibile intero come base', () => {
        // Nessuna riga di dettaglio: è il $imponibileTotaleFallback di RitenutaService.
        const totali = calcolaTotali({
            is_pregresso: true,
            imponibile_pregresso: 316.2,
            aliquota_iva_pregressa: 22,
            righe: [],
            ritenuta: APPALTO_4,
        });

        expect(totali.imponibile_cents).toBe(31620);
        expect(totali.iva_cents).toBe(6956);
        expect(totali.ritenuta_cents).toBe(1265);
        expect(totali.netto_cents).toBe(37311);
    });

    it('senza ritenuta il netto coincide col totale documento', () => {
        const totali = calcolaTotali({
            is_pregresso: false,
            righe: [{ importo_imponibile: 316.2, aliquota_iva: 22 }],
            ritenuta: null,
        });

        expect(totali.ritenuta_cents).toBe(0);
        expect(totali.netto_cents).toBe(totali.totale_documento_cents);
    });
});

describe('calcolaTotali — sopravvenienze', () => {
    it('separa ordinario e imprevisto senza perdere centesimi', () => {
        const totali = calcolaTotali({
            is_pregresso: false,
            righe: [
                { importo_imponibile: 1.15, aliquota_iva: 22 },
                { importo_imponibile: 1.15, aliquota_iva: 22, is_sopravvenienza: true },
            ],
            ritenuta: null,
        });

        expect(totali.ha_sopravvenienze).toBe(true);
        expect(totali.imponibile_ordinario_cents + totali.imponibile_sopravvenienza_cents)
            .toBe(totali.imponibile_cents);
        expect(totali.iva_ordinaria_cents + totali.iva_sopravvenienza_cents).toBe(totali.iva_cents);
    });
});

describe('risolviRegimeRitenuta', () => {
    it('preferisce il regime nuovo ai campi legacy del fornitore', () => {
        const regime = risolviRegimeRitenuta(
            { soggetto_ritenuta: true, tipo_ritenuta: 'provvigioni_base_50', perc_ritenuta: 4, perc_imponibile_ritenuta: 100 },
            true,
        );

        expect(regime).toEqual({ percBase: 50, percTratt: 23 });
    });

    it('ricade sui campi legacy quando il fornitore non ha ancora un regime', () => {
        const regime = risolviRegimeRitenuta(
            { soggetto_ritenuta: true, tipo_ritenuta: null, perc_ritenuta: 20, perc_imponibile_ritenuta: null },
            true,
        );

        expect(regime).toEqual({ percBase: 100, percTratt: 20 });
    });

    it('una base imponibile configurata a zero resta zero, non diventa il 100%', () => {
        // Il PHP legge `perc_imponibile_ritenuta ?? 100`: lo zero è un valore, non un vuoto.
        // Con `|| 100` l'anteprima annunciava una ritenuta che il salvataggio non faceva.
        const regime = risolviRegimeRitenuta(
            { soggetto_ritenuta: true, tipo_ritenuta: null, perc_ritenuta: 4, perc_imponibile_ritenuta: 0 },
            true,
        );

        expect(regime).toEqual({ percBase: 0, percTratt: 4 });
        expect(calcolaTotali({
            is_pregresso: false,
            righe: [{ importo_imponibile: 1000, aliquota_iva: 22 }],
            ritenuta: regime,
        }).ritenuta_cents).toBe(0);
    });

    it('non applica nulla al forfetario, anche se marcato soggetto a ritenuta', () => {
        expect(risolviRegimeRitenuta(
            { soggetto_ritenuta: true, regime_forfetario: true, tipo_ritenuta: 'appalto_4' },
            true,
        )).toBeNull();
    });

    it('non applica nulla quando la ritenuta è esclusa sul documento', () => {
        expect(risolviRegimeRitenuta({ soggetto_ritenuta: true, tipo_ritenuta: 'appalto_4' }, false)).toBeNull();
    });

    it('non applica nulla senza fornitore selezionato', () => {
        expect(risolviRegimeRitenuta(null, true)).toBeNull();
    });
});

describe("calcolaTotali — l'imposta che il documento dichiara (Coda 142, beta.19)", () => {
    // Il gruppo al 22 % del file 06 dei collaudi: tre righe (40,61 · 6,93 · −1,80) su un
    // imponibile dichiarato di 45,74 e un'imposta dichiarata di 10,06. Arrotondando riga per
    // riga si ottiene 8,93 + 1,52 − 0,40 = 10,05: un centesimo in meno di quello che il
    // fornitore chiede, e il documento veniva salvato a € 100,14 invece di € 100,15.
    const righeBolletta = [
        { importo_imponibile: 40.61, aliquota_iva: 22, natura: null },
        { importo_imponibile: 6.93, aliquota_iva: 22, natura: null },
        { importo_imponibile: -1.8, aliquota_iva: 22, natura: null },
    ];

    it('mostra la stessa imposta che il server salverà, non la somma delle righe', () => {
        const t = calcolaTotali({
            is_pregresso: false,
            righe: righeBolletta,
            riepiloghi: [{ aliquota_iva: 22, natura: null, imposta: 10.06 }],
            ritenuta: null,
        });

        expect(t.imponibile_cents).toBe(4574);
        expect(t.iva_cents).toBe(1006);
    });

    it('senza riepiloghi resta il calcolo per riga di sempre', () => {
        const t = calcolaTotali({ is_pregresso: false, righe: righeBolletta, ritenuta: null });

        expect(t.iva_cents).toBe(1005);
    });

    it('una riga a zero non riceve centesimi, come nel server', () => {
        const t = calcolaTotali({
            is_pregresso: false,
            righe: [
                { importo_imponibile: 0, aliquota_iva: 22, natura: null },
                { importo_imponibile: 33.33, aliquota_iva: 22, natura: null },
                { importo_imponibile: 33.33, aliquota_iva: 22, natura: null },
                { importo_imponibile: 33.34, aliquota_iva: 22, natura: null },
            ],
            riepiloghi: [{ aliquota_iva: 22, natura: null, imposta: 22.0 }],
            ritenuta: null,
        });

        expect(t.iva_cents).toBe(2200);
    });

    it('tiene separati due gruppi con la stessa aliquota e nature diverse', () => {
        // La chiave è la COPPIA aliquota/natura: due blocchi a 0 % con nature diverse sono
        // due gruppi distinti, e il tracciato li dichiara separati apposta.
        const t = calcolaTotali({
            is_pregresso: false,
            righe: [
                { importo_imponibile: 100, aliquota_iva: 0, natura: 'N2.2' },
                { importo_imponibile: 50, aliquota_iva: 0, natura: 'N4' },
            ],
            riepiloghi: [
                // ⚠️ **Imposte DIVERSE, e non è un dettaglio.** Nella prima versione di questo
                // test entrambi i gruppi dichiaravano imposta 0: l'asserzione `iva_cents === 0`
                // non distingueva niente e il test restava verde anche cancellando del tutto la
                // natura dalla chiave. Un test simmetrico non prova la separazione che dichiara
                // di provare. Trovato dalla Fase 1-bis della beta.19, lente «test fragili».
                { aliquota_iva: 0, natura: 'N2.2', imponibile: 100, imposta: 0 },
                { aliquota_iva: 0, natura: 'N4', imponibile: 50, imposta: 7.5 },
            ],
            ritenuta: null,
        });

        expect(t.imponibile_cents).toBe(15000);
        // 0 sul gruppo N2.2 e 750 su quello N4: se la natura non facesse parte della chiave i
        // due gruppi collasserebbero in uno solo e questo numero sarebbe diverso.
        expect(t.iva_cents).toBe(750);
        expect(t.iva_righe_cents).toEqual([0, 750]);
    });

    it("sul pregresso l'imposta dichiarata vince sull'aliquota media arrotondata", () => {
        // Il pannello pregresso ha un campo solo, quindi riceve la media pesata a due
        // decimali, e da quella il calcolo ricostruisce l'imposta.
        //
        // ⚠️ **Sugli undici file di collaudo quella ricostruzione è esatta in tutti e undici**
        // (verificato il 06/09/2026): la correzione non cambia nessuno di quei numeri. Ma il
        // caso divergente esiste ed è costruibile — qui imponibile 100,05 con imposta 10,00 dà
        // un'aliquota media di 10,00 che ricostruisce 10,01. Un centesimo inventato su un
        // debito che resta a bilancio.
        const conMedia = calcolaTotali({
            is_pregresso: true, imponibile_pregresso: 100.05, aliquota_iva_pregressa: 10.0,
            righe: [], ritenuta: null,
        });
        const conDichiarata = calcolaTotali({
            is_pregresso: true, imponibile_pregresso: 100.05, aliquota_iva_pregressa: 10.0,
            imposta_pregressa: 10.0, righe: [], ritenuta: null,
        });

        expect(conMedia.iva_cents).toBe(1001);      // il centesimo inventato
        expect(conDichiarata.iva_cents).toBe(1000); // il numero che il documento dichiara
    });
});

describe("calcolaTotali — l'IVA di ciascuna riga, per chi mostra e chi addebita", () => {
    // Trovato guardando lo schermo, non leggendo il codice: registrato il file 06 dei
    // collaudi, il dettaglio della fattura porta la riga «materia gas naturale» a € 8,46
    // mentre il modulo, un secondo prima, ne mostrava € 8,45. Il totale del documento era
    // giusto in entrambe le schermate — era la riga a mentire.
    //
    // La causa: `lordoRigaCents(imponibile, aliquota)` ricostruisce l'IVA dalla riga, e dalla
    // beta.19 l'IVA di riga non è più ricostruibile dalla riga. Lo stesso numero serviva a tre
    // cose che devono coincidere — il totale mostrato, la spesa addebitata al capitolo, la
    // soglia di sforo — quindi anche il pannello budget addebitava un centesimo in meno di
    // quanto la fattura avrebbe consumato.
    const bollettaFile06 = {
        is_pregresso: false as const,
        ritenuta: null,
        riepiloghi: [
            { aliquota_iva: 22, natura: null, imponibile: 45.74, imposta: 10.06 },
            { aliquota_iva: 0, natura: 'N2.2', imponibile: 44.35, imposta: 0 },
        ],
        righe: [
            { importo_imponibile: 44.35, aliquota_iva: 0, natura: 'N2.2' },
            { importo_imponibile: 40.61, aliquota_iva: 22, natura: null },
            { importo_imponibile: 6.93, aliquota_iva: 22, natura: null },
            { importo_imponibile: -1.80, aliquota_iva: 22, natura: null },
        ],
    };

    it('espone l’IVA riga per riga, nello stesso ordine delle righe', () => {
        const t = calcolaTotali(bollettaFile06);

        expect(t.iva_righe_cents).toEqual([0, 893, 153, -40]);
        // La somma è l'IVA di testata: se un giorno non lo fosse, la scrittura in partita
        // doppia verrebbe respinta dal backend.
        expect(t.iva_righe_cents.reduce((a, b) => a + b, 0)).toBe(t.iva_cents);
    });

    it('la riga che riceve il centesimo di compensazione non torna col calcolo per riga', () => {
        // È il controesempio che dà senso alla proprietà: se qui i due numeri coincidessero,
        // `iva_righe_cents` sarebbe una comodità e non una necessità.
        const t = calcolaTotali(bollettaFile06);

        const perRiga = Math.round((693 * 22) / 100);   // 152: quello che mostrava il modulo
        expect(perRiga).toBe(152);
        expect(t.iva_righe_cents[2]).toBe(153);         // quello che finisce a bilancio

        // Tradotto in lordo di riga: € 8,45 contro € 8,46.
        expect(693 + perRiga).toBe(845);
        expect(693 + t.iva_righe_cents[2]).toBe(846);
    });

    it('senza riepiloghi resta il calcolo per riga, e le due strade coincidono', () => {
        // Non regressione: la fattura digitata a mano non ha riepiloghi, e lì `iva_righe_cents`
        // deve dire esattamente ciò che il modulo ha sempre mostrato.
        const t = calcolaTotali({ ...bollettaFile06, riepiloghi: undefined });

        expect(t.iva_righe_cents).toEqual([0, 893, 152, -40]);
    });

    it('sul pregresso è vuoto, perché non ci sono righe', () => {
        const t = calcolaTotali({
            is_pregresso: true, imponibile_pregresso: 100.0, aliquota_iva_pregressa: 22,
            righe: [], ritenuta: null,
        });

        expect(t.iva_righe_cents).toEqual([]);
    });
});
