/**
 * Compensazione fra fatture e note di credito: costruisce le allocazioni che il motore accetta.
 *
 * ## Il difetto che questo modulo esiste per chiudere (beta.67)
 *
 * La schermata di registrazione pagamento emetteva **un record per documento**: la fattura tipizzata
 * `pagamento` per il suo intero residuo, la nota tipizzata `compensazione` per il suo. La partita
 * doppia non poteva quadrare, perché il motore la costruisce così:
 *
 *     DARE  Debiti v/Fornitori = totale allocato sulle fatture
 *     AVERE Banca             = totale dei soli `pagamento`
 *     AVERE Debiti            = totale allocato sulle note
 *
 * Con fattura € 1.000,00 e nota € 200,00 quel payload dà DARE 1.000 e AVERE 1.200: **sbilancio di
 * € 200,00**, e la registrazione veniva respinta. Sempre — non in qualche caso limite. Quindi né il
 * pulsante «Compensa automaticamente» né la selezione a mano di una nota arrivavano a destinazione.
 *
 * ## La forma giusta era già scritta
 *
 * `docs/pagamenti_fatture.md`, Decisione 1, la specifica dal 2025: la fattura compare **due volte**,
 * una per la parte che esce di cassa e una per la parte coperta dal credito.
 *
 *     fattura 42, 800, pagamento        ← il bonifico
 *     fattura 42, 200, compensazione    ← la parte coperta dalla nota
 *     nota    43, 200, compensazione    ← il credito consumato
 *
 * Il motore la implementava già; a non implementarla era la schermata.
 *
 * ## L'invariante che tiene tutto
 *
 *     Σ(compensazione sulle fatture) === Σ(compensazione sulle note)
 *
 * È l'unica cosa che rende quadrata la scrittura, ed è il motivo per cui il credito **si distribuisce**
 * sulle fatture invece di essere allocato per intero: se le note valgono più delle fatture
 * selezionate, l'eccedenza non si può usare in questo pagamento e resta sulla nota.
 *
 * ⚠️ **Il modulo lavora in centesimi interi**, come tutto il denaro del progetto. La conversione da
 * euro avviene una volta sola, al confine, in `PagamentoNew.vue`.
 */

export type TipoAllocazione = 'pagamento' | 'compensazione';

/** Una pendenza selezionata nel form, con gli importi già in centesimi. */
export interface PendenzaSelezionata {
    id: number;
    isNotaCredito: boolean;
    /** Quanto resta da saldare (fattura) o da compensare (nota). Magnitudo, sempre ≥ 0. */
    residuoCents: number;
    /** Quanto l'amministratore ha scelto di allocare su questa pendenza. */
    importoAllocatoCents: number;
    isScaduta?: boolean;
    dataScadenza?: string | null;
}

export interface Allocazione {
    fattura_id: number;
    tipo: TipoAllocazione;
    importo_allocato_cents: number;
}

export interface EsitoNetting {
    allocazioni: Allocazione[];
    /** Quanto esce davvero di cassa: la somma dei soli `pagamento`. */
    uscitaCassaCents: number;
    /** Quanto è stato coperto dal credito delle note. */
    compensatoCents: number;
    /**
     * Credito selezionato sulle note ma **non utilizzabile** in questo pagamento, perché le fatture
     * scelte non bastano a assorbirlo. Non è un errore: è un numero da mostrare, perché altrimenti
     * l'amministratore vede una nota selezionata per € 500,00 e compensati € 300,00 senza capire.
     */
    creditoNonUtilizzatoCents: number;
}

/**
 * L'ordine in cui il credito si consuma: prima le fatture scadute, poi le più vicine a scadere.
 *
 * Non è un dettaglio estetico. Compensare prima ciò che è già scaduto riduce l'esposizione su cui
 * un fornitore può chiedere interessi, ed è l'ordine che la schermata già proponeva.
 */
function ordinePerConsumo(a: PendenzaSelezionata, b: PendenzaSelezionata): number {
    if (a.isScaduta && !b.isScaduta) return -1;
    if (!a.isScaduta && b.isScaduta) return 1;
    return (a.dataScadenza || '').localeCompare(b.dataScadenza || '');
}

/**
 * Costruisce le allocazioni a partire da ciò che è selezionato nel form.
 *
 * Funziona sia per il pulsante «Compensa automaticamente» sia per la selezione a mano: è lo stesso
 * calcolo, ed è voluto — il difetto della beta.66 era che il percorso a mano produceva lo stesso
 * payload sbilanciato del pulsante, quindi correggere solo il pulsante non avrebbe corretto niente.
 */
export function costruisciAllocazioni(pendenze: PendenzaSelezionata[]): EsitoNetting {
    const attive = pendenze.filter((p) => p.importoAllocatoCents > 0);

    const note = attive.filter((p) => p.isNotaCredito);
    const fatture = attive.filter((p) => !p.isNotaCredito).sort(ordinePerConsumo);

    const creditoSelezionato = note.reduce((n, p) => n + p.importoAllocatoCents, 0);
    const totaleFatture = fatture.reduce((n, p) => n + p.importoAllocatoCents, 0);

    // Il credito utilizzabile è limitato da ciò che c'è da coprire: l'invariante è simmetrica.
    const creditoUtilizzabile = Math.min(creditoSelezionato, totaleFatture);

    const allocazioni: Allocazione[] = [];
    let creditoResiduo = creditoUtilizzabile;
    let compensato = 0;

    for (const ft of fatture) {
        const daCompensare = Math.min(creditoResiduo, ft.importoAllocatoCents);
        const daPagare = ft.importoAllocatoCents - daCompensare;

        if (daPagare > 0) {
            allocazioni.push({ fattura_id: ft.id, tipo: 'pagamento', importo_allocato_cents: daPagare });
        }
        if (daCompensare > 0) {
            allocazioni.push({ fattura_id: ft.id, tipo: 'compensazione', importo_allocato_cents: daCompensare });
        }

        creditoResiduo -= daCompensare;
        compensato += daCompensare;
    }

    // Il credito consumato si scarica sulle note nello stesso ordine in cui è stato selezionato,
    // fino a concorrenza di quanto è stato davvero utilizzato.
    let daScaricare = compensato;
    for (const nc of note) {
        if (daScaricare <= 0) break;
        const quota = Math.min(daScaricare, nc.importoAllocatoCents);
        allocazioni.push({ fattura_id: nc.id, tipo: 'compensazione', importo_allocato_cents: quota });
        daScaricare -= quota;
    }

    return {
        allocazioni,
        uscitaCassaCents: totaleFatture - compensato,
        compensatoCents: compensato,
        creditoNonUtilizzatoCents: creditoSelezionato - compensato,
    };
}

/**
 * Verifica la quadratura come la verifica il motore, e restituisce lo sbilancio.
 *
 * ⚠️ **Serve a non spedire mai un payload che sarà respinto**, e serve soprattutto ai test: è la
 * stessa aritmetica di `PagamentoFornitoreService`, scritta una seconda volta di proposito. Se le
 * due divergono, una delle due è sbagliata e il test lo dice — mentre un modulo che si limita a
 * fidarsi del proprio calcolo non può accorgersi di niente.
 */
export function quadratura(allocazioni: Allocazione[], idNote: number[], commissioniCents = 0) {
    const suNote = allocazioni.filter((a) => idNote.includes(a.fattura_id));
    const suFatture = allocazioni.filter((a) => !idNote.includes(a.fattura_id));

    const totaleSuFatture = suFatture.reduce((n, a) => n + a.importo_allocato_cents, 0);
    const totaleSuNote = suNote.reduce((n, a) => n + a.importo_allocato_cents, 0);
    const totalePagamento = allocazioni
        .filter((a) => a.tipo === 'pagamento')
        .reduce((n, a) => n + a.importo_allocato_cents, 0);

    const dare = totaleSuFatture + commissioniCents;
    const avere = totalePagamento + commissioniCents + totaleSuNote;

    return { dare, avere, sbilancio: dare - avere };
}

/**
 * Il pulsante «Compensa automaticamente»: decide quanto allocare su ciascuna pendenza.
 *
 * Restituisce una mappa `id → centesimi da allocare`. Chiama poi `costruisciAllocazioni()` per il
 * payload vero: qui si decide *quanto*, là *come si scrive*.
 *
 * ⚠️ **Le note non si selezionano più al credito pieno.** È quello che faceva la versione
 * precedente, e con fatture per € 500,00 e una nota da € 800,00 produceva un payload che chiedeva
 * di consumare € 800,00 di credito per coprire € 500,00 di debito: sbilanciato per costruzione.
 */
export function distribuisciNetting(pendenze: PendenzaSelezionata[]): Map<number, number> {
    const note = pendenze.filter((p) => p.isNotaCredito);
    const fatture = pendenze.filter((p) => !p.isNotaCredito).sort(ordinePerConsumo);

    const totaleFatture = fatture.reduce((n, p) => n + p.residuoCents, 0);
    const creditoTotale = note.reduce((n, p) => n + p.residuoCents, 0);

    const importi = new Map<number, number>();

    for (const ft of fatture) {
        importi.set(ft.id, ft.residuoCents);
    }

    let daUsare = Math.min(creditoTotale, totaleFatture);
    for (const nc of note) {
        const quota = Math.min(daUsare, nc.residuoCents);
        importi.set(nc.id, quota);
        daUsare -= quota;
    }

    return importi;
}
