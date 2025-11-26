import { Component } from 'vue';

export interface Esercizio {
    id: number
    nome: string
    descrizione: string
    note: string,
    data_inizio: string,
    data_fine: string | null,
    stato: 'aperto' | 'chiuso';         
}

export interface StatusType {
  value: string;
  label: string;
  icon: Component;
  colorClass: string;
}