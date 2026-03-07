import type { Gestione } from './gestioni'
import type { Conto } from './conti'

export interface PianoRate {
  id: number
  nome: string
  descrizione: string
  note?: string
  numero_rate: number
  stato: string // o 'bozza' | 'approvato' se preferisci string literal types
  giorno_scadenza?: number
  metodo_distribuzione?: string
  data_inizio: string
  totale_capitoli?: number
  totale_piano?: number
  gestione?: Gestione
  has_saldi: boolean
}

/**
 * Stato contabile del piano nell'esercizio
 */
export interface PianoRateContabile extends PianoRate {
  capitoli: Conto[]
  budget_movements?: BudgetMovement[]
}

export interface BudgetMovement {
  source_conto_id?: number
  destination_conto_id?: number
}