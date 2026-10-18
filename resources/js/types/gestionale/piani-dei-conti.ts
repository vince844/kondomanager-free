import { Gestione } from './gestioni';

export interface PianoDeiConti {
    id: number;
    nome: string;
    descrizione: string;
    note: string;
    gestione: Gestione;
    importo_totale: number;    // Somma degli importi delle voci di spesa (il preventivo)
    importo_entrate?: number;  // Somma, in valore assoluto, degli importi delle voci di entrata
    capitoli_count: number;    // Conteggio delle voci
    capitoli_entrate_count?: number; // Quante di quelle voci sono di entrata
    data_creazione: string;    // Data formattata (d/m/Y)
}