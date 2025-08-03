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

export interface Evento {
  id: number;
  title: string;
  description: string;
  created_at: string;
  occurs: string | Date;
  occurs_at: string;
  start_time?: string;
  end_time?: string;
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
}