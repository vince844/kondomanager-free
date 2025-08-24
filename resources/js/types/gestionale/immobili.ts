import { Palazzina } from './palazzine';
import { Scala } from './scale';

export interface Immobile {
    id: number
    name: string
    description: string
    note: string,
    codice_interno: string,
    superficie: number,
    comune_catasto: string,
    codice_catasto: string,
    foglio_catasto: string,
    particella_catasto: string,
    subalterno_catasto: string,
    vani: number,
    interno: string,
    piano: string,
    palazzina: Palazzina,
    scala: Scala             
  }
