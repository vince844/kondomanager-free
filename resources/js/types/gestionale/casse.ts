export type TipoCassa = 'contanti' | 'banca' | 'fondo' | 'virtuale';

export type TipoConto = 'ordinario' | 'dedicato' | 'postale' | 'contabilita_speciale' | 'estero' | 'altro';

// NUOVO TIPO: Garantisce type-safety per la destinazione d'uso
export type SottotipoFondo = 'generico' | 'vincolato_lavori' | 'tfr' | 'morosita';

export interface Cassa {
    id: number;
    condominio_id?: number; 
    
    nome: string;
    descrizione?: string;
    tipo: TipoCassa;
    tipo_label?: string; // Arriva dalla resource (es. "Banca")
    
    attiva: boolean;
    note?: string;

    // --- Dati appiattiti dalla CassaResource (Popolati se tipo === 'banca') ---
    banca_istituto?: string | null;
    banca_iban?: string | null;
    banca_predefinito?: boolean;
    banca_tipo_conto?: TipoConto | string | null; // Tipizzato meglio

    // --- NUOVI: Dati Governance Fondi (Popolati se tipo === 'fondo') ---
    sottotipo_fondo?: SottotipoFondo | string | null;
    vincolo_descrizione?: string | null;
    is_override_assemblea?: boolean | null;
    is_utilizzabile_per_imprevisti?: boolean | null;

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

// Opzioni per i dropdown (Banca e Fondo)
export interface ContoOption {
    label: string;
    value: string; // Oppure, se vuoi essere super rigoroso: value: TipoConto | SottotipoFondo
}