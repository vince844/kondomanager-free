/**
 * Il consuntivo di una voce del piano dei conti, come lo mostrano l'albero, la scheda della voce e la finestra dei movimenti.
 *
 * Una voce di spesa ha il consuntivo (`speso_raw`: fatture, regolazioni, commissioni) confrontato con il preventivo vero
 * (`budget_originale_raw`, non `importo_raw`, che il server porta allo speso quando c'è uno sforo). Una voce di entrata con i soli incassi da
 * terzi ha l'incassato (`incassato_raw`, positivo: `SpesaPerVoceService::perTipo`): si chiama «Incassato», non è mai uno speso negativo e mai
 * uno sforo. Una voce di entrata con dei dati di prima (una fattura registrata quando la registrazione la accettava, una regolazione scritta a
 * rovescio: `speso_raw` diverso da zero) si legge come prima, con il consuntivo e lo sforo sul preventivo: nessuna lettura nuova per i dati
 * vecchi. Il segno del tipo è quello del motore (`segnoDelTipo`).
 */

import { segnoDelTipo } from '@/lib/gestionale/pianiRate/totaliPerTipo';

export interface VoceConConsuntivo {
    tipo?: string | null;
    speso_raw?: number;
    incassato_raw?: number;
    budget_originale_raw?: number;
    importo_raw?: number;
}

export interface ConsuntivoDellaVoce {
    entrata: boolean;
    etichetta: 'Consuntivo' | 'Incassato';
    /** Centesimi: lo speso di una voce di spesa, l'incassato di una voce di entrata. */
    importo: number;
    preventivo: number;
    /** Preventivo meno speso: negativo vuol dire sforo. Per una voce di entrata, preventivo meno incassato. */
    differenza: number;
    haMovimenti: boolean;
    isSforo: boolean;
    percentuale: number | null;
}

export function consuntivoDellaVoce(voce: VoceConConsuntivo): ConsuntivoDellaVoce {
    const speso = voce.speso_raw ?? 0;
    // L'incassato vale solo per una voce di entrata senza dati di prima: con quelli la voce si legge come si leggeva.
    const entrata = (voce.tipo ? segnoDelTipo(voce.tipo) === -1 : false) && speso === 0;
    const importo = entrata ? (voce.incassato_raw ?? 0) : speso;
    const preventivo = voce.budget_originale_raw ?? voce.importo_raw ?? 0;
    const differenza = preventivo - importo;
    // Una voce senza preventivo non sfora (le sopravvenienze nascono fuori preventivo); un'entrata incassata più del previsto nemmeno.
    const isSforo = !entrata && differenza < 0 && preventivo > 0;

    return {
        entrata,
        etichetta: entrata ? 'Incassato' : 'Consuntivo',
        importo,
        preventivo,
        differenza,
        haMovimenti: importo !== 0,
        isSforo,
        percentuale: isSforo ? Math.round((Math.abs(differenza) / preventivo) * 1000) / 10 : null,
    };
}
