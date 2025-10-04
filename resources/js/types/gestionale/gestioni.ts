import { Component } from 'vue';

export interface Gestione {
    id: number
    nome: string
    descrizione: string
    note: string,
    data_inizio: string,
    data_fine: string,
    tipo: 'ordinaria' | 'straordinaria';         
}

export interface StatusType {
  value: string;
  label: string;
  icon: Component;
  colorClass: string;
}