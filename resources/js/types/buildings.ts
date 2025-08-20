import { Palazzina } from './gestionale/palazzine';

export interface Building {
    id: string
    nome: string
    indirizzo: string
    email: string
    note: string,              
    codice_fiscale: string,       
    comune_catasto: string,     
    codice_catasto: string,      
    sezione_catasto: string, 
    foglio_catasto: string,    
    particella_catasto: string,
    codice_identificativo: string,
    palazzine: Palazzina | null, 
    value: number; 
    label: string; 
    option?: {
      value: number;
      label: string;
    };
    full?: Building;
  }

   