// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La ratifica di una spesa oltre il preventivo nelle pagine della fattura (R12, decisione 561): la forma della fattura che le due finestre («Paga per
 * l'urgenza», «Ratifica») leggono, e quella del riquadro della pagina della fattura (`RatificaPerLePagine::perLaPagina`). Gli importi in centesimi.
 */

export interface FatturaDellaRatifica {
    id: number;
    numero_documento: string;
    /** `YYYY-MM-DD`. */
    data_documento: string | null;
    fornitore_nome: string | null;
    /** Centesimi. */
    totale_documento: number | null;
    stato_approvazione: string;
    /** Di quanto supera il preventivo, in centesimi. */
    sforo_oltre: number | null;
    voce_sforo: string | null;
    /** La motivazione scritta alla registrazione della fattura fuori preventivo: «Paga per l'urgenza» parte già scritta con quella. */
    motivazione_sforo?: string | null;
    /** Lo stato del pagamento (`aperta`, `parziale`, `pagata`…): «Sbloccata per l'urgenza», «Pagata in parte per l'urgenza», «Pagata per l'urgenza». */
    stato_pagamento?: string | null;
}

/**
 * «sbloccata per l'urgenza» finché la fattura non è pagata, «pagata in parte per l'urgenza» fino al saldo, «pagata per l'urgenza» dopo: la regola della pagina
 * della fattura e del punto proposto (`RatificaDelloSforo::parolaDelPagamento()` del server).
 */
export function parolaDellUrgenza(statoPagamento: string | null | undefined): string {
    const parola = statoPagamento === 'parziale' ? 'pagata in parte' : statoPagamento === 'pagata' ? 'pagata' : 'sbloccata';

    return `${parola} per l'urgenza`;
}

export interface RatificaDellaPagina {
    stato: 'da_ratificare' | 'ratificata';
    in_sforo: boolean;
    sforo: { oltre: number | null; voce: string | null; motivazione?: string | null };
    /** `parola`: «Sbloccata», «Pagata in parte» o «Pagata», davanti a «per l'urgenza». */
    sbloccata: { il: string | null; da: string | null; motivo: string | null; pagata: boolean; parola?: string } | null;
    delibera: {
        frase: string;
        deliberata_il: string | null;
        assemblea_id: number;
        punto_id: number | null;
        assemblea: string | null;
        numero_verbale: string | null;
        registrata_il: string | null;
        registrata_da: string | null;
        nota: string | null;
    } | null;
    /**
     * `testo`: il testo del punto per i condòmini senza la riga del motivo (che ha il suo dato); `proposta`: ancora fra i punti proposti, non in un'assemblea;
     * `numero` e `il` (`GG/MM/AAAA`): il punto e il giorno dell'assemblea che lo ha.
     */
    in_assemblea: { frase: string; testo?: string; proposta?: boolean; numero?: number | null; il?: string | null; assemblea_id: number | null } | null;
    di_prima: { il: string | null; nota: string | null } | null;
}

/** La fattura nella forma delle due finestre, da una riga dell'elenco, da una pendenza o dalla pagina della fattura. */
export function fatturaDellaRatifica(riga: object, fornitore?: string | null): FatturaDellaRatifica {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const f = riga as Record<string, any>;
    const data = typeof f.data_documento_iso === 'string' ? f.data_documento_iso
        : (typeof f.data_documento === 'string' && /^\d{4}-\d{2}-\d{2}/.test(f.data_documento) ? f.data_documento.slice(0, 10) : null);
    const totale = f.totale_documento ?? f.importo_lordo ?? null;
    return {
        id: Number(f.id),
        numero_documento: String(f.numero_documento ?? ''),
        data_documento: data,
        fornitore_nome: fornitore ?? f.fornitore?.ragione_sociale ?? null,
        totale_documento: totale === null || totale === undefined ? null : Number(totale),
        stato_approvazione: String(f.stato_approvazione ?? ''),
        sforo_oltre: f.sforo_oltre ?? f.dati_extra?.override_budget?.importo_sforo ?? null,
        voce_sforo: f.voce_sforo ?? null,
        motivazione_sforo: f.motivazione_sforo ?? f.dati_extra?.override_budget?.motivazione ?? null,
        stato_pagamento: typeof f.stato_pagamento === 'string' ? f.stato_pagamento : (f.stato_pagamento?.value ?? null),
    };
}

/** Un dato del riquadro della ratifica: l'etichetta sopra, il valore sotto, e l'eventuale rimando all'assemblea. */
export interface DatoDellaRatifica {
    id: string;
    etichetta: string;
    valore: string;
    /** L'assemblea da aprire, per «Nell'assemblea». */
    assemblea_id?: number | null;
}

/**
 * I dati del ramo «Da ratificare», uno per riga, con l'etichetta sopra (decisione 642): «Sbloccata» (il giorno e chi), «Nell'assemblea» (proposta fra i punti della
 * prossima, o il punto di quella che lo ha), «Perché era urgente» (il motivo) oppure, per la fattura solo in sforo, «La motivazione», e «Il punto proposto»
 * (il testo senza il motivo). Quello che non c'è non si scrive.
 */
export function datiDellaRatifica(r: Pick<RatificaDellaPagina, 'sbloccata' | 'sforo' | 'in_assemblea'>): DatoDellaRatifica[] {
    const dati: DatoDellaRatifica[] = [];
    if (r.sbloccata) {
        const quando = [r.sbloccata.il ? `il ${r.sbloccata.il}` : null, r.sbloccata.da ? `da ${r.sbloccata.da}` : null].filter(Boolean).join(', ');
        if (quando) {
            dati.push({ id: 'sbloccata', etichetta: 'Sbloccata', valore: quando });
        }
    }
    const a = r.in_assemblea;
    if (a) {
        const dove = a.proposta !== false && !a.assemblea_id ? 'Proposta fra i punti della prossima assemblea'
            : `Punto ${a.numero ?? ''} dell'assemblea${a.il ? ` del ${a.il}` : ''}`.replace('Punto  ', 'Punto ');
        dati.push({ id: 'nell-assemblea', etichetta: 'Nell\'assemblea', valore: dove, assemblea_id: a.assemblea_id });
    }
    const motivo = r.sbloccata ? r.sbloccata.motivo : (r.sforo.motivazione ?? null);
    if (motivo) {
        dati.push({ id: 'motivo', etichetta: r.sbloccata ? 'Perché era urgente' : 'La motivazione', valore: `«${motivo}»` });
    }
    if (a?.testo) {
        dati.push({ id: 'punto-proposto', etichetta: 'Il punto proposto', valore: a.testo });
    }

    return dati;
}
