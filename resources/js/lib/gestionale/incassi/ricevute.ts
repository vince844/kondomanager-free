/**
 * La ricevuta dell'incasso a video (1.12.0-beta.2): la selezione della pagina delle ricevute, le scelte del bollo che servono, gli indirizzi
 * spuntati di partenza nella finestra di un incasso, i testi dei conteggi e delle emissioni. Le cifre sono in centesimi; la soglia del bollo e
 * le regole vere stanno nel server (`EmissioneRicevute`): qui c'è quello che serve a mostrarle e a non mandare richieste che il server rifiuta.
 */

/** Sopra questa somma la finestra scrive «Importo sopra € 77,47» e il bollo si sceglie a parte: la stessa soglia di `RicevutaIncasso::SOGLIA_BOLLO_CENTS`. */
export const SOGLIA_BOLLO_CENTS = 7747;

/**
 * Le quattro scelte dell'imposta di bollo, nell'ordine in cui compaiono come pulsanti. Ognuna scrive i valori che il server conosce: lo stato
 * (`assolta`, `non_dovuta`, `da_assolvere`) e, per le due «Assolta», il modo in cui è assolta (`contrassegno` o `virtuale`).
 */
export const SCELTE_BOLLO = [
    { id: 'non_dovuta', stato: 'non_dovuta', modo: null, etichetta: 'Non dovuta' },
    { id: 'da_assolvere', stato: 'da_assolvere', modo: null, etichetta: 'Da assolvere' },
    { id: 'assolta_contrassegno', stato: 'assolta', modo: 'contrassegno', etichetta: 'Assolta: contrassegno' },
    { id: 'assolta_virtuale', stato: 'assolta', modo: 'virtuale', etichetta: 'Assolta: virtuale' },
] as const;

export type StatoBollo = (typeof SCELTE_BOLLO)[number]['stato'];

/** Quale dei quattro pulsanti è acceso per uno stato e un modo: «Assolta» senza un modo noto non ne accende nessuno. */
export function idSceltaBollo(stato: string | null | undefined, modo: string | null | undefined): string | undefined {
    return SCELTE_BOLLO.find((s) => s.stato === stato && (s.stato !== 'assolta' || s.modo === modo))?.id;
}

/** Lo stato come parte della finestra: «Assolta» senza il modo in cui è assolta non è una scelta dei pulsanti, e non conta come scelta fatta. */
export function statoConModo(stato: string | null | undefined, modo: string | null | undefined): string | null {
    return stato === 'assolta' && idSceltaBollo(stato, modo) === undefined ? null : (stato ?? null);
}

/** Il segnaposto degli estremi, secondo il modo in cui il bollo è assolto. */
export const SEGNAPOSTO_ESTREMI: Record<string, string> = {
    contrassegno: 'contrassegno n. …',
    virtuale: 'autorizzazione n. … del …',
};

export const TIPI_INDIRIZZO: Record<string, string> = { email: 'Primaria', email_secondaria: 'Secondaria', pec: 'PEC', altro: 'Altro indirizzo' };

/** La frase accanto alla PEC: il programma non è un gestore PEC. */
export const AVVISO_PEC =
    'Il programma manda la PEC come email semplice dal server di posta configurato, non come PEC. Alcune caselle PEC rifiutano i messaggi che non arrivano da una PEC.';

/**
 * Un'emissione della ricevuta. `errore` è la frase che leggono tutti (con il codice del server, se lo dà); `errore_completo` è l'errore intero
 * del server di posta, che il server manda solo a chi gestisce le impostazioni generali.
 */
export interface Emissione {
    id?: number;
    canale: string;
    stato: string;
    quando: string | null;
    destinatario: string | null;
    errore: string | null;
    errore_completo?: string | null;
    bollo?: string | null;
    modo_bollo?: string | null;
    in_coda_da_tempo?: boolean;
    /** Dove va una email se la si rimanda adesso: l'indirizzo di oggi della persona, o quello scritto a mano; nullo se la persona non ce l'ha più. */
    manda_a?: string | null;
}

/**
 * Il contrassegno su una ricevuta che parte per email: si può scegliere, ma la marca non viaggia nel messaggio. Come assolvere il bollo lo
 * decide l'amministratore con il commercialista (decisione 205): il programma non lo assolve e non dice quale modo vale.
 */
export const NOTA_CONTRASSEGNO_EMAIL =
    'Una ricevuta per email non porta la marca. Come assolvere il bollo, in modo virtuale o con il contrassegno sull\'esemplare che resta in studio, '
    + 'lo decidi tu con il commercialista.';

/** Quello che legge chi lavora quando il limite sugli indirizzi scritti a mano scatta (la stessa frase che scrive il server). */
export const FRASE_LIMITE_ALTRO_INDIRIZZO = 'Hai già mandato dieci messaggi a indirizzi scritti a mano nell\'ultimo minuto: aspetta un momento e riprova.';

/** La sessione è finita (401) o il gettone è scaduto (419): la stessa frase dell'accesso. */
export const FRASE_SESSIONE_SCADUTA = 'La sessione è scaduta: ricarica la pagina e, se serve, accedi di nuovo.';

/** La richiesta è stata rifiutata perché a chi lavora manca il permesso (403): il framework lo scrive in inglese («This action is unauthorized.»). */
export const FRASE_NON_PERMESSO = 'Non hai il permesso di fare questa operazione. Se ti serve, chiedilo a chi ti ha dato l\'accesso.';

/** La richiesta è stata rifiutata perché la sessione è finita: 401 («Unauthenticated.») o 419 («CSRF token mismatch.»). */
export function sessioneScaduta(e: unknown): boolean {
    const stato = (e as { response?: { status?: number } } | null)?.response?.status;

    return stato === 401 || stato === 419;
}

/**
 * Una risposta di buon esito (2xx) che non è il file chiesto: per esempio la pagina di accesso, arrivata con un rimando seguito dopo che la
 * sessione è finita. Non è un errore del server, ma il file non c'è: `frase` dice che cosa fare.
 */
export class RispostaNonValida extends Error {
    constructor(readonly frase: string) {
        super(frase);
        this.name = 'RispostaNonValida';
    }
}

/** Al posto del file è arrivata una pagina web (di solito la pagina di accesso, dopo che la sessione è finita): la sessione è il primo sospetto. */
export const FRASE_NON_E_UN_PDF = 'Non è arrivato un PDF: forse la sessione è scaduta. Ricarica la pagina e, se serve, accedi di nuovo.';
export const FRASE_NON_E_UN_ARCHIVIO = 'Non è arrivato l\'archivio: forse la sessione è scaduta. Ricarica la pagina e, se serve, accedi di nuovo.';
/** È arrivato un file, ma non quello chiesto e non una pagina web: non c'è motivo di parlare della sessione. */
export const FRASE_FILE_NON_PDF = 'Il file arrivato non è un PDF. Riprova; se succede ancora, ricarica la pagina.';
export const FRASE_FILE_NON_ARCHIVIO = 'Il file arrivato non è un archivio .zip. Riprova; se succede ancora, ricarica la pagina.';

const TIPI_ATTESI = { pdf: ['application/pdf'], zip: ['application/zip', 'application/x-zip-compressed'] } as const;
/** Come comincia un file di ciascun tipo: un PDF con «%PDF», un archivio .zip con «PK» e i byte 3 e 4. */
const INIZIO_DEL_FILE = { pdf: '%PDF', zip: 'PK\u0003\u0004' } as const;
/** I tipi che dicono soltanto «un file»: un proxy o un antivirus li mette anche a un PDF vero. Senza leggere il file non si può dire di più. */
const TIPI_GENERICI = ['', 'application/octet-stream', 'binary/octet-stream', 'application/x-download', 'application/download', 'application/force-download'];

type Risposta = { data: unknown; headers?: Record<string, unknown> };

/** Il tipo che la risposta dichiara: l'intestazione `Content-Type` senza i parametri e in minuscolo, o il tipo del file se l'intestazione manca. */
function tipoDichiarato(risposta: Risposta): string {
    const intestazione = risposta.headers?.['content-type'];
    const dichiarato = typeof intestazione === 'string' && intestazione.trim() !== '' ? intestazione : (risposta.data instanceof Blob ? risposta.data.type : '');

    return dichiarato.split(';')[0].trim().toLowerCase();
}

/** Una pagina web al posto del file: lo dice il tipo dichiarato, o l'inizio del file (la pagina di accesso comincia con «<!DOCTYPE» o «<html»). */
const unaPaginaWeb = (tipo: string, inizio = ''): boolean => tipo === 'text/html' || /^\s*</.test(inizio);

function nonValida(atteso: keyof typeof TIPI_ATTESI, paginaWeb: boolean): RispostaNonValida {
    if (atteso === 'pdf') {
        return new RispostaNonValida(paginaWeb ? FRASE_NON_E_UN_PDF : FRASE_FILE_NON_PDF);
    }

    return new RispostaNonValida(paginaWeb ? FRASE_NON_E_UN_ARCHIVIO : FRASE_FILE_NON_ARCHIVIO);
}

/** Come comincia il file, in poche lettere: basta per riconoscerlo. Il contenitore può essere un file, un testo o dei byte. */
async function inizioDelFile(dati: unknown): Promise<string> {
    const file = dati instanceof Blob ? dati : new Blob([dati as BlobPart]);

    return file.slice(0, 64).text();
}

/**
 * Il file arrivato è quello chiesto? Se il tipo dichiarato è quello giusto, sì, senza leggerlo. Altrimenti fa fede il file: un proxy o un
 * antivirus può cambiare il tipo a un PDF vero (`application/octet-stream`), e allora basta che cominci come un PDF (o come un archivio .zip).
 * Se non comincia così non è il file: `RispostaNonValida`, e chi chiama non lo apre né lo scarica. La frase dice della sessione solo se al
 * posto del file è arrivata una pagina web.
 */
export async function verificaIlFile(risposta: Risposta, atteso: keyof typeof TIPI_ATTESI): Promise<void> {
    const tipo = tipoDichiarato(risposta);
    if ((TIPI_ATTESI[atteso] as readonly string[]).includes(tipo)) {
        return;
    }
    const inizio = await inizioDelFile(risposta.data);
    if (!inizio.startsWith(INIZIO_DEL_FILE[atteso])) {
        throw nonValida(atteso, unaPaginaWeb(tipo, inizio));
    }
}

/**
 * Lo stesso controllo, sulla sola intestazione, per chi non può aspettare di leggere il file: un tipo dichiarato che è chiaramente un'altra cosa
 * (una pagina, un JSON, un archivio al posto del PDF) non passa; uno generico o assente sì. Chi può aspettare usa `verificaIlFile`.
 */
export function verificaTipoRisposta(risposta: Risposta, atteso: keyof typeof TIPI_ATTESI): void {
    const tipo = tipoDichiarato(risposta);
    if (!(TIPI_ATTESI[atteso] as readonly string[]).includes(tipo) && !TIPI_GENERICI.includes(tipo)) {
        throw nonValida(atteso, unaPaginaWeb(tipo));
    }
}

/**
 * La frase di un errore di una richiesta: il messaggio del server, oppure `predefinita` se non ce n'è. Il 429, la sessione scaduta (401, 419) e
 * il permesso che manca (403) si leggono sempre in italiano, mai come li scrive il framework («Too Many Attempts.», «Unauthenticated.»,
 * «CSRF token mismatch.», «This action is unauthorized.»).
 */
export function frasePerErrore(e: unknown, predefinita: string): string {
    const risposta = (e as { response?: { status?: number; data?: { message?: string } } } | null)?.response;
    if (sessioneScaduta(e)) {
        return FRASE_SESSIONE_SCADUTA;
    }
    if (risposta?.status === 403) {
        return FRASE_NON_PERMESSO;
    }

    // `||`: un 404 o un 500 arrivano con il messaggio vuoto, e la finestra deve comunque dire qualcosa.
    return risposta?.status === 429 ? FRASE_LIMITE_ALTRO_INDIRIZZO : risposta?.data?.message || predefinita;
}

/** Dopo «Riprova le non partite»: gli invii a indirizzi scritti a mano che il limite di dieci al minuto ha fermato, e che restano da riprovare. */
export function testoLimiteRiprova(r: { limite_altro_indirizzo?: number }): string | null {
    const n = r.limite_altro_indirizzo ?? 0;

    return n > 0
        ? `${n === 1 ? '1 invio a un indirizzo scritto a mano è rimasto fermo' : `${n} invii a indirizzi scritti a mano sono rimasti fermi`} per il limite di dieci al minuto: `
            + 'fra poco premi di nuovo «Riprova le non partite».'
        : null;
}

/** Il resto dei testi che la ricevuta mostra prima di emettere: non prescrivono e non danno effetti di legge, decide l'amministratore. */
export const NOTA_INTERESSI = 'Se la persona deve anche interessi o spese e vuoi tenerli fuori da questo versamento, scrivilo nella nota.';

/** La sola frase sul bollo: se e come la ricevuta lo sconta non lo dice il programma, lo decide l'amministratore con il suo commercialista. */
export const FRASE_BOLLO = 'Se e come la ricevuta sconta il bollo lo decidi tu, con il tuo commercialista.';

/** In blocco il modo di assolvere il bollo è uno solo: se tutti e due i gruppi sono «Assolta», cambiarlo in uno lo cambia anche nell'altro. */
export const NOTA_MODO_UNICO = 'Con «Assolta» in tutti e due i gruppi il modo è uno solo, per tutte le ricevute.';

export const RIGA_ORIGINALE = 'La prima emissione è l\'originale: quelle dopo escono come «Copia», con la data dell\'originale.';

/** Sotto la tabella delle quote: la ricevuta scrive il versamento com'è registrato nell'incasso, non lo sceglie (decisione 206.1). */
export const TESTO_IMPUTAZIONE = 'Se non è giusto, storna l\'incasso e registralo di nuovo prima di emettere.';

/** Una quota a cui va il versamento, come la manda il server per la finestra: il nome breve della rata, il suo piano e la gestione. */
export interface QuotaDellaRicevuta {
    rata: string; piano: string | null; gestione: string | null; unita: string | null; scadenza?: string | null;
    importo: number; imputato: number; dal_credito: number; dopo: number;
}

/**
 * Le quote in gruppi, uno per piano e gestione, nell'ordine in cui la ricevuta le porta (decisione 206.1): il piano e la gestione si dicono una
 * volta sola, nel titolo del gruppo; un gruppo senza piano né gestione ha il titolo vuoto.
 */
export function gruppiDelleQuote<T extends { piano: string | null; gestione: string | null }>(quote: T[]): Array<{ titolo: string; quote: T[] }> {
    const gruppi: Array<{ chiave: string; titolo: string; quote: T[] }> = [];
    for (const q of quote) {
        const chiave = `${q.piano ?? ''}|${q.gestione ?? ''}`;
        let gruppo = gruppi.find((g) => g.chiave === chiave);
        if (!gruppo) {
            gruppo = { chiave, titolo: [q.piano, q.gestione].filter((t) => !!t).join(' — '), quote: [] };
            gruppi.push(gruppo);
        }
        gruppo.quote.push(q);
    }

    return gruppi.map(({ titolo, quote: righe }) => ({ titolo, quote: righe }));
}

/** Il totale in fondo alla tabella: l'imputato, quanto resta e la parte che viene dal credito, in centesimi. */
export function totaliDelleQuote(quote: Array<{ imputato: number; dopo: number; dal_credito: number }>): { imputato: number; dopo: number; dal_credito: number } {
    return quote.reduce((t, q) => ({ imputato: t.imputato + q.imputato, dopo: t.dopo + q.dopo, dal_credito: t.dal_credito + q.dal_credito }), { imputato: 0, dopo: 0, dal_credito: 0 });
}

export const TESTO_COPIA_CAMBIATA = 'Dopo l\'originale la situazione di queste rate è cambiata (per esempio lo storno di un incasso registrato prima): '
    + 'la copia riporta le cifre dell\'originale, come deve.';

/** Una cifra in centesimi con l'euro prima: «€ 1.234,56». */
export function euroTesto(centesimi: number): string {
    return '€ ' + new Intl.NumberFormat('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(centesimi / 100);
}

/** Quanto della ricevuta viene dal credito usato: non conta per la soglia del bollo. */
export function testoSogliaCredito(creditoCentesimi: number): string | null {
    return creditoCentesimi > 0 ? `In questa ricevuta ${euroTesto(creditoCentesimi)} vengono dal credito: non contano per la soglia.` : null;
}

/** La parte in più di un versamento, in una frase: quanto riduce una rata da pagare e quanto resta a credito. */
export function testoParteInPiu(e: { importo: number; persona: string | null; rata: string | null; riduce: number; a_credito: number }): string {
    const di = e.persona ?? 'la persona';
    const parti: string[] = [];
    if (e.riduce > 0) {
        parti.push(`${euroTesto(e.riduce)} riducono la ${e.rata ?? 'rata'} di ${di}`);
    }
    if (e.a_credito > 0) {
        parti.push(`${euroTesto(e.a_credito)} restano a credito di ${di}${e.rata ? `, sulla ${e.rata}` : ''}`);
    }

    return parti.join('; ');
}

/** Lo stato del bollo scelto in un'emissione, in una frase: «bollo assolto in modo virtuale». */
export function testoBollo(bollo: string | null | undefined, modo: string | null | undefined): string {
    switch (bollo) {
        case 'assolta':
            return 'bollo assolto' + (modo === 'virtuale' ? ' in modo virtuale' : modo === 'contrassegno' ? ' con il contrassegno' : '');
        case 'non_dovuta':
            return 'bollo non dovuto';
        case 'da_assolvere':
            return 'bollo da assolvere';
        default:
            return '';
    }
}

/** Un indirizzo proposto. La `chiave` lo distingue dagli altri della persona: il tipo; per l'annullato anche l'indirizzo, perché la primaria di prima e quella di dopo una correzione sono due. */
export interface IndirizzoProposto { tipo: string; indirizzo: string; proposto: boolean; chiave?: string }

/** La chiave con cui si sceglie un indirizzo: quella che manda il server, o il tipo. */
export const chiaveIndirizzo = (i: IndirizzoProposto): string => i.chiave ?? i.tipo;
export interface Destinatario { anagrafica_id: number; nome: string; ruolo: string; indirizzi: IndirizzoProposto[] }

export interface Selezione { tutte: boolean; ids: number[] }

export function sopraSoglia(cents: number): boolean {
    return cents > SOGLIA_BOLLO_CENTS;
}

export function selezioneVuota(): Selezione {
    return { tutte: false, ids: [] };
}

/** Una casella. Da «tutte le N dei filtri» si esce togliendo una riga: si torna alla lista, con le sole righe che si spuntano dopo. */
export function alterna(s: Selezione, id: number): Selezione {
    if (s.tutte) {
        return { tutte: false, ids: [] };
    }

    return s.ids.includes(id) ? { tutte: false, ids: s.ids.filter((x) => x !== id) } : { tutte: false, ids: [...s.ids, id] };
}

/** La casella dell'intestazione: spunta le righe della pagina; se ci sono già tutte, le toglie. */
export function selezionaPagina(s: Selezione, pagina: number[]): Selezione {
    const ids = s.tutte ? [] : s.ids;
    const tutteGia = pagina.length > 0 && pagina.every((id) => ids.includes(id));

    return tutteGia
        ? { tutte: false, ids: ids.filter((id) => !pagina.includes(id)) }
        : { tutte: false, ids: [...ids, ...pagina.filter((id) => !ids.includes(id))] };
}

export function tutteDeiFiltri(_s: Selezione): Selezione {
    return { tutte: true, ids: [] };
}

export function quanteSelezionate(s: Selezione, totaleDeiFiltri: number): number {
    return s.tutte ? totaleDeiFiltri : s.ids.length;
}

/** Quello che la richiesta porta: la lista, o «tutte» con i filtri, che il server rilegge da sé. */
export function payloadSelezione(s: Selezione, filtri: Record<string, unknown>): Record<string, unknown> {
    return s.tutte ? { tutte: 1, filtri } : { incassi: s.ids };
}

/** Quali stati del bollo servono: per le ricevute sopra la soglia e per le altre, solo se ce ne sono. */
export function bolliRichiesti(a: { sopra_soglia: number; sotto_soglia: number }): { sopra: boolean; sotto: boolean } {
    return { sopra: a.sopra_soglia > 0, sotto: a.sotto_soglia > 0 };
}

export function pronteDaEmettere(scelte: { bollo_sopra: string | null; bollo_sotto: string | null }, richiesti: { sopra: boolean; sotto: boolean }): boolean {
    return (!richiesti.sopra || !!scelte.bollo_sopra) && (!richiesti.sotto || !!scelte.bollo_sotto);
}

/** Le caselle degli indirizzi di partenza: spuntate quelle che il server propone (primaria e secondaria della posizione). */
export function indirizziIniziali(destinatari: Destinatario[]): Record<string, boolean> {
    const caselle: Record<string, boolean> = {};
    for (const d of destinatari) {
        for (const i of d.indirizzi) {
            caselle[`${d.anagrafica_id}:${chiaveIndirizzo(i)}`] = i.proposto;
        }
    }

    return caselle;
}

/** Quello che parte: per ogni persona i tipi spuntati; una persona senza caselle spuntate non c'è. */
export function destinatariScelti(destinatari: Destinatario[], caselle: Record<string, boolean>): Array<{ anagrafica_id: number; tipi: string[] }> {
    return destinatari
        .map((d) => ({ anagrafica_id: d.anagrafica_id, tipi: d.indirizzi.map(chiaveIndirizzo).filter((k) => caselle[`${d.anagrafica_id}:${k}`]) }))
        .filter((d) => d.tipi.length > 0);
}

function conta(n: number, uno: string, tanti: string): string {
    return `${n} ${n === 1 ? uno : tanti}`;
}

/** «14 persone, 37 ricevute, a 20 indirizzi». */
export function testoAnteprima(e: { persone: number; ricevute: number; indirizzi: number }): string {
    return `${conta(e.persone, 'persona', 'persone')}, ${conta(e.ricevute, 'ricevuta', 'ricevute')}, a ${conta(e.indirizzi, 'indirizzo', 'indirizzi')}`;
}

/** Il riepilogo dopo l'invio, per messaggio: solo le voci che ci sono. */
export function testoRiepilogo(r: { inviate: number; in_coda: number; non_partite: number; senza_indirizzo: number }): string {
    const parti = [
        r.inviate > 0 ? `partite ${r.inviate}` : null,
        r.in_coda > 0 ? `in coda ${r.in_coda}` : null,
        r.non_partite > 0 ? `non partite ${r.non_partite}` : null,
        r.senza_indirizzo > 0 ? `senza indirizzo ${r.senza_indirizzo}` : null,
    ].filter((p): p is string => p !== null);
    if (parti.length === 0) {
        return 'Niente da inviare.';
    }
    const testo = parti.join(', ');

    return testo.charAt(0).toUpperCase() + testo.slice(1) + '.';
}

/**
 * L'esito di un invio in blocco quando la finestra che l'ha chiesto non c'è più (chiusa, o riaperta da capo): il riepilogo, gli errori dei
 * singoli invii e quanto resta da mandare, in una riga sola, in testa alla pagina.
 */
export function testoEsitoInvio(r: {
    inviate: number; in_coda: number; non_partite: number; senza_indirizzo: number; errori?: string[]; restanti?: { ricevute: number } | null;
}): string {
    const resto = r.restanti ? (r.restanti.ricevute === 1 ? 'Resta 1 ricevuta da mandare.' : `Restano ${r.restanti.ricevute} ricevute da mandare.`) : null;

    return [testoRiepilogo(r), ...(r.errori ?? []), resto].filter((p): p is string => !!p).join(' ');
}

/**
 * Come è stato assolto il bollo, come la richiesta lo porta: il modo solo se una delle ricevute ha il bollo «assolta»; gli estremi solo con il
 * modo, e in blocco solo quelli del modo virtuale (un'autorizzazione vale per tutte; il numero di un contrassegno è di una ricevuta sola, e il
 * server lo rifiuterebbe su più ricevute).
 */
export function estremiDaMandare(
    bolli: Array<string | null>,
    modo: string | null,
    estremi: string | null,
    inBlocco: boolean,
): { modo_bollo: string | null; estremi_bollo: string | null } {
    const assolta = bolli.includes('assolta');
    const modoBollo = assolta && modo ? modo : null;
    const testo = (estremi ?? '').trim();
    const ammessi = modoBollo !== null && (!inBlocco || modoBollo === 'virtuale');

    return { modo_bollo: modoBollo, estremi_bollo: ammessi && testo !== '' ? testo : null };
}

/** Nell'anteprima del blocco, senza il servizio della coda: quanti messaggi partiranno questa volta, se sono più del tetto. */
export function testoTetto(a: { tetto_messaggi: number | null; email: { messaggi: number; messaggi_adesso?: number } }): string | null {
    if (a.tetto_messaggi === null || a.email.messaggi <= a.tetto_messaggi) {
        return null;
    }
    // Il tetto taglia per persona intera: i messaggi che partono adesso sono quelli che il server ha contato, spesso meno del tetto.
    const adesso = a.email.messaggi_adesso ?? a.tetto_messaggi;

    return `Senza il servizio della coda partono al massimo ${a.tetto_messaggi} messaggi per volta: questa volta ${adesso} su ${a.email.messaggi}. `
        + 'Per gli altri, dopo l\'invio, premi «Invia le altre».';
}

/** Dopo l'invio in blocco con il tetto: perché ne sono partiti al massimo tanti, che cosa resta e come mandarlo. */
export function testoRestantiBlocco(r: {
    tetto_messaggi: number | null;
    restanti: { persone: number[]; incassi?: number[]; ricevute: number; messaggi: number } | null;
}): string | null {
    if (!r.restanti || r.tetto_messaggi === null) {
        return null;
    }

    return `Senza il servizio della coda il programma manda le email mentre la pagina aspetta, e oltre ${r.tetto_messaggi} messaggi per volta il server `
        + 'può interrompere l\'invio a metà. '
        + `Restano ${conta(r.restanti.ricevute, 'ricevuta', 'ricevute')} di ${conta(r.restanti.persone.length, 'persona', 'persone')} `
        + `(${conta(r.restanti.messaggi, 'messaggio', 'messaggi')}): premi «Invia le altre» per mandarle.`;
}

/** Dopo «Riprova le non partite» con il tetto: quanti restano da riprovare. */
export function testoRestantiRiprova(r: { tetto_messaggi: number | null; restanti: { messaggi: number } | null }): string | null {
    if (!r.restanti || r.tetto_messaggi === null) {
        return null;
    }

    return `Senza il servizio della coda si riprovano al massimo ${r.tetto_messaggi} invii per volta: ne restano ${r.restanti.messaggi}, premi di nuovo «Riprova le non partite».`;
}

/** Chi resta senza un indirizzo fra quelli scelti ma ne ha uno di un altro tipo: per tipo, perché l'amministratore lo spunti. */
export function testoAltriIndirizzi(altri: Record<string, number>): string | null {
    const nomi: Record<string, string> = { email: 'la primaria', email_secondaria: 'la secondaria', pec: 'la PEC' };
    const parti = Object.entries(nomi)
        .filter(([tipo]) => (altri[tipo] ?? 0) > 0)
        .map(([tipo, nome]) => `${altri[tipo]} ${altri[tipo] === 1 ? 'persona ha' : 'persone hanno'} ${nome}`);

    return parti.length === 0 ? null : `${parti.join(', ')}: non sono fra gli indirizzi scelti, spuntali per raggiungerle.`;
}

/** «2026-11-05 10:30» → «05/11/2026 alle 10:30». */
function quando(valore: string | null): string {
    if (!valore) {
        return '';
    }
    const [data, ora] = valore.split(' ');
    const [a, m, g] = data.split('-');

    return `${g}/${m}/${a}${ora ? ` alle ${ora}` : ''}`;
}

/** Un'emissione della ricevuta in una frase, con lo stato del bollo scelto. */
export function testoEmissione(e: Emissione | null | undefined): string {
    if (!e) {
        return 'Mai emessa';
    }
    const per = e.destinatario ? `, per ${e.destinatario}` : '';
    const bollo = testoBollo(e.bollo, e.modo_bollo);
    const conBollo = bollo ? `, ${bollo}` : '';
    switch (e.stato) {
        case 'stampata':
            return `Stampata il ${quando(e.quando)}${conBollo}`;
        case 'inviata':
            // Il server di posta l'ha accettata: che sia arrivata, il programma non lo sa.
            return `Inviata il ${quando(e.quando)}${e.destinatario ? ` a ${e.destinatario}` : ''}, consegna non provata${conBollo}`;
        case 'in_coda':
            return `In coda dal ${quando(e.quando)}${per}${conBollo}`;
        case 'non_partita':
            return `Non partita il ${quando(e.quando)}${per}${conBollo}${e.errore ? `: ${e.errore}` : ''}`;
        case 'non_da_comunicare':
            return `Segnata il ${quando(e.quando)}: non serve comunicare l'annullamento alla persona`;
        default:
            return e.stato;
    }
}

export interface PersonaAnteprima {
    id: number;
    nome: string;
    indirizzi: Array<{ tipo: string; indirizzo: string }>;
    ricevute: Array<{ numero: string; per_conto_di: string | null }>;
    messaggi: number;
}

/** I conteggi di chi resta nell'invio, tolte le persone che l'amministratore ha escluso dall'elenco «chi riceve che cosa». */
export function riepilogoDelleScelte(persone: PersonaAnteprima[], escluse: number[]): { persone: number; ricevute: number; indirizzi: number; messaggi: number } {
    const rimaste = persone.filter((p) => !escluse.includes(p.id));

    return {
        persone: rimaste.length,
        ricevute: rimaste.reduce((n, p) => n + p.ricevute.length, 0),
        indirizzi: rimaste.reduce((n, p) => n + p.indirizzi.length, 0),
        messaggi: rimaste.reduce((n, p) => n + p.messaggi, 0),
    };
}

/** Le persone a cui mandare, se qualcuna è stata tolta; altrimenti niente (il server manda a tutte). */
export function soloPersoneDaMandare(persone: PersonaAnteprima[], escluse: number[]): number[] | undefined {
    return escluse.length === 0 ? undefined : persone.filter((p) => !escluse.includes(p.id)).map((p) => p.id);
}

interface EsitoConSenzaIndirizzo {
    senza_indirizzo: number;
    senza_indirizzo_incassi: number[];
    senza_indirizzo_persone: string[];
}

/** Dopo «Invia le altre» l'esito è quello del secondo invio, che conosce solo le persone rimaste: tiene anche chi era senza indirizzo nel primo. */
export function unisciEsiti<T extends EsitoConSenzaIndirizzo>(prima: EsitoConSenzaIndirizzo | null, dopo: T): T {
    if (prima === null) {
        return dopo;
    }
    const unione = <V>(a: V[], b: V[]): V[] => [...a, ...b.filter((x) => !a.includes(x))];

    return {
        ...dopo,
        senza_indirizzo_incassi: unione(prima.senza_indirizzo_incassi, dopo.senza_indirizzo_incassi),
        senza_indirizzo_persone: unione(prima.senza_indirizzo_persone, dopo.senza_indirizzo_persone),
        senza_indirizzo: unione(prima.senza_indirizzo_incassi, dopo.senza_indirizzo_incassi).length,
    };
}

/**
 * La conferma di «Invia di nuovo»: a chi va e con quale bollo, perché un clic sbagliato manda un'email. L'indirizzo è quello a cui la ricevuta va
 * adesso (`manda_a`: per primaria, secondaria e PEC quello di oggi dell'anagrafica), non quello della volta prima; il bollo è quello della riga che
 * si rimanda.
 */
export function testoConfermaInviaDiNuovo(e: { manda_a?: string | null; destinatario: string | null; bollo?: string | null; modo_bollo?: string | null }): string {
    const bollo = testoBollo(e.bollo, e.modo_bollo);

    return `Mando di nuovo la ricevuta a ${e.manda_a ?? e.destinatario ?? 'questo indirizzo'}${bollo ? `, con ${bollo}` : ''}.`;
}

/** La conferma di «Non serve comunicarla»: il numero dell'incasso, e che la segnatura resta (non c'è un modo di toglierla). */
export function testoConfermaNonServe(numero: string): string {
    return `L'incasso n. ${numero} è annullato: segno che alla persona non serve comunicarlo. `
        + 'L\'avviso si toglie, e la segnatura resta fra le emissioni dell\'incasso: non si annulla.';
}

/** La conferma di «Riprova le non partite»: quanti invii, con lo stesso bollo di prima, e a quali indirizzi vanno (quelli di oggi delle persone). */
export function testoConfermaRiprova(n: number): string {
    return `Rimando ${n === 1 ? '1 invio non partito' : `${n} invii non partiti`}, con lo stesso stato del bollo di prima. `
        + 'Vanno agli indirizzi che le persone hanno oggi; un indirizzo scritto a mano resta quello.';
}

/** Le mai emesse di incassi registrati da poco con un versamento più vecchio del periodo della pagina. */
export function testoRegistratiDiRecente(r: { numero: number; dal: string }): string {
    const dal = r.dal.split('-').reverse().join('/');

    return r.numero === 1
        ? `1 ricevuta mai emessa è di un incasso registrato da poco con un versamento del ${dal}, più vecchio del periodo.`
        : `${r.numero} ricevute mai emesse sono di incassi registrati da poco con un versamento più vecchio del periodo, il primo del ${dal}.`;
}

/** Quante delle selezionate hanno una causale: «Stampa la causale» scrive un testo libero, che può portare note interne. */
export function testoCausali(a: { con_causale: number; causali: Array<{ testo: string; quante: number }> }): string | null {
    if (a.con_causale <= 0) {
        return null;
    }

    return a.con_causale === 1 ? '1 delle selezionate ha una causale.' : `${a.con_causale} delle selezionate hanno una causale.`;
}

/** Il pulsante della parte di PDF: la prima, le altre, l'ultima. */
export function etichettaParteDelPdf(parti: number[][], indice: number): string {
    if (indice === 0) {
        return `Stampa le prime ${parti[0].length}`;
    }

    return indice === parti.length - 1 ? 'Stampa l\'ultima' : `Stampa le altre ${parti[indice].length}`;
}

// ── Il PDF in una scheda nuova ────────────────────────────────────────────

/** La riga che la scheda nuova mostra mentre il PDF si prepara. */
export const RIGA_ATTESA_PDF = 'Sto preparando il PDF: si apre qui fra un momento.';

/**
 * Com'è andata: il PDF è nella scheda aperta dal clic; oppure (scheda bloccata, o chiusa prima che arrivasse) è scaricato con il suo nome; oppure
 * chi lavora ha chiuso la scheda e il file non serviva (l'anteprima): niente si è aperto e niente si è scaricato.
 */
export interface PdfArrivato { modo: 'scheda' | 'scaricato' | 'chiusa'; nome: string }

export interface AmbientePdf {
    apriScheda: () => Window | null;
    indirizzoDelFile: (file: Blob) => string;
    liberaAllaChiusura: (scheda: Window, url: string) => void;
    scarica: (file: Blob, nome: string) => void;
}

/** La scheda vuota del clic, con la riga d'attesa. Senza `opener`: il PDF non sa da quale pagina è stato aperto. */
export function apriSchedaDiAttesa(apri: () => Window | null = () => window.open('', '_blank')): Window | null {
    const scheda = apri();
    if (!scheda) {
        return null;
    }
    try {
        scheda.opener = null;
        const d = scheda.document;
        d.title = 'PDF delle ricevute';
        const riga = d.createElement('p');
        riga.textContent = RIGA_ATTESA_PDF;
        riga.setAttribute('style', 'font: 16px system-ui, sans-serif; margin: 2rem; color: #475569;');
        d.body.appendChild(riga);
    } catch {
        // Una scheda che non si lascia scrivere resta vuota: il PDF ci arriva lo stesso.
    }

    return scheda;
}

/**
 * L'indirizzo del PDF si libera quando la scheda che lo mostra si chiude, non prima: finché è aperta, ricaricarla o salvare il PDF dal
 * visore lo rilegge da lì. Il browser non dice quando una scheda si chiude: lo si guarda ogni tanto. Se si chiude la pagina, il browser
 * libera da sé tutti i suoi indirizzi.
 */
export function liberaQuandoSiChiude(scheda: Window, url: string, ogniMs = 5000): void {
    const controllo = setInterval(() => {
        if (scheda.closed) {
            clearInterval(controllo);
            URL.revokeObjectURL(url);
        }
    }, ogniMs);
}

const ambientePdfDelBrowser = (): AmbientePdf => ({
    apriScheda: () => apriSchedaDiAttesa(),
    indirizzoDelFile: (file) => URL.createObjectURL(file),
    liberaAllaChiusura: liberaQuandoSiChiude,
    scarica: scaricaFile,
});

/**
 * Un PDF del server (stampa o anteprima) in una scheda nuova. La scheda si apre subito, prima di ogni attesa: i blocchi dei popup lasciano
 * aprire solo la scheda chiesta nel gesto del clic, e chi chiama deve arrivare qui senza un `await` prima. Poi il PDF si chiede con una
 * richiesta in sottofondo (`chiedi`, un POST: la stampa scrive il registro) e arrivato si mostra nella scheda. Prima, con un modulo
 * nascosto mandato nella scheda nuova, un browser che riapre la scheda con un GET riceveva un 405.
 *
 * - Se la richiesta fallisce la scheda si chiude e l'errore torna a chi chiama, che scrive la frase dove scrive gli altri errori. Lo stesso se
 *   la risposta arriva ma non è un PDF (`RispostaNonValida`).
 * - Se la scheda non c'è (bloccata) il PDF si scarica con il nome che dà il server. Se chi lavora la chiude prima che il PDF arrivi, il PDF si
 *   scarica (`scaricaSeChiusa`, di serie): la stampa ha già scritto il registro e il file serve. L'anteprima non scrive niente, e chi ha chiuso
 *   la scheda non la voleva più: con `scaricaSeChiusa: false` niente si scarica e l'esito è `chiusa`.
 * - `seLaScheda` sa subito, prima dell'attesa, se la scheda si è aperta: chi chiama può dire mentre aspetta dove andrà il PDF (qualche browser
 *   integrato in un'applicazione non apre mai la scheda, neanche nel clic: lì il PDF si scarica sempre).
 */
export async function apriPdfInUnaScheda(
    chiedi: () => Promise<{ data: unknown; headers?: Record<string, unknown> }>,
    riserva: string,
    ambiente: AmbientePdf = ambientePdfDelBrowser(),
    seLaScheda?: (aperta: boolean) => void,
    { scaricaSeChiusa = true }: { scaricaSeChiusa?: boolean } = {},
): Promise<PdfArrivato> {
    const scheda = ambiente.apriScheda();
    seLaScheda?.(scheda !== null);
    let risposta: { data: unknown; headers?: Record<string, unknown> };
    try {
        risposta = await chiedi();
        // Una risposta di buon esito che non è un PDF (la pagina di accesso, arrivata con un rimando seguito) non va nella scheda né fra i file.
        await verificaIlFile(risposta, 'pdf');
    } catch (e) {
        if (scheda && !scheda.closed) {
            scheda.close();
        }
        throw e;
    }
    const dati = risposta.data;
    // Il file porta il tipo PDF con sé, perché il visore della scheda lo riconosca anche se l'intestazione era l'unica a dirlo.
    const pdf = dati instanceof Blob ? dati.slice(0, dati.size, 'application/pdf') : new Blob([dati as BlobPart], { type: 'application/pdf' });
    const nome = nomeDalContentDisposition(risposta.headers?.['content-disposition'] as string | undefined, riserva);
    if (scheda && !scheda.closed) {
        const url = ambiente.indirizzoDelFile(pdf);
        scheda.location.href = url;
        ambiente.liberaAllaChiusura(scheda, url);

        return { modo: 'scheda', nome };
    }
    if (scheda && !scaricaSeChiusa) {
        return { modo: 'chiusa', nome };
    }
    ambiente.scarica(pdf, nome);

    return { modo: 'scaricato', nome };
}

/**
 * Che cosa è successo, dopo un PDF arrivato: la scheda aperta o il file scaricato, e per la stampa che le ricevute risultano stampate.
 * L'anteprima nella sua scheda non ha niente da dire. Il file scaricato non è ancora sul computer quando la frase compare: il browser
 * può chiedere prima dove salvarlo. La stampa invece è già nel registro. Le lettere usano la stessa frase con la loro parola.
 */
export function testoDelPdf(p: PdfArrivato, cosa: { stampa: boolean; una: boolean; documento?: 'ricevuta' | 'lettera' }): string | null {
    const [una, piu] = cosa.documento === 'lettera' ? ['La lettera', 'Le lettere'] : ['La ricevuta', 'Le ricevute'];
    if (p.modo === 'chiusa') {
        return 'Hai chiuso la scheda: l\'anteprima non si è aperta.';
    }
    if (p.modo === 'scheda') {
        return cosa.stampa ? `Il PDF è aperto in una scheda nuova. ${cosa.una ? `${una} risulta stampata.` : `${piu} risultano stampate.`}` : null;
    }
    const stampate = cosa.una ? `${una} risulta già stampata` : `${piu} risultano già stampate`;

    return `La scheda nuova non si è aperta, o era già chiusa: il PDF si scarica con il nome «${p.nome}». Se il browser chiede dove salvarlo, `
        + `arriva quando rispondi.${cosa.stampa ? ` ${stampate}, anche se il file arriva dopo.` : ''}`;
}

// ── «Prepara l'email» e i file scaricati (decisione 207) ──────────────────

/** L'oggetto e il testo del messaggio della ricevuta, come li scrive il server: gli stessi del messaggio che il programma manda. */
export interface BozzaEmail { oggetto: string; saluto: string; saluti: Record<string, string>; righe: string[]; chiusura: string }

/** Il testo dell'email: il saluto della persona a cui va (o quello generico), le righe, la chiusura; a capo come le email. */
export function testoDellaBozza(b: BozzaEmail, anagraficaId: number | null): string {
    const saluto = (anagraficaId !== null ? b.saluti[String(anagraficaId)] : undefined) ?? b.saluto;

    return [saluto, '', ...b.righe, '', b.chiusura].join('\r\n');
}

/** Un collegamento `mailto:` con i destinatari, l'oggetto e il testo. Un `mailto:` non porta allegati, in nessun browser: il PDF si allega a mano. */
export function indirizzoMailto(destinatari: string[], oggetto: string, testo: string): string {
    const a = destinatari.map((d) => encodeURIComponent(d.trim()).replace(/%40/gi, '@')).join(',');

    return `mailto:${a}?subject=${encodeURIComponent(oggetto)}&body=${encodeURIComponent(testo)}`;
}

/** Il nome del file che il server dà nella risposta (`Content-Disposition`), o quello di riserva. */
export function nomeDalContentDisposition(intestazione: string | null | undefined, riserva: string): string {
    const trovato = /filename="?([^";]+)"?/i.exec(intestazione ?? '');

    return trovato ? trovato[1].trim() : riserva;
}

/** Salva un file sul computer di chi lavora: un collegamento con `download`, cliccato e tolto. */
export function scaricaFile(file: Blob, nome: string): void {
    const url = URL.createObjectURL(file);
    const collegamento = document.createElement('a');
    collegamento.href = url;
    collegamento.download = nome;
    collegamento.style.display = 'none';
    document.body.appendChild(collegamento);
    collegamento.click();
    collegamento.remove();
    setTimeout(() => URL.revokeObjectURL(url), 60_000);
}

interface AmbientePreparaEmail {
    navigatore: Navigator;
    scarica: (file: Blob, nome: string) => void;
    apri: (url: string) => void;
}

/** Apre un indirizzo come lo aprirebbe un clic su un collegamento: per `mailto:` il programma di posta, senza lasciare la pagina. */
export function apriCollegamento(url: string): void {
    const collegamento = document.createElement('a');
    collegamento.href = url;
    collegamento.style.display = 'none';
    document.body.appendChild(collegamento);
    collegamento.click();
    collegamento.remove();
}

const ambienteDelBrowser = (): AmbientePreparaEmail => ({
    navigatore: navigator,
    scarica: scaricaFile,
    apri: apriCollegamento,
});

/**
 * «Prepara l'email»: dove il browser condivide i file (Web Share con `files`) apre la condivisione con il PDF allegato, l'oggetto e il testo, e
 * chi lavora sceglie il programma di posta; altrimenti scarica il PDF e apre l'email (`mailto:`) con il destinatario, l'oggetto e il testo, e il
 * PDF si allega a mano. Se il browser rifiuta la condivisione (per esempio perché il gesto dell'utente è scaduto mentre il PDF si preparava) si
 * passa all'email; se chi lavora chiude la condivisione, non succede altro.
 */
export async function preparaEmail(
    pdf: Blob,
    nome: string,
    email: { a: string[]; oggetto: string; testo: string },
    ambiente: AmbientePreparaEmail = ambienteDelBrowser(),
): Promise<'condivisa' | 'scaricata' | 'annullata'> {
    const nav = ambiente.navigatore as Navigator & { canShare?: (d: ShareData) => boolean; share?: (d: ShareData) => Promise<void> };
    if (typeof nav?.canShare === 'function' && typeof nav.share === 'function' && typeof File !== 'undefined') {
        const file = new File([pdf], nome, { type: 'application/pdf' });
        if (nav.canShare({ files: [file] })) {
            try {
                await nav.share({ files: [file], title: email.oggetto, text: email.testo });

                return 'condivisa';
            } catch (e) {
                if ((e as { name?: string } | null)?.name === 'AbortError') {
                    return 'annullata';
                }
            }
        }
    }
    ambiente.scarica(pdf, nome);
    ambiente.apri(indirizzoMailto(email.a, email.oggetto, email.testo));

    return 'scaricata';
}

/**
 * L'errore di una richiesta che aspettava un file: il server risponde con un testo, che arriva come file anch'esso. La sessione scaduta (401,
 * 419), il permesso che manca (403) e gli errori del server (500 e oltre) arrivano con la frase inglese del framework: si leggono in italiano,
 * come il 429.
 */
export async function fraseDaErroreConFile(e: unknown, predefinita: string): Promise<string> {
    if (e instanceof RispostaNonValida) {
        return e.frase;
    }
    const risposta = (e as { response?: { status?: number; data?: unknown } } | null)?.response;
    if (risposta?.status === 429) {
        return FRASE_LIMITE_ALTRO_INDIRIZZO;
    }
    if (sessioneScaduta(e)) {
        return FRASE_SESSIONE_SCADUTA;
    }
    if (risposta?.status === 403) {
        return FRASE_NON_PERMESSO;
    }
    if ((risposta?.status ?? 0) >= 500) {
        return predefinita;
    }
    const dati = risposta?.data;
    if (dati instanceof Blob) {
        const testo = (await dati.text()).trim();
        try {
            const json = JSON.parse(testo) as { message?: string };
            return json.message || predefinita;
        } catch {
            return testo || predefinita;
        }
    }

    return frasePerErrore(e, predefinita);
}
