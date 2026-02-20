export interface QuotaPerAnagrafica {
  saldo_iniziale?: number
  rate?: RataPerAnagrafica[]
}

export interface RataPerAnagrafica {
  numero: number
  importo?: number
  scadenza: string
  stato: string
  importo_pagato?: number
}