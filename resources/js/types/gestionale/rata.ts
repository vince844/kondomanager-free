/**
 * Interfaccia per il dettaglio quote nel tooltip
 */
export interface DettaglioQuotaRata {
    unita: string;
    residuo: number;
    anagrafica: string;      
    ruolo: string;           
    is_credito: boolean;
    componente_saldo: number;
    componente_spesa: number;
    waterfall_start?: number;
    waterfall_cost?: number;
    waterfall_end?: number;
}

/**
 * Interfaccia per rappresentare una rata condominiale
 */
export interface Rata {
    /** ID univoco della rata (o della quota rata) */
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
    intestatari_full?: string;
    /** Tipologia della rata (opzionale) */
    tipologia?: string;
    /** Nome della gestione */
    gestione: string;
    /** Identificativo dell'unità immobiliare */
    unita: string;
    /** Importo totale originale della rata */
    importo_totale: number;
    /** ID della rata padre (fondamentale per collegare le quote alla rata originale) */
    rata_padre_id?: number;
    /** ID alternativo della rata (fallback) */
    rata_id?: number;
    /** Alias per data_scadenza usato per l'ordinamento */
    scadenza?: string;
    /** Controlla se la rata è stata emessa oppure no */
    is_emitted?: boolean;
    // Aggiunto questo campo opzionale per gestire il dettaglio delle quote
    dettaglio_quote?: DettaglioQuotaRata[];
    // Indica se la rata è parzialmente
    coperta_da_credito?: boolean;
    parzialmente_coperta?: boolean;
    residuo_originale?: number;
    is_published?: boolean;
}

export interface DettaglioPagamento {
    rata_id: number;
    importo: number;
}

export interface RigaPreview {
    id: number;
    descrizione: string;
    pagato: number;
    status: 'SALDATA' | 'PARZIALE';
    residuo_futuro: number;
}

export interface PreviewContabileRiga {
    id: number;
    descrizione: string;
    pagato: number;
    status: 'SALDATA' | 'PARZIALE' | 'COPERTA_CREDITO'; 
    residuo_futuro: number;
    tipo?: 'normale' | 'parzialmente_coperta' | 'credito';
    dettaglio?: string[]; 
}

export interface PreviewContabile {
    hasData: boolean;
    totale_versato: number;
    allocato_rate: number;
    anticipo: number;
    righe: PreviewContabileRiga[];
}

export interface BilancioFinale {
    label: string;
    value: number;
    class: string;
}

// ============================================================================
// INCASSI
// ============================================================================

export interface DettaglioRataIncasso {
    numero: number;
    scadenza: string;
    importo_formatted: string;
}

export interface PaganteIncasso {
    principale: string;
    altri_count: number;
    lista_completa: string;
    ruolo: string;
}

export interface Incasso {
    id: number;
    numero_protocollo: string;
    data_competenza: string;
    data_registrazione: string;
    causale: string;
    gestione_nome: string;
    cassa_nome: string;
    stato: 'registrata' | 'annullata' | 'bozza';
    importo_totale_raw: number;
    importo_totale_formatted: string;
    pagante: PaganteIncasso;
    cassa_tipo_label: string;
    dettagli_rate: DettaglioRataIncasso[];
    anagrafica_id_principale: number | null;
}

export interface RataPura {
  id: number
  numero_rata: number
  is_emessa: boolean
}