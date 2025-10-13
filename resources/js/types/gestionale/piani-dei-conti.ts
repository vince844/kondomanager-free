import { Gestione } from './gestioni';

export interface PianoDeiConti {
    id: number
    nome: string
    descrizione: string
    note: string,  
    gestione: Gestione
}