/**
 * La voce della commissione nel modulo del pagamento al fornitore: quali voci si offrono, quale si propone, quando il modulo non si invia.
 *
 * Il server manda le voci di spesa del condominio con la loro gestione (`VoceDellaCommissione::elenco`: il nome, il tipo e gli esercizi della gestione) e,
 * per ogni gestione, la voce dell'ultima commissione registrata (`VoceDellaCommissione::proposte`). La pagina offre in cima le voci della gestione del
 * pagamento, che è quella delle fatture scelte (un pagamento non copre fatture di due gestioni), e sotto quelle delle gestioni ordinarie dello stesso
 * esercizio, con il nome della gestione (Coda 553: la commissione dei lavori sulle spese bancarie dell'ordinaria). Una voce proposta che non è fra quelle
 * offerte (cancellata, o di una gestione che non c'è nell'esercizio) lascia il campo vuoto: mai una voce sbagliata.
 *
 * La modifica di un pagamento scritto prima senza voce (la commissione sulle «Spese bancarie» di sistema) può lasciarlo com'era, finché la commissione non
 * cambia: fra le scelte del campo c'è «Lascia senza voce, come era», che non è una voce e al server parte come nessuna voce.
 */

export interface VoceCommissione {
    id: number;
    label: string;
    gestione_id: number;
    gestione_nome?: string;
    gestione_tipo?: string;
    /** Gli esercizi a cui è legata la gestione della voce. */
    esercizi?: number[];
}

export interface PropostaCommissione {
    gestione_id: number;
    voce_id: number | null;
}

/** La frase del server (`VoceDellaCommissione::SENZA_VOCE`): la pagina la dice sul campo prima di inviare. */
export const FRASE_SENZA_VOCE = 'Scegli la voce della commissione: senza una voce la commissione resta fuori dal consuntivo delle voci.';

/** Che cosa offre il campo, detto sotto il campo (Coda 553). */
export const FRASE_VOCI_OFFERTE = 'In cima le voci della gestione del pagamento; sotto, quelle dell\'ordinaria dello stesso esercizio, per esempio le spese bancarie.';

/** Le voci della gestione del pagamento; senza gestione (nessuna fattura scelta) nessuna. */
export function vociDellaGestione<T extends VoceCommissione>(voci: T[], gestioneId: number | null): T[] {
    return gestioneId === null ? [] : voci.filter((v) => v.gestione_id === gestioneId);
}

/**
 * Le voci offerte per la commissione: in cima quelle della gestione del pagamento, poi quelle delle gestioni ordinarie dello stesso esercizio, con il nome
 * della gestione accanto. Senza gestione nessuna; senza esercizio solo quelle della gestione. `soloOrdinarie` (lo storno nell'anno dopo di una commissione
 * che era su una gestione che l'esercizio non ha): solo quelle delle ordinarie dell'esercizio, anche della gestione del pagamento se è una di loro.
 */
export function vociOfferte<T extends VoceCommissione>(voci: T[], gestioneId: number | null, esercizioId: number | null, soloOrdinarie = false): T[] {
    if (gestioneId === null) return [];
    const ordinarieDellEsercizio = (v: T) => esercizioId !== null && v.gestione_tipo === 'ordinaria' && (v.esercizi ?? []).includes(esercizioId);
    const conLaGestione = (v: T) => ({ ...v, label: v.gestione_nome ? `${v.label} · ${v.gestione_nome}` : v.label });
    if (soloOrdinarie) return voci.filter(ordinarieDellEsercizio).map(conLaGestione);
    const altre = voci.filter((v) => v.gestione_id !== gestioneId && ordinarieDellEsercizio(v)).map(conLaGestione);

    return [...vociDellaGestione(voci, gestioneId), ...altre];
}

/** La gestione delle fatture scelte, se è una sola. */
export function gestioneDelPagamento(fatture: Array<{ gestione_id?: number | null }>): number | null {
    const gestioni = [...new Set(fatture.map((f) => f.gestione_id).filter((g): g is number => typeof g === 'number'))];

    return gestioni.length === 1 && fatture.every((f) => typeof f.gestione_id === 'number') ? gestioni[0] : null;
}

/** La voce proposta per la gestione, se è ancora fra quelle offerte per l'esercizio. */
export function vocePropostaPer(proposte: PropostaCommissione[], voci: VoceCommissione[], gestioneId: number | null, esercizioId: number | null = null,
    soloOrdinarie = false): number | null {
    if (gestioneId === null) return null;
    const voce = proposte.find((p) => p.gestione_id === gestioneId)?.voce_id ?? null;

    return voce !== null && vociOfferte(voci, gestioneId, esercizioId, soloOrdinarie).some((v) => v.id === voce) ? voce : null;
}

/** La voce dopo un cambio di gestione: quella scelta resta se è fra le offerte, altrimenti la proposta della gestione. */
export function voceDopoIlCambio(attuale: number | null, voci: VoceCommissione[], proposte: PropostaCommissione[], gestioneId: number | null,
    esercizioId: number | null = null): number | null {
    if (gestioneId === null) return null;
    if (attuale !== null && vociOfferte(voci, gestioneId, esercizioId).some((v) => v.id === attuale)) return attuale;

    return vocePropostaPer(proposte, voci, gestioneId, esercizioId);
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
