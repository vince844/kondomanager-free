import type { Gestione } from './gestioni'
import type { Conto } from './conti'

export interface PianoRate {
  id: number
  nome: string
  descrizione: string
  note: string
  gestione: Gestione
}

/**
 * Stato contabile del piano nell'esercizio
 */
export interface PianoRateContabile extends PianoRate {
  stato: 'bozza' | 'approvato'
  numero_rate: number
  totale_capitoli?: number
  capitoli: Conto[]
  budget_movements?: BudgetMovement[]
}

export interface BudgetMovement {
  source_conto_id?: number
  destination_conto_id?: number
}