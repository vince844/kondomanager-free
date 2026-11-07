/**
 * La conferma dello storno di un incasso (`resources/js/components/gestionale/movimenti/incassi/DataTableRowActions.vue`),
 * 1.11.0-beta.49, voce P8 del verbale (Coda 209, decisione 75.5).
 */

/** Una voce dell'avviso: la rata la cui parte in più è già stata usata, e quanto tornerà da pagare. Importi in centesimi. */
export interface VoceAvvisoStorno {
    rata: number;
    persona: string;
    importo_rata: number;
    da_pagare_dopo: number;
    gia_usato: number;
    usi: Array<{ tipo: string; protocollo: string | null; data: string | null }>;
}

/** La natura dello storno di un incasso (1.12.0-beta.3, decisione 217, D1): la sceglie chi storna. */
export type NaturaStorno = 'errore_registrazione' | 'versamento_tornato';

/** Da quale data vale lo storno, per natura, come la dice il server (il giorno dell'amministratore, `aaaa-mm-gg`). */
export interface DateDelloStorno {
    errore_registrazione: { vale_dal: string; esercizio_chiuso: boolean };
    versamento_tornato: { vale_dal: string };
}

/** La risposta della rotta `movimenti-rate.storno.anteprima` (`AvvisoStornoIncasso`). */
export interface AnteprimaStorno {
    voci: VoceAvvisoStorno[];
    frase: string | null;
    /** La ricevuta dell'incasso già stampata o inviata (1.12.0-beta.2): la frase da dire nella conferma, o null. */
    ricevuta?: string | null;
    /** Da quale data vale ognuna delle due nature (1.12.0-beta.3). */
    natura?: DateDelloStorno;
}

/** Una scelta della natura nella conferma: il valore e la frase che la descrive, con da quando vale. */
export interface SceltaNatura {
    valore: NaturaStorno;
    titolo: string;
}

/**
 * Le due nature, nell'ordine in cui la conferma le propone, nessuna già scelta: solo chi storna sa che cosa è successo, e la scelta non si
 * ricorda (è un fatto di quel versamento). Le parole sono della decisione 219: la prima comprende anche il versamento vero registrato male
 * (persona, importo, rata, a che cosa va), che si storna per registrarlo di nuovo; le date restano quelle della decisione 217.
 */
export const SCELTE_NATURA: ReadonlyArray<SceltaNatura> = [
    { valore: 'errore_registrazione', titolo: 'Registrazione sbagliata: il versamento non c\'è mai stato, o va registrato di nuovo in un altro modo. Lo storno vale dal giorno del versamento.' },
    { valore: 'versamento_tornato', titolo: 'Il versamento c\'era ed è tornato indietro (assegno insoluto, addebito respinto, bonifico richiamato). Lo storno vale da oggi.' },
];

/** Le stesse due nature per un incasso fatto solo di credito (€ 0,00 in cassa): un versamento non c'è, c'è una compensazione. */
export const SCELTE_NATURA_COMPENSAZIONE: ReadonlyArray<SceltaNatura> = [
    { valore: 'errore_registrazione', titolo: 'La compensazione è sbagliata: si toglie dal giorno in cui è stata fatta.' },
    { valore: 'versamento_tornato', titolo: 'La compensazione era giusta e si annulla da oggi.' },
];

/**
 * Le scelte da mostrare per questo incasso. Se l'esercizio che contiene la data è chiuso, o non c'è, la prima non può valere da quel giorno
 * (decisione 217): la sua frase lo dice invece di promettere la data del versamento.
 */
export function scelteDellaNatura(incasso: { importo_totale_cents: number }, anteprima: AnteprimaStorno | null | undefined): ReadonlyArray<SceltaNatura> {
    const soloCredito = incasso.importo_totale_cents === 0;
    const scelte = soloCredito ? SCELTE_NATURA_COMPENSAZIONE : SCELTE_NATURA;
    if (!anteprima?.natura?.errore_registrazione?.esercizio_chiuso) {
        return scelte;
    }

    const daOggi = soloCredito
        ? 'La compensazione è sbagliata: si toglie da oggi, perché l\'esercizio in cui è stata fatta è chiuso, o non c\'è.'
        : 'Registrazione sbagliata: il versamento non c\'è mai stato, o va registrato di nuovo in un altro modo. Lo storno vale da oggi, perché l\'esercizio del versamento è chiuso, o non c\'è.';

    return scelte.map((s) => (s.valore === 'errore_registrazione' ? { ...s, titolo: daOggi } : s));
}

const gg = (data: string) => data.slice(0, 10).split('-').reverse().join('/');

/** «Vale dal gg/mm/aaaa.» accanto a una natura, dalla data del server; null senza l'anteprima: la conferma non inventa una data. */
export function valeDalDellaNatura(anteprima: AnteprimaStorno | null | undefined, natura: NaturaStorno): string | null {
    const data = anteprima?.natura?.[natura]?.vale_dal;

    return data ? `Vale dal ${gg(data)}.` : null;
}

/**
 * La frase che la conferma dice prima, quando l'esercizio che contiene la data del versamento è chiuso o non c'è: un errore di
 * registrazione non si retrodata lì dentro, e vale da oggi. Null altrimenti, o senza l'anteprima.
 */
export function avvisoErroreFuoriEsercizio(anteprima: AnteprimaStorno | null | undefined, soloCredito = false): string | null {
    const errore = anteprima?.natura?.errore_registrazione;
    if (!errore?.esercizio_chiuso) return null;

    return soloCredito
        ? `L'esercizio che contiene la data della compensazione è chiuso, o non c'è: se è sbagliata, si toglie da oggi, ${gg(errore.vale_dal)}.`
        : `L'esercizio che contiene la data del versamento è chiuso, o non c'è: se è una registrazione sbagliata, lo storno vale da oggi, ${gg(errore.vale_dal)}.`;
}

/**
 * Con l'anteprima fallita la conferma non sa da quale data varrà lo storno: lo dice, con la regola dell'esercizio chiuso, e dice dove si
 * legge la data dopo. Il server applica la regola lo stesso.
 */
export const AVVISO_DATA_NON_VERIFICATA =
    'Non si è potuto sapere da quale data vale lo storno: se l\'esercizio che contiene la data del versamento è chiuso, o non c\'è, vale da oggi anche come registrazione sbagliata. Dopo lo storno la data è scritta nella pagina dell\'incasso.';

/**
 * L'importo dell'incasso, in centesimi interi, come lo manda il server (`importo_totale_cents`). Fino alla beta.48 la pagina
 * riceveva gli euro (`importo_totale_raw`, già divisi per 100 dal server) e li rimoltiplicava per 100: una seconda conversione
 * a valle del confine, in virgola mobile (€ 19,99 diventava 1998.9999999999998).
 */
export function importoDaStornare(incasso: { importo_totale_cents: number; importo_totale_raw?: number }): number {
    return incasso.importo_totale_cents;
}

/**
 * La frase dell'avviso, solo quando c'è qualcosa da dire: decidono le voci, non la frase. Senza l'anteprima (non ancora arrivata,
 * o fallita) niente avviso: la conferma non inventa.
 */
export function avvisoDelloStorno(anteprima: AnteprimaStorno | null | undefined): string | null {
    if (!anteprima || anteprima.voci.length === 0 || !anteprima.frase) {
        return null;
    }

    return anteprima.frase;
}

/** I tre momenti dell'anteprima dello storno: chiesta e non ancora arrivata, arrivata, fallita (419, 500, la rete). */
export type StatoAnteprimaStorno = 'in_attesa' | 'arrivata' | 'fallita';

/**
 * La frase della conferma quando l'anteprima non è arrivata: lo storno si può fare, ma senza la verifica della Coda 209. Dice il
 * rischio, non ripete la frase sopra («Le rate torneranno da pagare»), e dove guardare prima di confermare (giro stretto sulle
 * correzioni della Fase 1-bis, LT-7).
 */
export const AVVISO_ANTEPRIMA_FALLITA =
    'Non è stato possibile verificare se la parte in più di questo incasso è già stata usata o rimborsata. Se lo è, lo storno non annulla quell\'uso, e una rata può tornare da pagare per più del suo importo: prima di confermare, guarda l\'estratto conto del condòmino.';

/** Quanto si aspetta l'anteprima prima di darla per fallita (LT-7): senza un limite, la conferma restava ferma su «Si verifica…». */
export const ATTESA_ANTEPRIMA_MS = 15000;

/**
 * Che cosa la conferma dello storno può fare in ognuno dei tre momenti dell'anteprima (rilievo S3 della Fase 1-bis della
 * 1.11.0-beta.49). Finché l'anteprima non arriva la conferma non si può dare: lo storno partiva prima di sapere se la parte in più
 * era già stata usata. Se l'anteprima fallisce si può confermare, ma la conferma dice che la verifica non c'è stata, invece di
 * tacere. Arrivata, l'avviso è quello del server (`avvisoDelloStorno`).
 */
export function confermaDelloStorno(stato: StatoAnteprimaStorno, anteprima: AnteprimaStorno | null | undefined): { confermabile: boolean; avviso: string | null } {
    if (stato === 'in_attesa') {
        return { confermabile: false, avviso: null };
    }
    if (stato === 'fallita') {
        return { confermabile: true, avviso: AVVISO_ANTEPRIMA_FALLITA };
    }

    return { confermabile: true, avviso: avvisoDelloStorno(anteprima) };
}
