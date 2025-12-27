export interface Incasso {
    id: number;
    numero_protocollo: string;
    data_competenza: string;    // Formato YYYY-MM-DD
    data_registrazione: string; // Formato YYYY-MM-DD
    causale: string;
    stato: 'registrata' | 'annullata' | 'bozza';
    importo_totale: number;
    pagante_nome: string;
}