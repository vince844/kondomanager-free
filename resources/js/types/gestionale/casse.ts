export type TipoCassa = 'contanti' | 'banca' | 'fondo' | 'virtuale';

// Aggiungo anche questo tipo utile per il form
export type TipoConto = 'ordinario' | 'dedicato' | 'postale' | 'contabilita_speciale' | 'estero' | 'altro';

export interface Cassa {
    id: number;
    condominio_id?: number; 
    
    nome: string;
    descrizione?: string;
    tipo: TipoCassa;
    tipo_label?: string; // Arriva dalla resource (es. "Banca")
    
    attiva: boolean;
    note?: string;

    // --- Dati appiattiti dalla CassaResource ---
    // Questi campi sono popolati solo se tipo === 'banca'
    banca_istituto?: string | null;
    banca_iban?: string | null;
    banca_predefinito?: boolean;
    banca_tipo_conto?: string | null;

    // --- Dati Calcolati (Aggiornati con la nuova Resource) ---
    saldo_iniziale_raw?: number;
    saldo_iniziale_formatted?: string;

    saldo_raw?: number; // Sostituisce saldo_attuale per matchare la Resource
    saldo_formatted?: string; 
    
    totale_entrate_formatted?: string;
    totale_uscite_formatted?: string;

    created_at?: string;
    updated_at?: string;
}

// Opzioni per il dropdown Tipo Risorsa
export interface CassaOption {
    label: string;
    value: TipoCassa;
}

// Opzioni per il dropdown Tipo Conto Corrente (nel form di creazione)
export interface ContoOption {
    label: string;
    value: string;
}