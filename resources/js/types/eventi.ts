import { Anagrafica } from './anagrafiche';
import { User } from './users';
import { Building } from './buildings';
import { CategoriaEvento } from './categorie-eventi';

export interface Evento {
  id: number;
  title: string;
  description: string;
  created_at: string;
  categoria: CategoriaEvento;
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