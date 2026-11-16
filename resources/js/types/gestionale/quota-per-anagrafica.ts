// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

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