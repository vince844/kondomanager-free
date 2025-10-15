// types/gestionale/conti.ts
import { PianoDeiConti } from '@/types/gestionale/piani-dei-conti';

export interface Conto {
  id: number
  piano_conto_id: number
  parent_id: number | null
  nome: string
  descrizione: string | null
  attivo: boolean
  tipo: 'spesa' | 'entrata'
  importo: number // in centesimi
  destinazione_type: string | null
  destinazione_id: number | null
  note: string | null
  created_at: string
  updated_at: string
  sottoconti?: Conto[] // Relazione ricorsiva per i sottoconti
  
  // Relazioni caricate opzionalmente
  parent?: Conto
  piano_conto?: PianoDeiConti
  tabelle_millesimali?: ContoTabellaMillesimale[]
}

export interface ContoTabellaMillesimale {
  id: number
  conto_id: number
  tabella_id: number
  coefficiente: number
  created_at: string
  updated_at: string
  ripartizioni?: ContoRipartizione[]
  tabella?: TabellaMillesimale
}

export interface ContoRipartizione {
  id: number
  conto_tabella_millesimale_id: number
  soggetto: 'proprietario' | 'inquilino' | 'usufruttuario'
  percentuale: number
  created_at: string
  updated_at: string
}

export interface TabellaMillesimale {
  id: number
  condominio_id: number
  nome: string
  principale: boolean
  descrizione?: string
  created_at: string
  updated_at: string
}

// Tipi per la creazione/modifica di un conto
export interface CreateContoData {
  nome: string
  descrizione?: string | null
  tipo: 'spesa' | 'entrata'
  importo: number // in centesimi
  parent_id?: number | null
  note?: string | null
  tabella_millesimale_id?: number | null
  percentuale_proprietario?: number
  percentuale_inquilino?: number
  percentuale_usufruttuario?: number
}

export interface UpdateContoData extends Partial<CreateContoData> {
  id: number
}

// Filtri per la ricerca dei conti
export interface ContoFilters {
  nome?: string
  tipo?: 'spesa' | 'entrata'
  attivo?: boolean
  parent_id?: number | null
  piano_conto_id?: number
}

// Risposta API per i conti
export interface ContiResponse {
  data: Conto[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}

// Dati per l'albero dei conti
export interface ContoAlbero extends Conto {
  livello: number
  espanso?: boolean
  selezionato?: boolean
  has_children: boolean
}

// Statistiche per i conti
export interface ContoStats {
  totale_conti: number
  totale_spese: number // in centesimi
  totale_entrate: number // in centesimi
  conti_attivi: number
  conti_capitolo: number
  conti_con_sottoconti: number
}

// Utility types
export type ContoTipo = 'spesa' | 'entrata'
export type ContoSoggetto = 'proprietario' | 'inquilino' | 'usufruttuario'

// Enums per maggiore type safety
export enum ContoTipoEnum {
  SPESA = 'spesa',
  ENTRATA = 'entrata'
}

export enum ContoSoggettoEnum {
  PROPRIETARIO = 'proprietario',
  INQUILINO = 'inquilino',
  USUFRUTTUARIO = 'usufruttuario'
}

// Funzioni helper per lavorare con i conti
export const ContoHelpers = {
  // Verifica se un conto è un capitolo (importo 0 e ha sottoconti)
  isCapitolo(conto: Conto): boolean {
    return conto.importo === 0 && !!conto.sottoconti && conto.sottoconti.length > 0
  },

  // Verifica se un conto ha sottoconti
  hasSottoconti(conto: Conto): boolean {
    return !!conto.sottoconti && conto.sottoconti.length > 0
  },

  // Formatta l'importo in Euro
  formatImporto(importo: number): string {
    return new Intl.NumberFormat('it-IT', {
      style: 'currency',
      currency: 'EUR',
      minimumFractionDigits: 2
    }).format(importo / 100)
  },

  // Converte Euro in centesimi
  toCents(euros: number): number {
    return Math.round(euros * 100)
  },

  // Converte centesimi in Euro
  toEuros(cents: number): number {
    return cents / 100
  },

  // Calcola il totale di un conto includendo i sottoconti
  calcolaTotaleConto(conto: Conto): number {
    if (ContoHelpers.isCapitolo(conto)) {
      // Per i capitoli, somma i sottoconti
      return conto.sottoconti?.reduce((total, sottoconto) => total + sottoconto.importo, 0) || 0
    }
    return conto.importo
  },

  // Verifica se le percentuali di ripartizione sono valide
  isValidRipartizione(percentuali: {
    percentuale_proprietario: number
    percentuale_inquilino: number
    percentuale_usufruttuario: number
  }): boolean {
    const totale = percentuali.percentuale_proprietario + 
                  percentuali.percentuale_inquilino + 
                  percentuali.percentuale_usufruttuario
    return totale === 100
  },

  // Ottiene il colore in base al tipo di conto
  getTipoColor(tipo: ContoTipo): string {
    return tipo === 'spesa' ? 'text-red-600 bg-red-50' : 'text-green-600 bg-green-50'
  },

  // Ottiene l'icona in base al tipo di conto
  getTipoIcon(tipo: ContoTipo): string {
    return tipo === 'spesa' ? 'trending-down' : 'trending-up'
  }
}

// Default values per la creazione di un conto
export const DefaultContoData: CreateContoData = {
  nome: '',
  tipo: 'spesa',
  importo: 0,
  descrizione: null,
  note: null,
  parent_id: null,
  percentuale_proprietario: 100,
  percentuale_inquilino: 0,
  percentuale_usufruttuario: 0
}

// Esempio di utilizzo:
/*
import type { Conto, CreateContoData } from '@/types/gestionale/conti'

const conto: Conto = {
  id: 1,
  piano_conto_id: 1,
  parent_id: null,
  nome: 'Spese condominiali',
  descrizione: 'Spese generali del condominio',
  attivo: true,
  tipo: 'spesa',
  importo: 150000, // 1500.00 €
  destinazione_type: null,
  destinazione_id: null,
  note: 'Note varie',
  created_at: '2024-01-15T10:00:00Z',
  updated_at: '2024-01-15T10:00:00Z',
  sottoconti: [
    {
      id: 2,
      piano_conto_id: 1,
      parent_id: 1,
      nome: 'Spese ascensore',
      descrizione: 'Manutenzione ascensore',
      attivo: true,
      tipo: 'spesa',
      importo: 50000, // 500.00 €
      destinazione_type: null,
      destinazione_id: null,
      note: null,
      created_at: '2024-01-15T10:00:00Z',
      updated_at: '2024-01-15T10:00:00Z'
    }
  ]
}
*/