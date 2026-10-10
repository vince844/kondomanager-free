/**
 * La rinuncia al conguaglio nella successione (1.11.0-beta.44, decisione 65), come la controlla il server
 * (`AnteprimaPassaggioRequest`): una regola sola per il modulo e per il pannello «Cosa cambierà».
 */
import type { ArretratoDelDefunto } from '@/types/gestionale/passaggi';

/**
 * La rinuncia vale solo se il pannello propone una coppia (verifica S5, R13) e l'arretrato non va agli eredi: lì coppia e arretrato
 * fanno un conto solo, e il server la rifiuta. La spunta resta com'è: se l'amministratore torna ad «a nome del defunto», ritrova
 * la rinuncia e la sua ragione (rilievo X11 della Fase 1-bis).
 */
export function rinunciaEffettiva(coppie: number, spunta: boolean, arretrato: string | null | undefined): boolean {
    return coppie > 0 && spunta && arretrato !== 'eredi';
}

/**
 * La frase dell'arretrato da mostrare. A nome del defunto, con la rinuncia, quella senza la coppia: la coppia non si scrive, e a
 * suo nome resta tutta la sua posizione (rilievo X8). L'anteprima non sa della spunta, e dà le due frasi.
 *
 * 1.11.0-beta.48 (rilievo R1 della Fase 1-bis): finché la scelta sul conguaglio manca (`daScegliere`), la frase con le due cifre — «€ 400,00
 * senza conguaglio, € 394,52 se scrivi il conguaglio» —: mostrare quella di «Scrivi» sarebbe la preselezione che la decisione 72 toglie.
 */
export function fraseArretrato(
    arretrato: (Pick<ArretratoDelDefunto, 'scelta' | 'frase'> & { frase_senza_conguaglio?: string | null; frase_da_scegliere?: string | null }) | null | undefined,
    rinuncia: boolean,
    daScegliere = false,
): string | null {
    if (!arretrato) return null;
    if (daScegliere && arretrato.scelta === 'defunto' && typeof arretrato.frase_da_scegliere === 'string') return arretrato.frase_da_scegliere;
    if (rinuncia && arretrato.scelta === 'defunto' && arretrato.frase_senza_conguaglio !== undefined) return arretrato.frase_senza_conguaglio;

    return arretrato.frase;
}

/** I valori della scelta della successione e del legato (decisione 72, 1.11.0-beta.48), come li manda il modulo. */
export type SceltaConguaglio = 'scrivi' | 'non_scrivere';

/**
 * Decisioni 72 e 73 (1.11.0-beta.48): nella successione e nel legato, con l'arretrato a nome del defunto e delle coppie proposte,
 * il conguaglio si sceglie, senza preselezione — al posto della casella. Con l'arretrato agli eredi coppia e arretrato fanno un
 * conto solo, e la scelta non c'è. La stessa regola del server (`RegistraSubentroAction`).
 */
export function sceltaConguaglioRichiesta(coppie: number, arretrato: string | null | undefined): boolean {
    return coppie > 0 && arretrato === 'defunto';
}

/**
 * Il conguaglio della successione non si scrive: la scelta serve, ed è «Non scriverlo». La scelta resta nel modulo anche se
 * l'arretrato passa agli eredi (come la spunta della .44, rilievo X11): tornando ad «a nome del defunto» la si ritrova.
 */
export function conguaglioNonScritto(coppie: number, arretrato: string | null | undefined, scelta: SceltaConguaglio | null | undefined): boolean {
    return sceltaConguaglioRichiesta(coppie, arretrato) && scelta === 'non_scrivere';
}


/** La scelta serve e non è ancora fatta (rilievo R1 della Fase 1-bis della .48): il pannello dice «conguaglio da scegliere» e le due cifre. */
export function conguaglioDaScegliere(coppie: number, arretrato: string | null | undefined, scelta: SceltaConguaglio | null | undefined): boolean {
    return sceltaConguaglioRichiesta(coppie, arretrato) && (scelta === null || scelta === undefined);
}
