// types/gestionale/conti.ts

export interface Conto {
  id: number
  piano_conto_id: number
  parent_id: number | null
  nome: string
  descrizione: string | null
  tipo: 'spesa' | 'entrata'
  importo: string // 👈 ORA È STRINGA FORMATTATA ("€ 1.234,56")
  note: string | null
  sottoconti?: Conto[] // Relazione ricorsiva per i sottoconti
  tabelle_millesimali?: ContoTabellaMillesimale[] // 👈 AGGIUNGI QUESTO
}

export interface ContoTabellaMillesimale {
  id: number
  conto_id: number
  tabella_id: number
  coefficiente: number
  tabella?: { // 👈 AGGIUNGI QUESTO PER I DATI DELLA TABELLA
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
  importo: string // 👈 Stringa formattata dal frontend ("1.234,56")
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
  // Verifica se un conto è un capitolo (importo 0 e ha sottoconti)
  isCapitolo(conto: Conto): boolean {
    return (conto.importo === '€ 0,00' || conto.importo === '0,00') && 
           !!conto.sottoconti && 
           conto.sottoconti.length > 0
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