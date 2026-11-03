/**
 * Lo stato «In attesa della compensazione» del promemoria di una rata nel portale.
 *
 * La persona non ha altro da versare (restante zero, o a credito) ma una sua quota da pagare aspetta il credito che ha su un'altra
 * quota della stessa rata, e la compensazione la registra l'amministratore. `EventiRataCondomino::stato()` lo scrive come `partial`
 * con `da_compensare` maggiore di zero; la finestra del condòmino e l'elenco lo leggono da qui, così dicono la stessa cosa.
 *
 * Su una rata non ancora emessa (`is_emitted` falso) la persona non ha niente da aspettare dall'amministratore: lo stato non vale.
 * Gli importi sono in centesimi, come nel `meta`.
 */
export interface MetaPromemoria {
    status?: string;
    importo_restante?: number;
    da_compensare?: number;
    is_emitted?: boolean;
}

export function inAttesaDellaCompensazione(meta?: MetaPromemoria | null): boolean {
    if (!meta) return false;

    return (
        meta.status === 'partial' &&
        meta.is_emitted !== false &&
        Number(meta.da_compensare ?? 0) > 0 &&
        Number(meta.importo_restante ?? 0) <= 0
    );
}

/**
 * Netto zero su una rata non ancora emessa, con una quota da compensare: debito e credito della stessa rata si pareggiano, la persona non deve
 * versare niente e non ha versato niente, e la compensazione fra le due quote la registrerà l'amministratore dopo l'emissione. Non è «in attesa
 * della compensazione» (non c'è niente da aspettare ancora) né «compensato dal credito applicato» (il credito non è applicato): la finestra e
 * l'etichetta lo dicono con parole loro.
 */
export function compensazioneDopoLEmissione(meta?: MetaPromemoria | null): boolean {
    if (!meta) return false;

    return (
        meta.status === 'partial' &&
        meta.is_emitted === false &&
        Number(meta.da_compensare ?? 0) > 0 &&
        Number(meta.importo_restante ?? 0) === 0
    );
}
