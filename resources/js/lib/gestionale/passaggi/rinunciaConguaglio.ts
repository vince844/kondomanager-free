/**
 * La rinuncia al conguaglio nella successione (1.11.0-beta.44, decisione 65), come la controlla il server
 * (`AnteprimaPassaggioRequest`): una regola sola per il modulo e per il pannello «Cosa cambierà». Dalla 1.11.0-beta.48 la scelta
 * della successione e del legato, dalla 1.11.0-beta.50 anche quella della fine di una locazione (decisione 77, in fondo).
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

/**
 * I valori della scelta della successione e del legato (decisione 72, 1.11.0-beta.48) e della fine di una locazione (decisione 77,
 * 1.11.0-beta.50), come li manda il modulo.
 */
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

/**
 * Decisione 77 (1.11.0-beta.50): i passaggi in cui il conguaglio si sceglie con le due voci al posto della casella. Alla successione
 * e al legato della .48 si aggiunge la fine di una locazione, con un nuovo inquilino (il cambio d'inquilino) o senza: un vocabolario
 * solo per tutta la locazione. La vendita e l'usufrutto tengono la casella con la nota obbligatoria.
 */
export function passaggioConLaScelta(tipo: string | null | undefined): boolean {
    return tipo === 'successione' || tipo === 'fine_locazione';
}

/**
 * Decisione 77 (1.11.0-beta.50): quando il modulo chiede la scelta. Alla fine di una locazione basta una coppia proposta (un piano che
 * non si riscrive più), e la scelta è una sola per l'appartamento e le pertinenze spuntate; con un coinquilino che resta il pannello non
 * propone coppie (decisione 79), e la scelta non c'è. Negli altri passaggi la regola della .48, `sceltaConguaglioRichiesta`: la
 * successione e il legato con l'arretrato a nome del defunto (l'arretrato c'è solo lì). La stessa regola del server
 * (`AnteprimaPassaggio::sceltaDelConguaglio`, `RegistraSubentroAction`).
 */
export function sceltaConguaglioDelPassaggio(tipo: string | null | undefined, coppie: number, arretrato: string | null | undefined): boolean {
    return tipo === 'fine_locazione' ? coppie > 0 : sceltaConguaglioRichiesta(coppie, arretrato);
}

/** «Non scriverlo» vale solo dove la scelta si chiede: un valore rimasto nel modulo, dove la scelta non c'è più, non parte. */
export function conguaglioNonScrittoNelPassaggio(tipo: string | null | undefined, coppie: number, arretrato: string | null | undefined, scelta: SceltaConguaglio | null | undefined): boolean {
    return sceltaConguaglioDelPassaggio(tipo, coppie, arretrato) && scelta === 'non_scrivere';
}

/** La scelta si chiede e non è ancora fatta: il pannello dice «conguaglio da scegliere», e la registrazione resta spenta. */
export function conguaglioDaScegliereNelPassaggio(tipo: string | null | undefined, coppie: number, arretrato: string | null | undefined, scelta: SceltaConguaglio | null | undefined): boolean {
    return sceltaConguaglioDelPassaggio(tipo, coppie, arretrato) && (scelta === null || scelta === undefined);
}

/**
 * Le coppie proposte non si scrivono: con la scelta, per «Non scriverlo»; con la casella, per la rinuncia motivata. Dove il passaggio
 * ha la scelta la casella non conta, anche se è rimasta spuntata nel modulo: il server rifiuta la scelta e la casella insieme, e il
 * modulo non la manda.
 */
export function coppieNonScritte(
    tipo: string | null | undefined,
    coppie: number,
    arretrato: string | null | undefined,
    spunta: boolean,
    scelta: SceltaConguaglio | null | undefined,
): boolean {
    return passaggioConLaScelta(tipo)
        ? conguaglioNonScrittoNelPassaggio(tipo, coppie, arretrato, scelta)
        : rinunciaEffettiva(coppie, spunta, null);
}
