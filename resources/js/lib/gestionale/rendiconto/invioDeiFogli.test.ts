/**
 * Le parole dell'invio dei fogli del conguaglio: l'ultimo invio di una persona (mai «consegnato»), lo stato di ogni indirizzo con l'errore in
 * chiaro, il tetto prima di partire, l'esito di un invio e di un rinvio, l'aggiornamento dell'ultimo invio senza ricaricare la scheda.
 */
import { describe, expect, test, vi } from 'vitest';

const axiosGet = vi.fn();
vi.mock('axios', () => ({ default: { get: (...a: unknown[]) => axiosGet(...a) } }));

const {
    FRASE_ESITO_INCERTO, fraseDellErroreDiInvio, chiaveDegliInvii, dimenticaStato, fraseFissa, partonoOra, rileggiStato, statoCorrente, testoDelRinvio, testoEsito, testoInCodaDaTempo, testoRigaDellInvio, testoTetto,
    testoUltimoInvio, ultimoDi,
} = await import('./invioDeiFogli');
type UltimoInvio = import('./invioDeiFogli').UltimoInvio;

const riga = (extra: Record<string, unknown> = {}) => ({ id: 1, destinatario: 'neri@prova.it', tipo: 'email', stato: 'inviata', quando: '2027-01-10 11:00', errore: null, ...extra });
const ultimo = (extra: Partial<UltimoInvio> = {}): UltimoInvio => ({ quando: '2027-01-10 11:00', stato: 'inviato', righe: [riga()], da_rimandare: false, pdf: 1, ...extra });
const VIETATE = /\b(consegnat[oaie]|ricevut[oaie]|dovut[oaie]|esigibil[ei]|da pagare entro)\b/i;

describe('l\'ultimo invio di una persona', () => {
    test('inviato, in coda, non partito con l\'errore in chiaro, in parte con lo stato di ogni indirizzo', () => {
        expect(testoUltimoInvio(ultimo())).toBe('Foglio inviato il 10/01/2027 alle 11:00, consegna non provata.');
        expect(testoUltimoInvio(ultimo({ stato: 'in_coda' }))).toBe('Foglio in coda dal 10/01/2027 alle 11:00.');
        expect(testoUltimoInvio(ultimo({ stato: 'non_partito', righe: [riga({ stato: 'non_partita', errore: 'Il server di posta non ha accettato il messaggio (codice 550).' })] })))
            .toBe('Foglio non partito il 10/01/2027 alle 11:00: il server di posta non ha accettato il messaggio (codice 550).');
        expect(testoUltimoInvio(ultimo({ stato: 'in_parte', righe: [riga(), riga({ id: 2, destinatario: 'neri.casa@prova.it', stato: 'non_partita',
            errore: 'Il server di posta non ha accettato il messaggio (codice 550).' })] })))
            .toBe('Foglio inviato in parte: inviato a neri@prova.it il 10/01/2027 alle 11:00; non partito, per neri.casa@prova.it: il server di posta non ha accettato il messaggio (codice 550).');
        expect(testoUltimoInvio(null)).toBeNull();
    });

    test('nessuna parola che dice più di quello che il programma sa, né che chiede un pagamento', () => {
        for (const stato of ['inviato', 'in_coda', 'non_partito', 'in_parte'] as const) {
            expect(testoUltimoInvio(ultimo({ stato }))).not.toMatch(VIETATE);
        }
        expect(testoRigaDellInvio(riga({ stato: 'in_coda' }))).toBe('in coda, per neri@prova.it');
    });
});

describe('il tetto, prima di partire', () => {
    test('senza il servizio della coda: quante email partono con questo clic, e «Invia le altre»', () => {
        expect(testoTetto({ messaggi: 61, partono: { persone: 50, messaggi: 50 }, tetto_messaggi: 50, tetto_fogli: 200 }))
            .toBe('Con questo clic partono 50 email a 50 persone: senza il servizio della coda partono al massimo 50 email per volta, a persone intere. Per le altre premi «Invia le altre».');
    });

    test('con la coda, il tetto dei fogli; nessuna frase quando partono tutte', () => {
        expect(testoTetto({ messaggi: 410, partono: { persone: 200, messaggi: 400 }, tetto_messaggi: null, tetto_fogli: 200 }))
            .toBe('Con questo clic partono 400 email a 200 persone: partono al massimo 200 fogli per volta, a persone intere. Per le altre premi «Invia le altre».');
        expect(testoTetto({ messaggi: 4, partono: { persone: 3, messaggi: 4 }, tetto_messaggi: 50, tetto_fogli: 200 })).toBeNull();
    });
});

describe('l\'esito', () => {
    test('di un invio: le email per stato, gli errori, chi è senza indirizzo, chi resta', () => {
        expect(testoEsito({ inviate: 3, in_coda: 0, non_partite: 1, errori: ['Il server di posta non ha accettato il messaggio (codice 550).'], senza_indirizzo: ['Verdi'],
            restanti: { persone: [7, 8] }, aggiunte: 1, uscite: 0 })).toEqual([
            'Email partite 3, non partite 1.',
            'Il server di posta non ha accettato il messaggio (codice 550).',
            'Senza un indirizzo fra quelli scelti: Verdi. Scarica il loro foglio.',
            '1 persona è entrata nella scelta dopo l\'anteprima: non ha avuto il foglio. Rifai l\'anteprima.',
            'Restano 2 persone: premi «Invia le altre».',
        ]);
    });

    test('di un rinvio: i fogli rifatti con le cifre di oggi, chi non ha più l\'indirizzo, chi resta', () => {
        expect(testoDelRinvio({ fogli: 1, inviate: 1, in_coda: 0, non_partite: 0, senza_indirizzo: [], restanti: null })).toEqual(['1 foglio rifatto con le cifre di oggi.', 'Email partite 1.']);
        expect(testoDelRinvio({ fogli: 0, inviate: 0, in_coda: 0, non_partite: 0, senza_indirizzo: ['Neri non ha più l\'indirizzo secondaria: scarica il suo foglio.'], restanti: { persone: 3 } }))
            .toEqual(['Nessun foglio rifatto.', 'Neri non ha più l\'indirizzo secondaria: scarica il suo foglio.', 'Restano 3 persone da rimandare: premi di nuovo.']);
    });
});

test('l\'errore di un invio: senza risposta o un errore del server, la frase dell\'incertezza; un rifiuto (422) dice il suo messaggio', () => {
    expect(FRASE_ESITO_INCERTO).toBe('Non so se i fogli sono partiti: guarda l\'ultimo invio accanto alle persone, o ricarica la scheda, prima di riprovare.');
    expect(fraseDellErroreDiInvio(new Error('Network Error'))).toBe(FRASE_ESITO_INCERTO);
    expect(fraseDellErroreDiInvio({ response: { status: 500, data: { message: 'Server Error' } } })).toBe(FRASE_ESITO_INCERTO);
    expect(fraseDellErroreDiInvio({ response: { status: 504, data: {} } })).toBe(FRASE_ESITO_INCERTO);
    expect(fraseDellErroreDiInvio({ response: { status: 422, data: { message: 'Scrivi l\'oggetto dell\'email.' } } })).toBe('Scrivi l\'oggetto dell\'email.');
    expect(fraseDellErroreDiInvio({ response: { status: 419, data: { message: 'CSRF token mismatch.' } } })).not.toBe('CSRF token mismatch.');
});

describe('il tetto contato nella finestra', () => {
    test('a persone intere, la prima sempre: prima i messaggi per volta, poi i fogli per volta', () => {
        expect(partonoOra([{ messaggi: 1 }, { messaggi: 2 }, { messaggi: 1 }], 2, 200)).toEqual({ persone: 1, messaggi: 1 });
        expect(partonoOra([{ messaggi: 3 }, { messaggi: 1 }], 2, 200)).toEqual({ persone: 1, messaggi: 3 });
        expect(partonoOra([{ messaggi: 1 }, { messaggi: 1 }, { messaggi: 1 }], null, 2)).toEqual({ persone: 2, messaggi: 2 });
        expect(partonoOra([{ messaggi: 1 }, { messaggi: 2 }], null, 200)).toEqual({ persone: 2, messaggi: 3 });
        expect(partonoOra([], 50, 200)).toEqual({ persone: 0, messaggi: 0 });
    });
});

test('la frase fissa e i fogli in coda da tempo', () => {
    expect(fraseFissa('È una proposta.')).toBe('In fondo a ogni email, sempre: «È una proposta.» e la data e l\'ora del calcolo delle cifre.');
    expect(testoInCodaDaTempo(0)).toBeNull();
    expect(testoInCodaDaTempo(1)).toContain('1 foglio per email è in coda da più di un\'ora: non è ancora partito.');
    expect(testoInCodaDaTempo(2)).toContain('2 fogli per email sono in coda da più di un\'ora: non sono ancora partiti.');
});

test('lo stato riletto vale per la sua scheda e per i dati della pagina con cui è stato riletto', async () => {
    dimenticaStato();
    const base = { ultimi: { 5: ultimo({ stato: 'non_partito' }) }, da_rimandare: 1 };
    const chiave = chiaveDegliInvii(3, 9);
    expect(ultimoDi(chiave, base, 5)?.stato).toBe('non_partito');
    axiosGet.mockResolvedValueOnce({ data: { ultimi: { 5: ultimo({ stato: 'in_coda' }) }, da_rimandare: 0, in_coda_da_tempo: 0 } });
    await rileggiStato('/stato', [5], chiave, base);
    expect(axiosGet).toHaveBeenCalledWith('/stato', { params: { persone: [5] } });
    expect(ultimoDi(chiave, base, 5)?.stato).toBe('in_coda');
    expect(statoCorrente(chiave, base)?.da_rimandare).toBe(0);
    // Un'altra scheda, o dati nuovi della pagina: lo stato riletto non vale.
    expect(statoCorrente(chiaveDegliInvii(3, 8), base)).toBeNull();
    expect(ultimoDi(chiave, { ...base }, 5)?.stato).toBe('non_partito');
    // Il server manda un elenco vuoto come [].
    expect(ultimoDi(chiave, { ultimi: [] }, 5)).toBeNull();
});
