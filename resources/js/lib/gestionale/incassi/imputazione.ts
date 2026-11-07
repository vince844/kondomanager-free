/**
 * L'imputazione dichiarata nel modulo dell'incasso (1.12.0-beta.3, decisioni 217 e 218).
 *
 * L'ordine lo calcola il server, una volta sola (`OrdineImputazione`): la situazione debitoria manda le righe in quell'ordine, la rotta
 * `movimenti-rate.ordine` dice prima di salvare se la distribuzione lo segue e, se no, la rata più vecchia che resta aperta (anche di
 * un'altra unità o di un'altra gestione), e il salvataggio rifà lo stesso calcolo e lo scrive. Qui solo le frasi e che cosa manca: il modulo
 * chiede chi ha scelto a che cosa va il versamento quando si discosta, e niente è già scelto (salvo l'arrivo dal collegamento del portale,
 * che è un pagamento segnalato da chi ha versato).
 */

export type EsitoImputazione = 'ordine_scadenze' | 'fuori_ordine' | 'compensazione';
export type SceltaImputazione = 'indicata_dal_versante' | 'scelta_amministratore';

/** La rata più vecchia che l'incasso lascia aperta, come la manda il server; `resta` in centesimi, la scadenza `aaaa-mm-gg`. */
export interface RataAperta {
    rata_id: number;
    numero_rata: number;
    descrizione: string;
    scadenza: string;
    emessa: boolean;
    gestione: string | null;
    unita: string;
    resta: number;
}

/** La risposta della rotta `movimenti-rate.ordine`. */
export interface RispostaOrdine {
    esito: EsitoImputazione;
    rata_aperta: RataAperta | null;
    /** A scostarsi è la parte in più che diventa credito mentre una rata resta aperta. */
    parte_in_piu: boolean;
}

/**
 * Le due dichiarazioni, nell'ordine. La nota è facoltativa per tutte e due (decisione 219): chi registra cento incassi fuori ordine non
 * scrive cento volte la stessa frase. La nota di «indicata da chi ha versato» si stampa sulla ricevuta (decisione 218, punto 4: sono parole
 * sue); quella di «scelta da te» resta interna. L'invito e la riga sotto il campo (`dove`) lo dicono.
 */
export const SCELTE_IMPUTAZIONE: ReadonlyArray<{ valore: SceltaImputazione; titolo: string; invito: string; dove: string; notaObbligatoria: boolean }> = [
    { valore: 'indicata_dal_versante', titolo: 'Indicata da chi ha versato', invito: 'Nota, se vuoi: va sulla ricevuta (per esempio la causale del bonifico)', dove: 'Si stampa sulla ricevuta: scrivi le parole di chi ha versato, non le tue.', notaObbligatoria: false },
    { valore: 'scelta_amministratore', titolo: 'Scelta da te', invito: 'Perché, se vuoi, in una riga: resta interna, non va sulla ricevuta', dove: 'Resta qui: non va sulla ricevuta.', notaObbligatoria: false },
];

const gg = (data: string) => data.slice(0, 10).split('-').reverse().join('/');

/**
 * La frase sopra la scelta: quale rata resta aperta, e quanto; se non è ancora emessa, lo dice (con una rata approvata e non emessa, con
 * la scadenza passata, la domanda compare a ogni incasso, e chi la legge deve capire perché). Con `aSchermo`, se la rata non è fra le righe
 * a schermo (il filtro della gestione, la ricerca per unità) dice come vederla. Null se la distribuzione segue l'ordine o la risposta non c'è.
 */
export function fraseFuoriOrdine(risposta: RispostaOrdine | null, euro: (centesimi: number) => string, aSchermo?: (rataId: number) => boolean, versatoDa?: string | null): string | null {
    const r = risposta?.esito === 'fuori_ordine' ? risposta.rata_aperta : null;
    if (!r) return null;

    const dove = [`scadenza ${gg(r.scadenza)}`, r.emessa ? null : 'non ancora emessa', r.unita, r.gestione].filter(Boolean).join(', ');
    const rata = `${r.descrizione} (${dove}) per ${euro(r.resta)}.`;
    const comeVederla = aSchermo && !aSchermo(r.rata_id) ? ' Non è fra le righe a schermo: per vederla togli il filtro della gestione o cerca per persona.' : '';

    // Con «Versato da» la parte in più va su una rata di chi ha versato (`StoreIncassoRateAction::quotaDiChiHaVersato`): ne paga quanto
    // resta da pagare, e solo il resto diventa un suo credito.
    const parteInPiu = versatoDa
        ? `La parte in più va su una rata di ${versatoDa} (ne paga quanto resta da pagare; il resto diventa un suo credito), mentre resta aperta ${rata}`
        : `La parte in più resta a credito mentre è ancora aperta ${rata}`;

    return (risposta!.parte_in_piu ? parteInPiu : `Questo versamento non segue l'ordine delle scadenze: resta aperta ${rata}`) + comeVederla;
}

/** Che cosa manca prima di confermare, o null. */
export function imputazioneMancante(risposta: RispostaOrdine | null, scelta: SceltaImputazione | null, _nota?: string | null): string | null {
    if (risposta?.esito !== 'fuori_ordine') return null;
    if (!scelta) return 'Il versamento non segue l\'ordine delle scadenze: scegli chi ha deciso a che cosa va.';

    return null;
}

/** I dati della richiesta che cambiano l'esito dell'ordine. */
export interface DatiOrdine {
    pagante_id: number | null;
    versato_da_id: number | null;
    gestione_id: number | null;
    importo_totale: number | string;
    eccedenza: number;
    credito_prima: boolean | null;
    credito_fra_gestioni: boolean | null;
    dettaglio_pagamenti: Array<{ rata_id: number; importo: number }>;
}

/**
 * La firma di una distribuzione: due richieste con la stessa firma hanno lo stesso esito. Serve a sapere se la risposta che il modulo ha è
 * ancora quella della distribuzione a schermo (una risposta per una distribuzione cambiata nel frattempo non vale).
 */
export function firmaOrdine(d: DatiOrdine): string {
    return JSON.stringify([d.pagante_id, d.versato_da_id, d.gestione_id, Number(d.importo_totale) || 0, d.eccedenza, d.credito_prima, d.credito_fra_gestioni,
        d.dettaglio_pagamenti.map((p) => [p.rata_id, p.importo])]);
}
