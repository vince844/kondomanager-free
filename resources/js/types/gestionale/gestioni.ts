// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

import { Component } from 'vue';
import { Esercizio } from './esercizi';

// Nel tuo file Vue (es. FatturaRegisterNew.vue)
export interface Gestione {
    id: number;
    nome: string;
    descrizione?: string;
    note?: string;
    data_inizio?: string;
    data_fine?: string;
    tipo: 'ordinaria' | 'straordinaria';
    
    // NUOVO: Array degli ID degli esercizi a cui questa gestione è collegata
    esercizio_ids?: number[]; 
}

export interface StatusType {
  value: string;
  label: string;
  icon: Component;
  colorClass: string;
}