// types/gestionale/conti.ts

export interface DettaglioCopertura {
  piano: string;
  piano_rate_id: number;
  importo: number; // in centesimi
  fonte: 'diretta' | 'indiretta' | 'mista';
  stato: 'bozza' | 'approvato';
  is_shifted?: boolean; // Per identificare gli spostamenti
  is_auto?: boolean;    // Per identificare i Jolly/NULL
  is_bozza?: boolean;
  note?: string | null;
}

export interface AddebitoPersonale {
  tipo: 'condominiale' | 'privato';
  immobile: string;
  proprietario: string;
  importo: number; // in centesimi
}

export interface StrategiaSforo {
  strategia: 'fondo_riserva' | 'conguaglio_fine_anno' | 'nuovo_piano_rate';
  motivazione: string;
  importo: number; // in centesimi
}

export interface Conto {
  id: number
  piano_conto_id: number
  parent_id: number | null
  // Fatto esplicito e persistito lato server — mai indovinato da importo/parent_id
  // (bug "voce a zero perde la tabella millesimale").
  is_capitolo: boolean
  codice?: string | null
  nome: string
  descrizione: string | null
  tipo: 'spesa' | 'entrata'
  importo: string // Stringa formattata ("1.234,56 €")
  note: string | null
  is_tecnico?: boolean
  
  // Smart Fields (Nuovi)
  importo_raw?: number; // Importo in centesimi (necessario per calcoli JS)
  default_fornitore_id?: number | null
  fornitore_nome?: string | null
  tipo_spesa?: 'standard' | 'professionista' | 'lavori' | 'utenza'

  // Audit & Deviazioni (Tridente)
  addebiti_personali?: AddebitoPersonale[];
  strategie_sforo?: StrategiaSforo[];

  // Radar Copertura & Lucchetto
  impegnato?: number
  percentuale_copertura?: number
  stato_copertura?: 'empty' | 'partial' | 'full' | 'over'
  piani_collegati?: string[]
  dettaglio_copertura?: DettaglioCopertura[] // Array per la tabella dettaglio
  has_rate_emesse?: boolean

  is_parent?: boolean
  is_frazionato?: boolean
  figli_names?: string[]
  importo_originale?: number
  importo_residuo: number; 
  formatted_residuo: string

  sottoconti?: Conto[]
  tabelle_millesimali?: ContoTabellaMillesimale[]
}

export interface ContoTabellaMillesimale {
  id: number
  conto_id: number
  tabella_id: number
  coefficiente: number
  tabella?: { 
    id: number
    nome: string
  }
  ripartizioni?: ContoRipartizione[]
}

export interface ContoRipartizione {
  id: number
  conto_tabella_millesimale_id: number
  soggetto: 'proprietario' | 'inquilino' | 'usufruttuario'
  percentuale: number
}

export interface TabellaMillesimale {
  id: number
  condominio_id: number
  nome: string
  principale: boolean
  descrizione?: string
}

// Tipi per la creazione/modifica di un conto
export interface CreateContoData {
  nome: string
  descrizione?: string | null
  tipo: 'spesa' | 'entrata'
  importo: string 
  parent_id?: number | null
  note?: string | null
}

export interface UpdateContoData extends Partial<CreateContoData> {
  id: number
}

// Utility types
export type ContoTipo = 'spesa' | 'entrata'
export type ContoSoggetto = 'proprietario' | 'inquilino' | 'usufruttuario'

// Helper semplificati
export const ContoHelpers = {
  // Fatto esplicito e persistito — mai indovinato da importo (l'euristica
  // precedente usava anche `importo.includes('0,00')`, che matcha per errore
  // anche "€ 10,00"). Vedi bug "voce a zero perde la tabella millesimale".
  isCapitolo(conto: Conto): boolean {
    return !!conto.is_capitolo
  },

  // Verifica se un conto ha sottoconti
  hasSottoconti(conto: Conto): boolean {
    return !!conto.sottoconti && conto.sottoconti.length > 0
  },

  // Verifica se l'importo è positivo
  hasImportoPositivo(conto: Conto): boolean {
    return conto.importo !== '€ 0,00' && conto.importo !== '0,00'
  },

  // Ottiene il colore in base al tipo di conto
  getTipoColor(tipo: ContoTipo): string {
    return tipo === 'spesa' ? 'text-red-600' : 'text-green-600'
  }
}