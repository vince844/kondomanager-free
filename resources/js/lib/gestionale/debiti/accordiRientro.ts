/**
 * Le parole dell'accordo di rientro, nell'elenco «Rate scadute», nella sua finestra e nell'anteprima delle lettere. L'accordo è un registro:
 * il segno dice le due cifre che il programma sa (i riconosciuti, e quanto resta oggi della parte riconosciuta), e mai che la persona è «in
 * regola» o che l'accordo è finito: «fino al» passato è una data passata, senza aggettivi. Le date arrivano dal server come `Y-m-d`.
 */
import { euroTesto, giorno } from './rateScadute';

export interface AccordoRientro {
    id: number;
    data_accordo: string;
    fino_al: string;
    riconosciuti: number;
    oggi: number;
    invariato: boolean;
    fino_al_passato: boolean;
    versati_oltre: number;
    autorizzato_assemblea: boolean;
    data_autorizzazione: string | null;
    verbale_autorizzazione: string | null;
    nota: string | null;
    chiuso_il: string | null;
    nota_chiusura: string | null;
}

export interface ParteInAccordo { riconosciuto: number; in_accordo: number; data_accordo: string; fuori_dalle_lettere: boolean; in_parte?: boolean }

export const FRASE_AUTORIZZAZIONE = 'Se l\'assemblea non ha ancora deliberato è «no», con una nota: diventa «sì» quando registri la delibera. '
    + 'L\'autorizzazione dell\'accordo non è la dispensa dei sei mesi.';

export const FRASE_REGISTRO = 'L\'accordo è un registro: le rate coperte restano fra le scadute, con il segno, e le lettere proposte lasciano fuori la parte '
    + 'riconosciuta fino a «fino al». Il calendario delle rate dell\'accordo, interessi e spese il programma non li registra.';

export const FRASE_IMPORTO = 'Di serie il residuo della rata il giorno dell\'accordo: lo puoi abbassare, non alzare.';

/** Una rata coperta solo in parte: come conta un versamento su quella rata. */
export const FRASE_IN_PARTE = 'Un versamento su questa rata conta prima per la parte fuori dall\'accordo.';

/** La finestra offre solo le rate scadute: una rata che scade dopo entra con un accordo nuovo. */
export const FRASE_NON_SCADUTE = 'Le rate non ancora scadute non entrano. Quando scadono, chiudi questo accordo e registrane uno nuovo con tutte le rate, con la data '
    + 'della chiusura.';

/** Il segno di un accordo: le due cifre, o «residuo invariato» quando sono uguali; e la parte versata oltre il capitale, se c'è. */
export function testoSegnoAccordo(a: AccordoRientro): string {
    const quando = `Accordo di rientro del ${giorno(a.data_accordo)}, fino al ${giorno(a.fino_al)}${a.fino_al_passato ? ' (data passata)' : ''}`;
    const cifre = a.invariato
        ? `riconosciuti ${euroTesto(a.riconosciuti)}, residuo invariato dal ${giorno(a.data_accordo)}`
        : `riconosciuti ${euroTesto(a.riconosciuti)}, oggi ${euroTesto(a.oggi)}`;
    const oltre = a.versati_oltre > 0
        ? ` Versati ${euroTesto(a.versati_oltre)} oltre il capitale riconosciuto: sono andati a credito della persona; interessi e spese il programma non li registra.`
        : '';

    return `${quando}: ${cifre}.${oltre}`;
}

/** La parte di una rata in un accordo, nella riga aperta dell'elenco; per una rata coperta solo in parte, come conta un versamento. */
export function testoParteInAccordo(p: ParteInAccordo): string {
    return `Nell'accordo del ${giorno(p.data_accordo)}: ${euroTesto(p.in_accordo)} (riconosciuti ${euroTesto(p.riconosciuto)})${p.fuori_dalle_lettere ? '; non nelle lettere proposte' : ''}.`
        + (p.in_parte ? ` ${FRASE_IN_PARTE}` : '');
}

/** Nell'anteprima delle lettere, per una persona: quanto delle sue rate scadute è in accordo e non è nella lettera. */
export function testoInAccordoNellaLettera(centesimi: number): string | null {
    return centesimi > 0 ? `${euroTesto(centesimi)} in accordo non sono nella lettera.` : null;
}

/** Nell'anteprima: chi ha solo rate in accordo, e non riceve la lettera se l'amministratore non le rimette. */
export function testoSoloInAccordo(nomi: string[]): string | null {
    return nomi.length ? `Solo rate in accordo di rientro, non nella lettera: ${nomi.join(', ')}.` : null;
}

/** L'autorizzazione dell'assemblea, come la dice la finestra. */
export function testoAutorizzazione(a: Pick<AccordoRientro, 'autorizzato_assemblea' | 'data_autorizzazione' | 'verbale_autorizzazione'>): string {
    if (!a.autorizzato_assemblea) {
        return 'Non autorizzato dall\'assemblea.';
    }
    const verbale = a.verbale_autorizzazione ? ` (verbale n. ${a.verbale_autorizzazione})` : '';

    return `Autorizzato dall'assemblea del ${giorno(a.data_autorizzazione)}${verbale}.`;
}
