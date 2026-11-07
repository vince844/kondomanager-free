import { afterEach, describe, expect, test, vi } from 'vitest';
import {
    alterna,
    bolliRichiesti,
    indirizziIniziali,
    payloadSelezione,
    pronteDaEmettere,
    quanteSelezionate,
    selezionaPagina,
    selezioneVuota,
    testoAnteprima,
    testoEmissione,
    testoRiepilogo,
    tutteDeiFiltri,
    destinatariScelti,
    sopraSoglia,
    estremiDaMandare,
    testoTetto,
    testoRestantiBlocco,
    testoRestantiRiprova,
    testoAltriIndirizzi,
    FRASE_BOLLO,
    NOTA_MODO_UNICO,
    SCELTE_BOLLO,
    SEGNAPOSTO_ESTREMI,
    idSceltaBollo,
    statoConModo,
    NOTA_INTERESSI,
    RIGA_ORIGINALE,
    TESTO_COPIA_CAMBIATA,
    testoSogliaCredito,
    riepilogoDelleScelte,
    soloPersoneDaMandare,
    unisciEsiti,
    testoConfermaInviaDiNuovo,
    testoConfermaRiprova,
    testoRegistratiDiRecente,
    testoCausali,
    etichettaParteDelPdf,
    testoBollo,
    testoParteInPiu,
    TESTO_IMPUTAZIONE,
    NOTA_CONTRASSEGNO_EMAIL,
    FRASE_LIMITE_ALTRO_INDIRIZZO,
    frasePerErrore,
    testoLimiteRiprova,
    chiaveIndirizzo,
    gruppiDelleQuote,
    totaliDelleQuote,
    indirizzoMailto,
    testoDellaBozza,
    nomeDalContentDisposition,
    preparaEmail,
    testoConfermaNonServe,
    fraseDaErroreConFile,
    sessioneScaduta,
    testoEsitoInvio,
    FRASE_SESSIONE_SCADUTA,
    FRASE_NON_PERMESSO,
    FRASE_NON_E_UN_PDF,
    FRASE_NON_E_UN_ARCHIVIO,
    FRASE_FILE_NON_PDF,
    FRASE_FILE_NON_ARCHIVIO,
    RispostaNonValida,
    verificaTipoRisposta,
    verificaIlFile,
    RIGA_ATTESA_PDF,
    apriPdfInUnaScheda,
    apriSchedaDiAttesa,
    liberaQuandoSiChiude,
    testoDelPdf,
    type AmbientePdf,
    type PersonaAnteprima,
} from './ricevute';

/**
 * La logica della pagina delle ricevute e della finestra di un incasso (1.12.0-beta.2): la selezione («seleziona tutte le N dei filtri»), i
 * conteggi dell'anteprima e del riepilogo, le scelte del bollo che servono, gli indirizzi spuntati di partenza; il PDF in una scheda nuova
 * (la scheda prima della richiesta, il PDF nella scheda o scaricato, la scheda chiusa sull'errore, l'indirizzo liberato alla chiusura). Le
 * cifre sono in centesimi.
 *
 * Che cosa NON copre: le pagine a video, il server (nei test PHP della ricevuta), un browser vero (la scheda, il visore del PDF).
 */
describe('la selezione', () => {
    test('una casella alla volta, poi «tutte le N dei filtri», che vale più della lista', () => {
        let s = selezioneVuota();
        s = alterna(s, 4);
        s = alterna(s, 9);
        s = alterna(s, 4);
        expect(s.ids).toEqual([9]);
        expect(quanteSelezionate(s, 37)).toBe(1);

        s = tutteDeiFiltri(s);
        expect(quanteSelezionate(s, 37)).toBe(37);
        expect(payloadSelezione(s, { ricevuta: 'mai', persone: [3] })).toEqual({ tutte: 1, filtri: { ricevuta: 'mai', persone: [3] } });

        // Toccare una riga esce da «tutte»: si torna alla lista, vuota (a video le righe sono spuntate e ferme finché «tutte» vale).
        s = alterna(s, 9);
        expect(s.tutte).toBe(false);
        expect(payloadSelezione(s, {})).toEqual({ incassi: [] });
    });

    test('la casella della pagina spunta le righe della pagina, e se ci sono già tutte le toglie', () => {
        let s = selezionaPagina(selezioneVuota(), [1, 2, 3]);
        expect(s.ids).toEqual([1, 2, 3]);
        s = selezionaPagina(s, [1, 2, 3]);
        expect(s.ids).toEqual([]);
        s = selezionaPagina(alterna(selezioneVuota(), 7), [1, 2]);
        expect(s.ids).toEqual([7, 1, 2]);
    });
});

describe('il bollo', () => {
    test('la soglia è sopra € 77,47, non a € 77,47', () => {
        expect(sopraSoglia(7747)).toBe(false);
        expect(sopraSoglia(7748)).toBe(true);
    });

    test('si chiede solo per i gruppi che ci sono, e senza la scelta non si emette', () => {
        expect(bolliRichiesti({ sopra_soglia: 3, sotto_soglia: 0 })).toEqual({ sopra: true, sotto: false });
        expect(pronteDaEmettere({ bollo_sopra: 'assolta', bollo_sotto: null }, { sopra: true, sotto: false })).toBe(true);
        expect(pronteDaEmettere({ bollo_sopra: 'assolta', bollo_sotto: null }, { sopra: true, sotto: true })).toBe(false);
        expect(pronteDaEmettere({ bollo_sopra: null, bollo_sotto: null }, { sopra: false, sotto: false })).toBe(true);
    });
});

describe('gli indirizzi della finestra di un incasso', () => {
    const destinatari = [
        { anagrafica_id: 1, nome: 'Anna Bruni', ruolo: 'posizione', indirizzi: [
            { tipo: 'email', indirizzo: 'a@x.it', proposto: true },
            { tipo: 'email_secondaria', indirizzo: 'b@x.it', proposto: true },
            { tipo: 'pec', indirizzo: 'a@pec.it', proposto: false },
        ] },
        { anagrafica_id: 2, nome: 'Carlo Bruni', ruolo: 'versato_da', indirizzi: [{ tipo: 'email', indirizzo: 'c@x.it', proposto: false }] },
    ];

    test('di partenza sono spuntati quelli proposti dal server: primaria e secondaria della posizione, non la PEC, niente di chi ha versato', () => {
        expect(indirizziIniziali(destinatari)).toEqual({ '1:email': true, '1:email_secondaria': true, '1:pec': false, '2:email': false });
    });

    test('quello che parte: una voce per persona con i tipi spuntati; chi non ne ha non c\'è', () => {
        expect(destinatariScelti(destinatari, { '1:email': true, '1:email_secondaria': false, '1:pec': true, '2:email': false }))
            .toEqual([{ anagrafica_id: 1, tipi: ['email', 'pec'] }]);
    });
});

describe('i testi', () => {
    test('l\'anteprima conta persone, ricevute e indirizzi, al singolare e al plurale', () => {
        expect(testoAnteprima({ persone: 14, ricevute: 37, indirizzi: 20 })).toBe('14 persone, 37 ricevute, a 20 indirizzi');
        expect(testoAnteprima({ persone: 1, ricevute: 1, indirizzi: 1 })).toBe('1 persona, 1 ricevuta, a 1 indirizzo');
    });

    test('il riepilogo dice partite, in coda, non partite e senza indirizzo, solo quello che c\'è', () => {
        expect(testoRiepilogo({ inviate: 3, in_coda: 10, non_partite: 0, senza_indirizzo: 2 })).toBe('Partite 3, in coda 10, senza indirizzo 2.');
        expect(testoRiepilogo({ inviate: 0, in_coda: 0, non_partite: 1, senza_indirizzo: 0 })).toBe('Non partite 1.');
    });

    test('un\'emissione: stampata, inviata a chi, in coda, non partita con l\'errore', () => {
        expect(testoEmissione({ canale: 'stampa', stato: 'stampata', quando: '2026-11-05 10:30', destinatario: null, errore: null })).toBe('Stampata il 05/11/2026 alle 10:30');
        expect(testoEmissione({ canale: 'email', stato: 'inviata', quando: '2026-11-05 10:30', destinatario: 'a@x.it', errore: null })).toBe('Inviata il 05/11/2026 alle 10:30 a a@x.it');
        expect(testoEmissione({ canale: 'email', stato: 'in_coda', quando: '2026-11-05 10:30', destinatario: 'a@x.it', errore: null })).toBe('In coda dal 05/11/2026 alle 10:30, per a@x.it');
        expect(testoEmissione({ canale: 'email', stato: 'non_partita', quando: '2026-11-05 10:30', destinatario: 'a@x.it', errore: 'Casella piena' })).toBe('Non partita il 05/11/2026 alle 10:30, per a@x.it: Casella piena');
        expect(testoEmissione(null)).toBe('Mai emessa');
    });
});

describe('come è stato assolto il bollo', () => {
    test('il modo va solo con «assolta»; gli estremi solo con il modo, e in blocco solo quelli del modo virtuale', () => {
        expect(estremiDaMandare(['assolta'], 'contrassegno', ' 0117 ', false)).toEqual({ modo_bollo: 'contrassegno', estremi_bollo: '0117' });
        expect(estremiDaMandare(['assolta', 'non_dovuta'], 'contrassegno', '0117', true)).toEqual({ modo_bollo: 'contrassegno', estremi_bollo: null });
        expect(estremiDaMandare(['non_dovuta', 'assolta'], 'virtuale', 'autorizzazione n. 12', true)).toEqual({ modo_bollo: 'virtuale', estremi_bollo: 'autorizzazione n. 12' });
        expect(estremiDaMandare(['non_dovuta'], 'virtuale', 'autorizzazione n. 12', false)).toEqual({ modo_bollo: null, estremi_bollo: null });
        expect(estremiDaMandare(['assolta'], null, '0117', false)).toEqual({ modo_bollo: null, estremi_bollo: null });
    });
});

describe('il tetto dei messaggi senza il servizio della coda', () => {
    test('l\'anteprima lo dice solo quando lo si supera', () => {
        expect(testoTetto({ tetto_messaggi: 50, email: { messaggi: 52, messaggi_adesso: 50 } })).toBe(
            'Senza il servizio della coda partono al massimo 50 messaggi per volta: questa volta 50 su 52. Per gli altri, dopo l\'invio, premi «Invia le altre».',
        );
        // Il tetto taglia per persona intera: con tre indirizzi a testa ne partono 48, e il testo dice 48 (non il tetto).
        expect(testoTetto({ tetto_messaggi: 50, email: { messaggi: 51, messaggi_adesso: 48 } })).toContain('questa volta 48 su 51');
        expect(testoTetto({ tetto_messaggi: 50, email: { messaggi: 50, messaggi_adesso: 50 } })).toBeNull();
        expect(testoTetto({ tetto_messaggi: null, email: { messaggi: 500, messaggi_adesso: 500 } })).toBeNull();
    });

    test('dopo l\'invio dice perché ne sono partite al massimo 50 e come mandare il resto', () => {
        expect(testoRestantiBlocco({ tetto_messaggi: 50, restanti: { persone: [7], incassi: [9], ricevute: 1, messaggi: 2 } })).toBe(
            'Senza il servizio della coda il programma manda le email mentre la pagina aspetta, e oltre 50 messaggi per volta il server può interrompere l\'invio a metà. '
            + 'Restano 1 ricevuta di 1 persona (2 messaggi): premi «Invia le altre» per mandarle.',
        );
        expect(testoRestantiBlocco({ tetto_messaggi: null, restanti: null })).toBeNull();
        expect(testoRestantiRiprova({ tetto_messaggi: 50, restanti: { messaggi: 3 } })).toBe(
            'Senza il servizio della coda si riprovano al massimo 50 invii per volta: ne restano 3, premi di nuovo «Riprova le non partite».',
        );
        expect(testoRestantiRiprova({ tetto_messaggi: 50, restanti: null })).toBeNull();
    });
});

describe('chi resta senza un indirizzo fra quelli scelti', () => {
    test('dice quale altro indirizzo ha, per tipo', () => {
        expect(testoAltriIndirizzi({ email: 0, email_secondaria: 1, pec: 2 })).toBe('1 persona ha la secondaria, 2 persone hanno la PEC: non sono fra gli indirizzi scelti, spuntali per raggiungerle.');
        expect(testoAltriIndirizzi({ email: 0, email_secondaria: 0, pec: 0 })).toBeNull();
    });
});

describe('i testi che non prescrivono e non danno effetti di legge', () => {
    test('la nota: chi vuole riservare interessi o spese lo scrive nella nota, senza parole di effetto legale', () => {
        expect(NOTA_INTERESSI).toBe('Se la persona deve anche interessi o spese e vuoi tenerli fuori da questo versamento, scrivilo nella nota.');
        for (const parola of [/art\./i, /presum/i, /quietanza/i, /liberator/i, /a saldo/i, /esigibil/i, /valid/i, /dovut/i]) {
            expect(NOTA_INTERESSI).not.toMatch(parola);
        }
    });

    test('sul bollo una frase sola, generica: se e come la ricevuta lo sconta lo decidi tu con il tuo commercialista', () => {
        expect(FRASE_BOLLO).toBe('Se e come la ricevuta sconta il bollo lo decidi tu, con il tuo commercialista.');
        // Non afferma niente sulla legge: né la norma, né una soglia, né una cifra, né un «di norma».
        expect(FRASE_BOLLO).not.toMatch(/di norma|soglia|77,47|2,00|art\.|642|dovut|esent|obbligo|devi/i);
    });

    test('se una parte della ricevuta viene dal credito la frase lo dice, con la cifra', () => {
        expect(testoSogliaCredito(0)).toBeNull();
        expect(testoSogliaCredito(5000)).toBe('In questa ricevuta € 50,00 vengono dal credito: non contano per la soglia.');
        expect(testoSogliaCredito(1234567)).toBe('In questa ricevuta € 12.345,67 vengono dal credito: non contano per la soglia.');
    });

    test('la prima emissione è l\'originale, e la copia cambiata non manda a cercare un incasso registrato dopo', () => {
        expect(RIGA_ORIGINALE).toBe('La prima emissione è l\'originale: quelle dopo escono come «Copia», con la data dell\'originale.');
        expect(TESTO_COPIA_CAMBIATA).toContain('lo storno di un incasso registrato prima');
        expect(TESTO_COPIA_CAMBIATA).not.toContain('registrato dopo');
    });

    test('il bollo di un\'emissione in una frase', () => {
        expect(testoBollo('assolta', 'virtuale')).toBe('bollo assolto in modo virtuale');
        expect(testoBollo('assolta', 'contrassegno')).toBe('bollo assolto con il contrassegno');
        expect(testoBollo('assolta', null)).toBe('bollo assolto');
        expect(testoBollo('non_dovuta', null)).toBe('bollo non dovuto');
        expect(testoBollo('da_assolvere', null)).toBe('bollo da assolvere');
        expect(testoBollo(null, null)).toBe('');
    });

    test('un\'emissione dice anche il bollo scelto, e l\'errore è la frase che leggono tutti', () => {
        expect(testoEmissione({ canale: 'stampa', stato: 'stampata', quando: '2026-11-05 10:30', destinatario: null, errore: null, bollo: 'assolta', modo_bollo: 'virtuale' }))
            .toBe('Stampata il 05/11/2026 alle 10:30, bollo assolto in modo virtuale');
        expect(testoEmissione({ canale: 'email', stato: 'non_partita', quando: '2026-11-05 10:30', destinatario: 'a@x.it', bollo: 'non_dovuta', modo_bollo: null,
            errore: 'Il server di posta non ha accettato il messaggio (codice 535).', errore_completo: 'Failed to authenticate…' }))
            .toBe('Non partita il 05/11/2026 alle 10:30, per a@x.it, bollo non dovuto: Il server di posta non ha accettato il messaggio (codice 535).');
    });
});

describe('chi riceve che cosa nell\'anteprima del blocco', () => {
    const persone: PersonaAnteprima[] = [
        { id: 1, nome: 'Anna Bruni', indirizzi: [{ tipo: 'email', indirizzo: 'a@x.it' }, { tipo: 'email_secondaria', indirizzo: 'b@x.it' }],
            ricevute: [{ numero: 'INC-1', per_conto_di: null }, { numero: 'INC-2', per_conto_di: null }], messaggi: 2 },
        { id: 2, nome: 'Carlo Bruni', indirizzi: [{ tipo: 'email', indirizzo: 'c@x.it' }], ricevute: [{ numero: 'INC-3', per_conto_di: 'Anna Bruni' }], messaggi: 1 },
        { id: 3, nome: 'Dina Rossi', indirizzi: [{ tipo: 'pec', indirizzo: 'd@pec.it' }], ricevute: [{ numero: 'INC-4', per_conto_di: null }], messaggi: 1 },
    ];

    test('i conteggi sono quelli di chi resta nell\'invio', () => {
        expect(riepilogoDelleScelte(persone, [])).toEqual({ persone: 3, ricevute: 4, indirizzi: 4, messaggi: 4 });
        expect(riepilogoDelleScelte(persone, [2])).toEqual({ persone: 2, ricevute: 3, indirizzi: 3, messaggi: 3 });
        expect(riepilogoDelleScelte(persone, [1, 2, 3])).toEqual({ persone: 0, ricevute: 0, indirizzi: 0, messaggi: 0 });
    });

    test('l\'invio manda le persone rimaste solo se qualcuna è stata tolta', () => {
        expect(soloPersoneDaMandare(persone, [])).toBeUndefined();
        expect(soloPersoneDaMandare(persone, [2])).toEqual([1, 3]);
        expect(soloPersoneDaMandare(persone, [1, 2, 3])).toEqual([]);
    });
});

describe('«Invia le altre» tiene l\'elenco di chi è senza indirizzo del primo invio', () => {
    test('il secondo esito porta anche chi era rimasto da stampare, senza doppioni', () => {
        const prima = { senza_indirizzo: 2, senza_indirizzo_incassi: [7, 8], senza_indirizzo_persone: ['Ugo Neri', 'Dina Rossi'] };
        const dopo = { inviate: 3, in_coda: 0, non_partite: 0, senza_indirizzo: 1, senza_indirizzo_incassi: [8, 9], senza_indirizzo_persone: ['Dina Rossi', 'Eva Verdi'], restanti: null };

        expect(unisciEsiti(prima, dopo)).toEqual({
            inviate: 3, in_coda: 0, non_partite: 0, restanti: null,
            senza_indirizzo: 3, senza_indirizzo_incassi: [7, 8, 9], senza_indirizzo_persone: ['Ugo Neri', 'Dina Rossi', 'Eva Verdi'],
        });
        expect(unisciEsiti(null, dopo)).toEqual(dopo);
    });
});

describe('le conferme prima di mandare di nuovo', () => {
    test('«Invia di nuovo» dice a chi va e con quale bollo; «Riprova» quanti invii, con lo stesso bollo, e a quali indirizzi vanno', () => {
        expect(testoConfermaInviaDiNuovo({ destinatario: 'a@x.it', bollo: 'assolta', modo_bollo: 'virtuale' })).toBe('Mando di nuovo la ricevuta a a@x.it, con bollo assolto in modo virtuale.');
        expect(testoConfermaInviaDiNuovo({ destinatario: 'a@x.it', bollo: null, modo_bollo: null })).toBe('Mando di nuovo la ricevuta a a@x.it.');
        expect(testoConfermaRiprova(1)).toContain('Rimando 1 invio non partito, con lo stesso stato del bollo di prima.');
        expect(testoConfermaRiprova(4)).toContain('Rimando 4 invii non partiti, con lo stesso stato del bollo di prima.');
        // Non promette «gli stessi indirizzi»: dopo una correzione dell'anagrafica non sarebbe vero.
        expect(testoConfermaRiprova(2)).not.toContain('stessi indirizzi');
        expect(testoConfermaRiprova(2)).toContain('indirizzi che le persone hanno oggi');
    });

    test('«Invia di nuovo» dice l\'indirizzo a cui la ricevuta va adesso (manda_a), non quello della volta prima', () => {
        expect(testoConfermaInviaDiNuovo({ destinatario: 'vecchio@x.it', manda_a: 'nuovo@x.it', bollo: 'non_dovuta', modo_bollo: null }))
            .toBe('Mando di nuovo la ricevuta a nuovo@x.it, con bollo non dovuto.');
    });
});

describe('il contrassegno su una ricevuta per email', () => {
    test('si può scegliere: la frase dice che la marca non viaggia e che come assolvere il bollo lo decide l\'amministratore con il commercialista', () => {
        expect(NOTA_CONTRASSEGNO_EMAIL).toContain('Una ricevuta per email non porta la marca');
        expect(NOTA_CONTRASSEGNO_EMAIL).toContain('in modo virtuale o con il contrassegno sull\'esemplare che resta in studio');
        expect(NOTA_CONTRASSEGNO_EMAIL).toContain('lo decidi tu con il commercialista');
        // Non prescrive e non dà effetti di legge.
        expect(NOTA_CONTRASSEGNO_EMAIL).not.toMatch(/esigibile|a saldo|quietanza|valida|obbligo|devi/i);
        // E non dice che il bollo è dovuto.
        expect(NOTA_CONTRASSEGNO_EMAIL).not.toMatch(/di norma|sconta|dovut|77,47|2,00/i);
    });
});

describe('gli errori delle richieste', () => {
    test('un 429 si legge in italiano, mai come lo scrive il framework; il resto dà il messaggio del server o quello di riserva', () => {
        expect(frasePerErrore({ response: { status: 429, data: { message: 'Too Many Attempts.' } } }, 'Invio non riuscito.')).toBe(FRASE_LIMITE_ALTRO_INDIRIZZO);
        expect(FRASE_LIMITE_ALTRO_INDIRIZZO).not.toMatch(/too many/i);
        expect(frasePerErrore({ response: { status: 422, data: { message: 'Scegli a chi mandare la ricevuta.' } } }, 'Invio non riuscito.')).toBe('Scegli a chi mandare la ricevuta.');
        expect(frasePerErrore({ response: { status: 500, data: { message: '' } } }, 'Invio non riuscito.')).toBe('Invio non riuscito.');
        expect(frasePerErrore(new Error('rete'), 'Invio non riuscito.')).toBe('Invio non riuscito.');
    });

    test('la sessione scaduta si legge in italiano e dice che cosa fare: un 401 («Unauthenticated.») e un 419 («CSRF token mismatch.») non arrivano come li scrive il framework', async () => {
        const json = (status: number, message: string) => ({ response: { status, data: { message } } });
        const comeFile = (status: number, message: string) => ({ response: { status, data: new Blob([JSON.stringify({ message })], { type: 'application/json' }) } });

        for (const [stato, messaggio] of [[401, 'Unauthenticated.'], [419, 'CSRF token mismatch.']] as const) {
            expect(frasePerErrore(json(stato, messaggio), 'Invio non riuscito.')).toBe(FRASE_SESSIONE_SCADUTA);
            expect(await fraseDaErroreConFile(comeFile(stato, messaggio), 'Non riuscito.')).toBe(FRASE_SESSIONE_SCADUTA);
            // Anche con un testo semplice, come lo manda il server quando rifiuta una richiesta che aspettava un file.
            expect(await fraseDaErroreConFile({ response: { status: stato, data: new Blob([messaggio], { type: 'text/plain' }) } }, 'Non riuscito.')).toBe(FRASE_SESSIONE_SCADUTA);
        }
        expect(FRASE_SESSIONE_SCADUTA).toMatch(/sessione è scaduta.*ricarica la pagina.*accedi di nuovo/);
        expect(FRASE_SESSIONE_SCADUTA).not.toMatch(/unauthenticated|csrf/i);
        // Gli altri rifiuti restano come sono: la frase del server (in italiano), o quella di riserva.
        expect(frasePerErrore(json(422, 'Scegli a chi mandare la ricevuta.'), 'Invio non riuscito.')).toBe('Scegli a chi mandare la ricevuta.');
        expect(sessioneScaduta(json(401, ''))).toBe(true);
        expect(sessioneScaduta(json(419, ''))).toBe(true);
        expect(sessioneScaduta(json(422, ''))).toBe(false);
        expect(sessioneScaduta(new Error('rete'))).toBe(false);
        expect(sessioneScaduta(null)).toBe(false);
    });

    test('un 403 si legge in italiano e dice che non c\'è il permesso, mai come lo scrive il framework («This action is unauthorized.»); anche quando il corpo arriva come file', async () => {
        const json = (message: string) => ({ response: { status: 403, data: { message } } });
        const comeFile = (corpo: string, tipo: string) => ({ response: { status: 403, data: new Blob([corpo], { type: tipo }) } });

        for (const messaggio of ['This action is unauthorized.', 'User does not have the right permissions.', 'Forbidden', '']) {
            expect(frasePerErrore(json(messaggio), 'Invio non riuscito.')).toBe(FRASE_NON_PERMESSO);
            expect(await fraseDaErroreConFile(comeFile(JSON.stringify({ message: messaggio }), 'application/json'), 'Non riuscito.')).toBe(FRASE_NON_PERMESSO);
            expect(await fraseDaErroreConFile(comeFile(messaggio, 'text/plain'), 'Non riuscito.')).toBe(FRASE_NON_PERMESSO);
        }
        expect(FRASE_NON_PERMESSO).toBe('Non hai il permesso di fare questa operazione. Se ti serve, chiedilo a chi ti ha dato l\'accesso.');
        expect(FRASE_NON_PERMESSO).not.toMatch(/unauthorized|forbidden|permissions/i);
        // Non è la sessione scaduta: lì ricaricare la pagina non serve a niente.
        expect(sessioneScaduta(json(''))).toBe(false);
        expect(FRASE_NON_PERMESSO).not.toBe(FRASE_SESSIONE_SCADUTA);
    });

    test('dopo «Riprova» dice quanti invii a indirizzi scritti a mano il limite ha fermato; se nessuno, niente', () => {
        expect(testoLimiteRiprova({ limite_altro_indirizzo: 0 })).toBeNull();
        expect(testoLimiteRiprova({})).toBeNull();
        expect(testoLimiteRiprova({ limite_altro_indirizzo: 1 })).toContain('1 invio a un indirizzo scritto a mano è rimasto fermo per il limite di dieci al minuto');
        expect(testoLimiteRiprova({ limite_altro_indirizzo: 2 })).toContain('2 invii a indirizzi scritti a mano sono rimasti fermi per il limite di dieci al minuto');
        expect(testoLimiteRiprova({ limite_altro_indirizzo: 2 })).not.toContain('rimasti fermo');
    });
});

describe('l\'esito di un invio in blocco quando la finestra non c\'è più', () => {
    const base = { inviate: 0, in_coda: 3, non_partite: 0, senza_indirizzo: 0 };

    test('il riepilogo, poi gli errori dei singoli invii, poi quanto resta da mandare: una riga sola', () => {
        expect(testoEsitoInvio(base)).toBe('In coda 3.');
        expect(testoEsitoInvio({ ...base, errori: ['Ugo Neri: l\'indirizzo non è valido.', 'Carlo Bruni: la casella è piena.'] }))
            .toBe('In coda 3. Ugo Neri: l\'indirizzo non è valido. Carlo Bruni: la casella è piena.');
        expect(testoEsitoInvio({ ...base, inviate: 50, in_coda: 0, restanti: { ricevute: 2 } })).toBe('Partite 50. Restano 2 ricevute da mandare.');
        expect(testoEsitoInvio({ ...base, restanti: { ricevute: 1 } })).toBe('In coda 3. Resta 1 ricevuta da mandare.');
        expect(testoEsitoInvio({ ...base, restanti: null, errori: [] })).toBe('In coda 3.');
        expect(testoEsitoInvio({ inviate: 0, in_coda: 0, non_partite: 0, senza_indirizzo: 0 })).toBe('Niente da inviare.');
    });
});

describe('un indirizzo si sceglie per chiave', () => {
    const d = { anagrafica_id: 1, nome: 'Anna', ruolo: 'posizione', indirizzi: [
        { tipo: 'email', indirizzo: 'vecchio@x.it', proposto: true, chiave: 'email|vecchio@x.it' },
        { tipo: 'email', indirizzo: 'nuovo@x.it', proposto: true, chiave: 'email|nuovo@x.it' },
    ] };

    test('due indirizzi dello stesso tipo hanno due caselle, e quello che parte porta le chiavi spuntate', () => {
        expect(d.indirizzi.map(chiaveIndirizzo)).toEqual(['email|vecchio@x.it', 'email|nuovo@x.it']);
        const caselle = indirizziIniziali([d]);
        expect(caselle).toEqual({ '1:email|vecchio@x.it': true, '1:email|nuovo@x.it': true });
        expect(destinatariScelti([d], { ...caselle, '1:email|vecchio@x.it': false })).toEqual([{ anagrafica_id: 1, tipi: ['email|nuovo@x.it'] }]);
    });

    test('senza la chiave (gli indirizzi di oggi) vale il tipo', () => {
        expect(chiaveIndirizzo({ tipo: 'email_secondaria', indirizzo: 'a@x.it', proposto: true })).toBe('email_secondaria');
    });
});

describe('«Non serve comunicarla» nell\'elenco delle emissioni', () => {
    test('la riga del registro si legge come una scelta dell\'amministratore, con il giorno', () => {
        expect(testoEmissione({ canale: 'nessuno', stato: 'non_da_comunicare', quando: '2026-11-05 10:30', destinatario: null, errore: null }))
            .toBe('Segnata il 05/11/2026 alle 10:30: non serve comunicare l\'annullamento alla persona');
    });
});

describe('gli avvisi della pagina delle ricevute', () => {
    test('le mai emesse di incassi registrati da poco con un versamento più vecchio del periodo', () => {
        expect(testoRegistratiDiRecente({ numero: 1, dal: '2026-08-10' })).toBe('1 ricevuta mai emessa è di un incasso registrato da poco con un versamento del 10/08/2026, più vecchio del periodo.');
        expect(testoRegistratiDiRecente({ numero: 3, dal: '2026-08-10' })).toBe('3 ricevute mai emesse sono di incassi registrati da poco con un versamento più vecchio del periodo, il primo del 10/08/2026.');
    });

    test('le causali delle selezionate: quante, e un testo libero che può portare note interne', () => {
        expect(testoCausali({ con_causale: 0, causali: [] })).toBeNull();
        expect(testoCausali({ con_causale: 1, causali: [{ testo: 'Bonifico', quante: 1 }] })).toBe('1 delle selezionate ha una causale.');
        expect(testoCausali({ con_causale: 5, causali: [{ testo: 'Bonifico', quante: 5 }] })).toBe('5 delle selezionate hanno una causale.');
    });

    test('più ricevute del tetto si stampano una parte per volta', () => {
        const parti = [[1, 2, 3], [4, 5], [6]];
        expect(etichettaParteDelPdf(parti, 0)).toBe('Stampa le prime 3');
        expect(etichettaParteDelPdf(parti, 1)).toBe('Stampa le altre 2');
        expect(etichettaParteDelPdf(parti, 2)).toBe('Stampa l\'ultima');
    });
});

describe('a che cosa va il versamento', () => {
    test('la frase sotto la tabella è corta: che cosa fare se non è giusto, senza parole di effetto legale', () => {
        expect(TESTO_IMPUTAZIONE).toBe('Se non è giusto, storna l\'incasso e registralo di nuovo prima di emettere.');
        for (const parola of [/art\./i, /presum/i, /quietanza/i, /liberator/i, /a saldo/i, /esigibil/i]) {
            expect(TESTO_IMPUTAZIONE).not.toMatch(parola);
        }
    });

    test('la parte in più: quanto riduce una rata da pagare e quanto resta a credito', () => {
        expect(testoParteInPiu({ importo: 5000, persona: 'Carlo Bruni', rata: 'Rata 3, Preventivo 2026', riduce: 5000, a_credito: 0 }))
            .toBe('€ 50,00 riducono la Rata 3, Preventivo 2026 di Carlo Bruni');
        expect(testoParteInPiu({ importo: 8000, persona: 'Carlo Bruni', rata: 'Rata 3', riduce: 5000, a_credito: 3000 }))
            .toBe('€ 50,00 riducono la Rata 3 di Carlo Bruni; € 30,00 restano a credito di Carlo Bruni, sulla Rata 3');
        expect(testoParteInPiu({ importo: 2000, persona: 'Anna Bruni', rata: null, riduce: 0, a_credito: 2000 })).toBe('€ 20,00 restano a credito di Anna Bruni');
    });
});

describe('la tabella compatta delle quote', () => {
    const q = (rata: string, piano: string | null, gestione: string | null, imputato: number, dopo: number, dal_credito = 0) => ({
        rata, piano, gestione, unita: 'Int. 4', scadenza: '2026-01-31', importo: imputato + dopo, imputato, dal_credito, dopo,
    });

    test('un gruppo per piano e gestione, nell\'ordine in cui la ricevuta porta le quote; dentro il gruppo le sue righe', () => {
        const quote = [q('Rata 1', 'Preventivo 2026', 'Ordinaria 2026', 15000, 0), q('Rata 1', 'Conguaglio 2025', 'Ordinaria 2026', 8000, 0), q('Rata 2', 'Preventivo 2026', 'Ordinaria 2026', 8050, 4000)];

        const gruppi = gruppiDelleQuote(quote);

        expect(gruppi.map((g) => [g.titolo, g.quote.map((x) => x.rata)])).toEqual([
            ['Preventivo 2026 — Ordinaria 2026', ['Rata 1', 'Rata 2']],
            ['Conguaglio 2025 — Ordinaria 2026', ['Rata 1']],
        ]);
        // Senza piano e senza gestione il gruppo non ha un titolo.
        expect(gruppiDelleQuote([q('Rata 1', null, null, 100, 0)]).map((g) => g.titolo)).toEqual(['']);
        expect(gruppiDelleQuote([q('Rata 1', null, 'Ordinaria 2026', 100, 0)]).map((g) => g.titolo)).toEqual(['Ordinaria 2026']);
    });

    test('il totale in fondo: l\'imputato, il resto e la parte dal credito, al centesimo', () => {
        expect(totaliDelleQuote([q('Rata 1', 'P', 'G', 10000, 0), q('Rata 2', 'P', 'G', 8050, 4000, 3000)])).toEqual({ imputato: 18050, dopo: 4000, dal_credito: 3000 });
        expect(totaliDelleQuote([])).toEqual({ imputato: 0, dopo: 0, dal_credito: 0 });
    });
});

describe('«Prepara l\'email»', () => {
    const bozza = {
        oggetto: 'Condominio Via dei Pini — Ricevuta n. INC-2026-00005 del 01/02/2026', saluto: 'Buongiorno,',
        saluti: { 1: 'Gentile Anna Bruni,', 7: 'Gentile Carlo Bruni,' } as Record<string, string>,
        righe: ['In allegato la ricevuta del versamento di € 150,00 del 01/02/2026, numero INC-2026-00005.'], chiusura: 'Cordiali saluti, Condominio Via dei Pini',
    };

    test('il testo: il saluto della persona a cui va, le righe del messaggio, la chiusura; senza una persona il saluto generico', () => {
        expect(testoDellaBozza(bozza, 1)).toBe('Gentile Anna Bruni,\r\n\r\nIn allegato la ricevuta del versamento di € 150,00 del 01/02/2026, numero INC-2026-00005.\r\n\r\nCordiali saluti, Condominio Via dei Pini');
        expect(testoDellaBozza(bozza, null).startsWith('Buongiorno,\r\n')).toBe(true);
        expect(testoDellaBozza(bozza, 99).startsWith('Buongiorno,\r\n')).toBe(true);
    });

    test('l\'indirizzo mailto: i destinatari separati da virgole, l\'oggetto e il testo codificati; la chiocciola resta', () => {
        const url = indirizzoMailto(['anna@esempio.it', 'anna.casa@esempio.it'], 'Ricevuta n. 5 — 01/02', 'Gentile Anna,\r\n\r\nIn allegato & altro');

        expect(url).toBe('mailto:anna@esempio.it,anna.casa@esempio.it?subject=Ricevuta%20n.%205%20%E2%80%94%2001%2F02&body=Gentile%20Anna%2C%0D%0A%0D%0AIn%20allegato%20%26%20altro');
        expect(indirizzoMailto([], 'Oggetto', 'Testo')).toBe('mailto:?subject=Oggetto&body=Testo');
    });

    test('il nome del file dalla risposta del server, o quello di riserva', () => {
        expect(nomeDalContentDisposition('inline; filename="ricevuta-condominio-via-dei-pini-inc-2026-00005.pdf"', 'ricevuta.pdf')).toBe('ricevuta-condominio-via-dei-pini-inc-2026-00005.pdf');
        expect(nomeDalContentDisposition('attachment; filename=ricevute.zip', 'x.zip')).toBe('ricevute.zip');
        expect(nomeDalContentDisposition(undefined, 'ricevuta.pdf')).toBe('ricevuta.pdf');
        expect(nomeDalContentDisposition('inline', 'ricevuta.pdf')).toBe('ricevuta.pdf');
    });

    const pdf = new Blob(['%PDF-1.4'], { type: 'application/pdf' });
    const email = { a: ['anna@esempio.it'], oggetto: 'Ricevuta', testo: 'Gentile Anna,' };
    const ambiente = (navigatore: Record<string, unknown>) => {
        const fatti: string[] = [];

        return {
            fatti,
            ambiente: {
                navigatore: navigatore as unknown as Navigator,
                scarica: (_b: Blob, nome: string) => { fatti.push(`scarica ${nome}`); },
                apri: (url: string) => { fatti.push(`apri ${url.split('?')[0]}`); },
            },
        };
    };

    test('dove il browser condivide i file, apre la condivisione con il PDF allegato, l\'oggetto e il testo; non scarica e non apre l\'email', async () => {
        const condivisi: Array<{ files: File[]; title: string; text: string }> = [];
        const { fatti, ambiente: a } = ambiente({ canShare: (d: { files: File[] }) => d.files[0].type === 'application/pdf', share: async (d: { files: File[]; title: string; text: string }) => { condivisi.push(d); } });

        expect(await preparaEmail(pdf, 'ricevuta-5.pdf', email, a)).toBe('condivisa');
        expect(condivisi).toHaveLength(1);
        expect(condivisi[0].files[0].name).toBe('ricevuta-5.pdf');
        expect([condivisi[0].title, condivisi[0].text]).toEqual(['Ricevuta', 'Gentile Anna,']);
        expect(fatti).toEqual([]);
    });

    test('senza la condivisione dei file scarica il PDF e apre l\'email con destinatario, oggetto e testo', async () => {
        const { fatti, ambiente: a } = ambiente({});

        expect(await preparaEmail(pdf, 'ricevuta-5.pdf', email, a)).toBe('scaricata');
        expect(fatti).toEqual(['scarica ricevuta-5.pdf', 'apri mailto:anna@esempio.it']);
    });

    test('se il browser rifiuta la condivisione (il gesto è scaduto mentre il PDF si preparava) passa all\'email; se l\'amministratore la chiude, niente', async () => {
        const rifiuta = ambiente({ canShare: () => true, share: async () => { throw Object.assign(new Error('no'), { name: 'NotAllowedError' }); } });
        expect(await preparaEmail(pdf, 'r.pdf', email, rifiuta.ambiente)).toBe('scaricata');
        expect(rifiuta.fatti).toEqual(['scarica r.pdf', 'apri mailto:anna@esempio.it']);

        const chiude = ambiente({ canShare: () => true, share: async () => { throw Object.assign(new Error('chiusa'), { name: 'AbortError' }); } });
        expect(await preparaEmail(pdf, 'r.pdf', email, chiude.ambiente)).toBe('annullata');
        expect(chiude.fatti).toEqual([]);

        // canShare dice di no per questo file: email.
        const no = ambiente({ canShare: () => false, share: async () => {} });
        expect(await preparaEmail(pdf, 'r.pdf', email, no.ambiente)).toBe('scaricata');
    });

    test('l\'errore di una richiesta che aspettava un file: il testo del server, che arriva come file anch\'esso', async () => {
        const errore = { response: { status: 422, data: new Blob(['Ricevuta non stampata. Scegli lo stato dell\'imposta di bollo.'], { type: 'text/plain' }) } };

        expect(await fraseDaErroreConFile(errore, 'Non riuscito.')).toBe('Ricevuta non stampata. Scegli lo stato dell\'imposta di bollo.');
        expect(await fraseDaErroreConFile({ response: { status: 404, data: new Blob([''], { type: 'text/plain' }) } }, 'Non riuscito.')).toBe('Non riuscito.');
        expect(await fraseDaErroreConFile(new Error('rete'), 'Non riuscito.')).toBe('Non riuscito.');
    });
});

describe('la conferma di «Non serve comunicarla»', () => {
    test('dice il numero dell\'incasso e che la segnatura resta', () => {
        expect(testoConfermaNonServe('INC-2026-00004')).toBe('L\'incasso n. INC-2026-00004 è annullato: segno che alla persona non serve comunicarlo. '
            + 'L\'avviso si toglie, e la segnatura resta fra le emissioni dell\'incasso: non si annulla.');
    });
});

describe('le scelte del bollo', () => {
    test('quattro pulsanti, nell\'ordine in cui compaiono, con i valori che il server conosce: lo stato e, per le due «Assolta», il modo', () => {
        expect(SCELTE_BOLLO.map((s) => [s.etichetta, s.stato, s.modo])).toEqual([
            ['Non dovuta', 'non_dovuta', null],
            ['Da assolvere', 'da_assolvere', null],
            ['Assolta: contrassegno', 'assolta', 'contrassegno'],
            ['Assolta: virtuale', 'assolta', 'virtuale'],
        ]);
        // Un identificatore per pulsante, tutti diversi.
        expect(new Set(SCELTE_BOLLO.map((s) => s.id)).size).toBe(4);
    });

    test('un solo pulsante acceso per uno stato e un modo: «Assolta» ne vuole uno dei due; gli altri due stati ignorano il modo ricordato', () => {
        expect(idSceltaBollo('non_dovuta', null)).toBe('non_dovuta');
        expect(idSceltaBollo('non_dovuta', 'virtuale')).toBe('non_dovuta');
        expect(idSceltaBollo('da_assolvere', 'contrassegno')).toBe('da_assolvere');
        expect(idSceltaBollo('assolta', 'contrassegno')).toBe('assolta_contrassegno');
        expect(idSceltaBollo('assolta', 'virtuale')).toBe('assolta_virtuale');
        // Nessuna scelta, o «Assolta» senza un modo noto: nessun pulsante acceso.
        expect(idSceltaBollo(null, null)).toBeUndefined();
        expect(idSceltaBollo('assolta', null)).toBeUndefined();
        expect(idSceltaBollo('assolta', 'altro')).toBeUndefined();
    });

    test('«Assolta» senza il modo non conta come scelta fatta; gli altri stati restano come sono', () => {
        expect(statoConModo('assolta', 'virtuale')).toBe('assolta');
        expect(statoConModo('assolta', null)).toBeNull();
        expect(statoConModo('non_dovuta', null)).toBe('non_dovuta');
        expect(statoConModo('da_assolvere', 'virtuale')).toBe('da_assolvere');
        expect(statoConModo(null, 'virtuale')).toBeNull();
    });

    test('il segnaposto degli estremi segue il modo; in blocco una riga dice che il modo è uno solo', () => {
        expect(SEGNAPOSTO_ESTREMI).toEqual({ contrassegno: 'contrassegno n. …', virtuale: 'autorizzazione n. … del …' });
        expect(NOTA_MODO_UNICO).toBe('Con «Assolta» in tutti e due i gruppi il modo è uno solo, per tutte le ricevute.');
    });
});

describe('il PDF in una scheda nuova', () => {
    const pdf = { data: new Blob(['%PDF-1.4'], { type: 'application/pdf' }), headers: { 'content-disposition': 'inline; filename="ricevuta-condominio-via-dei-pini-inc-2026-00005.pdf"' } };
    const schedaFinta = () => ({ closed: false, location: { href: '' }, close() { this.closed = true; } });
    /** Il browser finto: che cosa succede e in che ordine. */
    function ambiente(scheda: ReturnType<typeof schedaFinta> | null) {
        const fatti: string[] = [];
        const a: AmbientePdf = {
            apriScheda: () => { fatti.push('scheda'); return scheda as unknown as Window | null; },
            indirizzoDelFile: (f) => { fatti.push(`indirizzo ${f.type}`); return 'blob:pdf-1'; },
            liberaAllaChiusura: (_s, url) => { fatti.push(`libera ${url}`); },
            scarica: (_f, nome) => { fatti.push(`scarica ${nome}`); },
        };

        return { fatti, ambiente: a };
    }
    /** La richiesta del PDF, che risponde quando lo dice il test. */
    function richiesta(fatti: string[]) {
        let consegna: (v: typeof pdf) => void = () => {};
        let rifiuta: (e: unknown) => void = () => {};
        const chiedi = () => { fatti.push('richiesta'); return new Promise<typeof pdf>((ok, no) => { consegna = ok; rifiuta = no; }); };

        return { chiedi, arriva: (r: typeof pdf) => consegna(r), fallisce: (e: unknown) => rifiuta(e) };
    }

    test('la scheda si apre subito, nella chiamata: prima che la richiesta parta e prima che risponda; arrivato, il PDF va nella scheda', async () => {
        const scheda = schedaFinta();
        const { fatti, ambiente: a } = ambiente(scheda);
        const r = richiesta(fatti);

        const esito = apriPdfInUnaScheda(r.chiedi, 'ricevuta.pdf', a);
        // Ancora nessuna attesa: la scheda è aperta e la richiesta è partita, in quest'ordine.
        expect(fatti).toEqual(['scheda', 'richiesta']);
        r.arriva(pdf);

        expect(await esito).toEqual({ modo: 'scheda', nome: 'ricevuta-condominio-via-dei-pini-inc-2026-00005.pdf' });
        expect(scheda.location.href).toBe('blob:pdf-1');
        expect(fatti).toEqual(['scheda', 'richiesta', 'indirizzo application/pdf', 'libera blob:pdf-1']);
    });

    test('senza la scheda (il browser l\'ha bloccata) il PDF si scarica con il nome della risposta, o con quello di riserva', async () => {
        const { fatti, ambiente: a } = ambiente(null);

        expect(await apriPdfInUnaScheda(async () => pdf, 'ricevuta.pdf', a)).toEqual({ modo: 'scaricato', nome: 'ricevuta-condominio-via-dei-pini-inc-2026-00005.pdf' });
        expect(fatti).toEqual(['scheda', 'scarica ricevuta-condominio-via-dei-pini-inc-2026-00005.pdf']);

        const senzaNome = ambiente(null);
        expect(await apriPdfInUnaScheda(async () => ({ data: pdf.data }), 'ricevute.pdf', senzaNome.ambiente)).toEqual({ modo: 'scaricato', nome: 'ricevute.pdf' });
    });

    test('la scheda chiusa da chi lavora prima che il PDF arrivasse: il PDF si scarica, la scheda chiusa non si tocca', async () => {
        const scheda = schedaFinta();
        const { fatti, ambiente: a } = ambiente(scheda);
        const r = richiesta(fatti);

        const esito = apriPdfInUnaScheda(r.chiedi, 'ricevuta.pdf', a);
        scheda.closed = true;
        r.arriva(pdf);

        expect((await esito).modo).toBe('scaricato');
        expect(scheda.location.href).toBe('');
        expect(fatti.at(-1)).toBe('scarica ricevuta-condominio-via-dei-pini-inc-2026-00005.pdf');
    });

    describe('la scheda chiusa a mano prima che il PDF arrivi, quando il file non serve (l\'anteprima)', () => {
        test('niente si scarica e niente si apre: chi chiama sa che la scheda è stata chiusa; senza la richiesta di non scaricare, si scarica come prima', async () => {
            const scheda = schedaFinta();
            const { fatti, ambiente: a } = ambiente(scheda);
            const r = richiesta(fatti);

            const esito = apriPdfInUnaScheda(r.chiedi, 'anteprima.pdf', a, undefined, { scaricaSeChiusa: false });
            scheda.closed = true;
            r.arriva(pdf);

            expect(await esito).toEqual({ modo: 'chiusa', nome: 'ricevuta-condominio-via-dei-pini-inc-2026-00005.pdf' });
            expect(scheda.location.href).toBe('');
            expect(fatti).toEqual(['scheda', 'richiesta']);

            // La stampa (il registro è già scritto) tiene il file: è la scelta di prima, ed è anche quella di chi non dice niente.
            for (const opzioni of [{ scaricaSeChiusa: true }, undefined]) {
                const altra = schedaFinta();
                const prova = ambiente(altra);
                const s2 = richiesta(prova.fatti);
                const e2 = apriPdfInUnaScheda(s2.chiedi, 'ricevuta.pdf', prova.ambiente, undefined, opzioni);
                altra.closed = true;
                s2.arriva(pdf);
                expect((await e2).modo).toBe('scaricato');
                expect(prova.fatti.at(-1)).toBe('scarica ricevuta-condominio-via-dei-pini-inc-2026-00005.pdf');
            }
        });

        test('se la scheda non si è mai aperta (il browser l\'ha bloccata) il PDF si scarica comunque: chi lavora non l\'ha chiusa', async () => {
            const { fatti, ambiente: a } = ambiente(null);

            expect((await apriPdfInUnaScheda(async () => pdf, 'anteprima.pdf', a, undefined, { scaricaSeChiusa: false })).modo).toBe('scaricato');
            expect(fatti.at(-1)).toBe('scarica ricevuta-condominio-via-dei-pini-inc-2026-00005.pdf');
        });

        test('la scheda ancora aperta riceve il PDF, anche per l\'anteprima', async () => {
            const scheda = schedaFinta();
            const { ambiente: a } = ambiente(scheda);

            expect((await apriPdfInUnaScheda(async () => pdf, 'anteprima.pdf', a, undefined, { scaricaSeChiusa: false })).modo).toBe('scheda');
            expect(scheda.location.href).toBe('blob:pdf-1');
        });

        test('la frase dice che la scheda è stata chiusa e che l\'anteprima non si è aperta; non dice che il PDF si scarica', () => {
            expect(testoDelPdf({ modo: 'chiusa', nome: 'a.pdf' }, { stampa: false, una: true })).toBe('Hai chiuso la scheda: l\'anteprima non si è aperta.');
            expect(testoDelPdf({ modo: 'chiusa', nome: 'a.pdf' }, { stampa: false, una: false })).toBe('Hai chiuso la scheda: l\'anteprima non si è aperta.');
        });
    });

    test('se la richiesta fallisce la scheda si chiude e l\'errore torna a chi chiama, che scrive la frase', async () => {
        const scheda = schedaFinta();
        const { fatti, ambiente: a } = ambiente(scheda);
        const r = richiesta(fatti);
        const errore = { response: { status: 422, data: new Blob(['Ricevuta non stampata.'], { type: 'text/plain' }) } };

        const esito = apriPdfInUnaScheda(r.chiedi, 'ricevuta.pdf', a);
        r.fallisce(errore);

        await expect(esito).rejects.toBe(errore);
        expect(scheda.closed).toBe(true);
        expect(fatti).toEqual(['scheda', 'richiesta']);
    });

    describe('una risposta di buon esito che non è un PDF', () => {
        const pagina = (tipo: string, intestazioni: Record<string, unknown> = {}) => ({ data: new Blob(['<html>Accedi</html>'], { type: tipo }), headers: intestazioni });

        test.each([
            ['una pagina HTML (la pagina di accesso, dopo un rimando seguito)', pagina('text/html')],
            ['una pagina HTML, detto dall\'intestazione', { data: new Blob(['<!DOCTYPE html><title>Accedi</title>']), headers: { 'content-type': 'text/html; charset=UTF-8' } }],
            ['una pagina HTML con il tipo generico di un file', { data: new Blob(['\n<!DOCTYPE html><title>Accedi</title>']), headers: { 'content-type': 'application/octet-stream' } }],
            ['un JSON', pagina('application/json')],
            ['un file senza tipo', pagina('')],
        ])('%s: la scheda si chiude, il file non si apre e non si scarica, e chi chiama riceve una frase in italiano', async (_caso, risposta) => {
            for (const aperta of [true, false]) {
                const scheda = schedaFinta();
                const { fatti, ambiente: a } = ambiente(aperta ? scheda : null);

                const errore = await apriPdfInUnaScheda(async () => risposta, 'ricevuta.pdf', a).then(() => null, (e) => e);

                expect(errore).toBeInstanceOf(RispostaNonValida);
                expect(await fraseDaErroreConFile(errore, 'Non riuscito.')).toBe(FRASE_NON_E_UN_PDF);
                // Né un indirizzo del file, né un file scaricato: la scheda (se c'era) è chiusa e vuota.
                expect(fatti).toEqual(['scheda']);
                expect(scheda.closed).toBe(aperta);
                expect(scheda.location.href).toBe('');
            }
        });

        test('il PDF vero passa, anche con un tipo scritto in un altro modo o con i parametri', async () => {
            for (const tipo of ['application/pdf', 'Application/PDF', 'application/pdf; charset=binary']) {
                const { fatti, ambiente: a } = ambiente(schedaFinta());

                expect((await apriPdfInUnaScheda(async () => ({ data: new Blob(['%PDF-1.4']), headers: { 'content-type': tipo } }), 'ricevuta.pdf', a)).modo).toBe('scheda');
                expect(fatti).toContain('indirizzo application/pdf');
            }
        });

        test.each([
            ['application/octet-stream'],
            ['binary/octet-stream'],
            ['application/x-download'],
            [''],
            ['text/html'],
            ['application/json'],
        ])('un PDF vero arrivato con il tipo «%s» (lo cambia un proxy, un antivirus) si apre come PDF: fa fede il file, che comincia con «%PDF»', async (tipo) => {
            for (const aperta of [true, false]) {
                const scheda = schedaFinta();
                const { fatti, ambiente: a } = ambiente(aperta ? scheda : null);

                const esito = await apriPdfInUnaScheda(async () => ({ data: new Blob(['%PDF-1.7\n%âãÏÓ'], { type: tipo }), headers: tipo ? { 'content-type': tipo } : {} }), 'ricevuta.pdf', a);

                expect(esito.modo).toBe(aperta ? 'scheda' : 'scaricato');
                // Il file porta il tipo PDF con sé, qualunque cosa abbia detto l\'intestazione.
                expect(fatti).toContain(aperta ? 'indirizzo application/pdf' : 'scarica ricevuta.pdf');
                expect(scheda.closed).toBe(false);
            }
        });

        test.each([
            ['un file che non è un PDF né una pagina (il tipo è generico)', { data: new Blob(['ciao, non sono un PDF'], { type: 'application/octet-stream' }), headers: { 'content-type': 'application/octet-stream' } }, FRASE_FILE_NON_PDF],
            ['un JSON', { data: new Blob(['{"message":"No"}'], { type: 'application/json' }), headers: {} }, FRASE_FILE_NON_PDF],
            ['un file vuoto', { data: new Blob([], { type: 'application/octet-stream' }), headers: {} }, FRASE_FILE_NON_PDF],
            ['un archivio al posto del PDF', { data: new Blob(['PK\u0003\u0004'], { type: 'application/zip' }), headers: {} }, FRASE_FILE_NON_PDF],
            ['quasi un PDF (manca un carattere)', { data: new Blob(['%PD-1.4'], { type: 'application/octet-stream' }), headers: {} }, FRASE_FILE_NON_PDF],
            ['una pagina, preceduta da un segno di ordine dei byte (che la lettura toglie) e da spazi', { data: new Blob(['\uFEFF  <html>Accedi</html>'], { type: 'application/octet-stream' }), headers: {} }, FRASE_NON_E_UN_PDF],
            ['una pagina dichiarata tale dall\'intestazione, anche se non comincia con «<»', { data: new Blob(['Accedi per continuare'], { type: 'text/html' }), headers: {} }, FRASE_NON_E_UN_PDF],
        ])('%s: non si apre e non si scarica, e la frase non dice che la sessione è scaduta quando non c\'è motivo', async (_caso, risposta, frase) => {
            const scheda = schedaFinta();
            const { fatti, ambiente: a } = ambiente(scheda);

            const errore = await apriPdfInUnaScheda(async () => risposta, 'ricevuta.pdf', a).then(() => null, (e) => e);

            expect(errore).toBeInstanceOf(RispostaNonValida);
            expect(await fraseDaErroreConFile(errore, 'Non riuscito.')).toBe(frase);
            expect(fatti).toEqual(['scheda']);
            expect(scheda.closed).toBe(true);
        });

        test('l\'intestazione giusta basta: il file non si legge', async () => {
            const letto = vi.spyOn(Blob.prototype, 'slice');

            await expect(verificaIlFile({ data: new Blob(['non un PDF'], { type: 'application/pdf' }), headers: { 'content-type': 'application/pdf' } }, 'pdf')).resolves.toBeUndefined();
            expect(letto).not.toHaveBeenCalled();
            letto.mockRestore();
        });

        test('l\'archivio .zip: il tipo giusto passa; con un altro tipo fa fede l\'inizio del file («PK» e due byte); un PDF, una pagina o un file qualunque no', async () => {
            const zip = 'PK\u0003\u0004\u0014\u0000';
            await expect(verificaIlFile({ data: new Blob(['x'], { type: 'application/zip' }) }, 'zip')).resolves.toBeUndefined();
            await expect(verificaIlFile({ data: new Blob(['x']), headers: { 'content-type': 'application/x-zip-compressed' } }, 'zip')).resolves.toBeUndefined();
            await expect(verificaIlFile({ data: new Blob([zip]), headers: { 'content-type': 'application/octet-stream' } }, 'zip')).resolves.toBeUndefined();
            await expect(verificaIlFile({ data: new Blob([zip]), headers: {} }, 'zip')).resolves.toBeUndefined();
            await expect(verificaIlFile({ data: new Blob(['<html>Accedi</html>'], { type: 'text/html' }) }, 'zip')).rejects.toMatchObject({ frase: FRASE_NON_E_UN_ARCHIVIO });
            await expect(verificaIlFile({ data: new Blob(['PK senza i due byte'], { type: 'application/octet-stream' }) }, 'zip')).rejects.toMatchObject({ frase: FRASE_FILE_NON_ARCHIVIO });
            await expect(verificaIlFile({ data: pdf.data, headers: { 'content-type': 'application/octet-stream' } }, 'zip')).rejects.toMatchObject({ frase: FRASE_FILE_NON_ARCHIVIO });
            // Un archivio non è un PDF, anche se l\'intestazione è generica.
            await expect(verificaIlFile({ data: new Blob([zip]), headers: { 'content-type': 'application/octet-stream' } }, 'pdf')).rejects.toBeInstanceOf(RispostaNonValida);
        });

        test('il file può arrivare anche in un altro contenitore (un testo, un ArrayBuffer): si guarda lo stesso come comincia', async () => {
            await expect(verificaIlFile({ data: '%PDF-1.4 ...', headers: { 'content-type': 'application/octet-stream' } }, 'pdf')).resolves.toBeUndefined();
            await expect(verificaIlFile({ data: new TextEncoder().encode('%PDF-1.4').buffer, headers: { 'content-type': 'application/octet-stream' } }, 'pdf')).resolves.toBeUndefined();
            await expect(verificaIlFile({ data: undefined, headers: { 'content-type': 'application/octet-stream' } }, 'pdf')).rejects.toBeInstanceOf(RispostaNonValida);
        });

        test('le frasi: la pagina al posto del file dice che forse la sessione è scaduta e che cosa fare; un altro file dice solo che non è quello chiesto e di riprovare', () => {
            expect(FRASE_NON_E_UN_PDF).toBe('Non è arrivato un PDF: forse la sessione è scaduta. Ricarica la pagina e, se serve, accedi di nuovo.');
            expect(FRASE_NON_E_UN_ARCHIVIO).toBe('Non è arrivato l\'archivio: forse la sessione è scaduta. Ricarica la pagina e, se serve, accedi di nuovo.');
            expect(FRASE_FILE_NON_PDF).toBe('Il file arrivato non è un PDF. Riprova; se succede ancora, ricarica la pagina.');
            expect(FRASE_FILE_NON_ARCHIVIO).toBe('Il file arrivato non è un archivio .zip. Riprova; se succede ancora, ricarica la pagina.');
            for (const frase of [FRASE_FILE_NON_PDF, FRASE_FILE_NON_ARCHIVIO]) {
                expect(frase).not.toMatch(/sessione|accedi/i);
            }
        });

        test('la sola intestazione (chi non può leggere il file): un tipo che è chiaramente un\'altra cosa non passa, uno generico o assente sì, perché senza leggere il file non si può dire di più', () => {
            const prova = (tipo: string | null, atteso: 'pdf' | 'zip' = 'pdf') => verificaTipoRisposta({ data: new Blob(['x']), headers: tipo === null ? {} : { 'content-type': tipo } }, atteso);

            expect(() => prova('application/pdf')).not.toThrow();
            expect(() => prova('application/octet-stream')).not.toThrow();
            expect(() => prova('binary/octet-stream')).not.toThrow();
            expect(() => prova(null)).not.toThrow();
            expect(() => prova('text/html')).toThrow(RispostaNonValida);
            expect(() => prova('application/json')).toThrow(RispostaNonValida);
            expect(() => prova('application/zip')).toThrow(RispostaNonValida);
            expect(() => prova('application/pdf', 'zip')).toThrow(RispostaNonValida);
            // La frase: la pagina dice della sessione; un altro tipo no.
            expect(() => prova('text/html')).toThrow(FRASE_NON_E_UN_PDF);
            expect(() => prova('application/json')).toThrow(FRASE_FILE_NON_PDF);
            expect(() => prova('application/json', 'zip')).toThrow(FRASE_FILE_NON_ARCHIVIO);
        });

        test('l\'archivio .zip: il tipo giusto passa, una pagina HTML no; vale lo stesso per un PDF che arriva per l\'email', () => {
            expect(() => verificaTipoRisposta({ data: new Blob(['PK'], { type: 'application/zip' }) }, 'zip')).not.toThrow();
            expect(() => verificaTipoRisposta({ data: new Blob(['PK']), headers: { 'content-type': 'application/x-zip-compressed' } }, 'zip')).not.toThrow();
            expect(() => verificaTipoRisposta({ data: new Blob(['<html>'], { type: 'text/html' }) }, 'zip')).toThrow(RispostaNonValida);
            // Un PDF non è l\'archivio, e un archivio non è un PDF.
            expect(() => verificaTipoRisposta({ data: pdf.data }, 'zip')).toThrow(RispostaNonValida);
            expect(() => verificaTipoRisposta({ data: new Blob(['PK'], { type: 'application/zip' }) }, 'pdf')).toThrow(RispostaNonValida);
            try {
                verificaTipoRisposta({ data: new Blob(['<html>'], { type: 'text/html' }) }, 'zip');
            } catch (e) {
                expect((e as RispostaNonValida).frase).toBe(FRASE_NON_E_UN_ARCHIVIO);
            }
        });
    });

    test('la scheda vuota del clic ha la riga d\'attesa e non sa da quale pagina è stata aperta; se il browser la blocca, niente', () => {
        const figli: Array<{ textContent: string }> = [];
        const documento = {
            title: '',
            createElement: () => ({ textContent: '', setAttribute: () => {} }),
            body: { appendChild: (n: { textContent: string }) => { figli.push(n); } },
        };
        const scheda = { opener: {}, document: documento };

        expect(apriSchedaDiAttesa(() => scheda as unknown as Window)).toBe(scheda);
        expect(scheda.opener).toBeNull();
        expect(figli.map((f) => f.textContent)).toEqual([RIGA_ATTESA_PDF]);
        expect(documento.title).toBe('PDF delle ricevute');
        expect(apriSchedaDiAttesa(() => null)).toBeNull();
        // Una scheda che non si lascia scrivere resta com'è: il PDF ci arriva lo stesso.
        const chiusa = { get document(): Document { throw new Error('vietato'); }, opener: null };
        expect(apriSchedaDiAttesa(() => chiusa as unknown as Window)).toBe(chiusa);
    });

    describe('l\'indirizzo del PDF si libera quando la sua scheda si chiude', () => {
        afterEach(() => { vi.useRealTimers(); vi.restoreAllMocks(); });

        test('finché la scheda è aperta l\'indirizzo resta (ricaricarla o salvare il PDF lo rilegge); chiusa, si libera una volta sola', () => {
            vi.useFakeTimers();
            const liberati: string[] = [];
            vi.spyOn(URL, 'revokeObjectURL').mockImplementation((u: string) => { liberati.push(u); });
            const scheda = { closed: false };

            liberaQuandoSiChiude(scheda as unknown as Window, 'blob:pdf-1');
            vi.advanceTimersByTime(10 * 60_000);
            expect(liberati).toEqual([]);

            scheda.closed = true;
            vi.advanceTimersByTime(5000);
            expect(liberati).toEqual(['blob:pdf-1']);
            vi.advanceTimersByTime(60_000);
            expect(liberati).toEqual(['blob:pdf-1']);
        });
    });

    test('che cosa dice dopo: la scheda aperta o il file scaricato con il suo nome; per la stampa, che le ricevute risultano stampate', () => {
        expect(testoDelPdf({ modo: 'scheda', nome: 'r.pdf' }, { stampa: true, una: true })).toBe('Il PDF è aperto in una scheda nuova. La ricevuta risulta stampata.');
        expect(testoDelPdf({ modo: 'scheda', nome: 'r.pdf' }, { stampa: true, una: false })).toBe('Il PDF è aperto in una scheda nuova. Le ricevute risultano stampate.');
        expect(testoDelPdf({ modo: 'scheda', nome: 'r.pdf' }, { stampa: false, una: true })).toBeNull();
        // Il file scaricato non dice di essere già sul computer: il browser può chiedere prima dove salvarlo. La stampa invece è già nel registro.
        expect(testoDelPdf({ modo: 'scaricato', nome: 'r.pdf' }, { stampa: true, una: false })).toBe('La scheda nuova non si è aperta, o era già chiusa: il PDF si scarica con '
            + 'il nome «r.pdf». Se il browser chiede dove salvarlo, arriva quando rispondi. Le ricevute risultano già stampate, anche se il file arriva dopo.');
        expect(testoDelPdf({ modo: 'scaricato', nome: 'r.pdf' }, { stampa: true, una: true })).toContain('La ricevuta risulta già stampata, anche se il file arriva dopo.');
        expect(testoDelPdf({ modo: 'scaricato', nome: 'a.pdf' }, { stampa: false, una: true })).toBe('La scheda nuova non si è aperta, o era già chiusa: il PDF si scarica con '
            + 'il nome «a.pdf». Se il browser chiede dove salvarlo, arriva quando rispondi.');
        expect(testoDelPdf({ modo: 'scaricato', nome: 'a.pdf' }, { stampa: false, una: true })).not.toContain('scaricato come');
    });

    test('chi chiama sa subito, prima della risposta, se la scheda si è aperta: può dire mentre aspetta dove andrà il PDF', async () => {
        const conScheda = ambiente(schedaFinta());
        const visto: boolean[] = [];
        const r = richiesta(conScheda.fatti);

        const esito = apriPdfInUnaScheda(r.chiedi, 'ricevuta.pdf', conScheda.ambiente, (aperta) => { visto.push(aperta); conScheda.fatti.push(`aperta ${aperta}`); });
        expect(conScheda.fatti).toEqual(['scheda', 'aperta true', 'richiesta']);
        r.arriva(pdf);
        await esito;
        expect(visto).toEqual([true]);

        const senza = ambiente(null);
        const s = richiesta(senza.fatti);
        const scaricato = apriPdfInUnaScheda(s.chiedi, 'ricevuta.pdf', senza.ambiente, (aperta) => { senza.fatti.push(`aperta ${aperta}`); });
        expect(senza.fatti).toEqual(['scheda', 'aperta false', 'richiesta']);
        s.arriva(pdf);
        expect((await scaricato).modo).toBe('scaricato');
    });

    describe('nel browser: la scheda chiesta così com\'è, e l\'indirizzo del PDF che vive quanto la scheda', () => {
        afterEach(() => { vi.useRealTimers(); vi.unstubAllGlobals(); vi.restoreAllMocks(); });

        test('la scheda si chiede senza «noopener» (con «noopener» il browser rende sempre null, e il PDF si scaricherebbe sempre); l\'indirizzo non si libera mentre la scheda è aperta, e si libera alla chiusura', async () => {
            vi.useFakeTimers();
            const scheda = { closed: false, opener: {} as unknown, location: { href: '' }, close() { this.closed = true; } };
            const apri = vi.fn(() => scheda);
            vi.stubGlobal('window', { open: apri });
            vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:pdf-1');
            const liberati: string[] = [];
            vi.spyOn(URL, 'revokeObjectURL').mockImplementation((u: string) => { liberati.push(u); });

            // Il browser vero: nessun ambiente finto.
            expect(await apriPdfInUnaScheda(async () => pdf, 'ricevuta.pdf')).toEqual({ modo: 'scheda', nome: 'ricevuta-condominio-via-dei-pini-inc-2026-00005.pdf' });

            expect(apri).toHaveBeenCalledTimes(1);
            expect(apri).toHaveBeenCalledWith('', '_blank');
            expect(scheda.location.href).toBe('blob:pdf-1');
            // Né subito né dopo il minuto dello scaricamento: la scheda aperta lo rilegge se la si ricarica o se si salva il PDF dal visore.
            expect(liberati).toEqual([]);
            vi.advanceTimersByTime(10 * 60_000);
            expect(liberati).toEqual([]);
            scheda.closed = true;
            vi.advanceTimersByTime(5000);
            expect(liberati).toEqual(['blob:pdf-1']);
        });
    });

    test('gli errori del PDF si leggono in italiano: la sessione scaduta (419) e il server (500 e oltre), che il framework scrive in inglese', async () => {
        const json = (status: number, message: string) => ({ response: { status, data: new Blob([JSON.stringify({ message })], { type: 'application/json' }) } });

        expect(await fraseDaErroreConFile(json(419, 'CSRF token mismatch.'), 'Non riuscito.')).toBe(FRASE_SESSIONE_SCADUTA);
        expect(await fraseDaErroreConFile(json(500, 'Server Error'), 'Non riuscito.')).toBe('Non riuscito.');
        expect(await fraseDaErroreConFile(json(503, 'Service Unavailable'), 'Non riuscito.')).toBe('Non riuscito.');
        // Il 422 resta la frase del server, che è in italiano.
        expect(await fraseDaErroreConFile(json(422, 'Ricevuta non stampata. Scegli il bollo.'), 'Non riuscito.')).toBe('Ricevuta non stampata. Scegli il bollo.');
    });
});
