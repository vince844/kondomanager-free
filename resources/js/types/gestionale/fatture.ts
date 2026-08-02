export interface FornitoreFattura {
    id: number;
    ragione_sociale: string;
    piva?: string | null;
    codice_fiscale?: string | null;
}

// 1. Nuova interfaccia minima per i Documenti allegati
export interface DocumentoFattura {
    id: number;
    name: string;
    path: string;
    mime_type?: string;
    file_size?: number;
}

export interface FatturaPassiva {
    id: number;
    condominio_id: number;
    esercizio_id: number;
    fornitore_id: number;
    conto_corrente_id?: number | null;

    // Dati Documento
    tipo_documento: 'fattura' | 'nota_credito';
    numero_documento: string;
    numero_protocollo?: string | null;
    data_documento: string;
    data_scadenza: string;
    is_pregresso: boolean;

    // Importi (in centesimi, come da database)
    importo_imponibile: number;
    importo_iva: number;
    importo_ritenuta: number;
    totale_documento: number;
    netto_a_pagare: number;

    // Stati (Ciclo di vita)
    stato_pagamento: 'aperta' | 'parziale' | 'pagata' | 'stornata'; // FIX: Aggiunto 'stornata'
    stato_approvazione: 'da_approvare' | 'approvata' | 'contestata' | 'sforo_motivato';

    // Pagamento
    modalita_pagamento?: string | null;
    iban_fornitore?: string | null;

    // JSON Extra
    dati_extra?: {
        fiscal?: {
            cig?: string;
            cup?: string;
            ritenuta_details?: any;
        };
        competenza?: {
            dal?: string;
            al?: string;
        };
        override_budget?: {
            motivazione: string;
            importo_sforo: number;
            budget_residuo_al_momento: number;
            timestamp: string;
        };
        // FIX: Aggiunti i campi per la logica dello Storno Contabile
        is_stornata?: boolean;
        stornata_da_id?: number;
        nota_storno?: string;
    } | null;

    // Timestamps
    created_at: string;
    updated_at: string;

    /**
     * Perché la fattura non si può eliminare, calcolato dal server con tutti e
     * sette i motivi; `null` (o assente) significa eliminabile. Non è una
     * colonna: viene aggiunto solo nell'elenco, con `append()`.
     */
    motivo_blocco_eliminazione?: string | null;

    // Relazioni precaricate dal Backend (Eager Loading)
    fornitore?: FornitoreFattura;
    documenti?: DocumentoFattura[]; // FIX: Aggiunta la relazione dei documenti
}