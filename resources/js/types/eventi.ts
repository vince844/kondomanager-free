import { Anagrafica } from './anagrafiche';
import { User } from './users';
import { Building } from './buildings';
import { CategoriaEvento } from './categorie-eventi';

export type Stats = {
  next_seven_days: number,
  next_fourteen_days: number,
  next_twentyeight_days: number,
};

export interface Evento {
  id: number;
  title: string;
  description: string;
  created_at: string;
  categoria: CategoriaEvento;
  recurrence_id: number | null;
  created_by: {
    user: User;
    anagrafica: Anagrafica;
  };
  condomini: {
    options: Building[];
    full: Building[];
  };
  anagrafiche: Anagrafica[];
}