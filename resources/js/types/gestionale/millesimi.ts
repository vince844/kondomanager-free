import { Immobile } from './immobili';

export interface Millesimo {
  id: number;
  tabella_id: number;
  immobile_id: number;
  valore?: string;
  coefficienti?: Record<string, any> | null;
  escluso: boolean;
  created_by?: number | null;
  updated_by?: number | null;
  created_at: string;
  updated_at: string;
  immobile: Immobile
}
