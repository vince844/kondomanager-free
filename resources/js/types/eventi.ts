import { Component } from 'vue';
import { Anagrafica } from './anagrafiche';
import { User } from './users';
import { Building } from './buildings';
import { CategoriaEvento } from './categorie-eventi';

export type Stats = {
  next_seven_days: number,
  next_fourteen_days: number,
  next_twentyeight_days: number,
};

export type Recurrence = {
  frequency: 'daily' | 'weekly' | 'monthly' | 'yearly';
  interval: number;
  by_day: string[]; // e.g., ['MO', 'TU']
  until: string | null;
};

export interface VisibilityType {
  value: string;
  label: string;
  icon: Component;
  colorClass: string;
}

// NUOVO: Definiamo la struttura del campo JSON 'meta'
export interface EventoMeta {
  type?: string;              // es. 'emissione_rata', 'scadenza_rata_condomino'
  status?: string;            // es. 'pending', 'paid', 'partial', 'reported'
  requires_action?: boolean;  // Il flag per l'Inbox
  action_url?: string;        // Link per l'admin
  importo_originale?: number;
  importo_pagato?: number;
  importo_restante?: number;
  reported_at?: string;
  context?: Record<string, any>; // IDs vari (rata_id, piano_rate_id)
  [key: string]: any;         // Permette qualsiasi altro campo futuro
}

export interface Evento {
  id: number;
  title: string;
  description: string;
  created_at: string;
  occurs: string | Date; // Per retrocompatibilità con eventi manuali
  occurs_at: string;     // Stringa formattata
  start_time?: string;   // Nuovo standard datetime
  end_time?: string;     // Nuovo standard datetime
  categoria: CategoriaEvento;
  category_id?: number;
  recurrence_id: number | null;
  recurrence?: Recurrence; 
  created_by: {
    user: User;
    anagrafica: Anagrafica;
  };
  condomini:  Building[];
  condomini_ids?: number[];
  anagrafiche: Anagrafica[];
  occurrence_index?: number;
  note?: string;
  visibility?: string;
  is_approved: boolean;
  
  // --- CAMPO AGGIUNTO ---
  meta?: EventoMeta | null; 
}