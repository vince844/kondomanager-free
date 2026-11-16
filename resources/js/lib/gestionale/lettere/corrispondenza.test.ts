// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

import { describe, expect, it } from 'vitest';
import {
    FRASE_DATE_ROVESCIATE,
    FRASE_NIENTE,
    FRASE_NIENTE_COI_FILTRI,
    PER_PAGINA,
    fraseDelVuoto,
    TIPI_DEI_FILTRI,
    filtraCorrispondenza,
    numeroDellaScheda,
    paginaDi,
    riassuntoDellaCorrispondenza,
    testoCifreDelDocumento,
    testoDocumento,
    testoRigaDelDocumento,
    vociDellaCorrispondenza,
    type DocumentoInviato,
} from './corrispondenza';

/**
 * La scheda «Corrispondenza» (Coda 533): l'ordine misto di lettere e documenti, i filtri, le pagine da dieci, il numero della scheda, i testi
 * delle ricevute e degli avvisi. Che cosa NON copre: chi porta i dati (il server, `CorrispondenzaDellaPersonaTest.php`) e il clic nella
 * scheda (`RegistroLettere.test.ts`).
 */
const lettera = (chiave: string, tipo: string, emessa_il: string, ora: string, id: number) => ({ lettera: chiave, tipo, emessa_il, ora, righe: [{ id }] });
const documento = (altro: Partial<DocumentoInviato>): DocumentoInviato => ({
    chiave: `ricevuta-${altro.id ?? 1}`, id: 1, quando: '', tipo: 'ricevuta', non_da_comunicare: false, emessa_il: '2026-03-01', ora: '10:00', emesso_da: 'Anna Studio',
    numero: '12', versamento_del: '2026-02-01', importo: 17000, annullato: false, copia_del: null, incasso_id: 40, per_conto_di: null,
    righe: [{ id: altro.id ?? 1, canale: 'stampa', destinatario: null, stato: 'stampata', quando: '2026-03-01 10:00', errore: null }],
    ...altro,
});

describe('vociDellaCorrispondenza', () => {
    it('mette insieme lettere e documenti, dalla più recente; a pari minuto prima l\'id più alto', () => {
        const voci = vociDellaCorrispondenza(
            [lettera('a', 'sollecito', '2026-03-05', '09:00', 7), lettera('b', 'diffida', '2026-03-01', '10:00', 3)],
            [documento({ id: 5, emessa_il: '2026-03-03', ora: '12:00' }), documento({ id: 4, emessa_il: '2026-03-01', ora: '10:00', tipo: 'avviso_compensazione', chiave: 'avviso-4' })],
        );
        expect(voci.map((v) => v.chiave)).toEqual(['lettera-a', 'ricevuta-5', 'avviso-4', 'lettera-b']);
        expect(voci.map((v) => v.genere)).toEqual(['lettera', 'documento', 'documento', 'lettera']);
    });

    it('a pari minuto una lettera con l\'id più alto viene prima del documento', () => {
        const voci = vociDellaCorrispondenza([lettera('a', 'sollecito', '2026-03-01', '10:00', 9)], [documento({ id: 8, emessa_il: '2026-03-01', ora: '10:00' })]);
        expect(voci.map((v) => v.chiave)).toEqual(['lettera-a', 'ricevuta-8']);
    });

    it('il numero della scheda è il numero delle voci dell\'elenco senza filtri', () => {
        const lettere = [lettera('a', 'sollecito', '2026-03-05', '09:00', 7), lettera('b', 'diffida', '2026-03-01', '10:00', 3)];
        const documenti = [documento({ id: 5 }), documento({ id: 6, non_da_comunicare: true, chiave: 'ricevuta-6' })];
        expect(numeroDellaScheda(lettere, documenti)).toBe(vociDellaCorrispondenza(lettere, documenti).length);
        expect(numeroDellaScheda(lettere, documenti)).toBe(4);
        expect(numeroDellaScheda([], [])).toBe(0);
    });
});

describe('filtraCorrispondenza', () => {
    const voci = vociDellaCorrispondenza(
        [lettera('p', 'promemoria', '2026-01-10', '09:00', 1), lettera('s', 'sollecito', '2026-02-10', '09:00', 2), lettera('m', 'messa_in_mora', '2026-03-10', '09:00', 3)],
        [documento({ id: 4, emessa_il: '2026-02-28' }), documento({ id: 5, tipo: 'avviso_compensazione', chiave: 'avviso-5', emessa_il: '2026-03-01' }),
            documento({ id: 6, chiave: 'ricevuta-6', non_da_comunicare: true, emessa_il: '2026-03-31' })],
    );
    const chiavi = (tipo: string, dal = '', al = '') => filtraCorrispondenza(voci, { tipo, dal, al }).map((v) => v.chiave);

    it('per tipo: tutto, le lettere, ogni tipo di lettera, le ricevute (con «non serve comunicarla»), gli avvisi', () => {
        expect(chiavi('')).toHaveLength(6);
        expect(chiavi('lettere')).toEqual(['lettera-m', 'lettera-s', 'lettera-p']);
        expect(chiavi('sollecito')).toEqual(['lettera-s']);
        expect(chiavi('promemoria')).toEqual(['lettera-p']);
        expect(chiavi('diffida')).toEqual([]);
        expect(chiavi('ricevuta')).toEqual(['ricevuta-6', 'ricevuta-4']);
        expect(chiavi('avviso_compensazione')).toEqual(['avviso-5']);
        expect(TIPI_DEI_FILTRI.map((t) => t.valore)).toEqual(['', 'lettere', 'promemoria', 'sollecito', 'diffida', 'messa_in_mora', 'ricevuta', 'avviso_compensazione']);
    });

    it('per periodo, sul giorno di emissione, con gli estremi compresi; un estremo solo vale da solo', () => {
        expect(chiavi('', '2026-02-10', '2026-03-01')).toEqual(['avviso-5', 'ricevuta-4', 'lettera-s']);
        expect(chiavi('', '2026-03-01', '')).toEqual(['ricevuta-6', 'lettera-m', 'avviso-5']);
        expect(chiavi('', '', '2026-01-10')).toEqual(['lettera-p']);
        expect(chiavi('ricevuta', '2026-03-01', '2026-03-31')).toEqual(['ricevuta-6']);
        expect(chiavi('', '2026-04-01', '2026-03-01')).toEqual([]);
    });
});

describe('paginaDi', () => {
    const undici = Array.from({ length: 11 }, (_, i) => i + 1);

    it('dieci per pagina: undici voci sono due pagine, la seconda con una', () => {
        expect(PER_PAGINA).toBe(10);
        expect(paginaDi(undici, 1)).toEqual({ voci: undici.slice(0, 10), pagina: 1, pagine: 2 });
        expect(paginaDi(undici, 2)).toEqual({ voci: [11], pagina: 2, pagine: 2 });
    });

    it('una pagina che non c\'è più torna all\'ultima; nessuna voce è una pagina vuota', () => {
        expect(paginaDi(undici, 5).pagina).toBe(2);
        expect(paginaDi(undici, 0).pagina).toBe(1);
        expect(paginaDi([], 3)).toEqual({ voci: [], pagina: 1, pagine: 1 });
        expect(paginaDi(Array.from({ length: 10 }, (_, i) => i), 2)).toEqual({ voci: Array.from({ length: 10 }, (_, i) => i), pagina: 1, pagine: 1 });
    });
});

describe('i testi dei documenti', () => {
    it('la ricevuta: numero, giorno, ora, chi; il versamento con l\'importo; la copia e l\'incasso annullato', () => {
        expect(testoDocumento(documento({}))).toBe('Ricevuta n. 12 del 01/03/2026 alle 10:00, da Anna Studio');
        expect(testoCifreDelDocumento(documento({}))).toBe('Versamento del 01/02/2026, € 170,00.');
        expect(testoCifreDelDocumento(documento({ copia_del: '2026-02-20', annullato: true })))
            .toBe('Versamento del 01/02/2026, € 170,00; incasso annullato; copia della ricevuta del 20/02/2026.');
    });

    it('l\'avviso di compensazione: non ha un numero suo; l\'incasso con il numero e la parte coperta dal credito', () => {
        const avviso = documento({ tipo: 'avviso_compensazione', numero: '15', versamento_del: '2026-03-03', importo: 2000, emesso_da: null });
        expect(testoDocumento(avviso)).toBe('Avviso di compensazione del 01/03/2026 alle 10:00');
        expect(testoCifreDelDocumento(avviso)).toBe('Incasso n. 15 del 03/03/2026: € 20,00 coperti dal credito.');
    });

    it('la ricevuta mandata a chi ha versato per un altro dice per conto di chi', () => {
        expect(testoCifreDelDocumento(documento({ per_conto_di: 'Anna Bruni' }))).toBe('Versamento del 01/02/2026, € 170,00, per conto di Anna Bruni.');
    });

    it('«non serve comunicarla»: chi e quando, senza cifre inventate', () => {
        const nonServe = documento({ non_da_comunicare: true, importo: null, versamento_del: null, righe: [{ id: 9, canale: 'nessuno', destinatario: null, stato: 'non_da_comunicare', quando: '2026-03-06 10:00', errore: null }] });
        expect(testoDocumento(nonServe)).toBe('Incasso n. 12 annullato: «non serve comunicarlo», segnato il 01/03/2026 alle 10:00, da Anna Studio');
        expect(testoCifreDelDocumento(nonServe)).toBe('');
        expect(testoRigaDelDocumento(nonServe.righe[0])).toBe('Nessun invio, per scelta dell\'amministratore');
    });

    it('le righe: stampata (mai «spedizione»), inviata, in coda, non partita', () => {
        const riga = (altro: object) => ({ id: 1, canale: 'email', destinatario: 'a@x.it', stato: 'inviata', quando: '2026-03-01 10:00', errore: null, ...altro });
        expect(testoRigaDelDocumento(riga({ canale: 'stampa', destinatario: null, stato: 'stampata' }))).toBe('Stampata il 01/03/2026 alle 10:00');
        expect(testoRigaDelDocumento(riga({}))).toBe('Inviata il 01/03/2026 alle 10:00 a a@x.it, consegna non provata');
        expect(testoRigaDelDocumento(riga({ stato: 'in_coda' }))).toBe('In coda dal 01/03/2026 alle 10:00, per a@x.it');
        expect(testoRigaDelDocumento(riga({ stato: 'non_partita', errore: 'Indirizzo rifiutato.' }))).toBe('Non partita il 01/03/2026 alle 10:00, per a@x.it: Indirizzo rifiutato.');
        for (const stato of ['stampata', 'inviata', 'in_coda', 'non_partita']) {
            expect(testoRigaDelDocumento(riga({ stato }))).not.toMatch(/spedizion/i);
        }
    });

    it('la frase del vuoto vero', () => {
        expect(FRASE_NIENTE).toBe('Nessuna lettera, ricevuta o avviso a questa persona in questo condominio.');
    });

    it('i filtri che non trovano niente: con «dal» dopo «al» la frase dice perché', () => {
        expect(fraseDelVuoto({ tipo: '', dal: '2026-04-01', al: '2026-03-01' })).toBe(FRASE_DATE_ROVESCIATE);
        expect(FRASE_DATE_ROVESCIATE).toBe('«Dal» è dopo «al»: nessun giorno è in mezzo.');
        expect(fraseDelVuoto({ tipo: 'diffida', dal: '2026-03-01', al: '2026-03-01' })).toBe(FRASE_NIENTE_COI_FILTRI);
        expect(fraseDelVuoto({ tipo: '', dal: '2026-04-01', al: '' })).toBe(FRASE_NIENTE_COI_FILTRI);
    });
});

describe('riassuntoDellaCorrispondenza', () => {
    const lettera = (n: number) => ({ lettera: `l${n}`, tipo: 'sollecito', emessa_il: '2026-03-05', ora: '10:00', righe: [{ id: n }] });
    const documento = (n: number, tipo: string, nonDaComunicare = false) => ({ chiave: `${tipo}-${n}`, id: n, quando: '', tipo, non_da_comunicare: nonDaComunicare,
        emessa_il: '2026-03-01', ora: '10:00', emesso_da: null, numero: String(n), versamento_del: null, importo: null, annullato: false, copia_del: null,
        incasso_id: null, per_conto_di: null, righe: [] }) as DocumentoInviato;

    it('dice che cosa c\'è, con i nomi della scheda, al singolare e al plurale, nell\'ordine del filtro', () => {
        expect(riassuntoDellaCorrispondenza([], [documento(1, 'ricevuta'), documento(2, 'ricevuta'), documento(3, 'ricevuta', true)])).toBe('3 ricevute');
        expect(riassuntoDellaCorrispondenza([lettera(1)], [documento(2, 'ricevuta'), documento(3, 'ricevuta')])).toBe('1 lettera e 2 ricevute');
        expect(riassuntoDellaCorrispondenza([lettera(1), lettera(2)], [documento(3, 'avviso_compensazione'), documento(4, 'ricevuta')]))
            .toBe('2 lettere, 1 ricevuta e 1 avviso di compensazione');
        expect(riassuntoDellaCorrispondenza([], [documento(1, 'avviso_compensazione'), documento(2, 'avviso_compensazione')])).toBe('2 avvisi di compensazione');
        expect(riassuntoDellaCorrispondenza([lettera(1)], [])).toBe('1 lettera');
        expect(riassuntoDellaCorrispondenza([], [])).toBe('');
    });

    it('conta le stesse voci del numero della scheda', () => {
        const lettere = [lettera(1)];
        const documenti = [documento(2, 'ricevuta'), documento(3, 'avviso_compensazione'), documento(4, 'altro')];
        const numeri = riassuntoDellaCorrispondenza(lettere, documenti).match(/\d+/g)!.map(Number);
        expect(numeri.reduce((a, b) => a + b, 0)).toBe(numeroDellaScheda(lettere, documenti));
    });
});
