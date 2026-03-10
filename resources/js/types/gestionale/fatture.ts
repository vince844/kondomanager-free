export interface FornitoreFattura {
    id: number;
    ragione_sociale: string;
    piva?: string | null;
    codice_fiscale?: string | null;
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
    stato_pagamento: 'aperta' | 'parziale' | 'pagata';
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
    } | null;

    // Timestamps
    created_at: string;
    updated_at: string;

    // Relazioni precaricate dal Backend (Eager Loading)
    fornitore?: FornitoreFattura;
}