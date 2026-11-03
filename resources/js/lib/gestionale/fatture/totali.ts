import { euroToCents, ivaRigaCents, arrotonda } from './money';

/**
 * Totali di una fattura passiva per l'ANTEPRIMA del form (registrazione e modifica).
 *
 * Il backend resta l'unica fonte di verità e ricalcola tutto al salvataggio: questo modulo
 * esiste perché il numero mostrato prima di salvare coincida con quello che verrà salvato.
 * Finché i due calcoli vivevano in due aritmetiche diverse — centesimi interi di qua, float
 * di là — divergevano di un centesimo, e l'amministratore leggeva a schermo un netto da
 * pagare che l'elenco fatture poi smentiva.
 *
 * La regola è quindi una sola: **ogni operazione ricalca il PHP corrispondente**, nello
 * stesso ordine e con gli stessi arrotondamenti intermedi.
 *
 *   `FatturaPassivaService::registraFattura()`   imponibile e IVA, riga per riga
 *   `RitenutaService::calcola()`                 base ritenuta, poi importo
 *
 * I tre punti in cui l'ordine conta:
 *
 * 1. **L'IVA si arrotonda per riga QUANDO il documento non dichiara la propria.** Con più righe
 *    le due strade divergono. Dalla 1.11.0-beta.19 una fattura importata da XML porta con sé i
 *    propri `DatiRiepilogo`, e lì l'imposta non si ricalcola: si prende quella dichiarata per il
 *    gruppo (aliquota, natura) e la si distribuisce fra le sue righe col metodo dei resti
 *    maggiori, esattamente come fa `FatturaPassivaService::distribuisciImpostaDichiarata()`.
 *    ⚠️ La regola del patto non è cambiata — è sempre «ogni operazione ricalca il PHP» — è
 *    cambiato il PHP che va ricalcato.
 * 2. **La ritenuta si arrotonda due volte**: prima la base ridotta (`percBase`), poi la
 *    trattenuta. Sui regimi a base 50% o 20% il doppio arrotondamento sposta il risultato.
 * 3. **Il netto si calcola dai centesimi già arrotondati**, non dai grezzi. È il difetto
 *    segnalato dal forum: imponibile 316,20 + IVA 69,564 − ritenuta 12,648 fa 373,116, che
 *    arrotondato dà 373,12; ma i numeri scritti sulla fattura sono 316,20 + 69,56 − 12,65,
 *    e il loro totale è 373,11. Il netto deve quadrare con i numeri stampati, non con quelli
 *    che nessuno vede.
 */

/**
 * Aliquote di preview, allineate a `config('fiscale.aliquote')`.
 *
 * Il backend le legge storicizzate per data (`TipoRitenuta::aliquota($dataDocumento)`);
 * qui la data non c'è perché al 02/08/2026 ogni regime ha una riga sola, valida da sempre.
 * Se un domani una riga nuova entra in vigore da una certa data, questa tabella non basta
 * più e la preview va chiesta al server.
 */
export const REGIMI_RITENUTA_PREVIEW: Record<string, { aliquota: number; base: number }> = {
    appalto_4: { aliquota: 4, base: 100 },
    lavoro_autonomo_20: { aliquota: 20, base: 100 },
    provvigioni_base_50: { aliquota: 23, base: 50 },
    provvigioni_base_20: { aliquota: 23, base: 20 },
    non_residente_30: { aliquota: 30, base: 100 },
    lavoro_dipendente: { aliquota: 0, base: 100 },
};

export interface RigaTotali {
    importo_imponibile: unknown;
    aliquota_iva: unknown;
    /**
     * La natura IVA della riga (N1…N7). ⚠️ **Fa parte della CHIAVE del gruppo, non è
     * un'etichetta:** due righe alla stessa aliquota con nature diverse appartengono a due
     * riepiloghi distinti. Era assente da questa interfaccia e il codice la leggeva con un
     * cast — che è il modo in cui un campo scompare senza che nessuno se ne accorga.
     */
    natura?: string | null;
    is_sopravvenienza?: boolean;
    /** Contributo cassa, rimborsi art. 15, posa accessoria: fuori dalla base ritenuta. */
    concorre_base_ritenuta?: boolean;
}

/** Percentuali già risolte dal chiamante — regime nuovo (`tipo_ritenuta`) o legacy. */
export interface RegimeRitenuta {
    percBase: number;
    percTratt: number;
}

export interface InputTotali {
    is_pregresso: boolean;
    /** Solo per le fatture pregresse, che non hanno righe di dettaglio. */
    imponibile_pregresso?: unknown;
    aliquota_iva_pregressa?: unknown;
    /** Presente solo per il pregresso di un documento che dichiara la propria imposta. */
    imposta_pregressa?: unknown;
    righe: RigaTotali[];
    /**
     * I riepiloghi IVA che il documento dichiara, uno per coppia aliquota/natura.
     * Quando ci sono, l'imposta di ogni gruppo si distribuisce fra le sue righe invece di
     * essere ricalcolata: è ciò che il server fa al salvataggio, e l'anteprima deve mostrare
     * lo stesso numero — altrimenti si riapre il difetto per cui esiste questo modulo.
     */
    riepiloghi?: {
        aliquota_iva: unknown;
        natura?: string | null;
        imposta: unknown;
        /**
         * L'imponibile che il gruppo dichiara. ⚠️ **Non è decorativo:** è il metro con cui si
         * decide se l'imposta dichiarata descriva ancora le righe che ha davanti. Vedi la
         * guardia 4 in `distribuisciImpostaDichiarata()`.
         */
        imponibile?: unknown;
    }[] | null;
    /** `null` quando la ritenuta non si applica: fornitore non soggetto, forfetario, esclusa sul documento. */
    ritenuta: RegimeRitenuta | null;
}

/** Tutto in centesimi interi, come a database. */
export interface TotaliFattura {
    imponibile_cents: number;
    iva_cents: number;
    imponibile_ordinario_cents: number;
    iva_ordinaria_cents: number;
    imponibile_sopravvenienza_cents: number;
    iva_sopravvenienza_cents: number;
    base_ritenuta_cents: number;
    ritenuta_cents: number;
    totale_documento_cents: number;
    netto_cents: number;
    ha_sopravvenienze: boolean;
    /**
     * L'IVA di ciascuna riga, nello stesso ordine di `input.righe`.
     *
     * ⚠️ **Esiste perché dalla beta.19 l'IVA di riga non è più ricostruibile dalla riga.**
     * Quando il documento dichiara la propria imposta, un centesimo di compensazione può
     * finire su una riga qualsiasi del gruppo: `round(imponibile × aliquota / 100)` non lo
     * vede. Chi mostra o addebita il lordo di una riga deve prendere l'IVA da qui, altrimenti
     * l'anteprima dice € 8,45 e la fattura registrata vale € 8,46 — misurato a schermo sul
     * file 06 dei collaudi il 06/09/2026, ed è il difetto che questa proprietà chiude.
     *
     * Vuoto sul ramo del debito pregresso, che non ha righe di dettaglio.
     */
    iva_righe_cents: number[];
}

/**
 * Percentuali di ritenuta da applicare, o `null` se non si applica.
 *
 * Il regime nuovo (`tipo_ritenuta`) prevale sui campi legacy del fornitore, esattamente
 * come in `RitenutaService::calcola()`. Il forfetario è escluso per legge, a prescindere
 * da `soggetto_ritenuta`.
 */
export const risolviRegimeRitenuta = (
    fornitore: {
        soggetto_ritenuta?: boolean;
        regime_forfetario?: boolean;
        tipo_ritenuta?: string | null;
        perc_ritenuta?: number | null;
        perc_imponibile_ritenuta?: number | null;
    } | null | undefined,
    applicaRitenuta: boolean,
): RegimeRitenuta | null => {
    if (!fornitore || !fornitore.soggetto_ritenuta || fornitore.regime_forfetario || !applicaRitenuta) {
        return null;
    }

    const regime = fornitore.tipo_ritenuta ? REGIMI_RITENUTA_PREVIEW[fornitore.tipo_ritenuta] : null;

    return {
        // `?? 100`, non `|| 100`: il PHP legge `(float) ($fornitore->perc_imponibile_ritenuta ?? 100)`,
        // quindi una base configurata a ZERO resta zero — nessuna ritenuta. Con `|| 100` diventava
        // invece il 100%, e l'anteprima annunciava una trattenuta che il salvataggio non faceva.
        percBase: regime ? regime.base : numero(fornitore.perc_imponibile_ritenuta ?? 100),
        percTratt: regime ? regime.aliquota : numero(fornitore.perc_ritenuta ?? 0),
    };
};

/** Cast numerico con la tolleranza di `(float)` in PHP: quello che non è un numero vale zero. */
const numero = (v: unknown): number => {
    const n = Number(v);

    return Number.isFinite(n) ? n : 0;
};

/**
 * Ripartisce un importo in centesimi su pesi già normalizzati, col metodo dei resti maggiori.
 *
 * ⚠️ **Gemella di `MoneyHelper::distribuisciPesiNormalizzati()`**: stesso ordine, stessi
 * arrotondamenti intermedi, stesso criterio su chi prende il centesimo avanzato. Se una delle
 * due cambia, cambiano entrambe — è il patto in testa a questo file.
 */
const distribuisciPesiNormalizzati = (pesi: number[], totale: number): number[] => {
    if (totale === 0) return pesi.map(() => 0);

    const segno = totale < 0 ? -1 : 1;
    const assoluto = Math.abs(totale);
    const basi: number[] = [];
    const resti: { i: number; resto: number }[] = [];
    let sommaBasi = 0;

    pesi.forEach((peso, i) => {
        // `round(x, 8)` del PHP: qui si arrotonda all'ottavo decimale prima del floor.
        const grezzo = Math.round(assoluto * peso * 1e8) / 1e8;
        const base = Math.floor(grezzo);
        basi[i] = base;
        resti.push({ i, resto: grezzo - base });
        sommaBasi += base;
    });

    let avanzo = assoluto - sommaBasi;
    if (avanzo > 0) {
        // `arsort` è stabile: a parità di resto vince chi viene prima. Qui serve lo stesso
        // ordinamento stabile, altrimenti l'anteprima assegna il centesimo a un'altra riga.
        resti.sort((a, b) => (b.resto - a.resto) || (a.i - b.i));
        for (let k = 0; k < avanzo && k < resti.length; k++) basi[resti[k].i]++;
    }

    return basi.map((b) => b * segno);
};

/**
 * L'imposta dichiarata dal documento, già ripartita fra le righe. Vuota quando il documento non
 * dichiara riepiloghi utilizzabili. Le tre guardie sono quelle del PHP: peso zero → imposta
 * zero; gruppo che somma a zero → si torna al calcolo di riga; riga fuori dai gruppi dichiarati
 * → non si tocca.
 */
const distribuisciImpostaDichiarata = (input: InputTotali): Record<number, number> => {
    const gruppi = input.riepiloghi;
    if (!Array.isArray(gruppi) || gruppi.length === 0) return {};

    const chiave = (aliquota: unknown, natura?: string | null): string =>
        `${Number(aliquota ?? 0).toFixed(2)}|${natura ?? ''}`;

    // ⚠️ Si SOMMA, non si assegna: il tracciato ammette piu' blocchi sulla stessa coppia, e
    // `FatturaPaFattura::impostaDichiarataCents()` li somma gia' tutti. Vedi il gemello PHP.
    const impostaPerGruppo: Record<string, number> = {};
    const imponibileDichiaratoPerGruppo: Record<string, number> = {};
    const senzaImponibile = new Set<string>();
    gruppi.forEach((g) => {
        const k = chiave(g.aliquota_iva, g.natura);
        impostaPerGruppo[k] = (impostaPerGruppo[k] ?? 0) + euroToCents(g.imposta);

        // ⚠️ L'assenza dell'imponibile non è uno zero: senza metro la guardia 4 non si applica.
        if (g.imponibile === undefined || g.imponibile === null) {
            senzaImponibile.add(k);
        } else {
            imponibileDichiaratoPerGruppo[k] = (imponibileDichiaratoPerGruppo[k] ?? 0) + euroToCents(g.imponibile);
        }
    });
    senzaImponibile.forEach((k) => { delete imponibileDichiaratoPerGruppo[k]; });

    const pesiPerGruppo: Record<string, { i: number; peso: number }[]> = {};
    input.righe.forEach((r, i) => {
        const k = chiave(r.aliquota_iva, r.natura);
        if (!(k in impostaPerGruppo)) return;
        (pesiPerGruppo[k] ??= []).push({ i, peso: euroToCents(r.importo_imponibile) });
    });

    const distribuita: Record<number, number> = {};
    Object.entries(pesiPerGruppo).forEach(([k, righe]) => {
        const conPeso = righe.filter((r) => r.peso !== 0);
        righe.filter((r) => r.peso === 0).forEach((r) => { distribuita[r.i] = 0; });
        if (conPeso.length === 0) return;

        const somma = conPeso.reduce((t, r) => t + r.peso, 0);
        if (somma === 0) return;

        // ⚠️ Guardia 4 — l'imposta dichiarata descrive un imponibile dichiarato. Finche' le righe
        // del gruppo sommano a quell'imponibile la si usa; appena divergono, QUEL gruppo torna al
        // calcolo per riga. Stessa regola del gemello PHP, e per la stessa ragione.
        const dichiarato = imponibileDichiaratoPerGruppo[k];
        if (dichiarato !== undefined && righe.reduce((t, r) => t + r.peso, 0) !== dichiarato) {
            righe.forEach((r) => { delete distribuita[r.i]; });
            return;
        }

        const quote = distribuisciPesiNormalizzati(conPeso.map((r) => r.peso / somma), impostaPerGruppo[k]);
        conPeso.forEach((r, j) => { distribuita[r.i] = quote[j]; });
    });

    return distribuita;
};

export const calcolaTotali = (input: InputTotali): TotaliFattura => {
    let imponibile = 0;
    let iva = 0;
    let imponibileOrdinario = 0;
    let ivaOrdinaria = 0;
    let imponibileSopravvenienza = 0;
    let ivaSopravvenienza = 0;
    let baseRitenuta = 0;
    const ivaRighe: number[] = [];

    if (input.is_pregresso) {
        // Nessuna riga di dettaglio: l'imponibile intero fa da base ritenuta, come il
        // `$imponibileTotaleFallback` che il service passa a RitenutaService.
        imponibile = euroToCents(input.imponibile_pregresso);
        // Come il PHP: l'imposta dichiarata vince sull'aliquota media arrotondata.
        iva = input.imposta_pregressa !== undefined && input.imposta_pregressa !== null
            ? euroToCents(input.imposta_pregressa)
            : ivaRigaCents(imponibile, input.aliquota_iva_pregressa);
        imponibileOrdinario = imponibile;
        ivaOrdinaria = iva;
        baseRitenuta = imponibile;
    } else {
        const ivaDistribuita = distribuisciImpostaDichiarata(input);

        input.righe.forEach((r, i) => {
            const impRiga = euroToCents(r.importo_imponibile);
            const ivaRiga = ivaDistribuita[i] ?? ivaRigaCents(impRiga, r.aliquota_iva);
            ivaRighe.push(ivaRiga);

            imponibile += impRiga;
            iva += ivaRiga;

            if (r.is_sopravvenienza) {
                imponibileSopravvenienza += impRiga;
                ivaSopravvenienza += ivaRiga;
            } else {
                imponibileOrdinario += impRiga;
                ivaOrdinaria += ivaRiga;
            }

            if (r.concorre_base_ritenuta !== false) {
                baseRitenuta += impRiga;
            }
        });
    }

    let baseCalcolo = 0;
    let ritenuta = 0;

    if (input.ritenuta) {
        baseCalcolo = arrotonda((baseRitenuta * input.ritenuta.percBase) / 100);
        ritenuta = arrotonda((baseCalcolo * input.ritenuta.percTratt) / 100);
    }

    const totaleDocumento = imponibile + iva;

    return {
        imponibile_cents: imponibile,
        iva_cents: iva,
        imponibile_ordinario_cents: imponibileOrdinario,
        iva_ordinaria_cents: ivaOrdinaria,
        imponibile_sopravvenienza_cents: imponibileSopravvenienza,
        iva_sopravvenienza_cents: ivaSopravvenienza,
        base_ritenuta_cents: baseCalcolo,
        ritenuta_cents: ritenuta,
        totale_documento_cents: totaleDocumento,
        netto_cents: totaleDocumento - ritenuta,
        ha_sopravvenienze: imponibileSopravvenienza > 0,
        iva_righe_cents: ivaRighe,
    };
};
