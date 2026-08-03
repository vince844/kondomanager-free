/**
 * Il prospetto di una delega F24: i campi da trascrivere, nell'ordine del modello.
 *
 * Non è il modello ministeriale — quello è la Fase 6 del design e serve a pagare allo
 * sportello. Questo è il documento che l'amministratore ha davvero in mano quando compila
 * l'F24 nell'home banking o su F24 online, dove i campi si **digitano**: per questo l'ordine
 * e i nomi ricalcano la sezione Erario del modello, così la trascrizione è uno a uno e non
 * richiede di interpretare niente.
 *
 * La controparte PHP è `RigaF24::periodoLeggibile()`. Le due devono dire la stessa cosa: la
 * lezione della beta.35 è che un numero calcolato due volte in due linguaggi va fissato da
 * **entrambi** i lati, perché nessuna delle due copie è sbagliata da sola.
 */

export interface RigaDelega {
    ordine: number;
    codice_tributo: string;
    /** Quattro caratteri, come nel tracciato: `'0003'` per marzo. Mai un intero. */
    rateazione_mese_rif: string;
    anno_riferimento: string;
    importo_debito: number;
}

export interface RigaProspetto {
    ordine: number;
    codiceTributo: string;
    rateazione: string;
    mese: string;
    anno: string;
    periodo: string;
    importoDebito: number;
}

/** Lo stato di una scadenza, per il colore e per l'ordine di lettura. */
export type UrgenzaScadenza = 'scaduta' | 'urgente' | 'prossima' | 'lontana';

/**
 * Perché questa delega matura in questa data.
 *
 * Il server salva un **codice**, non la frase: la frase è testo di sistema, e persisterla
 * significa congelarla — correggendo una parola, tutte le deleghe già calcolate resterebbero
 * con la versione vecchia. Tradotta qui, si cambia senza toccare il database.
 */
export function descrizioneMotivo(codice: string | null | undefined): string {
    switch (codice) {
        case 'soglia_raggiunta':
            return 'La soglia di legge è stata raggiunta: si versa il 16 del mese successivo.';
        case 'termine_di_legge':
            return 'Termine di legge: si versa anche restando sotto soglia.';
        case 'ritenute_dicembre':
            return 'Ritenute operate a dicembre: il termine è il 16 gennaio.';
        case 'mensile_obbligatorio':
            return 'Versamento mensile obbligatorio: per questo regime non è ammesso il rinvio.';
        default:
            return '';
    }
}

/**
 * Il periodo di riferimento in forma leggibile: `'0003'` + `'2026'` → `03/2026`.
 *
 * Ricalca `RigaF24::periodoLeggibile()`, che fa `substr($mese, 2, 2).'/'.$anno`. I primi due
 * caratteri del campo non sono il mese: nel tracciato la posizione ospita anche forme
 * diverse, ed è il motivo per cui il campo è una stringa di quattro caratteri e non un
 * numero.
 */
export function periodoLeggibile(rateazioneMeseRif: string, annoRiferimento: string): string {
    const mese = String(rateazioneMeseRif ?? '').slice(2, 4);

    return `${mese}/${annoRiferimento ?? ''}`;
}

/**
 * Le righe pronte da trascrivere, nell'ordine del modello.
 *
 * L'ordine è quello del campo `ordine`, che il server assegna alla generazione: non va
 * riordinato per importo o per data, perché è l'ordine con cui le righe stanno sul modello
 * e riordinarle renderebbe la trascrizione soggetta a errore.
 */
export function righeProspetto(righe: RigaDelega[]): RigaProspetto[] {
    return [...(righe ?? [])]
        .sort((a, b) => a.ordine - b.ordine)
        .map((r) => ({
            ordine: r.ordine,
            codiceTributo: r.codice_tributo,
            rateazione: r.rateazione_mese_rif,
            mese: String(r.rateazione_mese_rif ?? '').slice(2, 4),
            anno: r.anno_riferimento,
            periodo: periodoLeggibile(r.rateazione_mese_rif, r.anno_riferimento),
            importoDebito: Number(r.importo_debito) || 0,
        }));
}

/**
 * Il totale a debito, in centesimi.
 *
 * Somma le righe invece di leggere il totale della testata: se i due divergono è un difetto,
 * e va visto — non nascosto mostrando comunque il numero della testata.
 */
export function totaleDebito(righe: RigaDelega[]): number {
    return (righe ?? []).reduce((somma, r) => somma + (Number(r.importo_debito) || 0), 0);
}

/**
 * Giorni che mancano alla scadenza. Negativo se è già passata.
 *
 * Confronta le date a mezzanotte: contare le ore produrrebbe «0 giorni» per una scadenza di
 * stamattina e «1 giorno» per una di stasera, che per una scadenza fiscale è la stessa cosa.
 */
export function giorniAllaScadenza(dataScadenza: string, oggi: Date): number {
    const scadenza = new Date(`${String(dataScadenza).slice(0, 10)}T00:00:00`);
    const riferimento = new Date(oggi.getFullYear(), oggi.getMonth(), oggi.getDate());

    return Math.round((scadenza.getTime() - riferimento.getTime()) / 86_400_000);
}

/**
 * Quanto è urgente una scadenza.
 *
 * Le soglie non sono estetiche: sette giorni è il margine entro cui un bonifico disposto
 * oggi arriva comunque in tempo, trenta è l'orizzonte del mese corrente.
 */
export function urgenzaScadenza(dataScadenza: string, oggi: Date): UrgenzaScadenza {
    const giorni = giorniAllaScadenza(dataScadenza, oggi);

    if (giorni < 0) return 'scaduta';
    if (giorni <= 7) return 'urgente';
    if (giorni <= 30) return 'prossima';

    return 'lontana';
}

/**
 * Il testo che accompagna la scadenza. Al singolare quando serve: «manca 1 giorno», non
 * «mancano 1 giorni» — è il genere di dettaglio che fa sembrare un software finito.
 */
export function testoScadenza(dataScadenza: string, oggi: Date): string {
    const giorni = giorniAllaScadenza(dataScadenza, oggi);

    if (giorni === 0) return 'Scade oggi';
    if (giorni === 1) return 'Manca 1 giorno';
    if (giorni > 1) return `Mancano ${giorni} giorni`;
    if (giorni === -1) return 'Scaduta da 1 giorno';

    return `Scaduta da ${Math.abs(giorni)} giorni`;
}
