// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

import { Component } from 'vue';

export interface Esercizio {
    id: number
    nome: string
    descrizione: string
    note: string,
    data_inizio: string,
    data_fine: string | null,
    stato: 'aperto' | 'chiuso';         
}

export interface StatusType {
  value: string;
  label: string;
  icon: Component;
  colorClass: string;
}