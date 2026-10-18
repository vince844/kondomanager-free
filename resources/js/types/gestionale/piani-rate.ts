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
  // In centesimi. Le entrate si sottraggono: da ripartire = spese − entrate.
  totale_spese?: number
  totale_entrate?: number
  totale_da_ripartire?: number
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