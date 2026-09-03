/**
 * Il confronto fra **la ritenuta che il file dichiara** e **quella che il modulo
 * tratterrebbe**, per la fattura che si sta registrando.
 *
 * ## Perché esiste
 *
 * Fino alla 1.11.0-beta.14 il dato del file attraversava il confine e non lo leggeva
 * nessuno: l'endpoint di importazione lo esponeva (`esito.ritenuta`), il tipo TypeScript
 * lo dichiarava, e nessun `.vue` ci arrivava (Fase 1-bis, reperti 2 e 12). Il risultato
 * era che una parcella con ritenuta dichiarata si registrava a netto pieno — il
 * condominio paga tutto al fornitore e non versa niente all'Erario, restando comunque
 * responsabile come sostituto d'imposta.
 *
 * ## Che cosa fa, e soprattutto che cosa NON fa
 *
 * ⚠️ **Non decide, non corregge, non tocca nessun importo.** Restituisce solo *quale
 * situazione* si è verificata, e i due numeri. Le parole le mette il template, l'ultima
 * parola l'amministratore. È la regola del progetto: il software segnala il difetto e
 * suggerisce come risolverlo, ma deve funzionare bene in entrambi gli esiti — che
 * l'amministratore accetti il suggerimento o lo ignori.
 *
 * ⚠️ **Non deduce il regime dall'anagrafica né viceversa.** L'anagrafica descrive il
 * fornitore *oggi*, il file descrive quel documento *allora*: nessuno dei due comanda
 * sull'altro, ed è per questo che si mostra una **discrepanza** e non un **errore**.
 *
 * ## Il caso più importante è quello in cui tace
 *
 * `nessun_confronto` copre le due situazioni di gran lunga più frequenti — il file non
 * dichiara niente e il modulo non trattiene niente — e non deve produrre nessun avviso.
 * Un riquadro che compare sempre smette di essere letto in una settimana.
 */

/** Il blocco `DatiRitenuta` letto dal file. `importo` in EURO, come lo espone l'endpoint. */
export interface RitenutaDaXml {
    tipo: string | null;
    importo: number;
    aliquota: number;
    causale_pagamento: string | null;
}

export interface FornitorePerConfronto {
    ragione_sociale?: string;
    soggetto_ritenuta?: boolean;
    regime_forfetario?: boolean;
    tipo_ritenuta?: string | null;
}

/**
 * Perché il modulo non trattiene, quando il file dichiara. Sono cause diverse che
 * chiedono rimedi diversi: distinguerle è tutta la differenza fra un avviso utile e un
 * «qualcosa non va».
 */
export type MotivoNessunaTrattenuta =
    | 'fornitore_mancante'
    | 'non_soggetto'
    | 'forfetario'
    | 'nota_credito'
    | 'esclusa_a_mano'
    | 'altro';

export type EsitoConfrontoRitenuta =
    | { stato: 'nessun_confronto' }
    | { stato: 'coincidono'; fileCents: number; moduloCents: number }
    | { stato: 'importi_diversi'; fileCents: number; moduloCents: number }
    | { stato: 'file_dichiara_modulo_no'; fileCents: number; motivo: MotivoNessunaTrattenuta }
    | { stato: 'modulo_trattiene_file_tace'; moduloCents: number };

export interface IngressoConfrontoRitenuta {
    /** `null` quando non si è importato nessun file, o quando il file non dichiara ritenute d'acconto. */
    ritenutaDaXml: RitenutaDaXml | null | undefined;
    /** La trattenuta che il modulo applicherebbe adesso, in centesimi (da `calcolaTotali`). */
    ritenutaModuloCents: number;
    fornitore: FornitorePerConfronto | null | undefined;
    tipoDocumento: string;
    /** `applicaRitenutaEffective`: la spunta «applica ritenuta su questo documento». */
    applicaRitenuta: boolean;
    /** Un file è stato letto: senza, non c'è niente da confrontare in nessuna direzione. */
    daFile: boolean;
}

/**
 * Euro → centesimi, una volta sola e al confine.
 *
 * ⚠️ L'endpoint espone l'importo in euro (`MoneyHelper::fromCents`), quindi la
 * conversione va fatta qui e **una volta sola**: un secondo `* 100` a valle è il bug che
 * è costato la 1.10.0-beta.32. `Math.round` e non un troncamento, perché `1.4 * 100` in
 * virgola mobile non fa esattamente 140.
 */
function inCentesimi(euro: number): number {
    return Math.round(euro * 100);
}

function motivoDellaMancanza(input: IngressoConfrontoRitenuta): MotivoNessunaTrattenuta {
    if (!input.fornitore) return 'fornitore_mancante';
    if (input.fornitore.regime_forfetario) return 'forfetario';
    if (!input.fornitore.soggetto_ritenuta) return 'non_soggetto';
    if (!input.applicaRitenuta) {
        // La nota di credito ha un default suo (niente ritenuta salvo spunta esplicita):
        // dirlo come «l'hai esclusa tu» sarebbe falso.
        return input.tipoDocumento === 'nota_credito' ? 'nota_credito' : 'esclusa_a_mano';
    }

    // Soggetto, non forfetario, ritenuta richiesta, e il modulo trattiene comunque zero:
    // regime incompleto, oppure nessuna riga concorre alla base. Sono due strade che
    // portano allo stesso posto e si indicano insieme, perché il rimedio è guardare
    // entrambe.
    return 'altro';
}

export function confrontaRitenuta(input: IngressoConfrontoRitenuta): EsitoConfrontoRitenuta {
    // Senza un file letto non esiste il confronto: il modulo compilato a mano fa fede da
    // solo e nessuno ha dichiarato niente da confrontarci.
    if (!input.daFile) {
        return { stato: 'nessun_confronto' };
    }

    const moduloCents = Math.round(input.ritenutaModuloCents);

    if (!input.ritenutaDaXml) {
        // Il file tace. Se anche il modulo non trattiene, non c'è niente da dire — ed è il
        // caso più frequente di tutti.
        return moduloCents > 0
            ? { stato: 'modulo_trattiene_file_tace', moduloCents }
            : { stato: 'nessun_confronto' };
    }

    const fileCents = inCentesimi(input.ritenutaDaXml.importo);

    // Un blocco dichiarato a zero è un fatto diverso da un blocco assente, ma per il
    // confronto vale come «il file non chiede nessuna trattenuta».
    if (fileCents <= 0) {
        return moduloCents > 0
            ? { stato: 'modulo_trattiene_file_tace', moduloCents }
            : { stato: 'nessun_confronto' };
    }

    if (moduloCents <= 0) {
        return { stato: 'file_dichiara_modulo_no', fileCents, motivo: motivoDellaMancanza(input) };
    }

    return moduloCents === fileCents
        ? { stato: 'coincidono', fileCents, moduloCents }
        : { stato: 'importi_diversi', fileCents, moduloCents };
}

// ═══════════════════════════════════════════════════════════════════════════════════
// LA PROPOSTA DEL REGIME, quando si crea il fornitore dal file
// ═══════════════════════════════════════════════════════════════════════════════════

/**
 * I due soli regimi che l'aliquota determina **da sola e senza ambiguità**.
 *
 * ⚠️ La mappa è volutamente corta. Il 4% è l'appalto di opere e servizi (art. 25-ter), il
 * regime classico del condominio: tutte e cinque le fatture con ritenuta del collaudo lo
 * dichiarano. Il 20% è il lavoro autonomo (art. 25). Tutto il resto — provvigioni al 23%
 * su base ridotta, non residenti al 30% — **non si propone**, perché l'aliquota da sola
 * non basta a distinguerli e indovinare qui significherebbe scrivere in anagrafica un
 * regime che poi si applica a tutte le fatture future di quel fornitore.
 *
 * Non è una regola inventata per l'occasione: è la stessa inversione già approvata per il
 * backfill dell'anagrafica (design F24 §2.4 M2, `perc_ritenuta = 4 → APPALTO_4`).
 */
const REGIME_DA_ALIQUOTA: Record<string, string> = {
    '4': 'appalto_4',
    '20': 'lavoro_autonomo_20',
};

export interface PropostaRegime {
    /** Il valore per `tipo_ritenuta`, oppure `null` se l'aliquota non lo determina. */
    tipoRitenuta: string | null;
    /** L'aliquota torna coi numeri del file stesso? */
    confermataDagliImporti: boolean;
    aliquota: number;
}

/**
 * Che regime proporre, leggendo la ritenuta dichiarata dal file.
 *
 * ⚠️ **L'aliquota non si crede sulla parola: si fa tornare coi conti.** Il file dichiara
 * `AliquotaRitenuta`, ma dichiara anche `ImportoRitenuta` e le righe: se l'importo non è
 * l'aliquota applicata alla base, allora quella percentuale è nominale su una base ridotta
 * (è il caso delle provvigioni: 23% dichiarato, 11,5% efficace) e proporre il regime dalla
 * percentuale scritta sarebbe sbagliato. Sui cinque file veri del collaudo il conto torna
 * su tutti e cinque.
 *
 * ⚠️ **Non si propone niente senza conferma.** Un regime non confermato dai numeri lascia
 * la scelta all'amministratore invece di riempirgli un campo che poi vale per sempre.
 *
 * @param baseImponibileCents la somma delle righe che concorrono alla base ritenuta
 */
export function proponiRegimeDaRitenuta(
    ritenuta: RitenutaDaXml | null | undefined,
    baseImponibileCents: number,
): PropostaRegime | null {
    if (!ritenuta) return null;

    const importoCents = inCentesimi(ritenuta.importo);
    if (importoCents <= 0 || baseImponibileCents <= 0) return null;

    // Tolleranza di due centesimi: l'arrotondamento del fornitore e il nostro possono
    // divergere sull'ultimo decimale senza che il regime sia diverso.
    const atteso = Math.round((baseImponibileCents * ritenuta.aliquota) / 100);
    const confermataDagliImporti = Math.abs(importoCents - atteso) <= 2;

    // La chiave è normalizzata: il file può scrivere 4.00 o 4, e sono la stessa cosa.
    const chiave = String(Number(ritenuta.aliquota));

    return {
        tipoRitenuta: confermataDagliImporti ? (REGIME_DA_ALIQUOTA[chiave] ?? null) : null,
        confermataDagliImporti,
        aliquota: ritenuta.aliquota,
    };
}

/**
 * La **natura del percipiente** proposta dal file, che decide il codice tributo dell'F24.
 *
 * ⚠️ **Asse diverso da quello del regime, e va tenuto diverso.** `AliquotaRitenuta` dice
 * *quale regime* (4% appalto, 20% lavoro autonomo); `TipoRitenuta` dice *chi è il
 * percipiente* — ed è esattamente l'asse di `NaturaPercipiente`, che nel nostro modello
 * governa una cosa sola: **1019 (IRPEF) contro 1020 (IRES)**.
 *
 * ## Perché si propone, invece di lasciare il campo vuoto
 *
 * Fino al 03/09/2026 il modale creava il fornitore **senza** natura, e a valle
 * `GeneraDelegheF24Action` ripiega in silenzio su `PERSONA_FISICA_IRPEF` — cioè stampa
 * **1019 anche su una società**, e il denaro arriva all'Erario sotto un codice che non è il
 * suo. Non proporla significa alimentare quel ripiego a ogni fornitore creato da un file.
 *
 * Lo stesso progetto ha già scritto la regola, applicata all'aliquota in
 * `RitenutaService::calcolaRegimeLegacy()`: *un fornitore soggetto a ritenuta senza
 * percentuale configurata non deve MAI calcolare uno zero silenzioso — sembrerebbe
 * «nessuna ritenuta dovuta» invece di «anagrafica incompleta»*. Vale identico qui.
 *
 * ## ⚠️ Il limite, dichiarato
 *
 * `RT02` significa «non persona fisica», e nel nostro modello un **ente non commerciale**
 * (cooperativa, consorzio, associazione) è persona giuridica ma va a **1019**, non a 1020.
 * La proposta può quindi sbagliare — ma è **visibile e modificabile nel modale**, mentre il
 * ripiego che sostituisce è muto e sbaglia su *tutte* le società. Un'ipotesi che si vede
 * vale più di un default che non si vede.
 */
const NATURA_DA_TIPO_RITENUTA: Record<string, string> = {
    RT01: 'persona_fisica_irpef',
    RT02: 'soggetto_ires',
};

export function proponiNaturaDaRitenuta(ritenuta: RitenutaDaXml | null | undefined): string | null {
    if (!ritenuta?.tipo) return null;

    return NATURA_DA_TIPO_RITENUTA[ritenuta.tipo.toUpperCase()] ?? null;
}
