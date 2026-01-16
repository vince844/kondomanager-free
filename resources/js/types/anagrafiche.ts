import { Building } from './buildings';

export interface AnagraficaPivot {
  tipologia: string;
  quota: number;
  tipologie_spese: string | null;
  data_inizio: string | null;
  data_fine: string | null;
  attivo: boolean;
  note: string | null;
}

export interface DocumentType {
  label: string;
  id: string;
};

export interface AnagraficaFornitorePivot {
  fornitore_id: number;
  anagrafica_id: number;
  ruolo: string;
  referente_principale: boolean;
}

export interface SaldoAmounts {
  iniziale: number;
  finale: number;
}

export interface Saldo {
  iniziale: string; 
  finale: string;   
  amounts: SaldoAmounts; 
}

export interface Anagrafica {
  id: string
  nome: string
  indirizzo: string
  codice_fiscale: string
  luogo_nascita: string
  data_nascita: string
  numero_documento: string
  tipologia_documento: string
  scadenza_documento: string
  email: string
  email_secondaria: string
  pec: string
  telefono: string
  cellulare: string
  note: string
  user_id: string | null
  condomini: Building[] 
}

export interface AnagraficaWithPivot extends Anagrafica {
  pivot: AnagraficaPivot
  saldo: Saldo
}

export interface AnagraficaFornitore extends Anagrafica {
  pivot: AnagraficaFornitorePivot
}
