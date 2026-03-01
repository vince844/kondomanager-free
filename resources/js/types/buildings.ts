import { Palazzina } from './gestionale/palazzine';
import { Scala } from './gestionale/scale';

export interface Building {
    id: number;
    nome: string;
    indirizzo: string;
    email: string | null;
    note: string | null;              
    codice_fiscale: string | null;       
    
    // Nuovi campi Ubicazione
    comune: string | null;
    provincia: string | null;
    cap: string | null;
    
    // Nuovi campi Strutturali
    anno_costruzione: number | null;
    anno_acquisizione: number | null;
    numero_piani: number | null;

    // Campi Catastali
    comune_catasto: string | null;     
    codice_catasto: string | null;      
    sezione_catasto: string | null; 
    foglio_catasto: string | null;    
    particella_catasto: string | null;
    
    codice_identificativo: string;
    palazzine: Palazzina; 
    scale: Scala; 
    value: number; 
    label: string; 
    option?: {
      value: number;
      label: string;
    };
    full?: Building;
}