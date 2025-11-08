import { Gestione } from './gestioni';

export interface PianoRate {
    id: number
    nome: string
    descrizione: string
    note: string,  
    gestione: Gestione
}