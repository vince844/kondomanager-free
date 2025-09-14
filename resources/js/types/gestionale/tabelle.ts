import { Millesimo } from './millesimi';
import { Palazzina } from './palazzine';
import { Scala } from './scale';
import { Building } from '../buildings';

export interface Tabella {
  id: number;
  condominio_id: number;
  nome: string;
  tipo: 'standard' | 'ascensore' | 'scale' | 'riscaldamento' | 'acqua' | 'lastrico' | 'speciale' | 'altro';
  quota: 'millesimi' | 'persone' | 'kwatt' | 'mtcubi'| 'quote';
  regole_calcolo?: Record<string, any> | null;
  descrizione?: string | null;
  note?: string | null;
  attiva: boolean;
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
