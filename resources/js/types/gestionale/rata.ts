/**
 * Interfaccia per rappresentare una rata condominiale
 */
export interface Rata {
    /** ID univoco della rata */
    id: number;
    /** Descrizione della rata */
    descrizione: string;
    /** Importo residuo da pagare (può essere negativo se credito) */
    residuo: number;
    /** Importo da pagare in questa operazione */
    da_pagare: number;
    /** Indica se la rata è stata selezionata per il pagamento */
    selezionata: boolean;
    /** Indica se la rata è scaduta */
    scaduta: boolean;
    /** Data di scadenza della rata */
    data_scadenza: string | null;
    /** ID della gestione a cui appartiene la rata */
    gestione_id: number;
    /** Data di scadenza formattata per visualizzazione */
    scadenza_human: string;
    /** Nome dell'intestatario della rata */
    intestatario: string;
    /** Tipologia della rata (opzionale) */
    tipologia?: string;
    /** Nome della gestione */
    gestione: string;
    /** Identificativo dell'unità immobiliare */
    unita: string;
    /** Importo totale originale della rata */
    importo_totale: number;
}

/**
 * Interfaccia per il dettaglio di un pagamento
 */
export interface DettaglioPagamento {
    /** ID della rata pagata */
    rata_id: number;
    /** Importo pagato per questa rata */
    importo: number;
}

/**
 * Interfaccia per l'anteprima contabile
 */
export interface RigaPreview {
    /** ID della rata */
    id: number;
    /** Descrizione della rata */
    descrizione: string;
    /** Importo pagato */
    pagato: number;
    /** Status del pagamento: 'SALDATA' o 'PARZIALE' */
    status: 'SALDATA' | 'PARZIALE';
    /** Residuo futuro dopo il pagamento */
    residuo_futuro: number;
}

/**
 * Interfaccia per l'anteprima completa della registrazione
 */
export interface PreviewContabile {
    /** Indica se ci sono dati da mostrare */
    hasData: boolean;
    /** Totale versato */
    totale_versato: number;
    /** Totale allocato sulle rate */
    allocato_rate: number;
    /** Anticipo/Eccedenza */
    anticipo: number;
    /** Righe di dettaglio */
    righe: RigaPreview[];
}

/**
 * Interfaccia per il bilancio finale
 */
export interface BilancioFinale {
    /** Label del bilancio (Residuo, Credito, Saldo) */
    label: string;
    /** Valore del bilancio */
    value: number;

    /** Classi CSS per lo stile */
    class: string;
}

// ============================================================================
// INCASSI - Interfacce per la gestione degli incassi registrati
// ============================================================================

/**
 * Dettaglio di una rata nell'incasso
 */
export interface DettaglioRataIncasso {
    /** Numero della rata */
    numero: number;
    /** Data di scadenza */
    scadenza: string;
    /** Importo formattato (es. "€ 100,00") */
    importo_formatted: string;
}

/**
 * Informazioni sul pagante dell'incasso
 */
export interface PaganteIncasso {
    /** Nome principale del pagante */
    principale: string;
    /** Numero di altri paganti */
    altri_count: number;
    /** Lista completa dei paganti */
    lista_completa: string;
    /** Ruolo del pagante */
    ruolo: string;
}

/**
 * Interfaccia per un incasso registrato
 */
export interface Incasso {
    /** ID univoco dell'incasso */
    id: number;
    /** Numero di protocollo */
    numero_protocollo: string;
    /** Data di competenza (YYYY-MM-DD) */
    data_competenza: string;
    /** Data di registrazione (YYYY-MM-DD) */
    data_registrazione: string;
    /** Causale dell'incasso */
    causale: string;
    /** Nome della gestione */
    gestione_nome: string;
    /** Nome della cassa */
    cassa_nome: string;
    /** Stato dell'incasso */
    stato: 'registrata' | 'annullata' | 'bozza';
    /** Importo totale raw per calcoli/colori */
    importo_totale_raw: number;
    /** Importo totale formattato (es. "€ 100,00") */
    importo_totale_formatted: string;
    /** Informazioni sul pagante */
    pagante: PaganteIncasso;
    /** Label del tipo di cassa */
    cassa_tipo_label: string;
    /** Dettagli delle rate associate */
    dettagli_rate: DettaglioRataIncasso[];
    /** ID anagrafica principale del pagante */
    anagrafica_id_principale: number | null;
}