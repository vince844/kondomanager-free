import { Anagrafica } from './anagrafiche';
import { Categoria } from './categorie';

export interface Fornitore {
  id: string
  ragione_sociale: string
  partita_iva: string
  codice_fiscale: string
  indirizzo: string
  cap: string
  comune: string
  provincia: string
  nazione: string
  iscrizione_cciaa: string
  data_iscrizione_cciaa: string
  codice_ateco: string
  numero_ordine: string
  categoria_id: string
  certificazione_iso: boolean
  capitale_sociale: string
  telefono: string
  cellulare: string
  fax: string
  email: string
  pec: string
  sito_web: string
  stato: string
  note: string
  codice_sia: string
  codice_cuc: string
  codice_sepa: string
  referenti: Anagrafica[]
  categoria: Categoria
}

