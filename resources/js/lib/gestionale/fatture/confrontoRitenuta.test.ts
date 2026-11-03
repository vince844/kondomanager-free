import { describe, expect, test } from 'vitest';
import { confrontaRitenuta, proponiNaturaDaRitenuta, proponiRegimeDaRitenuta, type IngressoConfrontoRitenuta } from './confrontoRitenuta';

/**
 * La matrice dei casi, ricavata mettendosi nei panni dell'amministratore (03/09/2026).
 *
 * ⚠️ I due casi che contano di più sono quelli in cui il risultato è `nessun_confronto`:
 * fornitore a posto con importi coincidenti — no, quello parla — e soprattutto **file
 * muto + modulo che non trattiene**, che è la stragrande maggioranza delle fatture. Se
 * quel caso producesse un avviso, in una settimana nessuno leggerebbe più nessun avviso.
 */

const FORNITORE_SOGGETTO = {
    ragione_sociale: 'Alfa Servizi Srl',
    soggetto_ritenuta: true,
    regime_forfetario: false,
    tipo_ritenuta: 'appalto_4',
};

const RITENUTA_FILE = { tipo: 'RT01', importo: 42, aliquota: 4, causale_pagamento: 'A' };

function ingresso(over: Partial<IngressoConfrontoRitenuta> = {}): IngressoConfrontoRitenuta {
    return {
        ritenutaDaXml: RITENUTA_FILE,
        ritenutaModuloCents: 4200,
        fornitore: FORNITORE_SOGGETTO,
        tipoDocumento: 'fattura',
        applicaRitenuta: true,
        daFile: true,
        ...over,
    };
}

describe('quando il software deve tacere', () => {
    test('nessun file importato: non c\'è niente da confrontare', () => {
        expect(confrontaRitenuta(ingresso({ daFile: false })).stato).toBe('nessun_confronto');
    });

    test('il file tace e il modulo non trattiene — il caso più frequente di tutti', () => {
        const esito = confrontaRitenuta(ingresso({
            ritenutaDaXml: null,
            ritenutaModuloCents: 0,
            fornitore: { ragione_sociale: 'Beta Srl', soggetto_ritenuta: false },
        }));
        expect(esito.stato).toBe('nessun_confronto');
    });

    test('il file dichiara un blocco a zero e il modulo non trattiene', () => {
        const esito = confrontaRitenuta(ingresso({
            ritenutaDaXml: { ...RITENUTA_FILE, importo: 0 },
            ritenutaModuloCents: 0,
        }));
        expect(esito.stato).toBe('nessun_confronto');
    });
});

describe('il file dichiara e il modulo trattiene', () => {
    test('gli importi coincidono: si dice, e si dice che coincidono', () => {
        const esito = confrontaRitenuta(ingresso());
        expect(esito).toEqual({ stato: 'coincidono', fileCents: 4200, moduloCents: 4200 });
    });

    test('gli importi non coincidono: nessuno dei due viene cambiato', () => {
        const esito = confrontaRitenuta(ingresso({ ritenutaModuloCents: 21000 }));
        expect(esito).toEqual({ stato: 'importi_diversi', fileCents: 4200, moduloCents: 21000 });
    });

    // ⚠️ **Il valore è scelto perché il difetto lo esibisce davvero, e l'ho verificato:**
    // `1.14 * 100` in virgola mobile non fa 114, fa 114.00000000000001. Senza `Math.round`
    // due importi identici risultano diversi e l'avviso ambra comparirebbe su una fattura
    // perfetta. Il primo valore che avevo scelto (1,4) NON esibisce il problema e lasciava
    // passare la mutazione: un test che non diventa rosso togliendo la guardia non prova
    // la guardia. € 1,14 è il 4% di € 28,50 — piccolo ma del tutto plausibile.
    test('gli importi coincidono anche quando la virgola mobile complica', () => {
        const esito = confrontaRitenuta(ingresso({
            ritenutaDaXml: { ...RITENUTA_FILE, importo: 1.14 },
            ritenutaModuloCents: 114,
        }));
        expect(esito.stato).toBe('coincidono');
    });
});

describe('il file dichiara, il modulo no — e il perché cambia il rimedio', () => {
    test('il fornitore non è ancora stato scelto', () => {
        const esito = confrontaRitenuta(ingresso({ fornitore: null, ritenutaModuloCents: 0 }));
        expect(esito).toEqual({ stato: 'file_dichiara_modulo_no', fileCents: 4200, motivo: 'fornitore_mancante' });
    });

    // È il reperto 12: il fornitore c'è, ma nessuno ha spuntato la casella in anagrafica.
    test('il fornitore non è segnato come soggetto a ritenuta', () => {
        const esito = confrontaRitenuta(ingresso({
            fornitore: { ragione_sociale: 'Alfa Servizi Srl', soggetto_ritenuta: false },
            ritenutaModuloCents: 0,
        }));
        expect(esito).toEqual({ stato: 'file_dichiara_modulo_no', fileCents: 4200, motivo: 'non_soggetto' });
    });

    // Il forfetario vince su soggetto_ritenuta anche nel motore (RitenutaService:46):
    // qui le due cose si contraddicono e va detto che si contraddicono, non che manca una spunta.
    test('il fornitore è forfetario: il file e l\'anagrafica si contraddicono', () => {
        const esito = confrontaRitenuta(ingresso({
            fornitore: { ...FORNITORE_SOGGETTO, regime_forfetario: true },
            ritenutaModuloCents: 0,
        }));
        expect(esito).toEqual({ stato: 'file_dichiara_modulo_no', fileCents: 4200, motivo: 'forfetario' });
    });

    test('nota di credito: il default è non applicare, ed è previsto', () => {
        const esito = confrontaRitenuta(ingresso({
            tipoDocumento: 'nota_credito',
            applicaRitenuta: false,
            ritenutaModuloCents: 0,
        }));
        expect(esito).toEqual({ stato: 'file_dichiara_modulo_no', fileCents: 4200, motivo: 'nota_credito' });
    });

    test('esclusa a mano su questo documento: la scelta dell\'amministratore vale di più', () => {
        const esito = confrontaRitenuta(ingresso({ applicaRitenuta: false, ritenutaModuloCents: 0 }));
        expect(esito).toEqual({ stato: 'file_dichiara_modulo_no', fileCents: 4200, motivo: 'esclusa_a_mano' });
    });

    test('soggetto e applicata, ma la trattenuta è zero: regime incompleto o nessuna riga in base', () => {
        const esito = confrontaRitenuta(ingresso({
            fornitore: { ...FORNITORE_SOGGETTO, tipo_ritenuta: null },
            ritenutaModuloCents: 0,
        }));
        expect(esito).toEqual({ stato: 'file_dichiara_modulo_no', fileCents: 4200, motivo: 'altro' });
    });
});

describe('il verso opposto: il modulo trattiene e il file tace', () => {
    // ⚠️ Il danno è speculare e altrettanto reale: si trattiene al fornitore denaro che
    // forse non andava trattenuto. È il caso della fornitura di soli beni da un fornitore
    // che di solito fa appalti — legittimo, e il sistema lo prevede riga per riga.
    test('il file non dichiara niente ma il modulo trattiene', () => {
        const esito = confrontaRitenuta(ingresso({ ritenutaDaXml: null, ritenutaModuloCents: 12800 }));
        expect(esito).toEqual({ stato: 'modulo_trattiene_file_tace', moduloCents: 12800 });
    });

    test('il file dichiara un blocco a zero e il modulo trattiene', () => {
        const esito = confrontaRitenuta(ingresso({
            ritenutaDaXml: { ...RITENUTA_FILE, importo: 0 },
            ritenutaModuloCents: 12800,
        }));
        expect(esito).toEqual({ stato: 'modulo_trattiene_file_tace', moduloCents: 12800 });
    });

    test('senza file non si segnala nulla, anche se il modulo trattiene', () => {
        const esito = confrontaRitenuta(ingresso({ daFile: false, ritenutaDaXml: null, ritenutaModuloCents: 12800 }));
        expect(esito.stato).toBe('nessun_confronto');
    });
});

/**
 * La proposta del regime alla creazione del fornitore.
 *
 * ⚠️ I numeri dei primi test sono **quelli veri** delle cinque fatture del collaudo con
 * ritenuta: € 5,40 su € 135,00, € 42,00 su € 1.050,00, € 165,84 su € 4.146,00. Tutte al
 * 4%, e su tutte il conto torna.
 */
describe('il regime proposto dall\'aliquota, confermato dai numeri del file', () => {
    const r = (importo: number, aliquota: number) => ({ tipo: 'RT01', importo, aliquota, causale_pagamento: 'A' });

    test('il 4% delle fatture vere diventa appalto, e il conto torna', () => {
        expect(proponiRegimeDaRitenuta(r(5.40, 4), 13500)).toEqual({
            tipoRitenuta: 'appalto_4', confermataDagliImporti: true, aliquota: 4,
        });
        expect(proponiRegimeDaRitenuta(r(42, 4), 105000)?.tipoRitenuta).toBe('appalto_4');
        expect(proponiRegimeDaRitenuta(r(165.84, 4), 414600)?.tipoRitenuta).toBe('appalto_4');
    });

    test('il 20% diventa lavoro autonomo', () => {
        expect(proponiRegimeDaRitenuta(r(80, 20), 40000)?.tipoRitenuta).toBe('lavoro_autonomo_20');
    });

    test('«4.00» e «4» sono la stessa aliquota', () => {
        expect(proponiRegimeDaRitenuta(r(5.40, 4.00), 13500)?.tipoRitenuta).toBe('appalto_4');
    });

    // ⚠️ Il caso delle provvigioni: il file dichiara il 23% ma l'importo è l'11,5% —
    // l'aliquota nominale si applica a una base dimezzata. Proporre «23%» sarebbe
    // sbagliato, e il conto che non torna è ciò che lo rivela.
    test('un\'aliquota nominale su base ridotta non viene confermata, e non si propone niente', () => {
        const esito = proponiRegimeDaRitenuta(r(115, 23), 100000); // 11,5% di 1.000,00
        expect(esito?.confermataDagliImporti).toBe(false);
        expect(esito?.tipoRitenuta).toBeNull();
    });

    test('un\'aliquota che il conto conferma ma che non sappiamo mappare non propone niente', () => {
        const esito = proponiRegimeDaRitenuta(r(300, 30), 100000); // non residente 30%
        expect(esito?.confermataDagliImporti).toBe(true);
        expect(esito?.tipoRitenuta, 'il 30% non è in mappa: si lascia scegliere').toBeNull();
    });

    test('due centesimi di arrotondamento non fanno saltare la conferma', () => {
        expect(proponiRegimeDaRitenuta(r(5.42, 4), 13500)?.confermataDagliImporti).toBe(true);
        expect(proponiRegimeDaRitenuta(r(5.45, 4), 13500)?.confermataDagliImporti).toBe(false);
    });

    test('senza ritenuta, senza importo o senza base non si propone niente', () => {
        expect(proponiRegimeDaRitenuta(null, 13500)).toBeNull();
        expect(proponiRegimeDaRitenuta(r(0, 4), 13500)).toBeNull();
        expect(proponiRegimeDaRitenuta(r(5.40, 4), 0)).toBeNull();
    });
});

/**
 * La natura del percipiente, che decide 1019 contro 1020 nel modello F24.
 *
 * ⚠️ Non proporla significa alimentare il ripiego silenzioso di `GeneraDelegheF24Action`,
 * che sceglie `PERSONA_FISICA_IRPEF` quando la natura manca — cioè stampa 1019 anche su
 * una società, e il denaro arriva all'Erario sotto un codice che non è il suo.
 */
describe('la natura del percipiente proposta dal tipo di ritenuta', () => {
    const con = (tipo: string | null) => ({ tipo, importo: 42, aliquota: 4, causale_pagamento: 'W' });

    test('RT01 è persona fisica: IRPEF, codice tributo 1019', () => {
        expect(proponiNaturaDaRitenuta(con('RT01'))).toBe('persona_fisica_irpef');
    });

    test('RT02 non è persona fisica: IRES, codice tributo 1020', () => {
        expect(proponiNaturaDaRitenuta(con('RT02'))).toBe('soggetto_ires');
    });

    test('la maiuscola non conta: il file può scrivere in minuscolo', () => {
        expect(proponiNaturaDaRitenuta(con('rt02'))).toBe('soggetto_ires');
    });

    // ⚠️ RT03…RT06 sono contributi previdenziali, non ritenute d'acconto: non dicono
    // niente sulla natura del percipiente ai fini del codice tributo, e indovinare qui
    // sarebbe il difetto che questa funzione esiste per non commettere.
    test('un contributo previdenziale non propone nessuna natura', () => {
        expect(proponiNaturaDaRitenuta(con('RT04'))).toBeNull();
    });

    test('senza ritenuta o senza tipo non si propone niente', () => {
        expect(proponiNaturaDaRitenuta(null)).toBeNull();
        expect(proponiNaturaDaRitenuta(con(null))).toBeNull();
    });
});
