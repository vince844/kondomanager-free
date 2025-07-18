import { Building } from './buildings';

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
  condomini: Building[] 
}