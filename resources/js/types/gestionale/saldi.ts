export interface Gestione {
  id: number;
  nome: string;
  tipo: string;
}

export interface Saldo {
  id: number;
  saldo_iniziale: number;
  is_applicato: boolean;
  origine: string;
  gestione_id: number; 
  anagrafica_id: number | null; 
  gestione: Gestione;
  anagrafica: { 
    id: number;
    nome: string;
    cognome: string;
  } | null; 
}

export interface AnagraficaConSaldi {
  id: number;
  nome: string;
  cognome: string;
  saldi?: Saldo[]; 
  pivot?: {
    tipologia: string;
  };
}

export interface ImmobileConSaldi {
  id: number;
  nome: string;
  interno: string | null;
  scala: { name: string } | null;
  palazzina: { name: string } | null;
  anagrafiche: AnagraficaConSaldi[];
  saldi: Saldo[]; 
}