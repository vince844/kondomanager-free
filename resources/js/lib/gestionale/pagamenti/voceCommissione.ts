/**
 * La voce della commissione nel modulo del pagamento al fornitore: quali voci si offrono, quale si propone, quando il modulo non si invia.
 *
 * Il server manda le voci di spesa del condominio con la loro gestione (`VoceDellaCommissione::elenco`) e, per ogni gestione, la voce
 * dell'ultima commissione registrata (`VoceDellaCommissione::proposte`). La pagina offre solo le voci della gestione del pagamento, che è quella
 * delle fatture scelte (un pagamento non copre fatture di due gestioni). Una voce proposta che non è fra quelle offerte (cancellata, o di
 * un'altra gestione) lascia il campo vuoto: mai una voce sbagliata.
 *
 * La modifica di un pagamento scritto prima senza voce (la commissione sulle «Spese bancarie» di sistema) può lasciarlo com'era, finché la commissione non
 * cambia: fra le scelte del campo c'è «Lascia senza voce, come era», che non è una voce e al server parte come nessuna voce.
 */

export interface VoceCommissione {
    id: number;
    label: string;
    gestione_id: number;
}

export interface PropostaCommissione {
    gestione_id: number;
    voce_id: number | null;
}

/** La frase del server (`VoceDellaCommissione::SENZA_VOCE`): la pagina la dice sul campo prima di inviare. */
export const FRASE_SENZA_VOCE = 'Scegli la voce della commissione: senza una voce la commissione resta fuori dal consuntivo delle voci.';

/** La via d'uscita per una voce di un'altra gestione (`VoceDellaCommissione::ALTRA_GESTIONE`), detta sotto il campo. */
export const FRASE_ALTRA_GESTIONE = 'Per metterla su una voce di un\'altra gestione, per esempio le spese bancarie dell\'ordinaria, lascia la commissione a zero e '
    + 'registrala con una regolazione immediata.';

/** Le voci della gestione del pagamento; senza gestione (nessuna fattura scelta) nessuna. */
export function vociDellaGestione<T extends VoceCommissione>(voci: T[], gestioneId: number | null): T[] {
    return gestioneId === null ? [] : voci.filter((v) => v.gestione_id === gestioneId);
}

/** La gestione delle fatture scelte, se è una sola. */
export function gestioneDelPagamento(fatture: Array<{ gestione_id?: number | null }>): number | null {
    const gestioni = [...new Set(fatture.map((f) => f.gestione_id).filter((g): g is number => typeof g === 'number'))];

    return gestioni.length === 1 && fatture.every((f) => typeof f.gestione_id === 'number') ? gestioni[0] : null;
}

/** La voce proposta per la gestione, se è ancora fra quelle offerte. */
export function vocePropostaPer(proposte: PropostaCommissione[], voci: VoceCommissione[], gestioneId: number | null): number | null {
    if (gestioneId === null) return null;
    const voce = proposte.find((p) => p.gestione_id === gestioneId)?.voce_id ?? null;

    return voce !== null && vociDellaGestione(voci, gestioneId).some((v) => v.id === voce) ? voce : null;
}

/** La voce dopo un cambio di gestione: quella scelta resta se è della gestione, altrimenti la proposta della gestione. */
export function voceDopoIlCambio(attuale: number | null, voci: VoceCommissione[], proposte: PropostaCommissione[], gestioneId: number | null): number | null {
    if (gestioneId === null) return null;
    if (attuale !== null && vociDellaGestione(voci, gestioneId).some((v) => v.id === attuale)) return attuale;

    return vocePropostaPer(proposte, voci, gestioneId);
}

/** Con una commissione più di zero (in euro, come la casella) la voce si sceglie. */
export function mancaLaVoce(commissioneEuro: number, voceId: number | null): boolean {
    return (Number(commissioneEuro) || 0) > 0 && voceId === null;
}

/** La scelta «Lascia senza voce, come era» del campo della voce, in modifica: non è l'id di una voce (gli id partono da 1). */
export const LASCIA_SENZA_VOCE = 0;

export const ETICHETTA_LASCIA_SENZA_VOCE = 'Lascia senza voce, come era';

/**
 * La scelta c'è solo per un pagamento scritto prima senza voce (lo dice il server) e finché la commissione non cambia: la stessa cifra di quella
 * registrata, in centesimi (la casella è in euro). Con la commissione cambiata, o tolta, la voce si sceglie.
 */
export function puoLasciareSenzaVoce(scrittoSenzaVoce: boolean, registrataCents: number, commissioneEuro: number): boolean {
    const cents = Math.round((Number(commissioneEuro) || 0) * 100);

    return scrittoSenzaVoce && cents > 0 && cents === registrataCents;
}

/** La voce da mandare al server: «Lascia senza voce, come era» non è una voce, parte come nessuna voce. */
export function voceDaInviare(voceId: number | null): number | null {
    return voceId === LASCIA_SENZA_VOCE ? null : voceId;
}
