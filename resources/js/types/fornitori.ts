import { Anagrafica, AnagraficaFornitore } from './anagrafiche';
import { Categoria } from './categorie';

export interface Fornitore {
  id: string; 
  ragione_sociale: string;
  partita_iva?: string;
  codice_fiscale?: string;
  indirizzo?: string;
  cap?: string;
  comune?: string;
  provincia?: string;
  nazione?: string;
  iscrizione_cciaa?: string;
  data_iscrizione_cciaa?: string;
  codice_ateco?: string;
  numero_iscrizione_ordine?: string;
  categoria_id?: string | number;
  certificazione_iso: boolean;
  capitale_sociale?: string;
  telefono?: string;
  cellulare?: string;
  fax?: string;
  email?: string;
  pec?: string;
  sito_web?: string;
  stato: string;
  note?: string;
  codice_sia?: string;
  codice_cuc?: string;
  codice_sepa?: string;
  referenti?: AnagraficaFornitore[];
  categoria?: Categoria;
  anagrafica_id?: number | string; 
  soggetto_ritenuta: boolean;
  perc_ritenuta?: string | number;
  perc_imponibile_ritenuta?: string | number;
  codice_tributo?: string;
  giorni_scadenza?: number;
  modalita_pagamento_default?: string;
  iban_principale?: string;
}

