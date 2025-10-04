import { Millesimo } from './millesimi';
import { Palazzina } from './palazzine';
import { Scala } from './scale';
import { Building } from '../buildings';
import { Component } from 'vue';

export interface Tabella {
  id: number;
  condominio_id: number;
  nome: string;
  tipo: 'standard' | 'ascensore' | 'scale' | 'riscaldamento' | 'acqua' | 'lastrico' | 'speciale' | 'altro';
  quota: 'millesimi' | 'persone' | 'kwatt' | 'mtcubi'| 'quote';
  regole_calcolo?: Record<string, any> | null;
  descrizione?: string;
  note?: string;
  attiva: boolean;
  numero_decimali: number;
  data_inizio?: string | null; 
  data_fine?: string | null; 
  created_by?: number | null;
  updated_by?: number | null;
  created_at: string;
  updated_at: string;
  millesimi: Millesimo[];
  condominio: Building
  palazzina: Palazzina;
  scala: Scala;
}

export interface StatusType {
  value: string;
  label: string;
  icon: Component;
  colorClass: string;
}
