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
 * 1. **L'IVA si arrotonda per riga**, non sul totale. Con più righe le due strade divergono.
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
    righe: RigaTotali[];
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

export const calcolaTotali = (input: InputTotali): TotaliFattura => {
    let imponibile = 0;
    let iva = 0;
    let imponibileOrdinario = 0;
    let ivaOrdinaria = 0;
    let imponibileSopravvenienza = 0;
    let ivaSopravvenienza = 0;
    let baseRitenuta = 0;

    if (input.is_pregresso) {
        // Nessuna riga di dettaglio: l'imponibile intero fa da base ritenuta, come il
        // `$imponibileTotaleFallback` che il service passa a RitenutaService.
        imponibile = euroToCents(input.imponibile_pregresso);
        iva = ivaRigaCents(imponibile, input.aliquota_iva_pregressa);
        imponibileOrdinario = imponibile;
        ivaOrdinaria = iva;
        baseRitenuta = imponibile;
    } else {
        input.righe.forEach((r) => {
            const impRiga = euroToCents(r.importo_imponibile);
            const ivaRiga = ivaRigaCents(impRiga, r.aliquota_iva);

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
    };
};
