
export interface Esercizio {
    id: number
    nome: string
    descrizione: string
    note: string,
    data_inizio: string,
    data_fine: string | null,
    stato: 'aperto' | 'chiuso';         
}