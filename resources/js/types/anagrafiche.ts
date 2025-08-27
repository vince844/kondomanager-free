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

export interface Anagrafica {
  id: number
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
  condomini: Building[] 
}

export interface AnagraficaWithPivot extends Anagrafica {
  pivot: AnagraficaPivot
}