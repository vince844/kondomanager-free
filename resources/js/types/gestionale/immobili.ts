import { Palazzina } from './palazzine';
import { Scala } from './scale';
import { TipologiaImmobile } from './tipologie-immobili';
import { AnagraficaWithPivot } from '../anagrafiche';

export interface Immobile {
    id: number
    nome: string
    descrizione: string
    note: string,
    codice_interno: string,
    superficie: number,
    comune_catasto: string,
    codice_catasto: string,
    foglio_catasto: string,
    particella_catasto: string,
    subalterno_catasto: string,
    sezione_catasto: string,
    attivo: boolean,
    numero_vani: number,
    interno: string,
    piano: string,
    palazzina: Palazzina,
    scala: Scala,
    tipologia: TipologiaImmobile, 
    anagrafiche: AnagraficaWithPivot[]          
  }
