import { Component } from 'vue';
import { Esercizio } from './esercizi';

export interface Gestione {
    id: number
    nome: string
    descrizione: string
    note: string,
    data_inizio: string,
    data_fine: string,
    tipo: 'ordinaria' | 'straordinaria';   
    esercizio: Esercizio       
}

export interface StatusType {
  value: string;
  label: string;
  icon: Component;
  colorClass: string;
}