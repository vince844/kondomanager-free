/**
 * Gli addebiti a una persona sola nella pagina: le frasi e i conti piccoli della finestra (`NuovoAddebitoDialog.vue`) e dell'elenco dell'estratto conto
 * (`AddebitiDellaPersona.vue`). Le cifre arrivano dal server in centesimi; la finestra le propone in euro, come le scrive l'amministratore, e il server
 * le riporta in centesimi una volta.
 */

/** Che cosa dice la finestra sotto il titolo. */
export const FRASE_ADDEBITO = 'Una somma chiesta a una persona sola, fuori dal riparto: è una sua scadenza, come una rata, nell\'estratto conto e '
    + 'nell\'incasso. Il programma propone la persona; la scelta è tua.';

/** Perché l'addebito non va all'inquilino: sotto l'elenco delle persone. */
export const FRASE_CHI = 'Si propone chi è o è stato proprietario, nudo proprietario o usufruttuario dell\'unità: mai l\'inquilino.';

/** Che cosa fa l'annullamento: nella conferma. */
export const FRASE_ANNULLA = 'L\'addebito si toglie dal giornale e la sua scadenza non c\'è più. Un addebito con un incasso non si annulla: prima si storna l\'incasso.';

/** «67,95»: i centesimi come li scrive l'amministratore nel campo, senza il simbolo. */
export function centesimiInEuro(centesimi: number): string {
    return (centesimi / 100).toFixed(2).replace('.', ',');
}

/**
 * La data di serie di un addebito nell'esercizio scelto: oggi, o la fine dell'esercizio se oggi è dopo (il rendiconto dell'anno prima si prepara
 * l'anno dopo), o il suo inizio se oggi è prima. È la data della sua scrittura, e il server la vuole dentro l'esercizio.
 */
export function dataDiSerie(oggi: string, inizio?: string | null, fine?: string | null): string {
    if (fine && oggi > fine) {
        return fine;
    }

    return inizio && oggi < inizio ? inizio : oggi;
}

/** La chiave di una scelta di «dove si scrive»: la gestione e l'esercizio. */
export function chiaveDove(d: { gestione_id: number; esercizio_id: number }): string {
    return `${d.gestione_id}|${d.esercizio_id}`;
}

/** Il contrario di {@link chiaveDove}. */
export function daChiaveDove(chiave: string): { gestione_id: number; esercizio_id: number } | null {
    const [g, e] = chiave.split('|').map(Number);

    return Number.isFinite(g) && Number.isFinite(e) && g > 0 && e > 0 ? { gestione_id: g, esercizio_id: e } : null;
}

/** «Contributo», «Interessi di mora» o «Spesa per il ritardo», come la riga dell'estratto conto dice la natura di un addebito. */
export function nomeNatura(natura: string, spesaFatturata: boolean, tipo: string | null = null): string {
    if (natura === 'accessorio') {
        return tipo === 'interessi' ? 'Interessi di mora' : 'Spesa per il ritardo';
    }

    return spesaFatturata ? 'Spesa di una sola unità' : 'Contributo';
}

/**
 * La card «Addebiti personali» dell'estratto conto: il totale e quanti sono, dalla stessa lista dell'elenco degli addebiti della persona (una fonte
 * sola). In centesimi. Ogni addebito è emesso quando si registra, con una riga in DARE sulla posizione della persona: per questo è già dentro
 * «Totale addebiti».
 */
export function riepilogoAddebiti(addebiti: ReadonlyArray<{ importo: number }>): { totale: number; numero: number } {
    return { totale: addebiti.reduce((somma, a) => somma + a.importo, 0), numero: addebiti.length };
}

/** La riga sotto la cifra della card: quanti sono e dove si contano, o che non ce ne sono. */
export function testoRiepilogoAddebiti(numero: number): string {
    if (numero === 0) {
        return 'Nessun addebito personale';
    }

    return `${numero === 1 ? '1 addebito' : `${numero} addebiti`} · nel totale addebiti`;
}
