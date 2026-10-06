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
 */
export function fraseArretrato(arretrato: (Pick<ArretratoDelDefunto, 'scelta' | 'frase'> & { frase_senza_conguaglio?: string | null }) | null | undefined, rinuncia: boolean): string | null {
    if (!arretrato) return null;
    if (rinuncia && arretrato.scelta === 'defunto' && arretrato.frase_senza_conguaglio !== undefined) return arretrato.frase_senza_conguaglio;

    return arretrato.frase;
}
