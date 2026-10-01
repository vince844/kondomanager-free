export interface Incasso {
    id: number;
    numero_protocollo: string;
    data_competenza: string;    // YYYY-MM-DD
    data_registrazione: string; // YYYY-MM-DD
    causale: string;
    gestione_nome: string;
    cassa_nome: string;
    stato: 'registrata' | 'annullata' | 'bozza';
    
    // 🔥 CAMPI IMPORTI AGGIORNATI
    importo_totale_raw: number;       // Float per calcoli/colori
    importo_totale_formatted: string; // Stringa "€ 100,00"
    
    pagante: {
        principale: string;
        altri_count: number;
        lista_completa: string;
        ruolo: string; 
        /** Coda 167: chi ha versato davvero, se non è la posizione. */
        versato_da?: string | null;
    };

    cassa_tipo_label: string;
    
    // CAMPO DETTAGLI AGGIORNATO (Non è più string!)
    dettagli_rate: Array<{
        numero: number;
        scadenza: string;
        importo_formatted: string;
        /** Coda 167 (R6): la rata di chi ha versato che riceve la parte in più; `null` sulle rate della posizione. */
        credito_di?: string | null;
    }>;
    
    anagrafica_id_principale: number | null; // Meglio number se l'ID è int
}