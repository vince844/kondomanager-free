import { Gestione } from './gestioni';

export interface PianoDeiConti {
    id: number;
    nome: string;
    descrizione: string;
    note: string;
    gestione: Gestione;
    importo_totale: number;    // Somma degli importi dei conti
    capitoli_count: number;    // Conteggio delle voci di spesa
    data_creazione: string;    // Data formattata (d/m/Y)
}